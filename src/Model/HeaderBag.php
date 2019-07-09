<?php

namespace React\Dns\Model;

class HeaderBag
{
    public $attributes = array(
        'qr'        => 0,
        'opcode'    => Message::OPCODE_QUERY,
        'aa'        => 0,
        'tc'        => 0,
        'rd'        => 0,
        'ra'        => 0,
        'z'         => 0,
        'rcode'     => Message::RCODE_OK,
    );

    public function get($name)
    {
        return isset($this->attributes[$name]) ? $this->attributes[$name] : null;
    }

    public function set($name, $value)
    {
        $this->attributes[$name] = $value;
    }

    public function isQuery()
    {
        return 0 === $this->attributes['qr'];
    }

    public function isResponse()
    {
        return 1 === $this->attributes['qr'];
    }

    public function isTruncated()
    {
        return 1 === $this->attributes['tc'];
    }
}
