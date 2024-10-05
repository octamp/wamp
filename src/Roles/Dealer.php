<?php

declare(strict_types=1);

namespace Octamp\Wamp\Roles;

use Octamp\Wamp\Adapter\AdapterInterface;
use Octamp\Wamp\Event\LeaveRealmEvent;
use Octamp\Wamp\Matcher\Matcher;
use Octamp\Wamp\Registration\CallInvocationStorage;
use Octamp\Wamp\Registration\Handler\CallMessageHandler;
use Octamp\Wamp\Registration\Handler\ErrorMessageHandler;
use Octamp\Wamp\Registration\Handler\ProcedureHandler;
use Octamp\Wamp\Registration\Handler\RegisterMessageHandler;
use Octamp\Wamp\Registration\Handler\UnregisterMessageHandler;
use Octamp\Wamp\Registration\Handler\YieldMessageHandler;
use Octamp\Wamp\Registration\Registration;
use Octamp\Wamp\Registration\RegistrationStorage;
use Octamp\Wamp\Session\Session;
use Octamp\Wamp\Session\SessionStorage;
use OpenSwoole\Coroutine;
use Thruway\Message\CallMessage;
use Thruway\Message\CancelMessage;
use Thruway\Message\ErrorMessage;
use Thruway\Message\InterruptMessage;
use Thruway\Message\Message;
use Thruway\Message\RegisterMessage;
use Thruway\Message\UnregisterMessage;
use Thruway\Message\YieldMessage;

class Dealer extends AbstractRole implements RoleInterface
{
    protected RegisterMessageHandler $registerMessageHandler;
    protected UnregisterMessageHandler $unregisterMessageHandler;
    protected ProcedureHandler $procedureHandler;
    protected CallMessageHandler $callMessageHandler;
    protected YieldMessageHandler $yieldMessageHandler;
    protected ErrorMessageHandler $errorMessageHandler;

    public function __construct(
        AdapterInterface $adapter,
        SessionStorage $sessionStorage,
        protected Matcher $matcher,
        protected RegistrationStorage $registrationStorage,
        protected CallInvocationStorage $callInvocationStorage,
        protected string $serverId
    ) {
        parent::__construct($adapter, $sessionStorage);

        $this->registerMessageHandler = new RegisterMessageHandler($this->registrationStorage);
        $this->unregisterMessageHandler = new UnregisterMessageHandler($this->registrationStorage);
        $this->procedureHandler = new ProcedureHandler($this->registrationStorage, $this->callInvocationStorage, $this->sessionStorage, $this->adapter, $this->serverId);
        $this->callMessageHandler = new CallMessageHandler($this->registrationStorage, $this->procedureHandler);
        $this->yieldMessageHandler = new YieldMessageHandler($this->sessionStorage, $this->callInvocationStorage, $this->adapter, $this->serverId);
        $this->errorMessageHandler = new ErrorMessageHandler($this->sessionStorage, $this->callInvocationStorage, $this->adapter, $this->serverId);

        $this->adapter->subscribe('session:leave', function(string $fromServerId, string $realmName, string $sessionId) {
            if ($fromServerId !== $this->serverId) {
                $this->processLeaveRealmEvent($realmName, $sessionId);
            }
        });

        $this->subscriptions();
    }

    protected function subscriptions(): void
    {
    }

    public function onRegisterMessage(Session $session, RegisterMessage $message): void
    {
        $this->registerMessageHandler->handleMessage($session, $message);
    }

    public function onUnregisterMessage(Session $session, UnregisterMessage $message): void
    {
        $this->unregisterMessageHandler->handleMessage($session, $message);
    }

    public function onCallMessage(Session $session, CallMessage $message): void
    {
        $this->callMessageHandler->handleMessage($session, $message);
    }

    public function onYieldMessage(Session $session, YieldMessage $message): void
    {
        $this->yieldMessageHandler->handleMessage($session, $message);
    }

    public function onErrorMessage(Session $session, ErrorMessage $message): void
    {
        $this->errorMessageHandler->handlerMessage($session, $message);
    }

    public function processLeaveRealmEvent(string $realmName, string $sessionId): void
    {
        // As Caller
        $invocations = $this->callInvocationStorage->getInvocationWithCallerSessionId($realmName, $sessionId);
        foreach ($invocations as $invocation) {
            $calleeSession = $invocation->getCalleeSession();
            if ($calleeSession->hasFeature('caller', 'call_canceling')) {
                $calleeSession->sendMessage(new InterruptMessage($invocation->invocationMessage->getRequestId(), (object)['mode' => 'killnowait']));
            }
            $this->callInvocationStorage->removeInvocationUsingSession($calleeSession, $invocation->invocationMessage->getRequestId());
        }

        // As Callee
        $calls = $this->callInvocationStorage->getCallsWithCalleeSessionId($realmName, $sessionId);
        foreach ($calls as $call) {
            $caller = $call->callerSession;
            if (!$call->isSentResult()) {
                $errorMessage = ErrorMessage::createErrorMessageFromMessage($call->message, 'wamp.error.cancelled');
                $caller->sendMessage($errorMessage);
            }
            $this->callInvocationStorage->removeCallUsingSession($caller, $call->message->getRequestId());
        }
    }

    public function onLeaveRealmEvent(Session $session, LeaveRealmEvent $event): void
    {
        $this->registrationStorage->deleteRegistrationBySessionLocal($session->getRealm()->getRealmName(), $session->getSessionId(), function (Registration $registration) use ($session) {
            if ($this->registrationStorage->tryDeleteProcedureFromRegistration($registration)) {
                $session->getRealm()->getMetaSession()->publish('wamp.registration.on_delete', [$session->getSessionId(), $registration->getId()]);
            }
        });

        $this->processLeaveRealmEvent($session->getRealm()->getRealmName(), $session->getSessionId());
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

    public function getName(): string
    {
        return 'dealer';
    }

    public function getFeatures(): object
    {
        $features = new \stdClass();
        $features->shared_registration = true;
        $features->session_meta_api = true;
        $features->registration_meta_api = true;

//        $features->call_canceling = true;
//        $features->progressive_call_results = true;

        return $features;
    }
}
