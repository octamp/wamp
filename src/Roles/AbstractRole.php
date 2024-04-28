<?php

namespace Octamp\Wamp\Roles;

use Octamp\Wamp\Adapter\AdapterInterface;
use Octamp\Wamp\Session\Session;
use Thruway\Message\Message;

abstract class AbstractRole implements RoleInterface
{
    public function __construct(protected AdapterInterface $adapter)
    {
    }

    public function handle(Session $session, Message $message): void
    {
        $eventName = (new \ReflectionClass($message))->getShortName();
        $handlerName = 'on' . $eventName;
        if (method_exists($this, $handlerName)) {
            call_user_func([$this, $handlerName], $session, $message);
        }
    }
}