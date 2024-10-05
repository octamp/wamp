<?php

namespace Octamp\Wamp\Registration\Model;

use Octamp\Wamp\Registration\Registration;
use Octamp\Wamp\Session\Session;
use Thruway\Message\CallMessage;

final class Call
{
    protected bool $sentResult = false;

    public function __construct(public CallMessage $message, public Session $callerSession, public Registration $registration, public object $procedure)
    {
    }

    public function setSentResult(): void
    {
        $this->sentResult = true;
    }

    public function isSentResult(): bool
    {
        return $this->sentResult;
    }

    public function getCalleeSession(): Session
    {
        return $this->registration->getSession();
    }
}