<?php

declare(strict_types=1);

namespace Octamp\Wamp\Auth;

use Octamp\Wamp\Auth\Response\AuthErrorResponse;
use Octamp\Wamp\Auth\Response\AuthSuccessResponse;
use Octamp\Wamp\Auth\Response\HelloErrorResponse;
use Octamp\Wamp\Auth\Response\HelloSuccessResponse;
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

    protected function generateChallengeResponse(
        \stdClass|array|null $authDetails = null,
         \stdClass|array|null $verifyDetails = null,
         \stdClass|array|null $challengeDetails = null
    ): HelloSuccessResponse {
        return new HelloSuccessResponse(AuthManager::STATUS_CHALLENGE, $this->getMethod(), $authDetails, $verifyDetails, $challengeDetails);
    }

    protected function generateNoChallengeResponse(
        \stdClass|array|null $authDetails = null,
        \stdClass|array|null $verifyDetails = null,
        \stdClass|array|null $challengeDetails = null
    ): HelloSuccessResponse {
        return new HelloSuccessResponse(AuthManager::STATUS_NO_CHALLENGE, $this->getMethod(), $authDetails, $verifyDetails, $challengeDetails);
    }

    protected function generateFailureResponse(
        ?string $errorUri = null,
        \stdClass|array|null $errorDetails = null
    ): HelloErrorResponse {
        return new HelloErrorResponse(AuthManager::STATUS_FAILURE, $this->getMethod(), $errorUri, $errorDetails);
    }

    protected function generateSuccessResponse(\stdClass|array|null $authDetails = null): AuthSuccessResponse
    {
        return new AuthSuccessResponse(AuthManager::STATUS_SUCCESS, $this->getMethod(), $authDetails);
    }

    protected function generatedErrorResponse(
        ?string $errorUri = null,
        \stdClass|array|null $errorDetails = null
    ): AuthErrorResponse {
        return new AuthErrorResponse(AuthManager::STATUS_FAILURE, $this->getMethod(), $errorUri, $errorDetails);
    }
}
