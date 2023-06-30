<?php

namespace React\Dns\Query;

use React\Dns\Model\Message;
use React\Dns\Protocol\BinaryDumper;
use React\Dns\Protocol\Parser;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise;

/**
 * Send DNS queries over a TCP/IP stream transport.
 *
 * This is one of the main classes that send a DNS query to your DNS server.
 *
 * For more advanced usages one can utilize this class directly.
 * The following example looks up the `IPv6` address for `reactphp.org`.
 *
 * ```php
 * $executor = new TcpTransportExecutor('8.8.8.8:53');
 *
 * $executor->query(
 *     new Query($name, Message::TYPE_AAAA, Message::CLASS_IN)
 * )->then(function (Message $message) {
 *     foreach ($message->answers as $answer) {
 *         echo 'IPv6: ' . $answer->data . PHP_EOL;
 *     }
 * }, 'printf');
 * ```
 *
 * See also [example #92](examples).
 *
 * Note that this executor does not implement a timeout, so you will very likely
 * want to use this in combination with a `TimeoutExecutor` like this:
 *
 * ```php
 * $executor = new TimeoutExecutor(
 *     new TcpTransportExecutor($nameserver),
 *     3.0
 * );
 * ```
 *
 * Unlike the `UdpTransportExecutor`, this class uses a reliable TCP/IP
 * transport, so you do not necessarily have to implement any retry logic.
 *
 * Note that this executor is entirely async and as such allows you to execute
 * queries concurrently. The first query will establish a TCP/IP socket
 * connection to the DNS server which will be kept open for a short period.
 * Additional queries will automatically reuse this existing socket connection
 * to the DNS server, will pipeline multiple requests over this single
 * connection and will keep an idle connection open for a short period. The
 * initial TCP/IP connection overhead may incur a slight delay if you only send
 * occasional queries – when sending a larger number of concurrent queries over
 * an existing connection, it becomes increasingly more efficient and avoids
 * creating many concurrent sockets like the UDP-based executor. You may still
 * want to limit the number of (concurrent) queries in your application or you
 * may be facing rate limitations and bans on the resolver end. For many common
 * applications, you may want to avoid sending the same query multiple times
 * when the first one is still pending, so you will likely want to use this in
 * combination with a `CoopExecutor` like this:
 *
 * ```php
 * $executor = new CoopExecutor(
 *     new TimeoutExecutor(
 *         new TcpTransportExecutor($nameserver),
 *         3.0
 *     )
 * );
 * ```
 *
 * > Internally, this class uses PHP's TCP/IP sockets and does not take advantage
 *   of [react/socket](https://github.com/reactphp/socket) purely for
 *   organizational reasons to avoid a cyclic dependency between the two
 *   packages. Higher-level components should take advantage of the Socket
 *   component instead of reimplementing this socket logic from scratch.
 *
 */
class TcpTransportExecutor implements ExecutorInterface
{
    private $nameserver;
    private $loop;
    private $parser;
    private $dumper;

    /**
     * @var ?resource
     */
    protected $socket;

    /**
     * @var Promise\Deferred[]
     */
    private $pending = array();

    /**
     * @var string[]
     */
    private $names = array();

    /**
     * Maximum idle time when socket is current unused (i.e. no pending queries outstanding)
     *
     * If a new query is to be sent during the idle period, we can reuse the
     * existing socket without having to wait for a new socket connection.
     * This uses a rather small, hard-coded value to not keep any unneeded
     * sockets open and to not keep the loop busy longer than needed.
     *
     * A future implementation may take advantage of `edns-tcp-keepalive` to keep
     * the socket open for longer periods. This will likely require explicit
     * configuration because this may consume additional resources and also keep
     * the loop busy for longer than expected in some applications.
     *
     * @var float
     * @link https://tools.ietf.org/html/rfc7766#section-6.2.1
     * @link https://tools.ietf.org/html/rfc7828
     */
    private $idlePeriod = 0.001;

    /**
     * @var ?\React\EventLoop\TimerInterface
     */
    private $idleTimer;

    private $writeBuffer = '';
    private $writePending = false;

    private $readBuffer = '';
    private $readPending = false;

    /** @var string */
    protected $readChunk = 0xffff;

    /** @var null|int */
    protected $writeChunk = null;

    /** @var array Connection parameters to provide to stream_context_create */
    private $connectionParameters = array();

    /**
     * @param string         $nameserver
     * @param ?LoopInterface $loop
     */
    public function __construct($nameserver, LoopInterface $loop = null)
    {
        if (\strpos($nameserver, '[') === false && \substr_count($nameserver, ':') >= 2 && \strpos($nameserver, '://') === false) {
            // several colons, but not enclosed in square brackets => enclose IPv6 address in square brackets
            $nameserver = '[' . $nameserver . ']';
        }

        $parts = \parse_url((\strpos($nameserver, '://') === false ? 'tcp://' : '') . $nameserver);
        if (!isset($parts['scheme'], $parts['host']) || $parts['scheme'] !== 'tcp' || @\inet_pton(\trim($parts['host'], '[]')) === false) {
            throw new \InvalidArgumentException('Invalid nameserver address given');
        }

        //Parse any connection parameters to be supplied to stream_context_create()
        if (isset($parts['query'])) {
            parse_str($parts['query'], $this->connectionParameters);
        }

        $this->nameserver = 'tcp://' . $parts['host'] . ':' . (isset($parts['port']) ? $parts['port'] : 53);
        $this->loop = $loop ?: Loop::get();
        $this->parser = new Parser();
        $this->dumper = new BinaryDumper();
    }

    public function query(Query $query)
    {
        $request = Message::createRequestForQuery($query);

        // keep shuffing message ID to avoid using the same message ID for two pending queries at the same time
        while (isset($this->pending[$request->id])) {
            $request->id = \mt_rand(0, 0xffff); // @codeCoverageIgnore
        }

        $queryData = $this->dumper->toBinary($request);
        $length = \strlen($queryData);
        if ($length > 0xffff) {
            return Promise\reject(new \RuntimeException(
                'DNS query for ' . $query->describe() . ' failed: Query too large for TCP transport'
            ));
        }

        $queryData = \pack('n', $length) . $queryData;

        if ($this->socket === null) {
            //Setup stream context if requested ($options must be null if connectionParameters is an empty array
            $context = stream_context_create((empty($this->connectionParameters) ? null : $this->connectionParameters));
            // create async TCP/IP connection (may take a while)
            $socket = @\stream_socket_client($this->nameserver, $errno, $errstr, 0, \STREAM_CLIENT_CONNECT | \STREAM_CLIENT_ASYNC_CONNECT, $context);
            if ($socket === false) {
                return Promise\reject(new \RuntimeException(
                    'DNS query for ' . $query->describe() . ' failed: Unable to connect to DNS server ' . $this->nameserver . ' ('  . $errstr . ')',
                    $errno
                ));
            }

            // set socket to non-blocking and wait for it to become writable (connection success/rejected)
            \stream_set_blocking($socket, false);
            if ($this->readChunk !== -1 && \function_exists('stream_set_chunk_size')) {
                \stream_set_chunk_size($socket, $this->readChunk); // @codeCoverageIgnore
            }
            $this->socket = $socket;
        }

        if ($this->idleTimer !== null) {
            $this->loop->cancelTimer($this->idleTimer);
            $this->idleTimer = null;
        }

        // wait for socket to become writable to actually write out data
        $this->writeBuffer .= $queryData;
        if (!$this->writePending) {
            $this->writePending = true;
            $this->loop->addWriteStream($this->socket, array($this, 'handleWritable'));
        }

        $names =& $this->names;
        $that = $this;
        $deferred = new Promise\Deferred(function () use ($that, &$names, $request) {
            // remove from list of pending names, but remember pending query
            $name = $names[$request->id];
            unset($names[$request->id]);
            $that->checkIdle();

            throw new CancellationException('DNS query for ' . $name . ' has been cancelled');
        });

        $this->pending[$request->id] = $deferred;
        $this->names[$request->id] = $query->describe();

        return $deferred->promise();
    }

    /**
     * @internal
     */
    public function handleWritable()
    {
        if ($this->readPending === false) {
            $name = @\stream_socket_get_name($this->socket, true);
            if (!is_string($name)) { //PHP: false, HHVM: null on error
                // Connection failed? Check socket error if available for underlying errno/errstr.
                // @codeCoverageIgnoreStart
                if (\function_exists('socket_import_stream')) {
                    $socket = \socket_import_stream($this->socket);
                    $errno = \socket_get_option($socket, \SOL_SOCKET, \SO_ERROR);
                    $errstr = \socket_strerror($errno);
                } else {
                    $errno = \defined('SOCKET_ECONNREFUSED') ? \SOCKET_ECONNREFUSED : 111;
                    $errstr = 'Connection refused';
                }
                // @codeCoverageIgnoreEnd

                $this->closeError('Unable to connect to DNS server ' . $this->nameserver . ' (' . $errstr . ')', $errno);
                return;
            }

            $this->readPending = true;
            $this->loop->addReadStream($this->socket, array($this, 'handleRead'));
        }

        $errno = 0;
        $errstr = null;
        \set_error_handler(function ($_, $error) use (&$errno, &$errstr) {
            // Match errstr from PHP's warning message.
            // fwrite(): Send of 327712 bytes failed with errno=32 Broken pipe
            \preg_match('/errno=(\d+) (.+)/', $error, $m);
            $errno = isset($m[1]) ? (int) $m[1] : 0;
            $errstr = isset($m[2]) ? $m[2] : $error;
        });

        $written = \fwrite($this->socket, $this->writeBuffer, $this->writeChunk);

        // Only report errors if *nothing* could be sent and an error has been raised, or we are unable to retrieve the remote socket name (connection dead) [HHVM].
        // Ignore non-fatal warnings if *some* data could be sent.
        // Any hard (permanent) error will fail to send any data at all.
        // Sending excessive amounts of data will only flush *some* data and then
        // report a temporary error (EAGAIN) which we do not raise here in order
        // to keep the stream open for further tries to write.
        // Should this turn out to be a permanent error later, it will eventually
        // send *nothing* and we can detect this.
        if (($written === false || $written === 0)) {
            $name = @\stream_socket_get_name($this->socket, true);
            if (!is_string($name) || $errstr !== null) {
                \restore_error_handler();
                $this->closeError(
                    'Unable to send query to DNS server ' . $this->nameserver . ' (' . $errstr . ')',
                    $errno
                );
                return;
            }
        }

        \restore_error_handler();

        if (isset($this->writeBuffer[$written])) {
            $this->writeBuffer = \substr($this->writeBuffer, $written);
        } else {
            $this->loop->removeWriteStream($this->socket);
            $this->writePending = false;
            $this->writeBuffer = '';
        }
    }

    /**
     * @internal
     */
    public function handleRead()
    {
        // @codeCoverageIgnoreStart
        if (null === $this->socket) {
            $this->closeError('Connection to DNS server ' . $this->nameserver . ' lost');
            return;
        }
        // @codeCoverageIgnoreEnd

        $chunk = @\stream_get_contents($this->socket, $this->readChunk);

        if ($chunk === false || $chunk === '') {
            $this->closeError('Connection to DNS server ' . $this->nameserver . ' lost');
            return;
        }

        // reassemble complete message by concatenating all chunks.
        $this->readBuffer .= $chunk;

        // response message header contains at least 12 bytes
        while (isset($this->readBuffer[11])) {
            // read response message length from first 2 bytes and ensure we have length + data in buffer
            list(, $length) = \unpack('n', $this->readBuffer);
            if (!isset($this->readBuffer[$length + 1])) {
                return;
            }

            $data = \substr($this->readBuffer, 2, $length);
            $this->readBuffer = (string)substr($this->readBuffer, $length + 2);

            try {
                $response = $this->parser->parseMessage($data);
            } catch (\Exception $e) {
                // reject all pending queries if we received an invalid message from remote server
                $this->closeError('Invalid message received from DNS server ' . $this->nameserver);
                return;
            }

            // reject all pending queries if we received an unexpected response ID or truncated response
            if (!isset($this->pending[$response->id]) || $response->tc) {
                $this->closeError('Invalid response message received from DNS server ' . $this->nameserver);
                return;
            }

            $deferred = $this->pending[$response->id];
            unset($this->pending[$response->id], $this->names[$response->id]);

            $deferred->resolve($response);

            $this->checkIdle();
        }
    }

    /**
     * @internal
     * @param string $reason
     * @param int    $code
     */
    public function closeError($reason, $code = 0)
    {
        $this->readBuffer = '';
        if ($this->readPending) {
            $this->loop->removeReadStream($this->socket);
            $this->readPending = false;
        }

        $this->writeBuffer = '';
        if ($this->writePending) {
            $this->loop->removeWriteStream($this->socket);
            $this->writePending = false;
        }

        if ($this->idleTimer !== null) {
            $this->loop->cancelTimer($this->idleTimer);
            $this->idleTimer = null;
        }

        if (null !== $this->socket) {
            @\fclose($this->socket);
            $this->socket = null;
        }

        foreach ($this->names as $id => $name) {
            $this->pending[$id]->reject(new \RuntimeException(
                'DNS query for ' . $name . ' failed: ' . $reason,
                $code
            ));
        }
        $this->pending = $this->names = array();
    }

    /**
     * @internal
     */
    public function checkIdle()
    {
        if ($this->idleTimer === null && !$this->names) {
            $that = $this;
            $this->idleTimer = $this->loop->addTimer($this->idlePeriod, function () use ($that) {
                $that->closeError('Idle timeout');
            });
        }
    }
}
