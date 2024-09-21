<?php

declare(strict_types=1);

namespace Octamp\Wamp\Auth;

use Octamp\Wamp\Auth\Response\AuthErrorResponse;
use Octamp\Wamp\Auth\Response\AuthSuccessResponse;
use Octamp\Wamp\Auth\Response\HelloErrorResponse;
use Octamp\Wamp\Auth\Response\HelloSuccessResponse;
use Octamp\Wamp\Promise\PromiseInterface;
use Octamp\Wamp\Session\Session;
use Thruway\Message\AuthenticateMessage;
use Thruway\Message\HelloMessage;

interface AuthenticatorInterface
{
    public function __construct(array $config, array $endpoint);

    public function processHello(Session $session, HelloMessage $message): HelloSuccessResponse|HelloErrorResponse;

    /**
     * @param Session $session
     * @param AuthenticateMessage $message
     * @return array
     */
    public function processAuthenticate(Session $session, AuthenticateMessage $message): AuthSuccessResponse|AuthErrorResponse;

    public function canAuthenticate(Session $session, HelloMessage $message, array $methods): bool;

    public function getRealms(): array;

    public function supportRealm(string $realmName): bool;

    public function getMethod(): string;
}
