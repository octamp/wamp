<?php

namespace Octamp\Wamp\Config;

readonly class ServerConfig
{
    /**
     * @var TransportProviderConfig[]
     */
    public array $transports;

    public function __construct(array $transports, public array $realms, public int $workerNum = 1)
    {
        $this->transports = array_map([$this, 'setTransport'], $transports);
    }

    private function setTransport(array $transport): TransportProviderConfig
    {
        return new TransportProviderConfig(
            $transport['endpoint']['port'],
            $transport['endpoint']['type'],
            $transport['auths'],
        );
    }
}