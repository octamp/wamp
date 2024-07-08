<?php

declare(strict_types=1);

namespace Octamp\Wamp\Auth;

use Octamp\Wamp\Promise\Promise;
use Octamp\Wamp\Promise\PromiseInterface;
use Octamp\Wamp\Session\Session;
use Thruway\Message\AuthenticateMessage;
use Thruway\Message\HelloMessage;

class AnonymousStaticAuthenticator extends AbstractAuthenticator
{
    public function processHello(Session $session, HelloMessage $message): PromiseInterface
    {
        return new Promise(function (callable $resolve) {
            $resolve(['status' => AuthManager::STATUS_NO_CHALLENGE, 'auth_details' => [
                'authid' => $this->config['authid'] ?? 'anonymous',
                'authrole' => $this->config['role'] ?? 'anonymous',
            ]]);
        });
    }

    public function getMethod(): string
    {
        return 'anonymous';
    }

    public function processAuthenticate(Session $session, AuthenticateMessage $message): PromiseInterface
    {
        return new Promise(function (callable $resolve) {
            $resolve(['status' => AuthManager::STATUS_SUCCESS]);
        });
    }
}