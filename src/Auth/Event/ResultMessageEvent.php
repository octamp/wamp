<?php

namespace Octamp\Wamp\Auth\Event;

use Symfony\Component\EventDispatcher\Event;
use Thruway\Message\ResultMessage;

class ResultMessageEvent extends Event
{
    public function __construct(public readonly ResultMessage $message)
    {

    }
}