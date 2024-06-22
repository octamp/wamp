<?php

namespace Octamp\Wamp\Config;

readonly class TransportProviderConfig
{
    public function __construct(
        public string $host = '0.0.0.0',
        public int    $port = 8080,
        public int    $workerNum = 1
    ) {

    }
}