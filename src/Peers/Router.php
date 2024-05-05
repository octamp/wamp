<?php

namespace Octamp\Wamp\Peers;

use Octamp\Wamp\Event\EventInterface;
use Octamp\Wamp\Roles\RoleInterface;
use Octamp\Wamp\Session\Session;
use Octamp\Wamp\Transport\TransportProviderInterface;
use Thruway\Event\MessageEvent;
use Thruway\Logging\Logger;
use Thruway\Message\GoodbyeMessage;
use Thruway\Message\Message;

class Router
{
    /**
     * @var RoleInterface[]
     */
    protected array $roles = [];

    /**
     * @var TransportProviderInterface[]
     */
    protected array $transportProviders = [];

    public function __construct()
    {
    }

    public function addTransportProvider(TransportProviderInterface $transportProvider): void
    {
        $this->transportProviders[] = $transportProvider;
    }

    public function addTransportProviders(array $transportProviders): void
    {
        foreach ($transportProviders as $transportProvider) {
            $this->transportProviders[] = $transportProvider;
        }
    }

    public function addRole(RoleInterface $role): void
    {
        $this->roles[] = $role;
    }

    public function start(): void
    {
        foreach ($this->transportProviders as $transportProvider) {
            $transportProvider->start();
        }
    }

    public function handle(Session $session, Message|EventInterface $message): void
    {
        $eventName = (new \ReflectionClass($message))->getShortName();
        $handlerName = 'on' . $eventName;
        if (method_exists($this, $handlerName)) {
            call_user_func([$this, $handlerName], $session, $message);
        }

        foreach ($this->roles as $role) {
            $role->handle($session, $message);
        }
    }

    public function onGoodbyeMessage(Session $session, GoodbyeMessage $message): void
    {
        $goodByeMessage = new GoodbyeMessage(new \stdClass(), 'wamp.close.goodbye_and_out');
        $session->sendMessage($goodByeMessage);
        $session->setGoodByeSent(true);
        $session->shutdown();
    }
}