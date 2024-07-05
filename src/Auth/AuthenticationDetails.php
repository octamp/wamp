<?php

namespace Octamp\Wamp\Auth;

class AuthenticationDetails implements \JsonSerializable
{
    private string|int|float|null $authId;

    private ?string $authMethod;

    private mixed $challenge;

    private ?object $challengeDetails;

    private array $authRoles;

    private ?object $authExtra;

    private ?string $authProvider;

    private ?AuthenticatorInterface $authenticator;

    function __construct()
    {
        $this->authId = null;
        $this->authMethod = null;
        $this->challenge = null;
        $this->challengeDetails = null;
        $this->authExtra = null;
        $this->authProvider = null;
        $this->authRoles = [];
    }

    public function setChallengeDetails(array|object $challengeDetails): void
    {
        $this->challengeDetails = (object) $challengeDetails;
    }

    public function getChallengeDetails(): ?object
    {
        return $this->challengeDetails;
    }

    public function setChallenge(mixed $challenge): void
    {
        $this->challenge = $challenge;
    }

    public function getChallenge(): ?array
    {
        return $this->challenge;
    }

    public function setAuthId(string|int|float|null $authId): void
    {
        $this->authId = $authId;
    }

    public function getAuthId(): string|int|float|null
    {
        return $this->authId;
    }

    public function setAuthMethod(string $authMethod): void
    {
        $this->authMethod = $authMethod;
    }

    public function getAuthMethod(): string
    {
        return $this->authMethod;
    }

    static public function createAnonymous(): AuthenticationDetails
    {
        $authDetails = new static();
        $authDetails->setAuthId("anonymous");
        $authDetails->setAuthMethod("anonymous");
        $authDetails->addAuthRole("anonymous");

        return $authDetails;
    }

    public function getAuthRoles(): array
    {
        return $this->authRoles;
    }

    public function setAuthRoles(array $authRoles): void
    {
        $this->authRoles = $authRoles;
    }

    public function addAuthRole(string|array $authRole): void
    {
        if (is_array($authRole)) {
            $this->authRoles = array_merge($authRole, $this->authRoles);
        } else {
            // this is done this way so that most recent addition will be the
            // singular role for compatibility
            array_unshift($this->authRoles, $authRole);
        }
    }

    public function hasAuthRole(string $authRole): bool
    {
        if (in_array($authRole, $this->authRoles)) {
            return true;
        } else {
            return false;
        }
    }

    public function getAuthRole(): ?string
    {
        if (count($this->authRoles) > 0) {
            return $this->authRoles[0];
        } else {
            return null;
        }
    }

    public function getAuthExtra(): object
    {
        return $this->authExtra;
    }

    public function setAuthExtra(array|object $authExtra): void
    {
        $this->authExtra = (object) $authExtra;
    }

    public function setAuthProvider(string $provider): void
    {
        $this->authProvider = $provider;
    }

    public function getAuthProvider(): ?string
    {
        return $this->authProvider;
    }

    public function setAuthenticator(AuthenticatorInterface $authenticator): void
    {
        $this->authenticator = $authenticator;
    }

    public function getAuthenticator(): ?AuthenticatorInterface
    {
        return $this->authenticator;
    }

    public function jsonSerialize(): array
    {
        $details = [
            'authid' => $this->getAuthId(),
            'authrole' => $this->getAuthRole(),
            'authmethod' => $this->authMethod,
        ];

        if ($this->getAuthExtra() !== null) {
            $details['authextra'] = $this->getAuthExtra();
        }

        if ($this->getAuthProvider() !== null) {
            $details['authprovider'] = $this->getAuthProvider();
        }

        return $details;
    }
}