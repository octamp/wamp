<?php

namespace Octamp\Wamp\Registration\Handler;

use Octamp\Wamp\Adapter\AdapterInterface;
use Octamp\Wamp\Registration\CallInvocationStorage;
use Octamp\Wamp\Registration\Model\Call;
use Octamp\Wamp\Registration\Model\Invocation;
use Octamp\Wamp\Registration\Registration;
use Octamp\Wamp\Registration\RegistrationStorage;
use Octamp\Wamp\Session\Session;
use Octamp\Wamp\Session\SessionStorage;
use Thruway\Message\CallMessage;
use Thruway\Message\ErrorMessage;
use Thruway\Message\InvocationMessage;
use Thruway\Message\Message;

class ProcedureHandler
{
    public function __construct(protected RegistrationStorage $registrationStorage, protected CallInvocationStorage $callInvocationStorage, protected SessionStorage $sessionStorage, protected AdapterInterface $adapter, protected string $serverId)
    {
        $this->init();
    }

    protected function init(): void
    {
        $this->adapter->subscribe('process:call', function (string $fromServer, string $server, string $realm, string $sessionId, string|int $registrationId, array $message) {
            if ($server !== $this->serverId) {
                return;
            }
            $callMessage = Message::createMessageFromArray($message);
            $registration = $this->registrationStorage->getRegistrationById($realm, $registrationId);
            if ($registration === null) {
                $this->adapter->publish('process:call:error', [$fromServer, 'wamp.error.no_such_procedure', $realm, $sessionId, $callMessage->getRequestId()], $fromServer);
                return;
            }
            $session = $this->sessionStorage->getSession($realm, $sessionId);
            $this->handleProcessCall($callMessage, $registration, $session);
        });

        $this->adapter->subscribe('process:call:error', function (string $fromServer, string $server, string $errorUri, string $realm, $sessionId, string|int $callRequestId) {
            if ($server !== $this->serverId) {
                return;
            }
            $session = $this->sessionStorage->getSession($realm, $sessionId);
            $call = $this->callInvocationStorage->getCallUsingSession($session, $callRequestId);
            if ($call !== null) {
                $error = ErrorMessage::createErrorMessageFromMessage($call->message, 'wamp.error.no_such_procedure');
                $session->sendMessage($error);
                $this->callInvocationStorage->removeCallUsingSession($session, $callRequestId);
            }
        });
    }

    protected function handleProcessCall(CallMessage $message, Registration $registration, Session $callerSession): void
    {
        $registration->setLastCallStartedAtNow();
        $this->registrationStorage->saveRegistration($registration);

        $requestId = $registration->getSession()->incrementWampId();
        $invocationDetails = new \stdClass();
        $invocationMessage = new InvocationMessage($requestId, $registration->getId(), $invocationDetails, $message->getArguments(), $message->getArgumentsKw());
        $invocation = new Invocation($invocationMessage, $message, $registration, $callerSession);
        $this->callInvocationStorage->addInvocation($invocation);
        $registration->getSession()->sendMessage($invocationMessage);
    }

    public function handleCallMessage(Session $session, CallMessage $message, object $procedure): void
    {
        $registration = $this->registrationStorage->getRegistrationByProcedure($procedure);
        if ($registration === null) {
            $error = ErrorMessage::createErrorMessageFromMessage($message, 'wamp.error.no_such_procedure');
            $session->sendMessage($error);
            return;
        }

        $call = new Call($message, $session, $registration, $procedure);
        $this->callInvocationStorage->addCall($call);

        $serverId = $registration->getSession()->getServerId();
        if ($serverId !== $this->serverId) {
            $this->adapter->publish(
                'process:call',
                [
                    $serverId,
                    $session->getRealm()->getRealmName(),
                    $session->getSessionId(),
                    $registration->getId(),
                    $message->getMessageParts(),
                ],
                $serverId
            );
        } else {
            $this->handleProcessCall($message, $registration, $session);
        }
    }
}