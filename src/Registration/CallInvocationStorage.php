<?php

namespace Octamp\Wamp\Registration;

use Octamp\Wamp\Registration\Model\Call;
use Octamp\Wamp\Registration\Model\Invocation;
use Octamp\Wamp\Session\Session;

class CallInvocationStorage
{
    /**
     * @var Call[]
     */
    protected array $call = [];

    /**
     * @var Invocation[]
     */
    protected array $invocations = [];

    public function addCall(Call $call): void
    {
        $id = $this->generateId($call->callerSession->getRealm()->getRealmName(), $call->callerSession->getSessionId(), $call->message->getRequestId());
        $this->call[$id] = $call;
    }

    public function getCall(string $realm, string $sessionId, string|int $requestId): ?Call
    {
        return $this->call[$this->generateId($realm, $sessionId, $requestId)] ?? null;
    }

    public function getCallUsingSession(Session $session, string|int $requestId): ?Call
    {
        return $this->getCall($session->getRealm()->getRealmName(), $session->getSessionId(), $requestId);
    }

    public function removeCall(string $realm, string $sessionId, string|int $requestId): void
    {
        unset($this->call[$this->generateId($realm, $sessionId, $requestId)]);
    }

    public function removeCallUsingSession(Session $session, string|int $requestId): void
    {
        $this->removeCall($session->getRealm()->getRealmName(), $session->getSessionId(), $requestId);
    }

    public function addInvocation(Invocation $invocation): void
    {
        $callee = $invocation->getCalleeSession();
        $id = $this->generateId($callee->getRealm()->getRealmName(), $callee->getSessionId(), $invocation->invocationMessage->getRequestId());
        $this->invocations[$id] = $invocation;
    }

    public function getInvocation(string $realm, string $sessionId, string|int $requestId): ?Invocation
    {
        return $this->invocations[$this->generateId($realm, $sessionId, $requestId)] ?? null;
    }

    public function getInvocationUsingSession(Session $session, string|int $requestId): ?Invocation
    {
        return $this->getInvocation($session->getRealm()->getRealmName(), $session->getSessionId(), $requestId);
    }

    public function removeInvocation(string $realm, string $sessionId, string|int $requestId): void
    {
        unset($this->invocations[$this->generateId($realm, $sessionId, $requestId)]);
    }

    public function removeInvocationUsingSession(Session $session, string|int $requestId): void
    {
        $this->removeInvocation($session->getRealm()->getRealmName(), $session->getSessionId(), $requestId);
    }

    /**
     * @return Invocation[]
     */
    public function getInvocationWithCallerSessionId(string $realmName, string $sessionId): array
    {
        return array_filter($this->invocations, function (Invocation $invocation) use ($sessionId, $realmName) {
            return $invocation->callerSession->getRealm()->getRealmName() === $realmName && $invocation->callerSession->getSessionId() === $sessionId;
        });
    }

    /**
     * @return Call[]
     */
    public function getCallsWithCalleeSessionId(string $realmName, string $sessionId): array
    {
        return array_filter($this->call, function (Call $call) use ($sessionId, $realmName) {
            return $call->getCalleeSession()->getRealm()->getRealmName() === $realmName && $call->getCalleeSession()->getSessionId() === $sessionId;
        });
    }

    protected function generateId(string $realm, string $sessionId, string|int $requestId): string
    {
        return sprintf('%s_%s_%s', $realm, $sessionId, $requestId);
    }
}