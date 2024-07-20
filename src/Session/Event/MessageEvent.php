<?php

declare(strict_types=1);

namespace Octamp\Wamp\Session\Event;

use Octamp\Wamp\Session\Session;
use Symfony\Component\EventDispatcher\Event;
use Thruway\Message\Message;

class MessageEvent extends Event
{
    public function __construct(public readonly Session $session, public readonly Message $message)
    {

    }
}
