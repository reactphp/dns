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
     * - CNAME / PTR:
     *   The hostname without trailing dot, for example "reactphp.org".
     * - Any other unknown type:
     *   An opaque binary string containing the RDATA as transported in the DNS
     *   record. For forwards compatibility, you should not rely on this format
     *   for unknown types. Future versions may add support for new types and
     *   this may then parse the payload data appropriately - this will not be
     *   considered a BC break. See the format definition of known types above
     *   for more details.
     *
     * @var string
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
