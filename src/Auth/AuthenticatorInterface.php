<?php

namespace Octamp\Wamp\Auth;

use Octamp\Wamp\Session\Session;
use Thruway\Message\AuthenticateMessage;
use Thruway\Message\HelloMessage;

interface AuthenticatorInterface
{
    public function __construct(array $config);

    public function processHello(Session $session, HelloMessage $message): array;

    /**
     * @param Session $session
     * @param AuthenticateMessage $message
     * @return array
     */
    public function processAuthenticate(Session $session, AuthenticateMessage $message): array;

    public function canAuthenticate(Session $session, HelloMessage $message, array $methods): bool;

    public function getRealms(): array;

    public function supportRealm(string $realmName): bool;

    public function getMethod(): string;
}