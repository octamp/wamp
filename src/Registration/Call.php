<?php

namespace Octamp\Wamp\Registration;

use Thruway\Common\Utils;
use Octamp\Wamp\Session\Session;
use Thruway\Message\CallMessage;
use Thruway\Message\CancelMessage;
use Thruway\Message\ErrorMessage;
use Thruway\Message\HelloMessage;
use Thruway\Message\InterruptMessage;
use Thruway\Message\InvocationMessage;
use Thruway\Message\ResultMessage;
use Thruway\Message\YieldMessage;

class Call
{
    private Session $callerSession;

    private ?Session $calleeSession;

    /**
     * @var \Thruway\Message\CallMessage
     */
    private CallMessage $callMessage;

    private ?InvocationMessage $invocationMessage = null;

    /**
     * @var InterruptMessage
     */
    private ?InterruptMessage $interruptMessage = null;

    /**
     * @var CancelMessage
     */
    private ?CancelMessage $cancelMessage = null;

    /**
     * @var boolean
     */
    private bool $isProgressive = false;

    /**
     * @var Registration
     */
    private ?Registration $registration = null;

    /**
     * @var string
     */
    private float $callStart;

    /**
     * @var bool
     */
    private bool $canceling = false;

    /**
     * @var bool
     */
    private bool $discard_result = false;

    /**
     * @var Procedure
     */
    private Procedure $procedure;

    private float $invocationRequestId;

    /**
     * Constructor
     *
     * @param \Thruway\Session $callerSession
     * @param \Thruway\Message\CallMessage $callMessage
     * @param Registration $registration
     */
    public function __construct(
        Session $callerSession,
        CallMessage $callMessage,
        Procedure $procedure
    ) {
        $this->callMessage       = $callMessage;
        $this->callerSession     = $callerSession;
        $this->procedure         = $procedure;

        $this->callStart = microtime(true);
        $this->invocationRequestId = Utils::getUniqueId();
    }

    public function getCallStart(): float
    {
        return $this->callStart;
    }

    /**
     * Process Yield message
     *
     * @param \Thruway\Session $session
     * @param \Thruway\Message\YieldMessage $msg
     * @return bool if we need to keep the call indexed
     */
    public function processYield(Session $session, YieldMessage $msg): bool
    {

        $keepIndex = true;
        $details   = new \stdClass();

        $yieldOptions = $msg->getOptions();
        if (is_object($yieldOptions) && isset($yieldOptions->progress) && $yieldOptions->progress) {
            if ($this->isProgressive()) {
                $details->progress = true;
            } else {
                // not sure what to do here - just going to drop progress
                // if we are getting progress messages that the caller didn't ask for
                return $keepIndex;
            }
        } else {
            $this->getRegistration()->removeCall($this);
            $keepIndex = false;
        }

        $resultMessage = new ResultMessage(
            $this->getCallMessage()->getRequestId(),
            $details,
            $msg->getArguments(),
            $msg->getArgumentsKw()
        );

        $this->getCallerSession()->sendMessage($resultMessage);

        return $keepIndex;
    }

    /**
     * processCancel processes cancel message from the caller.
     * Return true if the Call should be removed from active calls
     *
     * @param Session $session
     * @param CancelMessage $msg
     * @return bool
     */
    public function processCancel(Session $session, CancelMessage $msg): bool
    {
        if ($this->getCallerSession() !== $session) {
            return false;
        }

        if ($this->getCalleeSession() === null) {
            // this call has not been sent to a callee yet (it is in a queue)
            // we can just kill it and say it was canceled
            $errorMsg = ErrorMessage::createErrorMessageFromMessage($msg, "wamp.error.canceled");
            $details = $errorMsg->getDetails() ?: (object)[];
            $details->_thruway_removed_from_queue = true;
            $session->sendMessage($errorMsg);
            return true;
        }

        $details = (object)[];
        if ($this->getCalleeSession()->getHelloMessage() instanceof HelloMessage) {
            $details = $this->getCalleeSession()->getHelloMessage()->getDetails();
        }
        $calleeSupportsCancel = false;
        if (isset($details->roles->callee->features->call_canceling)
            && is_scalar($details->roles->callee->features->call_canceling)) {
            $calleeSupportsCancel = (bool)$details->roles->callee->features->call_canceling;
        }

        if (!$calleeSupportsCancel) {
            $errorMsg = ErrorMessage::createErrorMessageFromMessage($msg);
            $errorMsg->setErrorURI('wamp.error.not_supported');
            $session->sendMessage($errorMsg);
            return false;
        }

        $this->setCancelMessage($msg);

        $this->canceling = true;

        $calleeSession = $this->getCalleeSession();

        $interruptMessage = new InterruptMessage($this->getInvocationRequestId(), (object)[]);
        $calleeSession->sendMessage($interruptMessage);
        $this->setInterruptMessage($interruptMessage);

        if (isset($msg->getOptions()->mode) && is_scalar($msg->getOptions()->mode) && $msg->getOptions()->mode == "killnowait") {
            $errorMsg = ErrorMessage::createErrorMessageFromMessage($msg, "wamp.error.canceled");
            $session->sendMessage($errorMsg);

            return true;
        }

        return false;
    }

    /**
     * Get call message
     *
     * @return \Thruway\Message\CallMessage
     */
    public function getCallMessage(): CallMessage
    {
        return $this->callMessage;
    }

    /**
     * Set call message
     *
     * @param \Thruway\Message\CallMessage $callMessage
     */
    public function setCallMessage(CallMessage $callMessage): void
    {
        $this->callMessage = $callMessage;
    }

    public function getCalleeSession(): ?Session
    {
        return $this->calleeSession;
    }

    public function setCalleeSession(?Session $calleeSession): void
    {
        $this->calleeSession = $calleeSession;
    }

    public function getCallerSession(): Session
    {
        return $this->callerSession;
    }

    /**
     * Set caller session
     *
     * @param \Thruway\Session $callerSession
     */
    public function setCallerSession(Session $callerSession): void
    {
        $this->callerSession = $callerSession;
    }

    /**
     * Get InvocationMessage
     *
     * @throws \Exception
     * @return \Thruway\Message\InvocationMessage
     */
    public function getInvocationMessage(): InvocationMessage
    {
        if ($this->invocationMessage === null) {
            // try to create one
            if ($this->registration === null) {
                throw new \Exception("You must set the registration prior to calling getInvocationMessage");
            }

            if ($this->callMessage === null) {
                throw new \Exception("You must set the CallMessage prior to calling getInvocationMessage");
            }

            $invocationMessage = InvocationMessage::createMessageFrom($this->getCallMessage(), $this->getRegistration());

            $invocationMessage->setRequestId($this->getInvocationRequestId());

            $details = [];

            if ($this->getRegistration()->getDiscloseCaller() === true && $this->getCallerSession()->getAuthenticationDetails()) {
                $authenticationDetails = $this->getCallerSession()->getAuthenticationDetails();
                $details = [
                    "caller"     => $this->getCallerSession()->getSessionId(),
                    "authid"     => $authenticationDetails->getAuthId(),
                    "authrole"   => $authenticationDetails->getAuthRole(),
                    "authroles"  => $authenticationDetails->getAuthRoles(),
                    "authmethod" => $authenticationDetails->getAuthMethod(),
                ];

                if ($authenticationDetails->getAuthExtra() !== null) {
                    $details["authextra"] = $authenticationDetails->getAuthExtra();
                }
            }

            // TODO: check to see if callee supports progressive call
            $callOptions   = $this->getCallMessage()->getOptions();
            $isProgressive = false;
            if (is_object($callOptions) && isset($callOptions->receive_progress) && $callOptions->receive_progress) {
                $details = array_merge($details, ["receive_progress" => true]);
                $isProgressive = true;
            }

            // if nothing was added to details - change ot stdClass so it will serialize correctly
            if (count($details) == 0) {
                $details = new \stdClass();
            }
            $invocationMessage->setDetails($details);

            $this->setIsProgressive($isProgressive);

            $this->setInvocationMessage($invocationMessage);
        }

        return $this->invocationMessage;
    }

    /**
     * Set Invocation message
     *
     * @param \Thruway\Message\InvocationMessage $invocationMessage
     */
    public function setInvocationMessage(?InvocationMessage $invocationMessage): void
    {
        $this->invocationMessage = $invocationMessage;
    }

    /**
     * update state is progressive
     *
     * @param boolean $isProgressive
     */
    public function setIsProgressive(bool $isProgressive): void
    {
        $this->isProgressive = $isProgressive;
    }

    /**
     * Get state is progressive
     *
     * @return boolean
     */
    public function getIsProgressive(): bool
    {
        return $this->isProgressive;
    }

    /**
     * Check is progressive
     *
     * @return boolean
     */
    public function isProgressive(): bool
    {
        return $this->isProgressive;
    }

    /**
     * Get registration
     *
     * @return Registration
     */
    public function getRegistration(): ?Registration
    {
        return $this->registration;
    }

    /**
     * @param Registration $registration
     */
    public function setRegistration(?Registration $registration): void
    {
        $this->invocationMessage = null;
        if ($registration === null) {
            $this->setCalleeSession(null);
        } else {
            $this->setCalleeSession($registration->getSession());
        }

        $this->registration = $registration;
    }

    /**
     * @return mixed
     */
    public function getInvocationRequestId(): float
    {
        return $this->invocationRequestId;
    }

    /**
     * @return CancelMessage
     */
    public function getCancelMessage(): ?CancelMessage
    {
        return $this->cancelMessage;
    }

    /**
     * @param CancelMessage $cancelMessage
     */
    public function setCancelMessage(?CancelMessage $cancelMessage): void
    {
        $this->cancelMessage = $cancelMessage;
    }

    /**
     * @return InterruptMessage
     */
    public function getInterruptMessage(): ?InterruptMessage
    {
        return $this->interruptMessage;
    }

    /**
     * @param InterruptMessage $interruptMessage
     */
    public function setInterruptMessage(?InterruptMessage $interruptMessage): void
    {
        $this->interruptMessage = $interruptMessage;
    }

    /**
     * @return Procedure
     */
    public function getProcedure(): Procedure
    {
        return $this->procedure;
    }
}