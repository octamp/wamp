<?php

namespace Octamp\Wamp\Session;

use Octamp\Client\Promise\Promise;
use Octamp\Wamp\Event\LeaveRealmEvent;
use Octamp\Wamp\Realm\Realm;
use Octamp\Wamp\Transport\AbstractTransport;
use Thruway\Authentication\AuthenticationDetails;
use Thruway\Message\AbortMessage;
use Thruway\Message\HelloMessage;
use Thruway\Message\Message;

class Session
{
    protected ?HelloMessage $helloMessage = null;
    protected ?Realm $realm = null;

    protected bool $trusted = false;

    protected bool $authenticated = false;

    protected float $lastOutboundActivity = 0;

    protected ?string $id = null;

    protected bool $goodByeSent = false;

    protected int $pendingCallCount = 0;

    protected ?AuthenticationDetails $authenticationDetails = null;

    public function __construct(protected AbstractTransport $transport, protected string $serverId)
    {
    }

    public function setId(string $id): void
    {
        if ($this->id !== null) {
            throw new \Exception('Unable to set new session id');
        }

        $this->id = $id;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getSessionId(): ?string
    {
        return $this->getId();
    }

    public function setTrusted(bool $trusted): void
    {
        $this->trusted = $trusted;
    }

    public function getTransportId(): string
    {
        return $this->transport->getId();
    }

    public function getTransport(): AbstractTransport
    {
        return $this->transport;
    }

    public function setHelloMessage(HelloMessage $message): void
    {
        if ($this->helloMessage !== null) {
            throw new \Exception('Unable to set another hello message');
        }

        $this->helloMessage = $message;
    }

    public function setRealm(Realm $realm): void
    {
        if ($this->realm !== null) {
            throw new \Exception('Unable to set another realm');
        }

        $this->realm = $realm;
    }

    public function abort(object $details, string $uri): void
    {
        if ($this->isAuthenticated()) {
            throw new \Exception('Session::abort called after we are authenticated');
        }
        $abortMessage = new AbortMessage($details, $uri);
        $this->sendMessage($abortMessage);
    }

    public function setAuthenticated(bool $authenticated): void
    {
        $this->authenticated = $authenticated;
    }

    public function isAuthenticated(): bool
    {
        return $this->authenticated;
    }

    public function getRealm(): ?Realm
    {
        return $this->realm;
    }

    public function sendMessage(Message $message): void
    {
        $this->lastOutboundActivity = microtime(true);
        $this->getTransport()->sendMessage($message);
    }

    public function ping(): Promise
    {
        return $this->getTransport()->ping();
    }

    public function isTrusted(): bool
    {
        return $this->trusted;
    }

    public function getServerId(): string
    {
        return $this->serverId;
    }

    public function setGoodByeSent(bool $sent): void
    {
        $this->goodByeSent = $sent;
    }

    public function shutdown(): void
    {
        $this->onClose();
        $this->getTransport()->close();
    }

    public function onClose(): void
    {
        if ($this->realm !== null) {
            $this->realm->handle($this, new LeaveRealmEvent($this));
        }
    }

    public function getMetaInfo(): array
    {
        // TODO
        if ($this->getAuthenticationDetails() instanceof AuthenticationDetails) {
            $authId     = $this->getAuthenticationDetails()->getAuthId();
            $authMethod = $this->getAuthenticationDetails()->getAuthMethod();
            $authRole   = $this->getAuthenticationDetails()->getAuthRole();
            $authRoles  = $this->getAuthenticationDetails()->getAuthRoles();
        } else {
            $authId     = "anonymous";
            $authMethod = "anonymous";
            $authRole   = "anonymous";
            $authRoles  = [];
        }

        return [
            "realm"         => $this->getRealm()->name,
            "authprovider"  => null,
            "authid"        => $authId,
            "authrole"      => $authRoles,
            "authroles"     => $authRole,
            "authmethod"    => $authMethod,
            "session"       => $this->getId(),
            "role_features" => $this->getRoleFeatures()
        ];
    }

    public function getRoleFeatures(): array
    {
        // TODO
        return [];
    }

    public function incPendingCallCount(): int
    {
        return $this->pendingCallCount++;
    }

    public function decPendingCallCount(): int
    {
        // if we are already at zero - something is wrong
        if ($this->pendingCallCount === 0) {
            return 0;
        }

        return $this->pendingCallCount--;
    }

    public function setAuthenticationDetails(AuthenticationDetails $authenticationDetails): void
    {
        $this->authenticationDetails = $authenticationDetails;
    }

    public function getAuthenticationDetails(): AuthenticationDetails
    {
        return $this->authenticationDetails;
    }
}