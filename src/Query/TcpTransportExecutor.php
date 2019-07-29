<?php

namespace React\Dns\Query;

use React\Dns\Model\Message;
use React\Dns\Protocol\BinaryDumper;
use React\Dns\Protocol\Parser;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;

/**
 * Send DNS queries over a TCP/IP stream transport.
 *
 * This is one of the main classes that send a DNS query to your DNS server.
 *
 * For more advanced usages one can utilize this class directly.
 * The following example looks up the `IPv6` address for `reactphp.org`.
 *
 * ```php
 * $loop = Factory::create();
 * $executor = new TcpTransportExecutor('8.8.8.8:53', $loop);
 *
 * $executor->query(
 *     new Query($name, Message::TYPE_AAAA, Message::CLASS_IN)
 * )->then(function (Message $message) {
 *     foreach ($message->answers as $answer) {
 *         echo 'IPv6: ' . $answer->data . PHP_EOL;
 *     }
 * }, 'printf');
 *
 * $loop->run();
 * ```
 *
 * See also [example #92](examples).
 *
 * Note that this executor does not implement a timeout, so you will very likely
 * want to use this in combination with a `TimeoutExecutor` like this:
 *
 * ```php
 * $executor = new TimeoutExecutor(
 *     new TcpTransportExecutor($nameserver, $loop),
 *     3.0,
 *     $loop
 * );
 * ```
 *
 * Unlike the `UdpTransportExecutor`, this class uses a reliable TCP/IP
 * transport, so you do not necessarily have to implement any retry logic.
 *
 * Note that this executor is entirely async and as such allows you to execute
 * any number of queries concurrently. You should probably limit the number of
 * concurrent queries in your application or you're very likely going to face
 * rate limitations and bans on the resolver end. For many common applications,
 * you may want to avoid sending the same query multiple times when the first
 * one is still pending, so you will likely want to use this in combination with
 * a `CoopExecutor` like this:
 *
 * ```php
 * $executor = new CoopExecutor(
 *     new TimeoutExecutor(
 *         new TcpTransportExecutor($nameserver, $loop),
 *         3.0,
 *         $loop
 *     )
 * );
 * ```
 *
 * > Internally, this class uses PHP's TCP/IP sockets and does not take advantage
 *   of [react/socket](https://github.com/reactphp/socket) purely for
 *   organizational reasons to avoid a cyclic dependency between the two
 *   packages. Higher-level components should take advantage of the Socket
 *   component instead of reimplementing this socket logic from scratch.
 */
class TcpTransportExecutor implements ExecutorInterface
{
    private $nameserver;
    private $loop;
    private $parser;
    private $dumper;

    /**
     * @param string        $nameserver
     * @param LoopInterface $loop
     */
    public function __construct($nameserver, LoopInterface $loop)
    {
        if (\strpos($nameserver, '[') === false && \substr_count($nameserver, ':') >= 2 && \strpos($nameserver, '://') === false) {
            // several colons, but not enclosed in square brackets => enclose IPv6 address in square brackets
            $nameserver = '[' . $nameserver . ']';
        }

        $parts = \parse_url((\strpos($nameserver, '://') === false ? 'tcp://' : '') . $nameserver);
        if (!isset($parts['scheme'], $parts['host']) || $parts['scheme'] !== 'tcp' || !\filter_var(\trim($parts['host'], '[]'), \FILTER_VALIDATE_IP)) {
            throw new \InvalidArgumentException('Invalid nameserver address given');
        }

        $this->nameserver = $parts['host'] . ':' . (isset($parts['port']) ? $parts['port'] : 53);
        $this->loop = $loop;
        $this->parser = new Parser();
        $this->dumper = new BinaryDumper();
    }

    public function query(Query $query)
    {
        $request = Message::createRequestForQuery($query);
        $queryData = $this->dumper->toBinary($request);
        $length = \strlen($queryData);
        if ($length > 0xffff) {
            return \React\Promise\reject(new \RuntimeException(
                'DNS query for ' . $query->name . ' failed: Query too large for TCP transport'
            ));
        }

        $queryData = \pack('n', $length) . $queryData;

        // create async TCP/IP connection (may take a while)
        $socket = @\stream_socket_client($this->nameserver, $errno, $errstr, 0, \STREAM_CLIENT_CONNECT | \STREAM_CLIENT_ASYNC_CONNECT);
        if ($socket === false) {
            return \React\Promise\reject(new \RuntimeException(
                'DNS query for ' . $query->name . ' failed: Unable to connect to DNS server ('  . $errstr . ')',
                $errno
            ));
        }

        $loop = $this->loop;
        $deferred = new Deferred(function () use ($loop, $socket, $query) {
            // cancellation should remove socket from loop and close socket
            $loop->removeReadStream($socket);
            $loop->removeWriteStream($socket);
            \fclose($socket);

            throw new CancellationException('DNS query for ' . $query->name . ' has been cancelled');
        });

        // set socket to non-blocking and wait for it to become writable (connection success/rejected)
        \stream_set_blocking($socket, false);
        $loop->addWriteStream($socket, function ($socket) use ($loop, $query, $queryData, $deferred) {
            $loop->removeWriteStream($socket);
            $name = @\stream_socket_get_name($socket, true);
            if ($name === false) {
                $loop->removeReadStream($socket);
                @\fclose($socket);
                $deferred->reject(new \RuntimeException(
                    'DNS query for ' . $query->name . ' failed: Connection to DNS server rejected'
                ));
                return;
            }

            $written = @\fwrite($socket, $queryData);
            if ($written !== \strlen($queryData)) {
                $loop->removeReadStream($socket);
                \fclose($socket);
                $deferred->reject(new \RuntimeException(
                    'DNS query for ' . $query->name . ' failed: Unable to write DNS query message in one chunk'
                ));
            }
        });

        $buffer = '';
        $parser = $this->parser;
        $loop->addReadStream($socket, function ($socket) use (&$buffer, $loop, $deferred, $query, $parser, $request) {
            // read one chunk of data from the DNS server
            // any error is fatal, this is a stream of TCP/IP data
            $chunk = @\fread($socket, 65536);
            if ($chunk === false || $chunk === '') {
                $loop->removeReadStream($socket);
                \fclose($socket);
                $deferred->reject(new \RuntimeException(
                    'DNS query for ' . $query->name . ' failed: Connection to DNS server lost'
                ));
                return;
            }

            // reassemble complete message by concatenating all chunks.
            // response message header contains at least 12 bytes
            $buffer .= $chunk;
            if (!isset($buffer[11])) {
                return;
            }

            // read response message length from first 2 bytes and ensure we have length + data in buffer
            list(, $length) = \unpack('n', $buffer);
            if (!isset($buffer[$length + 1])) {
                return;
            }

            // we only react to the first complete message, so remove socket from loop and close
            $loop->removeReadStream($socket);
            \fclose($socket);
            $data = \substr($buffer, 2, $length);
            $buffer = '';

            try {
                $response = $parser->parseMessage($data);
            } catch (\Exception $e) {
                // reject if we received an invalid message from remote server
                $deferred->reject(new \RuntimeException(
                    'DNS query for ' . $query->name . ' failed: Invalid message received from DNS server',
                    0,
                    $e
                ));
                return;
            }

            // reject if we received an unexpected response ID or truncated response
            if ($response->id !== $request->id || $response->tc) {
                $deferred->reject(new \RuntimeException(
                    'DNS query for ' . $query->name . ' failed: Invalid response message received from DNS server'
                ));
                return;
            }

            $deferred->resolve($response);
        });

        return $deferred->promise();
    }
}
