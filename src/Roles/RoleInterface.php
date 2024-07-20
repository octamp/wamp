<?php

declare(strict_types=1);

namespace Octamp\Wamp\Roles;

use Octamp\Wamp\Session\Session;
use Thruway\Message\Message;

interface RoleInterface
{
    public function handle(Session $session, Message $message): void;

    public function getName(): string;

    public function getFeatures(): object;
}
