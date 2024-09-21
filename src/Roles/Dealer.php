<?php

declare(strict_types=1);

namespace Octamp\Wamp\Roles;

use Octamp\Wamp\Adapter\AdapterInterface;
use Octamp\Wamp\Event\LeaveRealmEvent;
use Octamp\Wamp\Matcher\Matcher;
use Octamp\Wamp\Realm\Realm;
use Octamp\Wamp\Registration\Procedure;
use Octamp\Wamp\Registration\Registration;
use Octamp\Wamp\Session\Session;
use Octamp\Wamp\Session\SessionStorage;
use Thruway\Common\Utils;
use Thruway\Message\CallMessage;
use Thruway\Message\CancelMessage;
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

    public function __construct(AdapterInterface $adapter, SessionStorage $sessionStorage, protected Matcher $matcher, protected string $serverId)
    {
        parent::__construct($adapter, $sessionStorage);
        $this->registrationsBySession = new \SplObjectStorage();
    }

    public function onCancelMessage(Session $session, CancelMessage $message): void
    {
        $mode = $message->getOptions()->mode ?? 'skip';
        $invocationKey = Registration::generateKeyForInvocation($session->getSessionId(), '*',  '*', '*', $message->getRequestId());
        $invocationDetails = $this->adapter->findOne($invocationKey);
        if ($invocationDetails === null) {
            return;
        }
        $invocationKey = Registration::generateKeyForInvocation(
            $invocationDetails['callSessionId'],
            $session->getSessionId(),
            $invocationDetails['registrationId'],
            $invocationDetails['invocationId'],
            $message->getRequestId(),
        );

        if ($invocationDetails['cancelled']) {
            return;
        }

        if ($invocationDetails['hasResponse'] ||  $invocationDetails['hasSentResult']) {
            return;
        }

        $this->adapter->setField($invocationKey, 'cancelled', true);
        $this->adapter->setField($invocationKey, 'cancelMode', $mode);

        $callerSession = $this->sessionStorage->getSessionUsingTransportId($invocationDetails['callTransportId']);
        $calleeSession = $this->sessionStorage->getSessionUsingTransportId($invocationDetails['calleeTransportId']);
        if (!$calleeSession->hasFeature('callee', 'call_canceling')) {
            $mode = 'skip';
        }

        if ($mode === 'kill') {
            $calleeSession->sendMessage(new InterruptMessage($invocationDetails['invocationId'], new \stdClass()));
        } elseif ($mode === 'killnowait') {
            $callerSession->sendMessage(new ErrorMessage(Message::MSG_CALL, $message->getRequestId()));
            $callerSession->sendMessage(new InterruptMessage($invocationDetails['invocationId'], new \stdClass()));
        } else {
            $callerSession->sendMessage(new ErrorMessage(Message::MSG_CALL, $message->getRequestId()));
        }
    }

    public function onCallMessage(Session $session, CallMessage $message): void
    {
        if (!Utils::uriIsValid($message->getUri())) {
            $session->sendMessage(ErrorMessage::createErrorMessageFromMessage($message, 'wamp.error.invalid_uri'));
            return;
        }

        if (!$this->hasProcedure($session->getRealm()->getRealmName(), $message->getProcedureName())) {
            $error = ErrorMessage::createErrorMessageFromMessage($message, 'wamp.error.no_such_procedure');
            $error->setArgumentsKw((object)['topic' => $message->getProcedureName()]);

            $session->sendMessage($error);
            return;
        }

        $procedure = $this->getProcedure($session->getRealm()->name, $message->getProcedureName());
        $procedure->processCallMessage($session, $message);
    }

    public function onYieldMessage(Session $session, YieldMessage $message): void
    {
        $invocationKey = Registration::generateKeyForInvocation('*', $session->getSessionId(), '*', $message->getRequestId(), '*');
        $invocationDetails = $this->adapter->findOne($invocationKey);

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
            $message->getRequestId(),
            $invocationDetails['callRequestId']
        );

        if (!$invocationDetails['cancelled']) {
            $callerSession = $this->sessionStorage->getSessionUsingTransportId($invocationDetails['callTransportId']);

            $isProgress = $message->getOptions()?->progress ?? false;
            $callIsProgressive = $invocationDetails['isProgressive'] ?? false;
            if ($isProgress && $callIsProgressive && $callerSession->hasFeature('caller', 'progressive_call_results')) {
                $resultMessage = new ResultMessage(
                    (int) $invocationDetails['callRequestId'],
                    ['progress' => true],
                    $message->getArguments(),
                    $message->getArgumentsKw()
                );
                $callerSession?->sendMessage($resultMessage);
                return;
            }

            $this->adapter->setField($invocationKey, 'hasResponse', true);
            $resultMessage = new ResultMessage(
                (int) $invocationDetails['callRequestId'],
                [],
                $message->getArguments(),
                $message->getArgumentsKw()
            );

            $callerSession?->sendMessage($resultMessage);
            $this->adapter->setField($invocationKey, 'hasSentResult', true);
        }

        $this->removeCall($invocationKey);
    }

    public function removeCall(string $invocationKey): void
    {
        $this->adapter->del($invocationKey);
    }

    public function onRegisterMessage(Session $session, RegisterMessage $message): void
    {
        $procedureName = $message->getProcedureName();
        $globalName = Procedure::generateGlobalName($session->getRealm()->getRealmName(), $procedureName);
        $exists = $this->procedureExists($globalName, true);
        if (!$exists) {
            $this->adapter->lock('proc:' . $globalName . ':lock', $procedureName, 2, 2);
        }
        $registration = Registration::createRegistrationFromRegisterMessage($session, $message, $this->adapter);

        $procedure = $this->getProcedure($session->getRealm()->getRealmName(), $procedureName, $registration);
        $this->saveProcedure($procedure);

        if (!$exists) {
            $this->adapter->unlock('proc:' . $globalName . ':lock', $procedureName);
        }
        $procedure->processRegister($session, $message, $registration)->then(function ($result) use($procedure) {
            if ($result) {
                $this->saveProcedure($procedure);
            }
        });
    }

    protected function hasProcedure(string $realm, string $procedureName): bool
    {
        $globalProcedureName = Procedure::generateGlobalName($realm, $procedureName);

        if (isset($this->procedures[$globalProcedureName])) {
            return true;
        }

        return $this->adapter->exists('proc:' . $globalProcedureName);
    }

    protected function getProcedure(string $realm, string $procedureName, ?Registration $registration = null): Procedure
    {
        $globalProcedureName = Procedure::generateGlobalName($realm, $procedureName);
        if (isset($this->procedures[$globalProcedureName])) {
            return $this->procedures[$globalProcedureName];
        }

        $procedureRaw = $this->adapter->get('proc:' . $globalProcedureName);
        if ($procedureRaw === null) {
            $procedure = new Procedure($this->adapter, $this->sessionStorage, $realm, $procedureName);
            if ($registration !== null) {
                $procedure->setDiscloseCaller($registration->getDiscloseCaller());
                $procedure->setInvokeType($registration->getInvokeType());
                $procedure->setAllowMultipleRegistrations($registration->getAllowMultipleRegistrations());
            }
            return $procedure;
        }

        $procedure = new Procedure($this->adapter, $this->sessionStorage, $realm, $procedureName, false);
        $procedure->setDiscloseCaller((bool)$procedureRaw['discloseCaller']);
        $procedure->setAllowMultipleRegistrations((bool)$procedureRaw['allowMultipleRegistrations']);
        $procedure->setInvokeType($procedureRaw['invokeType']);

        if (!$procedure->hasRegistrations(true)) {
            $procedure->processSets = true;
        }

        return $procedure;
    }

    protected function saveProcedure(Procedure $procedure): void
    {
        $this->procedures[$procedure->getGlobalName()] = $procedure;
        $this->adapter->set('proc:' . $procedure->getGlobalName(), [
            'discloseCaller' => $procedure->getDiscloseCaller(),
            'allowMultipleRegistrations' => $procedure->getAllowMultipleRegistrations(),
            'invokeType' => $procedure->getInvokeType(),
            'lastCallIndex' => -1,
            'realm' => $procedure->getRealmName(),
        ]);
    }

    protected function procedureExists(string $hash, bool $global = false): bool
    {
        if ($global) {
            return $this->adapter->exists('proc:' . $hash);
        }

        return isset($this->procedures[$hash]);
    }

    public function onUnregisterMessage(Session $session, UnregisterMessage $message): void
    {
        $registration = $this->getRegistrationById($session, $message->getRegistrationId());
        if ($registration === null) {
            $session->sendMessage(ErrorMessage::createErrorMessageFromMessage($message, 'wamp.error.no_such_procedure'));
            return;
        }
        $procedure = $this->getProcedure($session->getRealm()->getRealmName(), $registration->getProcedureName());
        if ($procedure == null) {
            $session->sendMessage(ErrorMessage::createErrorMessageFromMessage($message, 'wamp.error.no_such_procedure'));
            return;
        }

        $procedure->processUnregister($session, $message);
        $this->tryDeleteProcedure($session->getRealm(), $registration->getProcedureName());
    }

    public function onErrorMessage(Session $session, ErrorMessage $message): void
    {
        if ($message->getErrorMsgCode() === Message::MSG_INVOCATION) {
            $this->processInvocationError($session, $message);
        }
    }

    protected function processInvocationError(Session $session, ErrorMessage $message): void
    {
        $key = Registration::generateKeyForInvocation('*', $session->getSessionId(), '*', $message->getRequestId(), '*');
        $details = $this->adapter->findOne($key);
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
            $message->getRequestId(),
            $details['callRequestId']
        );
        $this->removeCall($key);

        if (!$details['cancelled'] || ($details['cancelled'] && $details['cancelMode'] === 'kill')) {
            $errorMessage = new ErrorMessage(
                Message::MSG_CALL,
                (int) $details['callRequestId'],
                $message->getDetails(),
                $message->getErrorURI(),
                $message->getArguments(),
                $message->getArgumentsKw()
            );

            $callerSession = $this->sessionStorage->getSessionUsingTransportId($details['callTransportId']);
            $callerSession->sendMessage($errorMessage);
        }
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
        $procedureNames = array_keys($this->procedures);
        foreach ($procedureNames as $name) {
            $this->procedures[$name]->leave($session);
            $this->tryDeleteProcedure($session->getRealm(), $this->procedures[$name]->getProcedureName());
        }

        $search = Registration::generateKeyForInvocation($session->getSessionId(), '*', '*', '*', '*');
        $results = $this->adapter->findWithRetainKey($search);
        foreach ($results as $key => $result) {
            $this->adapter->del($key);
            $calleeSession = $this->sessionStorage->getSessionUsingTransportId($result['calleeTransportId']);
            $calleeSession->sendMessage(new InterruptMessage($result['invocationId'], (object)[]));
        }
    }

    public function tryDeleteProcedure(Realm $realm, string $name): void
    {
        $procedureName = Procedure::generateGlobalName($realm->name, $name);
        if (isset($this->procedures[$procedureName]) && !$this->procedures[$procedureName]->hasRegistrations(true)) {
            $this->adapter->del('proc:' . $procedureName);
            $this->adapter->del('proc:' . $procedureName . ':regs');
            $this->adapter->del('proc:' . $procedureName . ':lock');

            unset($this->procedures[$procedureName]);
        } elseif (isset($this->procedures[$procedureName]) && !$this->procedures[$procedureName]->hasRegistrations(false)) {
            unset($this->procedures[$procedureName]);
        }
    }

    public function getName(): string
    {
        return 'dealer';
    }

    public function getFeatures(): object
    {
        $features = new \stdClass();
        $features->shared_registration = true;
        $features->progressive_call_results = true;
        $features->call_canceling = true;

        return $features;
    }
}
