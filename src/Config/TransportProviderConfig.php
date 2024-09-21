<?php

declare(strict_types=1);

namespace Octamp\Wamp\Config;

use OpenSwoole\Constant;

readonly class TransportProviderConfig
{
    public function __construct(
        public int $port,
        public string $type = 'tcp',
        public array $auths = []
    ) {

    }

    public function getSocketType(): int
    {
        return match ($this->type) {
            'tcp' => Constant::SOCK_TCP,
            'udp' => Constant::SOCK_UDP,
        };
    }
}
