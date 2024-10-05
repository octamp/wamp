<?php

namespace Octamp\Wamp\Event;

interface OptionEventInterface extends EventInterface
{
    public function setOptions(object $option): void;

    public function setOption(string $field, bool|string|int|array|object|null $value): void;

    public function getOptions(): object;

    public function getOption(string $field): bool|string|int|array|object|null;
}