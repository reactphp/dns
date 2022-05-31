<?php

namespace React\Dns\Query;

use React\EventLoop\LoopInterface;

/**
 * Send DNS queries over a TCP/IP+TLS stream transport.
 *
 * This is the class to send a secure DNS query to your DNS server.
 *
 * For more advanced usages one can utilize this class directly.
 * The following example looks up the `IPv6` address for `reactphp.org`.
 *
 * ```php
 * $executor = new TlsTransportExecutor('8.8.8.8:853');
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
 * queries concurrently. The first query will establish a TCP/IP+TLS socket
 * connection to the DNS server which will be kept open for a short period.
 * Additional queries will automatically reuse this existing socket connection
 * to the DNS server, will pipeline multiple requests over this single
 * connection and will keep an idle connection open for a short period. The
 * initial TCP/IP+TLS connection overhead may incur a slight delay if you only send
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
 *         new TlsTransportExecutor($nameserver),
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
 *  Support for DNS over TLS can be enabled via specifying the nameserver with scheme tls://
 *  @link https://tools.ietf.org/html/rfc7858
 */
class TlsTransportExecutor extends TcpTransportExecutor
{
    /** @var bool */
    private $cryptoEnabled = false;

    /**
     * @param string         $nameserver
     * @param ?LoopInterface $loop
     */
    public function __construct($nameserver, LoopInterface $loop = null)
    {
        if (!\function_exists('stream_socket_enable_crypto') || defined('HHVM_VERSION') || \PHP_VERSION_ID < 50600) {
            throw new \RuntimeException('Encryption not supported on your platform (HHVM < 3.8 or PHP < 5.6?)'); // @codeCoverageIgnore
        }

        $parsedNameserver = \parse_url((\strpos($nameserver, '://') === false ? 'tls://' : '') . $nameserver);
        if ($parsedNameserver['scheme'] !== 'tls') {
            throw new \InvalidArgumentException('Invalid nameserver address given');
        }

        // Setup sane defaults for SSL to ensure secure connection to the DNS server
        $query = array();
        if (isset($parsedNameserver['query'])) {
            \parse_str($parsedNameserver['query'], $query);
        }
        $query = array_merge(array(
            'ssl' => array(
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            )
        ), $query);

        // Rebuild the nameserver string and set the default DoTLS port of 853 if not set before sending to TcpTransportExecutor constructor
        $nameserver = 'tcp://' . $parsedNameserver['host'] . ':' . (isset($parsedNameserver['port']) ? $parsedNameserver['port'] : 853) . '/? ' . http_build_query($query);

        parent::__construct($nameserver, $loop);

        // read one chunk of data from the DNS server
        // any error is fatal, this is a stream of TCP/IP data
        // PHP < 7.3.3 (and PHP < 7.2.15) suffers from a bug where feof() might
        // block with 100% CPU usage on fragmented TLS records.
        // We try to work around this by always consuming the complete receive
        // buffer at once to avoid stale data in TLS buffers. This is known to
        // work around high CPU usage for well-behaving peers, but this may
        // cause very large data chunks for high throughput scenarios. The buggy
        // behavior can still be triggered due to network I/O buffers or
        // malicious peers on affected versions, upgrading is highly recommended.
        // @link https://bugs.php.net/bug.php?id=77390
        if (\PHP_VERSION_ID < 70215 || (\PHP_VERSION_ID >= 70300 && \PHP_VERSION_ID < 70303)) {
            $this->readChunk = -1;
        }

        // PHP < 7.1.4 (and PHP < 7.0.18) suffers from a bug when writing big
        // chunks of data over TLS streams at once.
        // We try to work around this by limiting the write chunk size to 8192
        // bytes for older PHP versions only.
        // This is only a work-around and has a noticable performance penalty on
        // affected versions. Please update your PHP version.
        // This applies only to configured TLS connections
        // See https://github.com/reactphp/socket/issues/105
        if (\PHP_VERSION_ID < 70018 || (\PHP_VERSION_ID >= 70100 && \PHP_VERSION_ID < 70104)) {
            $this->writeChunk = 8192; // @codeCoverageIgnore
        }
    }

    /**
     * @internal
     */
    public function handleWritable()
    {
        if (!$this->cryptoEnabled) {
            $error = null;
            \set_error_handler(function ($_, $errstr) use (&$error) {
                $error = \str_replace(array("\r", "\n"), ' ', $errstr);

                // remove useless function name from error message
                if (($pos = \strpos($error, "): ")) !== false) {
                    $error = \substr($error, $pos + 3);
                }
            });

            $method = \STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (\PHP_VERSION_ID < 70200 && \PHP_VERSION_ID >= 50600) {
                $method |= \STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT | \STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT | \STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT; // @codeCoverageIgnore
            }

            $result = \stream_socket_enable_crypto($this->socket, true, $method);

            \restore_error_handler();

            if (true === $result) {
                $this->cryptoEnabled = true;
            } elseif (false === $result) {
                if (\feof($this->socket) || $error === null) {
                    // EOF or failed without error => connection closed during handshake
                    $this->closeError(
                        'Connection lost during TLS handshake (ECONNRESET)',
                        \defined('SOCKET_ECONNRESET') ? \SOCKET_ECONNRESET : 104
                    );
                } else {
                    // handshake failed with error message
                    $this->closeError(
                        $error
                    );
                }
                return;
            } else {
                // need more data, will retry
                return;
            }
        }

        parent::handleWritable();
    }
}
