<?php

declare(strict_types=1);

namespace Octamp\Wamp\Auth\Anonymous;

use Octamp\Wamp\Auth\AbstractAuthenticator;
use Octamp\Wamp\Auth\Response\AuthErrorResponse;
use Octamp\Wamp\Auth\Response\AuthSuccessResponse;
use Octamp\Wamp\Auth\Response\HelloErrorResponse;
use Octamp\Wamp\Auth\Response\HelloSuccessResponse;
use Octamp\Wamp\Session\Session;
use Thruway\Message\AuthenticateMessage;
use Thruway\Message\HelloMessage;

class AnonymousStaticAuthenticator extends AbstractAuthenticator
{
    public function processHello(Session $session, HelloMessage $message): HelloSuccessResponse|HelloErrorResponse
    {
        return $this->generateNoChallengeResponse([
            'authid' => $this->config['authid'] ?? 'anonymous',
            'authrole' => $this->config['role'] ?? 'anonymous',
        ]);
    }

    public function getMethod(): string
    {
        return 'anonymous';
    }

    public function processAuthenticate(Session $session, AuthenticateMessage $message): AuthSuccessResponse|AuthErrorResponse
    {
        return $this->generateSuccessResponse([]);
    }
}
