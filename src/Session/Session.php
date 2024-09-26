<?php

declare(strict_types=1);

namespace Octamp\Wamp\Session;

use Octamp\Wamp\Promise\Promise;
use Octamp\Wamp\Auth\AuthenticationDetails;
use Octamp\Wamp\Connection\Event\SendMessageEvent;
use Octamp\Wamp\Connection\WithEventDispatcherInterface;
use Octamp\Wamp\Event\LeaveRealmEvent;
use Octamp\Wamp\Promise\Deferred;
use Octamp\Wamp\Promise\PromiseInterface;
use Octamp\Wamp\Realm\Realm;
use Octamp\Wamp\Session\Adapter\AdapterInterface;
use Octamp\Wamp\Session\Event\MessageEvent;
use Octamp\Wamp\Transport\AbstractTransport;
use Thruway\Message\AbortMessage;
use Thruway\Message\ErrorMessage;
use Thruway\Message\HelloMessage;
use Thruway\Message\Message;

class Session
{
    protected ?HelloMessage $helloMessage = null;

    protected ?Realm $realm = null;

    protected bool $trusted = false;

    protected bool $authenticated = false;

    protected float $lastOutboundActivity = 0;

    protected ?string $id = null;

    protected bool $goodByeSent = false;

    protected int $pendingCallCount = 0;

    protected ?AuthenticationDetails $authenticationDetails = null;

    /**
     * @var Deferred[]
     */
    protected array $deferredList = [];

    public function __construct(protected AbstractTransport $transport, protected string $serverId, protected AdapterInterface $adapter)
    {
        $connection = $this->transport->getConnection();
        if ($connection instanceof WithEventDispatcherInterface) {
            $connection->on('SendMessage', [$this, 'onSendMessage']);
        }
    }

    public function onSendMessage(SendMessageEvent $event): void
    {
        $connection = $this->transport->getConnection();
        if (!($connection instanceof WithEventDispatcherInterface)) {
            return;
        }
        if ($event->opcode !== \OpenSwoole\WebSocket\Server::WEBSOCKET_OPCODE_TEXT && $event->opcode !== \OpenSwoole\WebSocket\Server::WEBSOCKET_OPCODE_BINARY) {
            return;
        }
        $message = $this->getTransport()->getSerializer()->deserialize($event->data);
        $event = new MessageEvent($this, $message);

        $eventName = 'Message:' . $message->getMsgCode();
        if ($message instanceof ErrorMessage) {
            $connection->dispatch($eventName . ':' . $message->getErrorMsgCode(), $event);
            $connection->dispatch($eventName . ':' . $message->getErrorMsgCode() . ':' . $message->getRequestId(), $event);
        }

        if (method_exists($message, 'getRequestId')) {
            $connection->dispatch($eventName . ':' . $message->getRequestId(), $event);
        }

        if (!$event->isPropagationStopped()) {
            $connection->dispatch($eventName, new MessageEvent($this, $message));
        }
    }

    public function addListener(string $eventName, callable $callback, int $priority = 0): void
    {
        $connection = $this->transport->getConnection();
        if (!($connection instanceof WithEventDispatcherInterface)) {
            return;
        }

        $connection->on($eventName, $callback, $priority);
    }

    public function addListenerOnce(string $eventName, callable $callback, int $priority = 0): void
    {
        $connection = $this->transport->getConnection();
        if (!($connection instanceof WithEventDispatcherInterface)) {
            return;
        }

        $connection->once($eventName, $callback, $priority);
    }

    public function removeAllListenerByEvent(string $eventName): void
    {
        $connection = $this->transport->getConnection();
        if (!($connection instanceof WithEventDispatcherInterface)) {
            return;
        }

        $connection->removeListenersForEvent($eventName);
    }

    public function setId(string $id): void
    {
        if ($this->id !== null) {
            throw new \Exception('Unable to set new session id');
        }

        $this->id = $id;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getSessionId(): ?string
    {
        return $this->getId();
    }

    public function setTrusted(bool $trusted): void
    {
        $this->trusted = $trusted;
    }

    public function getTransportId(): string
    {
        return $this->transport->getId();
    }

    public function getTransport(): AbstractTransport
    {
        return $this->transport;
    }

    public function setHelloMessage(HelloMessage $message): void
    {
        if ($this->helloMessage !== null) {
            throw new \Exception('Unable to set another hello message');
        }

        $this->helloMessage = $message;
    }

    public function getHelloMessage(): ?HelloMessage
    {
        return $this->helloMessage;
    }

    public function setRealm(Realm $realm): void
    {
        if ($this->realm !== null) {
            throw new \Exception('Unable to set another realm');
        }

        $this->realm = $realm;
    }

    public function abort(object|array $details, string $uri): void
    {
        if (is_array($details)) {
            $details = (object) $details;
        }
        if ($this->isAuthenticated()) {
            throw new \Exception('Session::abort called after we are authenticated');
        }
        $abortMessage = new AbortMessage($details, $uri);
        $this->sendMessage($abortMessage);
        $this->clearDeferred();
        $this->shutdown();
    }

    public function setAuthenticated(bool $authenticated): void
    {
        $this->authenticated = $authenticated;
    }

    public function isAuthenticated(): bool
    {
        return $this->authenticated;
    }

    public function getRealm(): ?Realm
    {
        return $this->realm;
    }

    public function sendMessage(Message $message): void
    {
        $this->lastOutboundActivity = microtime(true);
        $this->getTransport()->sendMessage($message);
    }

    public function ping(): PromiseInterface
    {
        return $this->getTransport()->ping();
    }

    public function isTrusted(): bool
    {
        return $this->trusted;
    }

    public function getServerId(): string
    {
        return $this->serverId;
    }

    public function setGoodByeSent(bool $sent): void
    {
        $this->goodByeSent = $sent;
    }

    public function isGoodByeSent(): bool
    {
        return $this->goodByeSent;
    }

    public function shutdown(): void
    {
        $this->onClose();
        $this->getTransport()->close();
    }

    public function onClose(): void
    {
        if ($this->realm !== null) {
            $this->realm->handle($this, new LeaveRealmEvent($this));
        }

        $this->clearDeferred();
    }

    public function getMetaInfo(): object
    {
        return (object)[
            'session' => $this->getSessionId(),
            'authid' => $this->getAuthenticationDetails()?->getAuthId() ?? null,
            'authrole' => $this->getAuthenticationDetails()?->getAuthRole() ?? null,
            'authroles' => $this->getAuthenticationDetails()?->getAuthRoles() ?? [],
            'authmethod' => $this->getAuthenticationDetails()?->getAuthMethod() ?? null,
            'authprovider' => $this->getAuthenticationDetails()?->getAuthProvider() ?? null,
            'authextra' => $this->getAuthenticationDetails()->getAuthExtra() ?? new \stdClass(),
        ];
    }

    public function getRoleFeatures(): \stdClass
    {
        return (object)($this->getHelloMessage()->getDetails()?->roles ?? []);
    }

    public function hasFeature(string $role, string $feature): bool
    {
        $roles = $this->getRoleFeatures();
        if (!isset($roles->{$role})) {
            return false;
        }

        return $roles->{$role}->features->{$feature} ?? false;
    }

    public function incPendingCallCount(): int
    {
        return $this->pendingCallCount++;
    }

    public function decPendingCallCount(): int
    {
        // if we are already at zero - something is wrong
        if ($this->pendingCallCount === 0) {
            return 0;
        }

        return $this->pendingCallCount--;
    }

    public function setAuthenticationDetails(?AuthenticationDetails $authenticationDetails): void
    {
        $this->authenticationDetails = $authenticationDetails;
    }

    public function getAuthenticationDetails(): ?AuthenticationDetails
    {
        return $this->authenticationDetails;
    }

    public function incrementWampId(): int|float
    {
        return $this->adapter->incWampIdName($this, 'wampId');
    }

    public function addDeferred(Deferred $deferred): void
    {
        $this->deferredList[] = $deferred;
    }

    public function cancelDeferred(Deferred $deferred): void
    {
        $index = array_search($deferred, $this->deferredList);
        if ($index !== false) {
            unset($this->deferredList[$index]);
        }
    }

    public function clearDeferred(): void
    {
        $keys = array_keys($this->deferredList);
        foreach ($keys as $key) {
            unset($this->deferredList[$key]);
        }
    }
}
