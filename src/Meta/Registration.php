<?php

namespace Octamp\Wamp\Meta;

readonly class Registration
{
    public function __construct(public int $registrationId, public string $procedure, public mixed $callback, public object $options = new \stdClass())
    {

    }
}