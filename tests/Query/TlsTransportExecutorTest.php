<?php

namespace React\Tests\Dns\Query;

use React\Dns\Model\Message;
use React\Dns\Protocol\BinaryDumper;
use React\Dns\Protocol\Parser;
use React\Dns\Query\Query;
use React\Dns\Query\TlsTransportExecutor;
use React\EventLoop\Factory;
use React\Tests\Dns\TestCase;

class TlsTransportExecutorTest extends TestCase
{
    public function testQueryRejectsWhenTlsCannotBeEstablished()
    {
        if (defined('HHVM_VERSION') || \PHP_VERSION_ID < 50600) {
            $this->markTestSkipped('DNS over TLS not supported on legacy PHP');
        }

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $server = \stream_socket_server('tcp://127.0.0.1:0');
        $address = \stream_socket_get_name($server, false);
        $executor = new TlsTransportExecutor('tls://' . $address, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $exception = null;
        $executor->query($query)->then(
            null,
            function ($e) use (&$exception) {
                $exception = $e;
            }
        );

        $obj = new \ReflectionObject($executor);
        $ref = $obj->getParentClass()->getProperty('writePending');
        $ref->setAccessible(true);
        while($ref->getValue($executor)) {
            //Call handleWritable as many times as required to perform the attempted TLS handshake
            $executor->handleWritable();
            @\stream_socket_accept($server,0);
        }

        /** @var \RuntimeException $exception */
        $this->assertInstanceOf('RuntimeException', $exception);
        $this->assertContains($exception->getMessage(), array(
            'DNS query for google.com (A) failed: Connection lost during TLS handshake (ECONNRESET)',
            'DNS query for google.com (A) failed: SSL: Undefined error: 0',
        ));
    }

    public function testQueryRejectsWhenTlsClosedDuringHandshake()
    {
        if (defined('HHVM_VERSION') || \PHP_VERSION_ID < 50600) {
            $this->markTestSkipped('DNS over TLS not supported on legacy PHP');
        }

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $server = \stream_socket_server('tcp://127.0.0.1:0');
        $address = \stream_socket_get_name($server, false);
        $executor = new TlsTransportExecutor('tls://' . $address, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $exception = null;
        $executor->query($query)->then(
            null,
            function ($e) use (&$exception) {
                $exception = $e;
            }
        );

        $obj = new \ReflectionObject($executor);
        $ref = $obj->getParentClass()->getProperty('writePending');
        $ref->setAccessible(true);
        while($ref->getValue($executor)) {
            //Call handleWritable as many times as required to perform the attempted TLS handshake
            $executor->handleWritable();
            $client = @\stream_socket_accept($server,0);
            if (false !== $client) {
                fclose($client);
            }
        }

        /** @var \RuntimeException $exception */
        $this->assertInstanceOf('RuntimeException', $exception);
        $this->assertContains($exception->getMessage(), array(
            'DNS query for google.com (A) failed: Connection lost during TLS handshake (ECONNRESET)',
            'DNS query for google.com (A) failed: SSL: Undefined error: 0',
        ));
    }

    public function testQueryRejectsWhenTlsCertificateVerificationFails()
    {
        if (defined('HHVM_VERSION') || \PHP_VERSION_ID < 50600) {
            $this->markTestSkipped('DNS over TLS not supported on legacy PHP');
        }

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        // Connect to self-signed.badssl.com https://github.com/chromium/badssl.com
        $executor = new TlsTransportExecutor('tls://104.154.89.105:443', $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $executor->query($query)->then(
            null,
            function ($e) use (&$exception) {
                $exception = $e;
            }
        );

        $obj = new \ReflectionObject($executor);
        $ref = $obj->getParentClass()->getProperty('writePending');
        $ref->setAccessible(true);
        while($ref->getValue($executor)) {
            //Call handleWritable as many times as required to perform the TLS handshake
            $executor->handleWritable();
        }

        /** @var \RuntimeException $exception */
        $this->assertInstanceOf('RuntimeException', $exception);
        $this->assertStringStartsWith('DNS query for google.com (A) failed: SSL operation failed with code ', $exception->getMessage());
        if (method_exists($this, 'assertStringContainsString')) {
            $this->assertStringContainsString('certificate verify failed', $exception->getMessage());
        } else {
            $this->assertContains('certificate verify failed', $exception->getMessage());
        }
    }

    public function testCryptoEnabledAfterConnectingToTlsDnsServer()
    {
        if (defined('HHVM_VERSION') || \PHP_VERSION_ID < 50600) {
            $this->markTestSkipped('DNS over TLS not supported on legacy PHP');
        }

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $executor = new TlsTransportExecutor('tls://8.8.8.8', $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $executor->query($query);

        $obj = new \ReflectionObject($executor);
        $ref = $obj->getParentClass()->getProperty('writePending');
        $ref->setAccessible(true);
        while($ref->getValue($executor)) {
            //Call handleWritable as many times as required to perform the TLS handshake
            $executor->handleWritable();
        }

        $ref = new \ReflectionProperty($executor, 'cryptoEnabled');
        $ref->setAccessible(true);
        $this->assertTrue($ref->getValue($executor));
    }

    public function testCryptoEnabledWithPeerFingerprintMatch()
    {
        if (defined('HHVM_VERSION') || \PHP_VERSION_ID < 50600) {
            $this->markTestSkipped('DNS over TLS not supported on legacy PHP');
        }

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        //1.1.1.1 used here. Google 8.8.8.8 uses two different certs at same geographical region so fingerprint match can fail
        $dns = '1.1.1.1';
        $context = stream_context_create( array('ssl' => array(
            'verify_peer_name' => false,
            'capture_peer_cert' => true
        )));
        $result = stream_socket_client("ssl://$dns:853", $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $context);
        $cont = stream_context_get_params($result);
        $certificatePem = $cont['options']['ssl']['peer_certificate'];
        $fingerprint = openssl_x509_fingerprint($certificatePem, 'sha1');

        $executor = new TlsTransportExecutor('tls://1.1.1.1?ssl[peer_fingerprint]=' . $fingerprint, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $exception = null;
        $executor->query($query)->then(
            null,
            function ($e) use (&$exception) {
                $exception = $e;
            }
        );

        $obj = new \ReflectionObject($executor);
        $ref = $obj->getParentClass()->getProperty('writePending');
        $ref->setAccessible(true);
        while($ref->getValue($executor)) {
            //Call handleWritable as many times as required to perform the TLS handshake
            $executor->handleWritable();
        }
        $this->assertNull($exception);

        $ref = new \ReflectionProperty($executor, 'cryptoEnabled');
        $ref->setAccessible(true);
        $this->assertTrue($ref->getValue($executor), 'Crypto was not enabled');
    }

    public function testCryptoFailureWithPeerFingerprintMismatch()
    {
        if (defined('HHVM_VERSION') || \PHP_VERSION_ID < 50600) {
            $this->markTestSkipped('DNS over TLS not supported on legacy PHP');
        }

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $invalid_fingerprint = sha1('invalid');
        $executor = new TlsTransportExecutor('tls://8.8.8.8?ssl[peer_fingerprint]=' . $invalid_fingerprint, $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $exception = null;
        $executor->query($query)->then(
            null,
            function ($e) use (&$exception) {
                $exception = $e;
            }
        );

        $obj = new \ReflectionObject($executor);
        $ref = $obj->getParentClass()->getProperty('writePending');
        $ref->setAccessible(true);
        while($ref->getValue($executor)) {
            //Call handleWritable as many times as required to perform the TLS handshake
            $executor->handleWritable();
        }

        $ref = new \ReflectionProperty($executor, 'cryptoEnabled');
        $ref->setAccessible(true);
        $this->assertFalse($ref->getValue($executor));

        /** @var \RuntimeException $exception */
        $this->assertInstanceOf('RuntimeException', $exception);
        $this->assertEquals('DNS query for google.com (A) failed: peer_fingerprint match failure', $exception->getMessage());
    }

    public function testCryptoEnabledWithPeerNameVerified()
    {
        if (defined('HHVM_VERSION') || \PHP_VERSION_ID < 50600) {
            $this->markTestSkipped('DNS over TLS not supported on legacy PHP');
        }

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $executor = new TlsTransportExecutor('tls://8.8.8.8?ssl[peer_name]=dns.google', $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $executor->query($query);

        $obj = new \ReflectionObject($executor);
        $ref = $obj->getParentClass()->getProperty('writePending');
        $ref->setAccessible(true);
        while($ref->getValue($executor)) {
            //Call handleWritable as many times as required to perform the TLS handshake
            $executor->handleWritable();
        }

        $ref = new \ReflectionProperty($executor, 'cryptoEnabled');
        $ref->setAccessible(true);
        $this->assertTrue($ref->getValue($executor));
    }

    public function testCryptoFailureWithPeerNameVerified()
    {
        if (defined('HHVM_VERSION') || \PHP_VERSION_ID < 50600) {
            $this->markTestSkipped('DNS over TLS not supported on legacy PHP');
        }

        $loop = $this->getMockBuilder('React\EventLoop\LoopInterface')->getMock();

        $executor = new TlsTransportExecutor('tls://8.8.8.8?ssl[peer_name]=notgoogle', $loop);

        $query = new Query('google.com', Message::TYPE_A, Message::CLASS_IN);

        $executor->query($query)->then(
            null,
            function ($e) use (&$exception) {
                $exception = $e;
            }
        );

        $obj = new \ReflectionObject($executor);
        $ref = $obj->getParentClass()->getProperty('writePending');
        $ref->setAccessible(true);
        while($ref->getValue($executor)) {
            //Call handleWritable as many times as required to perform the TLS handshake
            $executor->handleWritable();
        }

        $ref = new \ReflectionProperty($executor, 'cryptoEnabled');
        $ref->setAccessible(true);
        $this->assertFalse($ref->getValue($executor));

        /** @var \RuntimeException $exception */
        $this->assertInstanceOf('RuntimeException', $exception);
        $this->assertEquals('DNS query for google.com (A) failed: Peer certificate CN=`dns.google\' did not match expected CN=`notgoogle\'', $exception->getMessage());
    }
}
