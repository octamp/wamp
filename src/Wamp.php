<?php
declare(strict_types=1);

namespace Octamp\Wamp;

use Octamp\Server\Connection\Connection;
use Octamp\Server\Generator\RedisIDGenerator;
use Octamp\Server\Server;
use Octamp\Wamp\Adapter\AdapterInterface;
use Octamp\Wamp\Config\TransportProviderConfig;
use Octamp\Wamp\Helper\IDHelper;
use Octamp\Wamp\Helper\SerializerHelper;
use Octamp\Wamp\Peers\Router;
use Octamp\Wamp\Realm\RealmManager;
use Octamp\Wamp\Roles\Broker;
use Octamp\Wamp\Roles\Dealer;
use Octamp\Wamp\Serializer\JsonSerializer;
use Octamp\Wamp\Serializer\MessagePackSerializer;
use Octamp\Wamp\Serializer\WampMessageSerializerInterface;
use Octamp\Wamp\Session\SessionStorage;
use Octamp\Wamp\Transport\OctampTransport;
use Octamp\Wamp\Transport\OctampTransportProvider;
use Octamp\Wamp\Transport\TransportProviderInterface;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use OpenSwoole\WebSocket\Frame;

class Wamp
{
    private RealmManager $realmManager;

    /**
     * @var TransportProviderInterface[]
     */
    private array $transportProviders = [];

    protected string $serverId;

    public function __construct(private readonly TransportProviderConfig $config, private readonly AdapterInterface $adapter)
    {
        $this->realmManager = new RealmManager();
        $this->serverId = uniqid('');
        $this->init();
    }

    public function init(): void
    {
        $transportProvider = new OctampTransportProvider($this->config);
        $transportProvider->setAdapter($this->adapter);
        $this->transportProviders[] = $transportProvider;

        $transportProvider->getServer()->on('beforeStart', function (Server $server) {
            $idGenerator = new RedisIDGenerator($server, $this->adapter);
            $server->setGenerator($idGenerator);
        });


        $transportProvider->getServer()->on('afterStart', function (Server $server) {
            $this->serverId = $server->getServerId();
            $sessionAdapter = new \Octamp\Wamp\Session\Adapter\RedisAdapter($this->adapter);
            IDHelper::setAdapter($this->adapter);
            IDHelper::setSessionAdapter($sessionAdapter);
            $sessionStorage = new SessionStorage($sessionAdapter, $server->getConnectionStorage(), $this->realmManager, $this->serverId);
            $this->realmManager->init($sessionStorage, $this->adapter);

            $router = new Router();
            $router->addRole(new Broker($this->adapter, $sessionStorage, $this->serverId));
            $router->addRole(new Dealer($this->adapter, $sessionStorage, $this->serverId));

            $router->addTransportProviders($this->transportProviders);

            $realm = $this->realmManager->createRealm('realm1', $router);

            $this->realmManager->addRealm($realm);
        });

        $transportProvider->getServer()->on('handshake', function (Server $server, Request $request, Response $response) {
            $protocolString = $request->header['sec-websocket-protocol'] ?? '';
            if ($protocolString === '') {
                return;
            }

            $protocols = array_map(function ($protocol) {
                return trim($protocol);
            }, explode(',', $protocolString));

            $protocol = SerializerHelper::getFirstSupportedProtocols($protocols);
            if ($protocol !== null) {
                $response->header('Sec-Websocket-Protocol', $protocol);
            }
        });

        $transportProvider->getServer()->on('open', function (Server $server, Connection $connection) {
            $transport = new OctampTransport($connection);
            $serializer = $this->getSerializer($connection->getRequest());
            if ($serializer === null) {
                $connection->close();
                return;
            }
            $transport->setSerializer($serializer);
            $session = $this->realmManager->generateSession($transport);
            $this->realmManager->saveSession($session);
        });

        $transportProvider->getServer()->on('close', function (Server $server, Connection $connection) {
            $session = null;
            $retry = 0;
            $maxRetry = 10;
            while ($session === null) {
                if ($retry >= $maxRetry) {
                    break;
                }
                $session = $this->realmManager->getSessionStorage()->getSessionUsingTransportId($connection->getId(), false);
                if ($session === null) {
                    $retry++;
                    sleep(1);
                }
            }

            if ($session === null) {
                return;
            }

            $session->onClose();
        });

        $transportProvider->getServer()->on('message', function (Server $server, Connection $connection, Frame $frame) {
            if ($frame->opcode === \OpenSwoole\WebSocket\Server::WEBSOCKET_OPCODE_PING) {
                $server->pong($connection->getFd());

                return;
            }
            $session = null;
            $retry = 0;
            $maxRetry = 10;
            while ($session === null) {
                if ($retry >= $maxRetry) {
                    break;
                }
                $session = $this->realmManager->getSessionStorage()->getSessionUsingTransportId($connection->getId(), false);
                if ($session === null) {
                    $retry++;
                    sleep(1);
                }
            }

            if ($session === null) {
                return;
            }

            if ($frame->opcode === \OpenSwoole\WebSocket\Server::WEBSOCKET_OPCODE_PONG) {
                $session->getTransport()->onPong($frame);
            } elseif ($frame->opcode === \OpenSwoole\WebSocket\Server::WEBSOCKET_OPCODE_TEXT || $frame->opcode === \OpenSwoole\WebSocket\Server::WEBSOCKET_OPCODE_BINARY) {
                $message = $session->getTransport()->getSerializer()->deserialize($frame->data);
                $this->realmManager->dispatch($session, $message);
            }
        });
    }

    public function run(): void
    {
        $this->transportProviders[0]->start();
    }

    protected function getSerializer(Request $request): ?WampMessageSerializerInterface
    {
        $protocolString = $request->header['sec-websocket-protocol'] ?? '';
        if ($protocolString === '') {
            return null;
        }

        $protocols = array_map(function ($protocol) {
            return trim($protocol);
        }, explode(',', $protocolString));

        $protocolName = SerializerHelper::getFirstSupportedProtocols($protocols);
        if ($protocolName === null) {
            return null;
        }

        return SerializerHelper::getSerializer($protocolName);
    }
}