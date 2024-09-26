<?php

namespace Octamp\Wamp\Meta;

readonly class Result
{
    public function __construct(public array $args = [], public object $kwargs = new \stdClass())
    {

    }
}