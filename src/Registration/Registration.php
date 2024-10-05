<?php

declare(strict_types=1);

namespace Octamp\Wamp\Registration;

use DateTimeInterface;
use Octamp\Wamp\Adapter\AdapterInterface;
use Octamp\Wamp\Helper\IDHelper;
use Octamp\Wamp\Matcher\ExactMatch;
use Octamp\Wamp\Realm\Realm;
use Octamp\Wamp\Session\Session;
use Octamp\Wamp\Session\SessionStorage;
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

    private string|int $id;

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
    private \DateTimeImmutable $registeredAt;

    /**
     * @var int
     */
    private int $busyTime;

    /**
     * @var int
     */
    private int $maxSimultaneousCalls;

    /**
     * @var float
     */
    private float $invocationAverageTime;

    /**
     * @var null|\DateTime
     */
    private ?\DateTimeImmutable $lastCallStartedAt;

    /**
     * @var null|\DateTime
     */
    private ?\DateTimeImmutable $lastIdledAt;

    /**
     * @var string|null
     */
    private ?string $busyStart;

    /**
     * @var float
     */
    private float $completedCallTimeTotal;

    private string $match;

    const SINGLE_REGISTRATION = 'single';
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
    public function __construct(protected Session $session, protected AdapterInterface $adapter, protected string $procedureName, protected object $options = new \stdClass(), ?string $id = null)
    {
        $this->id = $id ?? IDHelper::generateRouterWampID($session->getServerId());

        $this->allowMultipleRegistrations = false;
        $this->invokeType = 'single';
        $this->discloseCaller = false;
        $this->calls = [];
        $this->registeredAt = new \DateTimeImmutable();
        $this->invocationCount = 0;
        $this->busyTime = 0;
        $this->invocationAverageTime = 0;
        $this->maxSimultaneousCalls = 0;
        $this->lastCallStartedAt = new \DateTimeImmutable();
        $this->lastIdledAt = $this->registeredAt;
        $this->busyStart = null;
        $this->completedCallTimeTotal = 0;
        $this->match = 'exact';
    }

    public static function createRegistrationFromRegisterMessage(Session $session, RegisterMessage $msg, AdapterInterface $adapter, ?string $id = null): Registration
    {
        return new Registration($session, $adapter, $msg->getProcedureName(), $msg->getOptions(), $id);
    }

    /**
     * @return boolean
     */
    public function getAllowMultipleRegistrations(): bool
    {
        return $this->getInvokeType() !== 'single';
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
     * @deprecated do nothing
     */
    public function setAllowMultipleRegistrations(bool $allowMultipleRegistrations): void
    {
        // do nothin
    }

    /**
     *
     * @return String
     */
    public function getInvokeType(): string
    {
        return $this->options->invoke ?? 'single';
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
        $invocationMessage = $call->getInvocationMessage();
        $key = static::generateKeyForInvocation(
            $call->getCallerSession()->getSessionId(),
            $call->getCalleeSession()->getSessionId(),
            $call->getRegistration()->getId(),
            $invocationMessage->getRequestId(),
            $call->getCallMessage()->getRequestId()
        );

        $this->adapter->set($key, [
            'callRequestId' => $call->getCallMessage()->getRequestId(),
            'callSessionId' => $call->getCallerSession()->getSessionId(),
            'callTransportId' => $call->getCallerSession()->getTransportId(),
            'calleeSessionId' => $call->getCalleeSession()->getSessionId(),
            'calleeTransportId' => $call->getCalleeSession()->getTransportId(),
            'invocationId' => $invocationMessage->getRequestId(),
            'registrationId' => $invocationMessage->getRegistrationId(),
            'isProgressive' => $call->isProgressive(),
            'hasResponse' => false,
            'hasSentResult' => false,
            'cancelled' => false,
            'cancelMode' => 'skip',
            'callerRealm' => $call->getCallerSession()->getRealm()->name,
            'calleeRealm' => $call->getCalleeSession()->getRealm()->name,
        ]);

        $this->getSession()->sendMessage($invocationMessage);
    }

    public static function generateKeyForInvocation(string|int $callerId, string|int $calleeId, string|int $registrationId, string|int $requestId, string|int $callRequestId): string
    {
        return sprintf(
            'invoc:%s:%s:%s:%s:%s',
            $callerId,
            $calleeId,
            $registrationId,
            $requestId,
            $callRequestId
        );
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

    public function getId(): string
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

    public function setId(string|int $id): void
    {
        $this->id = $id;
    }

    public function getRealm(): Realm
    {
        return $this->session->getRealm();
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

    public function getParsedId(): object
    {
        [$serverId, $id] = explode(':', $this->getId());

        return (object)['routerId' => $serverId, 'id' => $id];
    }

    public function getOptions(): object
    {
        return $this->options;
    }

    public function getRegisteredAt(): \DateTimeImmutable
    {
        return $this->registeredAt;
    }

    public function setRegisteredAt(\DateTimeImmutable $registeredAt): void
    {
        $this->registeredAt = $registeredAt;
    }

    public function getMatch(): string
    {
        return $this->options->match ?? 'exact';
    }

    public function setLastCallStartedAtNow(): void
    {
        $this->lastCallStartedAt = new \DateTimeImmutable('now');
    }

    public function toArray(): array
    {
        return [
            'id' => $this->getId(),
            'realm' => $this->getRealm()->getRealmName(),
            'sessionId' => $this->getSession()->getId(),
            'procedure' => $this->getProcedureName(),
            'registeredAt' => $this->getRegisteredAt(),
            'options' => $this->options,
        ];
    }

    /**
     * @return array{
     *     id: string,
     *     realm: string,
     *     sessionId: null|string,
     *     procedure: string,
     *     registeredAt: string,
     *     options: object{match:string, invoke:string}
     * }
     */
    public function toArrayFormatted(): array
    {
        return [
            'id' => $this->getId(),
            'realm' => $this->getRealm()->getRealmName(),
            'sessionId' => $this->getSession()->getId(),
            'procedure' => $this->getProcedureName(),
            'registeredAt' => $this->getRegisteredAt()->format(DateTimeInterface::ATOM),
            'lastCallStartedAt' => $this->lastCallStartedAt?->format(DateTimeInterface::ATOM) ?? null,
            'serverId' => $this->getSession()->getServerId(),
            'options' => $this->options,
        ];
    }
}
