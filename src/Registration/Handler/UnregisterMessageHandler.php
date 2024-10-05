<?php

namespace Octamp\Wamp\Registration\Handler;

use Octamp\Wamp\Registration\RegistrationStorage;
use Octamp\Wamp\Session\Session;
use OpenSwoole\Coroutine;
use Thruway\Message\ErrorMessage;
use Thruway\Message\UnregisteredMessage;
use Thruway\Message\UnregisterMessage;

class UnregisterMessageHandler
{
    public function __construct(protected RegistrationStorage $registrationStorage)
    {
    }

    public function handleMessage(Session $session, UnregisterMessage $message): void
    {
        $registration = $this->registrationStorage->getRegistrationById($session->getRealm()->getRealmName(), $message->getRegistrationId());
        if ($registration === null) {
            $session->sendMessage(ErrorMessage::createErrorMessageFromMessage($message, 'wamp.error.no_such_registration'));
            return;
        }

        $this->registrationStorage->deleteRegistrationById($session->getRealm()->getRealmName(), $message->getRegistrationId());

        $session->sendMessage(UnregisteredMessage::createFromUnregisterMessage($message));

        $session->getRealm()->getMetaSession()->publish('wamp.registration.on_unregister', [$session->getSessionId(), $message->getRegistrationId()]);
        if ($this->registrationStorage->tryDeleteProcedureFromRegistration($registration)) {
            $session->getRealm()->getMetaSession()->publish('wamp.registration.on_delete', [$session->getSessionId(), $message->getRegistrationId()]);
        }
    }
}