<?php

declare(strict_types=1);

namespace Octamp\Wamp\Realm;

use Octamp\Server\Connection\Connection;
use Octamp\Wamp\Auth\AuthenticationDetails;
use Octamp\Wamp\Auth\AuthManager;
use Octamp\Wamp\Event\EventInterface;
use Octamp\Wamp\Event\JoinRealmEvent;
use Octamp\Wamp\Event\LeaveRealmEvent;
use Octamp\Wamp\Helper\IDHelper;
use Octamp\Wamp\Helper\UriHelper;
use Octamp\Wamp\Meta\Error;
use Octamp\Wamp\Meta\ErrorException;
use Octamp\Wamp\Meta\Result;
use Octamp\Wamp\Peers\Router;
use Octamp\Wamp\Session\Event\MessageEvent;
use Octamp\Wamp\Session\Session;
use Octamp\Wamp\Session\SessionMeta;
use Octamp\Wamp\Session\SessionStorage;
use OpenSwoole\Coroutine;
use Thruway\Common\Utils;
use Thruway\Message\AuthenticateMessage;
use Thruway\Message\GoodbyeMessage;
use Thruway\Message\HelloMessage;
use Thruway\Message\Message;
use Thruway\Message\PublishMessage;
use Thruway\Message\RegisteredMessage;
use Thruway\Message\RegisterMessage;

class Realm
{
    private ?SessionMeta $metaSession = null;

    private ?Connection $connection = null;

    public function __construct(public readonly string $name, protected SessionStorage $sessionStorage, protected Router $router, protected AuthManager $authManager)
    {
    }

    public function init(): void
    {
        $this->getMetaSession()->register('wamp.session.count', function() {
            return $this->sessionStorage->getTotalSession($this->getRealmName());
        });
        $this->getMetaSession()->register('wamp.session.list', function(Session $session, array $args) {
            return $this->sessionStorage->getAllSessionIDs($this->getRealmName(), $args[0] ?? []);
        });
        $this->getMetaSession()->register('wamp.session.get', function(Session $callerSession, array $args) {
            $session = $this->sessionStorage->getSession($this->getRealmName(), $args[0]);

            if ($session === null) {
                throw new ErrorException(new Error('wamp.error.no_such_session'));
            }

            return (object) [
                'session' => $session->getSessionId(),
                'authid' => $session->getAuthenticationDetails()->getAuthId(),
                'authrole' => $session->getAuthenticationDetails()->getAuthRole(),
                'authmethod' => $session->getAuthenticationDetails()->getAuthMethod(),
                'authprovider' => $session->getAuthenticationDetails()->getAuthProvider(),
            ];
        });
        $this->getMetaSession()->register('wamp.session.kill', function(Session $callerSession, array $args, object $kwargs = new \stdClass()) {
            $session = $this->sessionStorage->getSession($this->getRealmName(), $args[0]);
            if ($session === null || $session->getId() === $callerSession->getId()) {
                throw new ErrorException(new Error('wamp.error.no_such_session'));
            }
            $reason = $kwargs->reason ?? 'wamp.close.killed';
            $message = $kwargs->message ?? 'session killed';
            if ($reason !== 'wamp.close.killed' && !UriHelper::uriIsValidStrict($reason)) {
                throw new ErrorException(new Error('wamp.error.invalid_uri'));
            }
            if (!$session->isGoodByeSent()) {
                $message = new GoodbyeMessage((object)['message' => $message], $reason);
                $session->sendMessage($message);
                $session->setGoodByeSent(true);
            }
            $session->shutdown();

            return new Result();
        });
        $this->getMetaSession()->register('wamp.session.kill_by_authid', function(Session $callerSession, array $args, object $kwargs = new \stdClass()) {
            return $this->killSessions($callerSession, $kwargs, $args[0]);
        });
        $this->getMetaSession()->register('wamp.session.kill_by_authrole', function(Session $callerSession, array $args, object $kwargs = new \stdClass()) {
            return count($this->killSessions($callerSession, $kwargs, null, $args[0]));
        });
        $this->getMetaSession()->register('wamp.session.kill_all', function(Session $callerSession, array $args, object $kwargs = new \stdClass()) {
            return count($this->killSessions($callerSession, $kwargs, null, null, $args[0]));
        });
    }

    protected function killSessions(Session $callerSession, object $kwargs = new \stdClass(), ?string $id = null, ?string $authId = null, ?string $authRole = null): array
    {
        $conditions = [];
        if ($id !== null) {
            $conditions['id'] = $id;
        }
        if ($authId !== null) {
            $conditions['authId'] = $authId;
        }
        if ($authRole !== null) {
            $conditions['authRole'] = $authRole;
        }
        $sessions = $this->sessionStorage->findSessions($this->getRealmName(), $conditions);

        $reason = $kwargs->reason ?? 'wamp.close.killed';
        $message = $kwargs->message ?? 'session killed';
        if ($reason !== 'wamp.close.killed' && !UriHelper::uriIsValidStrict($reason)) {
            throw new ErrorException(new Error('wamp.error.invalid_uri'));
        }

        $sessionIds = [];
        foreach ($sessions as $session) {
            if ($session->getId() === $callerSession->getId()) {
                continue;
            }
            if (!$session->isGoodByeSent()) {
                $message = new GoodbyeMessage((object)['message' => $message], $reason);
                $session->sendMessage($message);
                $session->setGoodByeSent(true);
            }
            $session->shutdown();
            $sessionIds[] = $session->getId();
        }

        return $sessionIds;
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
        $afterHandlerName = 'onAfter' . $eventName;
        if (method_exists($this, $handlerName)) {
            call_user_func([$this, $handlerName], $session, $message);
        }

        $this->router->handle($session, $message);

        if (method_exists($this, $afterHandlerName)) {
            call_user_func([$this, $afterHandlerName], $session, $message);
        }
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

    public function onAfterJoinRealmEvent(Session $session, JoinRealmEvent $event): void
    {
        if (!$event->session->isAuthenticated()) {
            return;
        }
        $this->sessionStorage->saveSession($session);
        $this->getMetaSession()->publish('wamp.session.on_join', [$session->getMetaInfo() ?? new \stdClass()]);
    }

    public function onAfterLeaveRealmEvent(Session $session, LeaveRealmEvent $event): void
    {
        if (!$event->session->isAuthenticated()) {
            return;
        }

        $this->sessionStorage->removeSession($session);

        $this->getMetaSession()->publish('wamp.session.on_leave', [$session->getMetaInfo() ?? new \stdClass()]);
    }

    public function getMetaSession(): SessionMeta
    {
        if ($this->metaSession === null) {
            $this->metaSession = $this->sessionStorage->createDummySessionMeta($this->connection);
            $authenticationDetails = AuthenticationDetails::createAnonymous();
            $authenticationDetails->setAuthId('internal');
            $authenticationDetails->setAuthRoles(['internal']);
            $authenticationDetails->setAuthMethod('anonymous');
            $this->metaSession->setAuthenticationDetails($authenticationDetails);
            $this->metaSession->setTrusted(true);
            $this->metaSession->setAuthenticated(true);
            $this->metaSession->init();

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
