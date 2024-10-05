<?php

namespace Octamp\Wamp\Registration\Handler;

use Octamp\Wamp\Helper\UriHelper;
use Octamp\Wamp\Registration\RegistrationStorage;
use Octamp\Wamp\Session\Session;
use Thruway\Message\ErrorMessage;
use Thruway\Message\RegisteredMessage;
use Thruway\Message\RegisterMessage;

class RegisterMessageHandler
{
    public function __construct(protected RegistrationStorage $registrationStorage)
    {
    }

    public function handleMessage(Session $session, RegisterMessage $message): void
    {
        $useExactMatch = ($message->getOptions()->match ?? 'exact') === 'exact';
        if (!UriHelper::uriIsValidStrict($message->getUri(), !$useExactMatch, $session->isTrusted())) {
            $session->sendMessage(ErrorMessage::createErrorMessageFromMessage($message, 'wamp.error.invalid_uri'));
            return;
        }

        $registration = $this->registrationStorage->getRegistrationUsingRegisterMessage($session, $message);
        $new = true;
        if ($registration === null) {
            $registration = $this->registrationStorage->generateRegistrationFromRegisterMessage($session, $message);
            $this->registrationStorage->saveRegistration($registration);
        } elseif ($registration->isAllowMultipleRegistrations()) {
            $this->registrationStorage->saveRegistration($registration);
            $new = false;
        } else {
            $promise = $registration->getSession()->ping()->then(
                function () use ($session, $message) {
                    $errorMsg = ErrorMessage::createErrorMessageFromMessage($message, 'wamp.error.procedure_already_exists');
                    $session->sendMessage($errorMsg);

                    return null;
                },
                function () use ($registration, $session, $message) {
                    $deadSession = $registration->getSession();
                    $deadSession->shutdown();
                    $newRegistration = $this->registrationStorage->getRegistrationUsingRegisterMessage($session, $message);
                    $this->registrationStorage->saveRegistration($newRegistration);

                    return $newRegistration;
                }
            );
            $registration = $promise->wait();
            if ($registration === null) {
                return;
            }
        }
        if ($new) {
            $session->getRealm()->getMetaSession()->publish('wamp.registration.on_create', [$session->getSessionId(), (object)[
                'id' => $registration->getId(),
                'created' => $registration->getRegisteredAt()->format(\DateTimeInterface::ATOM),
                'uri' => $registration->getProcedureName(),
                'match' => $registration->getMatch(),
                'invoke' => $registration->getInvokeType(),
            ]]);
        }

        $registration->getSession()->sendMessage(new RegisteredMessage($message->getRequestId(), $registration->getId()));
        $session->getRealm()->getMetaSession()->publish('wamp.registration.on_register', [$session->getSessionId(), $registration->getId()]);
    }
}