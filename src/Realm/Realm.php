<?php

declare(strict_types=1);

namespace Octamp\Wamp\Realm;

use Octamp\Server\Connection\Connection;
use Octamp\Wamp\Auth\AuthenticationDetails;
use Octamp\Wamp\Auth\AuthManager;
use Octamp\Wamp\Event\EventInterface;
use Octamp\Wamp\Event\JoinRealmEvent;
use Octamp\Wamp\Event\LeaveRealmEvent;
use Octamp\Wamp\Peers\Router;
use Octamp\Wamp\Session\Session;
use Octamp\Wamp\Session\SessionStorage;
use OpenSwoole\Coroutine;
use Thruway\Common\Utils;
use Thruway\Message\AuthenticateMessage;
use Thruway\Message\HelloMessage;
use Thruway\Message\Message;
use Thruway\Message\PublishMessage;

class Realm
{
    private ?Session $metaSession = null;

    private ?Connection $connection = null;

    public function __construct(public readonly string $name, protected SessionStorage $sessionStorage, protected Router $router, protected AuthManager $authManager)
    {
    }

    public function addSession(Session $session): void
    {
        $session->setRealm($this);
        $this->sessionStorage->saveSession($session);
    }

    public function handle(Session $session, Message|EventInterface $message): void
    {
        $eventName = (new \ReflectionClass($message))->getShortName();
        $handlerName = 'on' . $eventName;
        if (method_exists($this, $handlerName)) {
            call_user_func([$this, $handlerName], $session, $message);
        }

        $this->router->handle($session, $message);
    }

    public function onHelloMessage(Session $session, HelloMessage $message): void
    {
        if ($session->isAuthenticated()) {
            // TODO log
            return;
        }
        Coroutine::create(function () use ($session, $message) {
            $this->authManager->processHelloMessage($session, $message);
            $this->sessionStorage->saveSession($session);
        });

    }

    public function onAuthenticateMessage(Session $session, AuthenticateMessage $message): void
    {
        if ($session->isAuthenticated()) {
            // TODO log
            return;
        }

        $this->authManager->processAuthenticateMessage($session, $message);
        $this->sessionStorage->saveSession($session);
    }

    public function onLeaveRealmEvent(Session $session, LeaveRealmEvent $event): void
    {
        $this->sessionStorage->removeSession($session);
    }

    public function getMetaSession(): Session
    {
        if ($this->metaSession === null) {
            $this->metaSession = $this->sessionStorage->createDummy($this->connection);
            $authenticationDetails = AuthenticationDetails::createAnonymous();
            $authenticationDetails->setAuthId('internal');
            $authenticationDetails->setAuthRoles(['internal']);
            $authenticationDetails->setAuthMethod('anonymous');
            $this->metaSession->setAuthenticationDetails($authenticationDetails);
            $this->metaSession->setTrusted(true);
            $this->metaSession->setAuthenticated(true);

            $this->addSession($this->metaSession);
        }

        return $this->metaSession;
    }

    public function setConnection(Connection $connection): void
    {
        $this->connection = $connection;
    }

    public function getRealmName(): string
    {
        return $this->name;
    }
}
