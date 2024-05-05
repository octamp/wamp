<?php

namespace Octamp\Wamp\Roles;

use Octamp\Wamp\Adapter\AdapterInterface;
use Octamp\Wamp\Event\EventInterface;
use Octamp\Wamp\Session\Session;
use Octamp\Wamp\Session\SessionStorage;
use Thruway\Message\Message;

abstract class AbstractRole implements RoleInterface
{
    public function __construct(protected AdapterInterface $adapter, protected SessionStorage $sessionStorage)
    {
    }

    public function handle(Session $session, Message|EventInterface $message): void
    {
        $eventName = (new \ReflectionClass($message))->getShortName();
        $handlerName = 'on' . $eventName;
        if (method_exists($this, $handlerName)) {
            call_user_func([$this, $handlerName], $session, $message);
        }
    }
}