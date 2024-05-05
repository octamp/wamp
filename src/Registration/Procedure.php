<?php
declare(strict_types=1);

namespace Octamp\Wamp\Registration;

use Octamp\Wamp\Session\Session;
use Thruway\Message\ErrorMessage;
use Thruway\Message\RegisteredMessage;
use Thruway\Message\RegisterMessage;
use Thruway\Message\UnregisteredMessage;
use Thruway\Message\UnregisterMessage;

class Procedure
{
    private string $procedureName;

    /**
     * @var Registration[]
     */
    private array $registrations;

    private bool $allowMultipleRegistrations;

    private string $invokeType;

    private bool $discloseCaller;

    private \SplQueue $callQueue;

    /**
     * Constructor
     *
     * @param string $procedureName
     */
    public function __construct(string $procedureName)
    {
        $this->setProcedureName($procedureName);

        $this->registrations = [];
        $this->allowMultipleRegistrations = false;
        $this->invokeType = Registration::SINGLE_REGISTRATION;
        $this->discloseCaller = false;

        $this->callQueue = new \SplQueue();
    }

    /**
     * Process register
     *
     * @param Session $session
     * @param \Thruway\Message\RegisterMessage $msg
     * @return bool
     * @throws \Exception
     */
    public function processRegister(Session $session, RegisterMessage $msg): bool
    {
        $registration = Registration::createRegistrationFromRegisterMessage($session, $msg);

        if (count($this->registrations) > 0) {
            // we already have something registered
            if ($this->getAllowMultipleRegistrations()) {
                return $this->addRegistration($registration, $msg);
            } else {
                // we are not allowed multiple registrations, but we may want
                // to replace an orphaned session
                $errorMsg = ErrorMessage::createErrorMessageFromMessage($msg, 'wamp.error.procedure_already_exists');

                $options = $msg->getOptions();
                // get the existing registration
                /** @var Registration $oldRegistration */
                $oldRegistration = $this->registrations[0];
                if (isset($options->replace_orphaned_session) && $options->replace_orphaned_session == "yes") {
                    try {
                        $oldRegistration->getSession()->ping()
                            ->then(function ($res) use ($session, $errorMsg) {
                                // the ping came back - send procedure_already_exists
                                $session->sendMessage($errorMsg);
                            }, function ($r) use ($oldRegistration, $session, $registration, $msg) {
                                // bring down the exiting session because the
                                // ping timed out
                                $deadSession = $oldRegistration->getSession();

                                // this should do all the cleanup needed and remove the
                                // registration from this procedure also
                                $deadSession->shutdown();

                                // complete this registration now
                                return $this->addRegistration($registration, $msg);
                            });
                    } catch (\Exception $e) {
                        $session->sendMessage($errorMsg);
                    }
                } else {
                    $session->sendMessage($errorMsg);
                }
            }
        } else {
            // this is the first registration
            // setup the procedure to match the options
            $this->setDiscloseCaller($registration->getDiscloseCaller());
            $this->setAllowMultipleRegistrations($registration->getAllowMultipleRegistrations());
            $this->setInvokeType($registration->getInvokeType());

            return $this->addRegistration($registration, $msg);
        }
    }

    /**
     * Add registration
     *
     * @param \Thruway\Registration $registration
     * @return bool
     * @throws \Exception
     */
    private function addRegistration(Registration $registration, RegisterMessage $msg)
    {
        try {
            // make sure the uri is exactly the same
            if ($registration->getProcedureName() != $this->getProcedureName()) {
                throw new \Exception('Attempt to add registration to procedure with different procedure name.');
            }

            // make sure options match
            if (strcasecmp($registration->getInvokeType(), $this->getInvokeType()) != 0) {
                throw new \Exception('Registration and procedure must agree on invocation type');
            }
            if ($registration->getDiscloseCaller() != $this->getDiscloseCaller()) {
                throw new \Exception('Registration and procedure must agree on disclose caller');
            }

            $this->registrations[] = $registration;

            $registration->getSession()->sendMessage(new RegisteredMessage($msg->getRequestId(), $registration->getId()));

            // now that we have added a new registration, process the queue if we are using it
            if ($this->getAllowMultipleRegistrations()) {
                $this->processQueue();
            }

            return true;
        } catch (\Exception $e) {
            $registration->getSession()->sendMessage(ErrorMessage::createErrorMessageFromMessage($msg));

            return false;
        }
    }

    /**
     * Get registration by ID
     *
     * @param $registrationId
     * @return bool|Registration
     */
    public function getRegistrationById($registrationId): ?Registration
    {
        /** @var Registration $registration */
        foreach ($this->registrations as $registration) {
            if ($registration->getId() == $registrationId) {
                return $registration;
            }
        }

        return null;
    }

    public function processUnregister(Session $session, UnregisterMessage $msg): bool
    {
        for ($i = 0; $i < count($this->registrations); $i++) {
            /** @var Registration $registration */
            $registration = $this->registrations[$i];
            if ($registration->getId() == $msg->getRegistrationId()) {

                // make sure the session is the correct session
                if ($registration->getSession() !== $session) {
                    $session->sendMessage(ErrorMessage::createErrorMessageFromMessage($msg, "wamp.error.no_such_registration"));
                    //$this->manager->warning("Tried to unregister a procedure that belongs to a different session.");
                    return false;
                }

                array_splice($this->registrations, $i, 1);

                // TODO: need to handle any calls that are hanging around

                $session->sendMessage(UnregisteredMessage::createFromUnregisterMessage($msg));
                return true;
            }
        }

        $session->sendMessage(ErrorMessage::createErrorMessageFromMessage($msg, 'wamp.error.no_such_registration'));

        return false;
    }

    public function processCall(Session $session, Call $call): bool
    {
        // find a registration to call
        if (count($this->registrations) == 0) {
            $session->sendMessage(ErrorMessage::createErrorMessageFromMessage($call->getCallMessage(), 'wamp.error.no_such_procedure'));
            return false;
        }

        // just send it to the first one if we don't allow multiple registrations
        if (!$this->getAllowMultipleRegistrations()) {
            $this->registrations[0]->processCall($call);
        } else {
            $this->callQueue->enqueue($call);
            $this->processQueue();
        }

        return true;
    }

    /**
     * Process the Queue
     *
     * @throws \Exception
     */
    public function processQueue(): void
    {
        if (!$this->getAllowMultipleRegistrations()) {
            throw new \Exception("Queuing only allowed when there are multiple registrations");
        }

        // find the best candidate
        while ($this->callQueue->count() > 0) {
            $registration = null;

            if (strcasecmp($this->getInvokeType(), Registration::FIRST_REGISTRATION) === 0) {
                $registration = $this->getNextFirstRegistration();
            } else if (strcasecmp($this->getInvokeType(), Registration::LAST_REGISTRATION) === 0) {
                $registration = $this->getNextLastRegistration();
            } else if (strcasecmp($this->getInvokeType(), Registration::RANDOM_REGISTRATION) === 0) {
                $registration = $this->getNextRandomRegistration();
            } else if (strcasecmp($this->getInvokeType(), Registration::ROUNDROBIN_REGISTRATION) === 0) {
                $registration = $this->getNextRoundRobinRegistration();
            }

            if ($registration === null) {
                break;
            }
            $call = $this->callQueue->dequeue();
            $registration->processCall($call);
        }
    }

    private function getNextRandomRegistration(): Registration
    {
        if (count($this->registrations) === 1) {
            //just return this so that we don't have to run mt_rand
            return $this->registrations[0];
        }
        //mt_rand is apparently faster than array_rand(which uses the libc generator)
        return $this->registrations[mt_rand(0, count($this->registrations) - 1)];
    }

    private function getNextRoundRobinRegistration(): Registration
    {
        $bestRegistration = $this->registrations[0];
        foreach ($this->registrations as $registration) {
            if ($registration->getStatistics()['lastCallStartedAt'] <
                $bestRegistration->getStatistics()['lastCallStartedAt']) {
                $bestRegistration = $registration;
                break;
            }
        }
        return $bestRegistration;
    }

    private function getNextFirstRegistration(): Registration
    {
        return $this->registrations[0];
    }

    private function getNextLastRegistration(): Registration
    {
        return $this->registrations[count($this->registrations) - 1];
    }

    /**
     * Remove all references to Call to it can be GCed
     *
     * @param Call $call
     */
    public function removeCall(Call $call): void
    {
        $newQueue = new \SplQueue();
        while (!$this->callQueue->isEmpty()) {
            $c = $this->callQueue->dequeue();

            if ($c === $call)
                continue;

            $newQueue->enqueue($c);
        }
        $this->callQueue = $newQueue;

        $registration = $call->getRegistration();
        if ($registration) {
            $registration->removeCall($call);
        }
    }

    public function getCallByRequestId(int $requestId): ?Call
    {
        foreach ($this->registrations as $registration) {
            $call = $registration->getCallByRequestId($requestId);
            if ($call) {
                return $call;
            }
        }

        return null;
    }

    public function getProcedureName(): string
    {
        return $this->procedureName;
    }

    private function setProcedureName(string $procedureName): void
    {
        $this->procedureName = $procedureName;
    }

    public function isDiscloseCaller(): bool
    {
        return $this->getDiscloseCaller();
    }

    public function getDiscloseCaller(): bool
    {
        return $this->discloseCaller;
    }

    public function setDiscloseCaller(bool $discloseCaller): void
    {
        $this->discloseCaller = $discloseCaller;
    }

    public function getInvokeType(): string
    {
        return $this->invokeType;
    }

    public function setInvokeType(string $invoketype): void
    {
        $this->invokeType = $invoketype;
    }

    public function isAllowMultipleRegistrations(): bool
    {
        return $this->getAllowMultipleRegistrations();
    }

    public function getAllowMultipleRegistrations(): bool
    {
        return $this->allowMultipleRegistrations;
    }

    /**
     * @param boolean $allowMultipleRegistrations
     */
    public function setAllowMultipleRegistrations(bool $allowMultipleRegistrations): void
    {
        $this->allowMultipleRegistrations = $allowMultipleRegistrations;
    }

    public function getRegistrations(): array
    {
        return $this->registrations;
    }

    public function leave(Session $session): void
    {
        // remove all registrations that belong to this session
        /* @var $registration Registration */
        foreach ($this->registrations as $i => $registration) {
            if ($registration->getSession() === $session) {
                // if this session is the callee on pending calls - error them out
                $registration->errorAllPendingCalls();
                array_splice($this->registrations, $i, 1);
            }
        }
    }

    /**
     * todo: This was part of the manager stuff - but may be used by some tests
     *
     */
    public function managerGetRegistrations()
    {
        $registrations = $this->getRegistrations();

        $regInfo = [];
        /** @var Registration $reg */
        foreach ($registrations as $reg) {
            $regInfo[] = [
                'id' => $reg->getId(),
                "invoke" => $reg->getInvokeType(),
                "thruway_multiregister" => $reg->getAllowMultipleRegistrations(),
                "disclose_caller" => $reg->getDiscloseCaller(),
                "session" => $reg->getSession()->getSessionId(),
                "authid" => $reg->getSession()->getAuthenticationDetails()->getAuthId(),
                "statistics" => $reg->getStatistics()
            ];
        }
    }
}