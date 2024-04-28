<?php

namespace Octamp\Wamp\Event;

use Octamp\Wamp\Session\Session;

class ConnectionOpenEvent implements EventInterface
{
    public function __construct(public readonly Session $session)
    {
    }
}