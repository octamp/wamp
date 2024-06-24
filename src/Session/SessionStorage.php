<?php

namespace Octamp\Wamp\Session;

use Octamp\Server\Connection\ConnectionStorage;
use Octamp\Wamp\Helper\SerializerHelper;
use Octamp\Wamp\Realm\RealmManager;
use Octamp\Wamp\Serializer\JsonSerializer;
use Octamp\Wamp\Session\Adapter\AdapterInterface;
use Octamp\Wamp\Transport\AbstractTransport;
use Octamp\Wamp\Transport\DummyTransport;
use Octamp\Wamp\Transport\OctampTransport;

class SessionStorage
{
    /**
     * @var Session[]
     */
    private array $transportSessions = [];

    public function __construct(
        protected AdapterInterface $adapter,
        protected ConnectionStorage $connectionStorage,
        protected RealmManager $realmManager,
        protected string $serverId
    ) {
    }

    public function createSession(AbstractTransport $transport, ?string $serverId = null): Session
    {
        $session = new Session($transport, $serverId ?? $this->serverId, $this->adapter);

        $id = $this->adapter->generateId();
        $session->setId($id);

        return $session;
    }

    public function createDummy(): Session
    {
        return new Session(new DummyTransport(), $this->serverId, $this->adapter);
    }

    public function createFromArray(array $data): ?Session
    {
        $id = $data['id'];
        $transportId = $data['transportId'];
        $serverId = $data['serverId'];

        $session = $this->getSessionUsingTransportId($transportId, false);
        if ($session !== null) {
            return $session;
        }

        $connection = $this->connectionStorage->get($transportId);
        if ($connection === null) {
            return null;
        }

        $transportClass = $data['transportClass'];
        $serializerClass = $data['serializerClass'] ?? null;
        $websocketProtocol = $data['websocketProtocol'] ?? null;
        /** @var OctampTransport $transport */
        $transport = new $transportClass($connection);

        if ($websocketProtocol !== null) {
            $serializer = SerializerHelper::getSerializer($websocketProtocol);
            if ($serializer !== null) {
                $transport->setSerializer($serializer);
            }
        }

        if ($transport->getSerializer() === null) {
            if ($serializerClass === null) {
                $transport->setSerializer(new JsonSerializer());
            } else {
                $transport->setSerializer(new $serializerClass());
            }
        }

        $realm = $this->realmManager->getRealm($data['realm']);

        $session = new Session($transport, $serverId ?? $this->serverId, $this->adapter);
        $session->setId($id);
        $session->setAuthenticated($data['authenticated']);
        $session->setRealm($realm);
        $session->setTrusted($data['trusted']);

        return $session;
    }

    public function saveSession(Session $session): void
    {
        if ($this->serverId !== $session->getServerId() && !($session->getTransport() instanceof DummyTransport)) {
            return;
        }
        $this->transportSessions[$session->getTransportId()] = $session;
        $this->adapter->saveSession($session);
    }

    public function setAdapter(AdapterInterface $adapter): void
    {
        $this->adapter = $adapter;
    }

    public function getSessionUsingTransportId(string $transportId, bool $global = true): ?Session
    {
        if (isset($this->transportSessions[$transportId])) {
            return $this->transportSessions[$transportId];
        }

        if (!$global) {
            return null;
        }

        $result = $this->adapter->get('*:' . base64_encode($transportId));
        if ($result === null) {
            return null;
        }

        return $this->createFromArray($result);
    }

    public function inLocal(string $transportId): bool
    {
        return isset($this->transportSessions[$transportId]);
    }

    public function removeSession(Session $session): void
    {
        if ($this->inLocal($session->getTransportId())) {
            unset($this->transportSessions[$session->getTransportId()]);
        }
        $this->adapter->remove($session);
    }


    public function loopSession(): \Generator
    {
        foreach ($this->transportSessions as $transportSession) {
            yield $transportSession;
        }
    }
}