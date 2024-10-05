<?php

declare(strict_types=1);

namespace Octamp\Wamp\Registration;

use Octamp\Wamp\Matcher\ExactMatch;
use Octamp\Wamp\Matcher\MatchInterface;
use Octamp\Wamp\Promise\Promise;
use Octamp\Wamp\Adapter\AdapterInterface;
use Octamp\Wamp\Promise\PromiseInterface;
use Octamp\Wamp\Session\Session;
use Octamp\Wamp\Session\SessionStorage;
use OpenSwoole\Coroutine;
use Thruway\Message\CallMessage;
use Thruway\Message\ErrorMessage;
use Thruway\Message\Message;
use Thruway\Message\RegisteredMessage;
use Thruway\Message\RegisterMessage;
use Thruway\Message\UnregisteredMessage;
use Thruway\Message\UnregisterMessage;

class Procedure
{
    /**
     * @var Registration[]
     */
    private array $registrations;

    private \SplQueue $callQueue;

    private \DateTimeImmutable $createdAt;

    /**
     * Constructor
     *
     * @param string $procedureName
     */
    public function __construct(protected AdapterInterface $adapter, protected SessionStorage $sessionStorage, private readonly string $realmName, protected string $procedureName, protected object $options = new \stdClass(), public bool $processSets = true)
    {
        $this->registrations = [];
        $this->createdAt = new \DateTimeImmutable();
        $this->callQueue = new \SplQueue();
    }

    /**
     * Process register
     *
     * @param Session $session
     * @param \Thruway\Message\RegisterMessage $msg
     * @return \Octamp\Wamp\Promise\Promise;
     * @throws \Exception
     */
    public function processRegister(Session $session, RegisterMessage $msg, ?Registration $registration = null): PromiseInterface
    {
        return Promise::create(function ($resolve, $reject) use ($session, $msg, $registration) {
            if ($registration === null) {
                $registration = Registration::createRegistrationFromRegisterMessage($session, $msg, $this->adapter);
            }

            if ($this->hasRegistrations(true)) {
                // we already have something registered
                if ($this->getAllowMultipleRegistrations()) {
                    $resolve($this->addRegistration($registration, $msg));
                    $session->getRealm()->getMetaSession()->publish('wamp.registration.on_register', [$session->getSessionId(), $registration->getId()]);
                    return;
                } else {
                    // we are not allowed multiple registrations, but we may want
                    // to replace an orphaned session
                    $errorMsg = ErrorMessage::createErrorMessageFromMessage($msg, 'wamp.error.procedure_already_exists');
                    $options = $msg->getOptions();
                    $oldRegistration = $this->getFirstRegistration();

                    try {
                        $promise = $oldRegistration->getSession()->ping()
                            ->then(function ($res) use ($session, $errorMsg) {
                                // the ping came back - send procedure_already_exists
                                $session->sendMessage($errorMsg);

                                return false;
                            }, function ($r) use ($oldRegistration, $session, $registration, $msg) {
                                // bring down the exiting session because the
                                // ping timed out
                                $deadSession = $oldRegistration->getSession();

                                // this should do all the cleanup needed and remove the
                                // registration from this procedure also
                                $deadSession->shutdown();

                                // complete this registration now
                                $this->setDiscloseCaller($registration->getDiscloseCaller());
                                $this->setAllowMultipleRegistrations($registration->getAllowMultipleRegistrations());
                                $this->setInvokeType($registration->getInvokeType());

                                $added = $this->addRegistration($registration, $msg);

                                $session->getRealm()->getMetaSession()->publish('wamp.registration.on_register', [$session->getSessionId(), $registration->getId()]);

                                return $added;
                            });

                        $result = $promise->wait();
                        $resolve($result);
                        return;
                    } catch (\Exception $e) {
                        $session->sendMessage($errorMsg);
                    }
                }
            } else {
                if ($this->processSets) {
                    // setup the procedure to match the options
                    $this->setDiscloseCaller($registration->getDiscloseCaller());
                    $this->setAllowMultipleRegistrations($registration->getAllowMultipleRegistrations());
                    $this->setInvokeType($registration->getInvokeType());
                }

                $resolve($this->addRegistration($registration, $msg));

                $session->getRealm()->getMetaSession()->publish('wamp.registration.on_create', [$session->getSessionId(), (object)[
                    'id' => $registration->getId(),
                    'created' => $this->getCreatedAt()->format('c'),
                    'uri' => $this->getProcedureName(),
                    'match' => $this->getMatch()->getName(),
                    'invoke' => $this->getInvokeType(),
                ]]);
                $session->getRealm()->getMetaSession()->publish('wamp.registration.on_register', [$session->getSessionId(), $registration->getId()]);
                return;
            }

            $resolve(false);
        });
    }

    /**
     * Add registration
     *
     * @param \Thruway\Registration $registration
     * @return bool
     * @throws \Exception
     */
    private function addRegistration(Registration $registration, RegisterMessage $msg): bool
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

            $this->saveRegistration($registration, $msg);

            $registration->getSession()->sendMessage(new RegisteredMessage($msg->getRequestId(), $registration->getId()));

            return true;
        } catch (\Exception $e) {
            $registration->getSession()->sendMessage(ErrorMessage::createErrorMessageFromMessage($msg));

            return false;
        }
    }

    public function saveRegistration(Registration $registration, RegisterMessage $message): void
    {
        $this->registrations[$registration->getSession()->getTransportId() . ':' . $registration->getId()] = $registration;

        $session = $registration->getSession();
        $id =  $session->getTransportId() . ':' . $registration->getId();
        $parsedId = $registration->getParsedId();
        $this->adapter->setField('procregs:' . $this->getGlobalName(), $id, [
            'id' => $registration->getId(),
            'routerId' => $parsedId->routerId,
            'registrationId' => $parsedId->id,
            'sessionId' => $session->getId(),
            'transportId' => $session->getTransportId(),
            'message' => $message->getMessageParts(),
            'realm' => $registration->getRealm()->getRealmName(),
        ]);
    }

    /**
     * Get registration by ID
     *
     * @param $registrationId
     * @return bool|Registration
     */
    public function getRegistrationById(Session $session, $registrationId): ?Registration
    {
        $key = $session->getTransportId() . ':' . $registrationId;

        return $this->getRegistrationByKey($key);
    }

    public function getRegistrationByKey(string $key): ?Registration
    {
        if (isset($this->registrations[$key])) {
            return $this->registrations[$key];
        }

        $procedureRegKey = 'procregs:' . $this->getGlobalName();
        $result = $this->adapter->get($procedureRegKey, [$key]);

        $registrationRaw = $result[$key] ?? null;
        if ($registrationRaw !== null) {
            return $this->generateRegistrationFromRaw($registrationRaw);
        }

        return null;
    }

    public function processUnregister(Session $session, UnregisterMessage $msg): bool
    {
        $registration = $this->getRegistrationById($session, $msg->getRegistrationId());
        if ($registration === null) {
            $session->sendMessage(ErrorMessage::createErrorMessageFromMessage($msg, 'wamp.error.no_such_registration'));
            return false;
        }
        $key = $session->getTransportId() . ':' . $registration->getId();
        unset($this->registrations[$key]);
        $this->adapter->del('procregs:' . $this->getGlobalName(), [$key]);

        Coroutine::create(function () use ($session, $msg) {
            $this->cancelCalls($session, $msg->getRegistrationId());
        });

        $session->sendMessage(UnregisteredMessage::createFromUnregisterMessage($msg));

        $session->getRealm()->getMetaSession()->publish('wamp.registration.on_unregister', [$session->getSessionId(), $msg->getRegistrationId()]);

        return true;
    }

    public function processCallMessage(Session $session, CallMessage $message): bool
    {
        if (!$this->hasRegistrations()) {
            $error = ErrorMessage::createErrorMessageFromMessage($message, 'wamp.error.no_such_procedure');
            $error->setArgumentsKw((object)['topic' => $message->getProcedureName()]);

            $session->sendMessage($error);
            return false;
        }

        $registration = $this->getRegistrationForCall();
        if ($registration === null) {
            $error = ErrorMessage::createErrorMessageFromMessage($message, 'wamp.error.no_such_procedure');
            $error->setArgumentsKw((object)['topic' => $message->getProcedureName()]);

            $session->sendMessage($error);
            return false;
        }

        $call = new Call($session, $message, $this);
        $registration->processCall($call);

        return true;
    }

    public function processCall(Session $session, Call $call): bool
    {
        // find a registration to call
        if (!$this->hasRegistrations()) {
            $session->sendMessage(ErrorMessage::createErrorMessageFromMessage($call->getCallMessage(), 'wamp.error.no_such_procedure'));
            return false;
        }

        $registration = $this->getRegistrationForCall();

        if ($registration === null) {
            $session->sendMessage(ErrorMessage::createErrorMessageFromMessage($call->getCallMessage(), 'wamp.error.no_such_procedure'));
            return false;
        }

        $registration->processCall($call);

        return true;
    }

    protected function getRegistrationForCall(): ?Registration
    {
        $registration = null;
        // just send it to the first one if we don't allow multiple registrations
        if (!$this->getAllowMultipleRegistrations()) {
            $registration = $this->getFirstRegistration();
        } elseif (strcasecmp($this->getInvokeType(), Registration::FIRST_REGISTRATION) === 0) {
            $registration = $this->getFirstRegistration();
        } elseif (strcasecmp($this->getInvokeType(), Registration::LAST_REGISTRATION) === 0) {
            $registration = $this->getLastRegistration();
        } elseif (strcasecmp($this->getInvokeType(), Registration::RANDOM_REGISTRATION) === 0) {
            $registration = $this->getRandomRegistration();
        } elseif (strcasecmp($this->getInvokeType(), Registration::ROUNDROBIN_REGISTRATION) === 0) {
            $registration = $this->getRoundRobinRegistration();
        }

        return $registration;
    }

    public function hasRegistrations(bool $global = true): bool
    {
        if ($global) {
            $registrations = $this->adapter->hkeys('procregs:' . $this->getGlobalName());

            return !empty($registrations);
        }

        return !empty($this->registrations);
    }

    public function getFirstRegistration(): ?Registration
    {
        $procedureRegKey = 'procregs:' . $this->getGlobalName();
        $keys = $this->adapter->hkeys($procedureRegKey);
        if (count($keys) === 0) {
            return null;
        }

        return $this->getRegistrationByKey($keys[0]);
    }

    public function getLastRegistration(): ?Registration
    {
        $keys = $this->adapter->hkeys('procregs:' . $this->getGlobalName());
        if (count($keys) === 0) {
            return null;
        }

        $index = count($keys) - 1;

        return $this->getRegistrationByKey($keys[$index]);
    }

    public function getRandomRegistration(): ?Registration
    {
        $keys = $this->adapter->hkeys('procregs:' . $this->getGlobalName());
        if (count($keys) === 0) {
            return null;
        }

        $index = array_rand($keys);

        return $this->getRegistrationByKey($keys[$index]);
    }

    public function getRoundRobinRegistration(): ?Registration
    {
        $index = $this->adapter->inc('proc:' . $this->getGlobalName(), 1, 'lastCallIndex');
        $totalRegistration = $this->adapter->countFields('procregs:' . $this->getGlobalName());
        if ($index >= $totalRegistration) {
            $index = 0;
            $this->adapter->setField('proc:' . $this->getGlobalName(), 'lastCallIndex', 0);
        }
        $keys = $this->adapter->hkeys('procregs:' . $this->getGlobalName());
        if (count($keys) === 0) {
            return null;
        }

        return $this->getRegistrationByKey($keys[$index]);
    }

    protected function generateRegistrationFromRaw(array $registrationRaw): Registration
    {
        $id = $registrationRaw['transportId'] . ':' . $registrationRaw['id'];

        if (isset($this->registrations[$id])) {
            return $this->registrations[$id];
        }

        $registerMessage = RegisterMessage::createMessageFromArray($registrationRaw['message']);
        $session = $this->sessionStorage->getSessionUsingTransportId($registrationRaw['transportId']);

        $registration = Registration::createRegistrationFromRegisterMessage($session, $registerMessage, $this->adapter, $registrationRaw['id']);

        return $registration;
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
        return $this->options->disclose_caller;
    }

    public function setDiscloseCaller(bool $discloseCaller): void
    {
        $this->options->disclose_caller = $discloseCaller;
    }

    public function getInvokeType(): string
    {
        return $this->options->invoke ?? 'single';
    }

    public function setInvokeType(string $invoke): void
    {
        $this->options->invoke = $invoke;
    }

    public function isAllowMultipleRegistrations(): bool
    {
        return $this->getAllowMultipleRegistrations();
    }

    public function getAllowMultipleRegistrations(): bool
    {
        return $this->getInvokeType() !== 'single';
    }

    /**
     * @param boolean $allowMultipleRegistrations
     * @deprecated this will do nothing
     */
    public function setAllowMultipleRegistrations(bool $allowMultipleRegistrations): void
    {
        // $this->allowMultipleRegistrations = $allowMultipleRegistrations;
    }

    public function getRegistrations(): array
    {
        return $this->registrations;
    }

    public function leave(Session $session): ?Registration
    {
        $lastRegistration = null;
        // remove all registrations that belong to this session
        /* @var $registration Registration */
        foreach ($this->registrations as $i => $registration) {
            if ($registration->getSession()->getTransportId() !== $session->getTransportId()) {
                continue;
            }

            $key = $session->getTransportId() . ':' . $registration->getId();
            unset($this->registrations[$key]);
            $this->adapter->del('procregs:' . $this->getGlobalName(), [$key]);

            $lastRegistration = $registration;

            Coroutine::create(function () use ($session, $registration) {
                $this->cancelCalls($session, $registration->getId());
            });
        }

        return $lastRegistration;
    }

    protected function cancelCalls(Session $session, string|int $registrationId): array
    {
        $key = Registration::generateKeyForInvocation('*', $session->getSessionId(), $registrationId, '*', '*');
        $results = $this->adapter->find($key);

        foreach ($results as $result) {
            $id = Registration::generateKeyForInvocation(
                $result['callSessionId'],
                $session->getSessionId(),
                $registrationId,
                $result['invocationId'],
                $result['callRequestId']
            );
            $this->adapter->del($id);
            $callerSession = $this->sessionStorage->getSessionUsingTransportId($result['callTransportId']);
            if ($callerSession !== null) {
                $callerSession->sendMessage(new ErrorMessage(Message::MSG_CALL, $result['callRequestId'], new \stdClass(), 'wamp.error.cancelled', [], new \stdClass()));
            }
        }

        return $results;
    }

    /**
     * todo: This was part of the manager stuff - but may be used by some tests
     *
     */
    public function managerGetRegistrations(): array
    {
        $registrations = $this->getRegistrations();

        $regInfo = [];
        /** @var Registration $reg */
        foreach ($registrations as $reg) {
            $regInfo[] = [
                'id' => $reg->getId(),
                "invoke" => $reg->getInvokeType(),
                "disclose_caller" => $reg->getDiscloseCaller(),
                "session" => $reg->getSession()->getSessionId(),
                "authid" => $reg->getSession()->getAuthenticationDetails()->getAuthId(),
                "statistics" => $reg->getStatistics()
            ];
        }

        return $regInfo;
    }

    public function getRealmName(): string
    {
        return $this->realmName;
    }

    public function getGlobalName(): string
    {
        return static::generateGlobalName($this->getRealmName(), $this->getProcedureName());
    }

    public static function generateGlobalName(string $realmName, string $name): string
    {
        return $realmName . ':' . $name;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getMatch(): string
    {
        return $this->options->match ?? 'exact';
    }

    public function setMatch(string $match): void
    {
        $this->options->match = $match;
    }
}
