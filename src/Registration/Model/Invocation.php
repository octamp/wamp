<?php

namespace Octamp\Wamp\Registration\Model;

use Octamp\Wamp\Registration\Registration;
use Octamp\Wamp\Session\Session;
use Thruway\Message\CallMessage;
use Thruway\Message\InvocationMessage;

class Invocation
{
    protected bool $receivedResponse = false;

    protected bool $canceled = false;

    public function __construct(public InvocationMessage $invocationMessage, public CallMessage $callMessage, public Registration $registration, public Session $callerSession)
    {
    }

    public function setToReceivedResponse(): void
    {
        $this->receivedResponse = true;
    }

    public function hasReceivedResponse(): bool
    {
        return $this->receivedResponse;
    }

    public function cancel(): void
    {
        $this->canceled = true;
    }

    public function isCanceled(): bool
    {
        return $this->canceled;
    }

    public function getCalleeSession(): Session
    {
        return $this->registration->getSession();
    }
}