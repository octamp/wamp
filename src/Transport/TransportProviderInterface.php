<?php

namespace Octamp\Wamp\Transport;

use Octamp\Wamp\Peers\Router;

interface TransportProviderInterface
{
    public function start(): void;
}