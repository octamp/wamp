<?php

declare(strict_types=1);

namespace Octamp\Wamp\Event;

use Octamp\Wamp\Session\Session;

class LeaveRealmEvent implements EventInterface
{
    public function __construct(public readonly Session $session)
    {
    }
}
