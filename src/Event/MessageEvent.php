<?php

namespace Octamp\Wamp\Event;

use Thruway\Message\Message;

class MessageEvent implements OptionEventInterface
{
    public function __construct(public readonly Message $message, protected object $options = new \stdClass())
    {

    }

    public function setOptions(object $option): void
    {
        $this->options = $option;
    }

    public function setOption(string $field, object|array|bool|int|string|null $value): void
    {
        $this->options->{$field} = $value;
    }

    public function getOptions(): object
    {
        return $this->options;
    }

    public function getOption(string $field, bool|string|int|array|object|null $default = null): bool|string|int|array|object|null
    {
        return $this->options->{$field} ?? $default;
    }
}