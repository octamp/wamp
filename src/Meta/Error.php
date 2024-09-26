<?php

namespace Octamp\Wamp\Meta;

readonly class Error
{
    public function __construct(public string $uri, public array $args = [], public object $kwargs = new \stdClass())
    {

    }
}