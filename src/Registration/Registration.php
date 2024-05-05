<?php

namespace Octamp\Wamp\Registration;

use Octamp\Wamp\Session\Session;
use Thruway\Common\Utils;
use Thruway\Message\ErrorMessage;
use Thruway\Message\RegisterMessage;

/**
 * Class Registration
 *
 * @package Thruway
 */
class Registration
{

    private int $id;

    private Session $session;

    private string $procedureName;

    /**
     * @var bool
     */
    private bool $discloseCaller;

    /**
     * @var bool
     */
    private bool $allowMultipleRegistrations;

    /**
     * @var string
     */
    private string $invokeType;

    /**
     * @var Call[]
     */
    private array $calls;

    /**
     * This holds the count of total invocations
     *
     * @var int
     */
    private int $invocationCount;

    /**
     * @var \DateTime
     */
    private \DateTime $registeredAt;

    /**
     * @var int
     */
    private int $busyTime;

    /**
     * @var int
     */
    private int $maxSimultaneousCalls;

    /**
     * @var int
     */
    private int $invocationAverageTime;

    /**
     * @var null|\DateTime
     */
    private ?\DateTime $lastCallStartedAt;

    /**
     * @var null|\DateTime
     */
    private ?\DateTime $lastIdledAt;

    /**
     * @var string|null
     */
    private ?string $busyStart;

    /**
     * @var float
     */
    private float $completedCallTimeTotal;

    const SINGLE_REGISTRATION = 'single';
    const THRUWAY_REGISTRATION = '_thruway';
    const ROUNDROBIN_REGISTRATION = 'roundrobin';
    const RANDOM_REGISTRATION = 'random';
    const FIRST_REGISTRATION = 'first';
    const LAST_REGISTRATION = 'last';

    /**
     * Constructor
     *
     * @param \Thruway\Session $session
     * @param string $procedureName
     */
    public function __construct(Session $session, string $procedureName)
    {
        $this->id = Utils::getUniqueId();
        $this->session = $session;
        $this->procedureName = $procedureName;
        $this->allowMultipleRegistrations = false;
        $this->invokeType = 'single';
        $this->discloseCaller = false;
        $this->calls = [];
        $this->registeredAt = new \DateTime();
        $this->invocationCount = 0;
        $this->busyTime = 0;
        $this->invocationAverageTime = 0;
        $this->maxSimultaneousCalls = 0;
        $this->lastCallStartedAt = null;
        $this->lastIdledAt = $this->registeredAt;
        $this->busyStart = null;
        $this->completedCallTimeTotal = 0;
    }

    public static function createRegistrationFromRegisterMessage(Session $session, RegisterMessage $msg): Registration
    {
        $registration = new Registration($session, $msg->getProcedureName());
        $options = $msg->getOptions();

        if (isset($options->disclose_caller) && $options->disclose_caller === true) {
            $registration->setDiscloseCaller(true);
        }

        if (isset($options->invoke)) {
            $registration->setInvokeType($options->invoke);
        } else {
            if (isset($options->thruway_multiregister) && $options->thruway_multiregister === true) {
                $registration->setInvokeType(Registration::THRUWAY_REGISTRATION);
            } else {
                $registration->setInvokeType(Registration::SINGLE_REGISTRATION);
            }
        }

        return $registration;
    }

    /**
     * @return boolean
     */
    public function getAllowMultipleRegistrations(): bool
    {
        return $this->allowMultipleRegistrations;
    }

    /**
     * @return boolean
     */
    public function isAllowMultipleRegistrations(): bool
    {
        return $this->getAllowMultipleRegistrations();
    }

    /**
     * @param boolean $allowMultipleRegistrations
     */
    public function setAllowMultipleRegistrations(bool $allowMultipleRegistrations): bool
    {
        $this->allowMultipleRegistrations = $allowMultipleRegistrations;
    }

    /**
     *
     * @return String
     */
    public function getInvokeType(): string
    {
        return $this->invokeType;
    }

    /**
     *
     * @param String $type
     */
    public function setInvokeType(string $type): void
    {
        $type = strtolower($type);
        $allowedRegistrations = array(
            Registration::SINGLE_REGISTRATION,
            Registration::ROUNDROBIN_REGISTRATION,
            Registration::RANDOM_REGISTRATION,
            Registration::THRUWAY_REGISTRATION,
            Registration::FIRST_REGISTRATION,
            Registration::LAST_REGISTRATION
        );
        if (in_array($type, $allowedRegistrations)) {
            if ($type !== Registration::SINGLE_REGISTRATION) {
                $this->invokeType = $type;
                $this->setAllowMultipleRegistrations(true);
            } else {
                $this->invokeType = Registration::SINGLE_REGISTRATION;
                $this->setAllowMultipleRegistrations(false);
            }
        }
    }

    /**
     * Process call
     *
     * @param Call $call
     * @throws \Exception
     */
    public function processCall(Call $call): void
    {
        if ($call->getRegistration() !== null) {
            throw new \Exception("Registration already set when asked to process call");
        }
        $call->setRegistration($this);

        $this->calls[] = $call;

        $this->session->incPendingCallCount();
        $callCount = count($this->calls);
        if ($callCount == 1) {
            // we just became busy
            $this->busyStart = microtime(true);
        }
        if ($callCount > $this->maxSimultaneousCalls) {
            $this->maxSimultaneousCalls = $callCount;
        }
        $this->invocationCount++;
        $this->lastCallStartedAt = new \DateTime();

        $this->getSession()->sendMessage($call->getInvocationMessage());
    }

    /**
     * Get call by request ID
     *
     * @param int $requestId
     * @return boolean
     */
    public function getCallByRequestId(int $requestId): ?Call
    {
        /** @var Call $call */
        foreach ($this->calls as $call) {
            if ($call->getInvocationMessage()->getRequestId() == $requestId) {
                return $call;
            }
        }

        return null;
    }

    public function removeCall(Call $callToRemove): void
    {
        /* @var $call \Thruway\Call */
        foreach ($this->calls as $i => $call) {
            if ($callToRemove === $call) {
                array_splice($this->calls, $i, 1);
                $this->session->decPendingCallCount();
                $callEnd = microtime(true);

                // average call time
                $callsInAverage = $this->invocationCount - count($this->calls) - 1;

                // add this call time into the total
                $this->completedCallTimeTotal += $callEnd - $call->getCallStart();
                $callsInAverage++;
                $this->invocationAverageTime = ((float) $this->completedCallTimeTotal) / $callsInAverage;

                if (count($this->calls) == 0) {
                    $this->lastIdledAt = new \DateTime();
                    if ($this->busyStart !== null) {
                        $this->busyTime = $this->busyTime + ($callEnd - $this->busyStart);
                        $this->busyStart = null;
                    }
                }
            }
        }
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getProcedureName(): string
    {
        return $this->procedureName;
    }

    public function getSession(): Session
    {
        return $this->session;
    }

    public function getDiscloseCaller(): bool
    {
        return $this->discloseCaller;
    }

    public function setDiscloseCaller($discloseCaller): void
    {
        $this->discloseCaller = $discloseCaller;
    }

    public function getCurrentCallCount(): int
    {
        return count($this->calls);
    }

    public function errorAllPendingCalls(): void
    {
        foreach ($this->calls as $call) {
            $call->getCallerSession()->sendMessage(ErrorMessage::createErrorMessageFromMessage($call->getCallMessage(), 'wamp.error.canceled'));
        }
    }

    public function getStatistics(): array
    {
        return [
            'currentCallCount' => count($this->calls),
            'registeredAt' => $this->registeredAt,
            'invocationCount' => $this->invocationCount,
            'invocationAverageTime' => $this->invocationAverageTime,
            'busyTime' => $this->busyTime,
            'busyStart' => $this->busyStart,
            'lastIdledAt' => $this->lastIdledAt,
            'lastCallStartedAt' => $this->lastCallStartedAt,
            'completedCallTimeTotal' => $this->completedCallTimeTotal
        ];
    }

}