<?php

namespace Octamp\Wamp\Session;

use Octamp\Wamp\Realm\Realm;
use Octamp\Wamp\Transport\AbstractTransport;
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

    public function ping(): void
    {
        $this->getTransport()->getConnection()->ping();
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
            $this->realm->onLeaveRealm($this);
        }
    }

    public function getMetaInfo(): array
    {
        // TODO
//        if ($this->getAuthenticationDetails() instanceof AuthenticationDetails) {
//            $authId     = $this->getAuthenticationDetails()->getAuthId();
//            $authMethod = $this->getAuthenticationDetails()->getAuthMethod();
//            $authRole   = $this->getAuthenticationDetails()->getAuthRole();
//            $authRoles  = $this->getAuthenticationDetails()->getAuthRoles();
//        } else {
//            $authId     = "anonymous";
//            $authMethod = "anonymous";
//            $authRole   = "anonymous";
//            $authRoles  = [];
//        }

        return [
            "realm"         => $this->getRealm()->name,
            "authprovider"  => null,
            "authid"        => null,
            "authrole"      => null,
            "authroles"     => null,
            "authmethod"    => null,
            "session"       => $this->getId(),
            "role_features" => $this->getRoleFeatures()
        ];
    }

    public function getRoleFeatures(): array
    {
        // TODO
        return [];
    }
}