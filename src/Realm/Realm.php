<?php

namespace Octamp\Wamp\Realm;

use Octamp\Wamp\Event\EventInterface;
use Octamp\Wamp\Event\LeaveRealmEvent;
use Octamp\Wamp\Peers\Router;
use Octamp\Wamp\Session\Session;
use Octamp\Wamp\Session\SessionStorage;
use Octamp\Wamp\Transport\DummyTransport;
use Thruway\Authentication\AuthenticationDetails;
use Thruway\Common\Utils;
use Thruway\Message\HelloMessage;
use Thruway\Message\Message;
use Thruway\Message\PublishMessage;
use Thruway\Message\WelcomeMessage;

class Realm
{
    private ?Session $metaSession = null;

    public function __construct(public readonly string $name, protected SessionStorage $sessionStorage, protected Router $router)
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
            return;
        }
        $session->setAuthenticationDetails(AuthenticationDetails::createAnonymous());
        $session->setAuthenticated(true);

        $welcome = new WelcomeMessage($session->getId(), $message->getDetails());
        $session->sendMessage($welcome);
    }

    public function onLeaveRealmEvent(Session $session, LeaveRealmEvent $event): void
    {
        if ($session->isAuthenticated()) {
            $this->publishMeta('wamp.session.on_leave', [$session->getMetaInfo()]);
        }

        $this->sessionStorage->removeSession($session);
    }

    public function publishMeta(string $topicName, array $arguments, ?object $argumentsKw = null, ?object $options = null): void
    {
        if ($this->metaSession === null) {
            $this->metaSession = $this->sessionStorage->createDummy();
        }

        $this->handle($this->metaSession, new PublishMessage(
            Utils::getUniqueId(),
            $options,
            $topicName,
            $arguments,
            $argumentsKw
        ));
    }
}