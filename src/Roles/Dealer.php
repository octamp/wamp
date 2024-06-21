<?php

namespace Octamp\Wamp\Roles;

use Octamp\Wamp\Adapter\AdapterInterface;
use Octamp\Wamp\Event\LeaveRealmEvent;
use Octamp\Wamp\Registration\Call;
use Octamp\Wamp\Registration\Procedure;
use Octamp\Wamp\Registration\Registration;
use Octamp\Wamp\Session\Session;
use Octamp\Wamp\Session\SessionStorage;
use Thruway\Common\Utils;
use Thruway\Message\CallMessage;
use Thruway\Message\ErrorMessage;
use Thruway\Message\InterruptMessage;
use Thruway\Message\Message;
use Thruway\Message\RegisterMessage;
use Thruway\Message\ResultMessage;
use Thruway\Message\UnregisterMessage;
use Thruway\Message\YieldMessage;

class Dealer extends AbstractRole implements RoleInterface
{
    /**
     * @var Procedure[]
     */
    protected array $procedures = [];
    protected \SplObjectStorage $registrationsBySession;

    public function __construct(AdapterInterface $adapter, SessionStorage $sessionStorage, protected string $serverId)
    {
        parent::__construct($adapter, $sessionStorage);
        $this->registrationsBySession = new \SplObjectStorage();
    }

    public function onCallMessage(Session $session, CallMessage $message): void
    {
        if (!Utils::uriIsValid($message->getUri())) {
            $session->sendMessage(ErrorMessage::createErrorMessageFromMessage($message, 'wamp.error.invalid_uri'));
            return;
        }

        if (!$this->hasProcedure($message->getProcedureName())) {
            $session->sendMessage(ErrorMessage::createErrorMessageFromMessage($message, 'wamp.error.no_such_procedure'));
            return;
        }

        $procedure = $this->getProcedure($message->getProcedureName());

        $call = new Call($session, $message, $procedure);
        $procedure->processCall($session, $call);
    }

    public function onYieldMessage(Session $session, YieldMessage $message): void
    {
        $details   = new \stdClass();

        $invocationKey = Registration::generateKeyForInvocation('*', $session->getSessionId(), '*', $message->getRequestId());
        $invocationDetails = $this->adapter->get($invocationKey);

        if ($invocationDetails === null) {
            $session->sendMessage(ErrorMessage::createErrorMessageFromMessage($message));
            return;
        }

        if ($invocationDetails['hasResponse']) {
            $session->sendMessage(ErrorMessage::createErrorMessageFromMessage($message, 'wamp.error.invocation_already_recieved_yield'));
            return;
        }

        $invocationKey = Registration::generateKeyForInvocation(
            $invocationDetails['callSessionId'],
            $session->getSessionId(),
            $invocationDetails['registrationId'],
            $message->getRequestId()
        );

        $this->adapter->setField($invocationKey, 'hasResponse', true);
        $resultMessage = new ResultMessage(
            $invocationDetails['callRequestId'],
            $details,
            $message->getArguments(),
            $message->getArgumentsKw()
        );

        $callerSession = $this->sessionStorage->getSessionUsingTransportId($invocationDetails['callTransportId']);
        $callerSession?->sendMessage($resultMessage);
        $this->adapter->setField($invocationKey, 'hasSentResult', true);

        $this->removeCall($invocationKey);
    }

    public function removeCall(string $invocationKey): void
    {
        $this->adapter->del($invocationKey);
    }

    public function onRegisterMessage(Session $session, RegisterMessage $message): void
    {
        $procedureName = $message->getProcedureName();
        $exists = $this->procedureExists($procedureName, true);
        if (!$exists) {
            $this->adapter->lock('proc:' . $procedureName . ':lock', $procedureName, 2, 2);
        }
        $registration = Registration::createRegistrationFromRegisterMessage($session, $message, $this->adapter);
        $procedure = $this->getProcedure($procedureName, $registration);
        $this->saveProcedure($procedure);
        if (!$exists) {
            $this->adapter->unlock('proc:' . $procedureName . ':lock', $procedureName);
        }
        $procedure->processRegister($session, $message, $registration);
    }

    protected function hasProcedure(string $procedureName): bool
    {
        if (isset($this->procedures[$procedureName])) {
            return true;
        }

        return $this->adapter->exists('proc:' . $procedureName);
    }

    protected function getProcedure(string $procedureName, ?Registration $registration = null): Procedure
    {
        if (isset($this->procedures[$procedureName])) {
            return $this->procedures[$procedureName];
        }

        $procedureRaw = $this->adapter->get('proc:' . $procedureName);
        if ($procedureRaw === null) {
            $procedure = new Procedure($this->adapter, $this->sessionStorage, $procedureName);
            if ($registration !== null) {
                $procedure->setDiscloseCaller($registration->getDiscloseCaller());
                $procedure->setInvokeType($registration->getInvokeType());
                $procedure->setAllowMultipleRegistrations($registration->getAllowMultipleRegistrations());
            }
            return $procedure;
        }

        $procedure = new Procedure($this->adapter, $this->sessionStorage, $procedureName, false);
        $procedure->setDiscloseCaller($procedureRaw['discloseCaller']);
        $procedure->setAllowMultipleRegistrations($procedureRaw['allowMultipleRegistrations']);
        $procedure->setInvokeType($procedureRaw['invokeType']);

        return $procedure;
    }

    protected function saveProcedure(Procedure $procedure): void
    {
        $this->procedures[$procedure->getProcedureName()] = $procedure;
        $this->adapter->set('proc:' . $procedure->getProcedureName(), [
            'discloseCaller' => $procedure->getDiscloseCaller(),
            'allowMultipleRegistrations' => $procedure->getAllowMultipleRegistrations(),
            'invokeType' => $procedure->getInvokeType(),
            'lastCallIndex' => -1,
        ]);
    }

    protected function procedureExists(string $name, bool $global = false): bool
    {
        if ($global) {
            return $this->adapter->exists($name);
        }

        return isset($this->procedures[$name]);
    }

    public function onUnregisterMessage(Session $session, UnregisterMessage $message): void
    {
        $registration = $this->getRegistrationById($session, $message->getRegistrationId());
        if ($registration === null) {
            $session->sendMessage(ErrorMessage::createErrorMessageFromMessage($message, 'wamp.error.no_such_procedure'));
            return;
        }
        $procedure = $this->getProcedure($registration->getProcedureName());
        if ($procedure == null) {
            $session->sendMessage(ErrorMessage::createErrorMessageFromMessage($message, 'wamp.error.no_such_procedure'));
            return;
        }

        $procedure->processUnregister($session, $message);
        $localProcedure = $this->procedures[$registration->getProcedureName()] ?? null;
        if ($localProcedure === $procedure && $localProcedure->hasRegistrations(false)) {
            unset($this->procedures[$registration->getProcedureName()]);
        }
    }

    public function onErrorMessage(Session $session, ErrorMessage $message)
    {
        if ($message->getErrorMsgCode() === Message::MSG_INVOCATION) {
            $this->processInvocationError($session, $message);
        }
    }

    protected function processInvocationError(Session $session, ErrorMessage $message)
    {
        $key = Registration::generateKeyForInvocation('*', $session->getSessionId(), '*', $message->getRequestId());
        $details = $this->adapter->get($key);
        if ($details === null) {
            $session->sendMessage(ErrorMessage::createErrorMessageFromMessage($message, 'wamp.error.no_such_procedure'));
            return;
        } elseif ($details['hasResponse']) {
            $session->sendMessage(ErrorMessage::createErrorMessageFromMessage($message, 'wamp.error.no_such_procedure'));
            return;
        } elseif ($details['hasSentResult']) {
            $session->sendMessage(ErrorMessage::createErrorMessageFromMessage($message, 'wamp.error.no_such_procedure'));
            return;
        }

        $key = Registration::generateKeyForInvocation(
            $details['callSessionId'],
            $session->getSessionId(),
            $details['registrationId'],
            $message->getRequestId()
        );
        $this->removeCall($key);

        $errorMessage = new ErrorMessage(
            Message::MSG_CALL,
            $details['callRequestId'],
            $message->getDetails(),
            $message->getErrorURI(),
            $message->getArguments(),
            $message->getArgumentsKw()
        );

        $callerSession = $this->sessionStorage->getSessionUsingTransportId($details['callTransportId']);
        $callerSession->sendMessage($errorMessage);
    }

    protected function getRegistrationById(Session $session, int $registrationId): ?Registration
    {
        foreach ($this->procedures as $procedure) {
            /** @var Registration $registration */
            $registration = $procedure->getRegistrationById($session, $registrationId);

            if ($registration !== null) {
                return $registration;
            }
        }

        return null;
    }

    public function onLeaveRealmEvent(Session $session, LeaveRealmEvent $event): void
    {
        foreach ($this->procedures as $procedure) {
            $procedure->leave($session);
        }

        $search = Registration::generateKeyForInvocation($session->getSessionId(), '*', '*', '*');
        $results = $this->adapter->findWithRetainKey($search);
        foreach ($results as $key => $result) {
            $this->adapter->del($key);
            $calleeSession = $this->sessionStorage->getSessionUsingTransportId($result['calleeTransportId']);
            $calleeSession->sendMessage(new InterruptMessage($result['invocationId']));
        }
    }
}