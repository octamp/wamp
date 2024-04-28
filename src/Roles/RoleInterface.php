<?php

namespace Octamp\Wamp\Roles;

use Octamp\Wamp\Session\Session;
use Thruway\Message\Message;

interface RoleInterface
{
    public function handle(Session $session, Message $message): void;
}