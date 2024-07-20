<?php

declare(strict_types=1);

namespace Octamp\Wamp\Transport;

use Octamp\Wamp\Peers\Router;

interface TransportProviderInterface
{
    public function start(): void;
}
