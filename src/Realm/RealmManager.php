<?php

namespace Octamp\Wamp\Realm;

use Octamp\Wamp\Adapter\AdapterInterface;
use Octamp\Wamp\Peers\Router;
use Octamp\Wamp\Session\Session;
use Octamp\Wamp\Session\SessionStorage;
use Octamp\Wamp\Transport\AbstractTransport;
use Thruway\Message\HelloMessage;
use Thruway\Message\Message;

class RealmManager
{
    private array $realms = [];

    protected ?SessionStorage $sessionStorage = null;
    protected ?AdapterInterface $adapter = null;

    public function __construct()
    {
    }

    public function init(SessionStorage $sessionStorage, AdapterInterface $adapter): void
    {
        $this->adapter = $adapter;
        $this->sessionStorage = $sessionStorage;
    }

    public function createRealm(string $name, Router $router): Realm
    {
        return new Realm($name, $this->sessionStorage, $router);
    }

    public function addRealm(Realm $realm): void
    {
        if (isset($this->realms[$realm->name])) {
            return;
        }

        $this->realms[$realm->name] = $realm;
        $added = $this->adapter->addToList('realms', $realm->name);
        if ($added) {
            $this->adapter->publish('realms:added', [$realm->name]);
        }
    }

    public function getRealm(string $name): ?Realm
    {
        return $this->realms[$name] ?? null;
    }

    public function hasRealm(string $name): bool
    {
        return isset($this->realms[$name]);
    }

    public function generateSession(AbstractTransport $transport): Session
    {
        return $this->sessionStorage->createSession($transport);
    }

    public function saveSession(Session $session): void
    {
        $this->sessionStorage->saveSession($session);
    }

    public function getSessionStorage(): SessionStorage
    {
        return $this->sessionStorage;
    }

    public function dispatch(Session $session, Message $message): void
    {
        if ($message instanceof HelloMessage) {
            $this->onHelloMessage($session, $message);
        }

        if ($session->getRealm() === null) {
            $session->abort((object) ['message' => 'the real does not exists'], 'wamp.error.no_such_realm');
            return;
        }

        $realm = $session->getRealm();
        $realm->handle($session, $message);
    }

    public function onHelloMessage(Session $session, HelloMessage $message): void
    {
        $details = $message->getDetails();
        $details->trasport = $session->getTransport()->getTransportDetails();
        $message->setDetails($details);

        $realm = $this->getRealm($message->getRealm());
        if ($realm) {
            $session->setHelloMessage($message);
            $realm->addSession($session);
        }
    }
}