<?php

namespace React\Dns\Model;

class Record
{
    /**
     * @var string hostname without trailing dot, for example "reactphp.org"
     */
    public $name;

    /**
     * @var int see Message::TYPE_* constants (UINT16)
     */
    public $type;

    /**
     * @var int see Message::CLASS_IN constant (UINT16)
     */
    public $class;

    /**
     * @var int maximum TTL in seconds (UINT16)
     */
    public $ttl;

    /**
     * The payload data for this record
     *
     * The payload data format depends on the record type. As a rule of thumb,
     * this library will try to express this in a way that can be consumed
     * easily without having to worry about DNS internals and its binary transport:
     *
     * - A:
     *   IPv4 address string, for example "192.168.1.1".
     * - AAAA:
     *   IPv6 address string, for example "::1".
     * - CNAME / PTR / NS:
     *   The hostname without trailing dot, for example "reactphp.org".
     * - TXT:
     *   List of string values, for example `["v=spf1 include:example.com"]`.
     *   This is commonly a list with only a single string value, but this
     *   technically allows multiple strings (0-255 bytes each) in a single
     *   record. This is rarely used and depending on application you may want
     *   to join these together or handle them separately. Each string can
     *   transport any binary data, its character encoding is not defined (often
     *   ASCII/UTF-8 in practice). [RFC 1464](https://tools.ietf.org/html/rfc1464)
     *   suggests using key-value pairs such as `["name=test","version=1"]`, but
     *   interpretation of this is not enforced and left up to consumers of this
     *   library (used for DNS-SD/Zeroconf and others).
     * - Any other unknown type:
     *   An opaque binary string containing the RDATA as transported in the DNS
     *   record. For forwards compatibility, you should not rely on this format
     *   for unknown types. Future versions may add support for new types and
     *   this may then parse the payload data appropriately - this will not be
     *   considered a BC break. See the format definition of known types above
     *   for more details.
     *
     * @var string|string[]
     */
    public $data;

    public function __construct($name, $type, $class, $ttl = 0, $data = null)
    {
        $this->name     = $name;
        $this->type     = $type;
        $this->class    = $class;
        $this->ttl      = $ttl;
        $this->data     = $data;
    }
}
