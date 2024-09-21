<?php

declare(strict_types=1);

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

    private ?object $verificationDetails;

    function __construct()
    {
        $this->authId = null;
        $this->authMethod = null;
        $this->challenge = null;
        $this->challengeDetails = null;
        $this->verificationDetails = null;
        $this->authExtra = null;
        $this->authProvider = null;
        $this->authRoles = [];
    }

    public function setChallengeDetails(array|object|null $challengeDetails): void
    {
        if ($challengeDetails === null) {
            $this->challengeDetails = null;
            return;
        }

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

    public function getChallenge(): mixed
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

    public static function createAnonymous(): AuthenticationDetails
    {
        $authDetails = new static();
        $authDetails->setAuthId("anonymous");
        $authDetails->setAuthMethod("anonymous");
        $authDetails->addAuthRole("anonymous");

        return $authDetails;
    }

    public static function createFromArray(array $data): AuthenticationDetails
    {
        $authDetails = new static();
        $authDetails->setAuthId($data['authid'] ?? null);
        $authDetails->setAuthMethod($data['authmethod'] ?? null);
        $authDetails->setAuthMethod($data['authmethod'] ?? null);
        $authDetails->setAuthRoles($data['authroles'] ?? []);
        $authDetails->setChallenge($data['challenge'] ?? null);
        $authDetails->setChallengeDetails($data['challengeDetails'] ?? null);
        $authDetails->setVerificationDetails($data['verificationDetails'] ?? null);
        $authDetails->setAuthExtra($data['authextra'] ?? null);
        $authDetails->setAuthProvider($data['authprovider'] ?? null);

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

    public function getAuthExtra(): ?object
    {
        return $this->authExtra;
    }

    public function setAuthExtra(array|object|null $authExtra): void
    {
        if ($authExtra === null) {
            $this->authExtra = null;
            return;
        }

        $this->authExtra = (object) $authExtra;
    }

    public function setAuthProvider(?string $provider): void
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

    public function setVerificationDetails(array|object|null $details): void
    {
        if ($details === null) {
            $this->verificationDetails = null;
            return;
        }

        $this->verificationDetails = (object) $details;
    }

    public function getVerificationDetails(): ?object
    {
        return $this->verificationDetails;
    }

    public function getAuthenticator(): ?AuthenticatorInterface
    {
        return $this->authenticator;
    }

    public function jsonSerialize(bool $includeChallenge = false): array
    {
        $details = [
            'authid' => $this->getAuthId(),
            'authrole' => $this->getAuthRole(),
            'authmethod' => $this->authMethod,
            'authroles' => $this->getAuthRoles(),
        ];

        if ($includeChallenge) {
            $details['challenge'] = $this->getChallenge();
            $details['challengeDetails'] = $this->challengeDetails;
            $details['verificationDetails'] = $this->verificationDetails;
        }

        if ($this->getAuthExtra() !== null) {
            $details['authextra'] = $this->getAuthExtra();
        }

        if ($this->getAuthProvider() !== null) {
            $details['authprovider'] = $this->getAuthProvider();
        }

        return $details;
    }
}
