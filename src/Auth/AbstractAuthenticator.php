<?php

namespace Octamp\Wamp\Auth;

use Octamp\Wamp\Session\Session;
use Thruway\Message\HelloMessage;

abstract class AbstractAuthenticator implements AuthenticatorInterface
{
    protected array $realms;

    public function __construct(protected array $config)
    {
        $this->realms = $this->config['realms'] ?? [];
        $this->init();
    }

    protected function init(): void
    {
        // overwrite this method for custom implementation
    }

    public function getRealms(): array
    {
        return $this->realms;
    }

    public function supportRealm(string $realmName): bool
    {
        return empty($this->realms) || in_array('*', $this->realms) || in_array($realmName, $this->realms);
    }

    public function canAuthenticate(Session $session, HelloMessage $message, array $methods): bool
    {
        return in_array($this->getMethod(), $methods) && $this->supportRealm($session->getRealm()->name);
    }
}