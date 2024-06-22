<?php

namespace Octamp\Wamp\Transport;

use Octamp\Server\Adapter\AdapterInterface;
use Octamp\Server\Adapter\RedisAdapter;
use Octamp\Server\Connection\Connection;
use Octamp\Server\Server;
use Octamp\Wamp\Config\TransportProviderConfig;
use Octamp\Wamp\Event\ConnectionOpenEvent;
use Octamp\Wamp\Peers\Router;
use OpenSwoole\WebSocket\Frame;
use OpenSwoole\WebSocket\Server as WebsocketServer;

class OctampTransportProvider implements TransportProviderInterface
{
    private Server $server;

    private WebsocketServer $websocketServer;

    private array $routers = [];

    public function __construct(private readonly TransportProviderConfig $config)
    {
        $this->websocketServer = Server::createWebsocketServer($this->config->host, $this->config->port, [
            'worker_num' => $this->config->workerNum,
            'websocket_subprotocol' => 'wamp.2.json',
            'open_websocket_close_frame' => true,
            'open_websocket_ping_frame' => true,
            'open_websocket_pong_frame' => true,
//            "enable_reuse_port" => true,
        ]);
        $this->server = new Server($this->websocketServer);
    }

    public function start(): void
    {
        $this->server->start();
    }

    public function setAdapter(AdapterInterface $adapter): void
    {
        $this->server->setAdapter($adapter);
    }

    public function getServer(): Server
    {
        return $this->server;
    }

    public function getWebsocketServer(): WebsocketServer
    {
        return $this->websocketServer;
    }
}