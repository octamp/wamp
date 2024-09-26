<?php

declare(strict_types=1);

namespace Octamp\Wamp\Session;

use Octamp\Server\Connection\Connection;
use Octamp\Server\Connection\ConnectionStorage;
use Octamp\Wamp\Auth\AuthenticationDetails;
use Octamp\Wamp\Helper\SerializerHelper;
use Octamp\Wamp\Realm\RealmManager;
use Octamp\Wamp\Serializer\JsonSerializer;
use Octamp\Wamp\Session\Adapter\AdapterInterface;
use Octamp\Wamp\Transport\AbstractTransport;
use Octamp\Wamp\Transport\DummyTransport;
use Octamp\Wamp\Transport\OctampTransport;
use Thruway\Message\HelloMessage;

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

    public function createSessionMeta(AbstractTransport $transport, ?string $serverId = null): SessionMeta
    {
        $session = new SessionMeta($transport, $serverId ?? $this->serverId, $this->adapter);

        $id = $this->adapter->generateId();
        $session->setId($id);

        return $session;
    }

    public function createDummy(Connection $connection): Session
    {
        $transport = new OctampTransport($connection);
        $transport->setSerializer(new JsonSerializer());

        return $this->createSession($transport, $this->serverId);
    }

    public function createDummySessionMeta(Connection $connection): SessionMeta
    {
        $transport = new OctampTransport($connection);
        $transport->setSerializer(new JsonSerializer());

        return $this->createSessionMeta($transport, $this->serverId);
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
        $session->setAuthenticated((bool) $data['authenticated']);
        $session->setRealm($realm);
        $session->setTrusted((bool) $data['trusted']);

        if(isset($data['helloMessage']) && $data['helloMessage']) {
            $helloMessage = HelloMessage::createMessageFromArray($data['helloMessage']);
            if ($helloMessage instanceof HelloMessage) {
                $session->setHelloMessage($helloMessage);
            }
        }

        if (isset($data['authDetails']) && $data['authDetails']) {
            $session->setAuthenticationDetails(AuthenticationDetails::createFromArray($data['authDetails']));
        }

        return $session;
    }

    public function saveSession(Session $session): void
    {
        if ($this->serverId !== $session->getServerId() && !($session->getTransport() instanceof DummyTransport)) {
            return;
        }
        $this->transportSessions[$session->getTransportId()] = $session;
        $this->adapter->saveSession($session);
        $this->savePrincipal($session);
    }


    public function savePrincipal(Session $session): void
    {
        if ($session->isAuthenticated() && $session->getAuthenticationDetails() !== null) {
            $this->adapter->savePrincipal($session);
        }
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

    public function getTotalSession(string $realm): int
    {
        $sessions = $this->adapter->count(['authenticated' => true, 'realm' => $realm]) ?? 0;

        return max($sessions, 0);
    }

    public function getAllSessionIDs(string $realm, array $authRoles = []): array
    {
        return $this->adapter->findReturnKey(['authenticated' => true, 'realm' => $realm, 'authRole' => $authRoles]);
    }

    public function getSession(string $realm, string $id): ?Session
    {
        $record = $this->adapter->getSession($realm, $id);
        if ($record === null) {
            return null;
        }

        return $this->createFromArray((array)$record);
    }

    /**
     * @return Session[]
     */
    public function findSessionsByAuthId(string $realm, string $authId): array
    {
        return $this->findSessions($realm, ['authId' => $authId]);
    }

    /**
     * @return Session[]
     */
    public function findSessionsByAuthRole(string $realm, string $authRole): array
    {
        return $this->findSessions($realm, ['authRole' => $authRole]);
    }

    /**
     * @return Session[]
     */
    public function findSessions(string $realm, array $conditions = []): array
    {
        $result = $this->adapter->find(array_merge(['realm' => $realm], $conditions));

        return array_map(function (array $item) {
            return $this->createFromArray($item);
        }, $result);
    }
}
