<?php

namespace Octamp\Wamp\Transport;

use Octamp\Server\Adapter\AdapterInterface;
use Octamp\Server\Adapter\RedisAdapter;
use Octamp\Server\Connection\Connection;
use Octamp\Server\Server;
use Octamp\Wamp\Config\TransportProviderConfig;
use Octamp\Wamp\Event\ConnectionOpenEvent;
use Octamp\Wamp\Peers\Router;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
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
            'open_websocket_close_frame' => true,
            'open_websocket_ping_frame' => true,
            'open_websocket_pong_frame' => true,
//            "enable_reuse_port" => true,
        ]);
        $this->server = new Server($this->websocketServer);
        $this->websocketServer->on('handshake', function (Request $request, Response $response)
        {
            $secWebSocketKey = $request->header['sec-websocket-key'];
            $patten = '#^[+/0-9A-Za-z]{21}[AQgw]==$#';

            // At this stage if the socket request does not meet custom requirements, you can ->end() it here and return false...

            // Websocket handshake connection algorithm verification
            if (0 === preg_match($patten, $secWebSocketKey) || 16 !== strlen(base64_decode($secWebSocketKey)))
            {
                $response->end();
                return false;
            }

            $key = base64_encode(sha1($request->header['sec-websocket-key'] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

            $headers = [
                'Upgrade' => 'websocket',
                'Connection' => 'Upgrade',
                'Sec-WebSocket-Accept' => $key,
                'Sec-WebSocket-Version' => '13',
            ];

            foreach($headers as $key => $val)
            {
                $response->header($key, $val);
            }

            $this->server->dispatch('handshake', $request, $response);

            $response->status(101);
            $response->end();

            $this->getWebsocketServer()->defer(function () use ($request) {
                call_user_func($this->getWebsocketServer()->getCallback('Open'), $this->getWebsocketServer(), $request);
            });

            return true;
        });
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