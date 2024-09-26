<?php

namespace Octamp\Wamp\Meta;

readonly class Subscription
{
    public function __construct(public int $subscriptionId, public string $topic, public mixed $callback, public object $options = new \stdClass())
    {

    }
}