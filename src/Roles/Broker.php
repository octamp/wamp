<?php

namespace Octamp\Wamp\Roles;

use Octamp\Wamp\Adapter\AdapterInterface;
use Octamp\Wamp\Event\LeaveRealmEvent;
use Octamp\Wamp\Session\Session;
use Octamp\Wamp\Session\SessionStorage;
use Octamp\Wamp\Subscription\Subscription;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
use Thruway\Common\Utils;
use Thruway\Message\ErrorMessage;
use Thruway\Message\EventMessage;
use Thruway\Message\PublishedMessage;
use Thruway\Message\PublishMessage;
use Thruway\Message\SubscribedMessage;
use Thruway\Message\SubscribeMessage;
use Thruway\Message\UnsubscribedMessage;
use Thruway\Message\UnsubscribeMessage;

class Broker extends AbstractRole implements RoleInterface
{
    protected const TYPE_SUBSCRIBE = 1;
    protected const TYPE_REMOVE_SUBSCRIPTION = 2;

    /**
     * @var array<int, array<int, Subscription>>
     */
    protected array $subscriptionGroups = [];
    protected bool $stopped = false;
    protected Channel $subscribeChan;

    public function __construct(AdapterInterface $adapter, SessionStorage $sessionStorage, protected string $serverId)
    {
        parent::__construct($adapter, $sessionStorage);
        $this->subscribeChan = new Channel(1);
        $this->start();
    }

    public function start(): void
    {
        Coroutine::create(function () {
            while (!$this->stopped) {
                $data = $this->subscribeChan->pop();
                $type = $data[0];
                if ($type === self::TYPE_SUBSCRIBE) {
                    $session = $data[1];
                    $message = $data[2];
                    if (!isset($this->subscriptionGroups[$message->getUri()])) {
                        $this->subscriptionGroups[$message->getUri()] = [];
                    }
                    Coroutine::create(function () use ($session, $message) {
                        $this->handleSubscribeMessage($session, $message);
                    });
                } elseif ($type === self::TYPE_REMOVE_SUBSCRIPTION) {
                    $uri = $data[1];
                    $this->removeSubscriptionGroup($uri);
                }
            }
            $this->subscribeChan->close();
            $cid = Coroutine::getCid();
            Coroutine::cancel($cid);
        });
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    public function onPublishMessage(Session $session, PublishMessage $message): void
    {
        if ($message->getPublicationId() === null) {
            $message->setPublicationId(Utils::getUniqueId());
        }

        $subscriptionGroupsUri = $this->adapter->keys('sub:*');
        foreach ($subscriptionGroupsUri as $subscriptionGroupUri) {
            $messageUri = substr($subscriptionGroupUri, 4);
            if ($messageUri === $message->getUri()) {
                $subscriptionsRaw = $this->adapter->get($subscriptionGroupUri);
                foreach ($subscriptionsRaw as $key => $subscriptionRaw) {
                    $subscription = $this->getSubscription($messageUri, $key, $subscriptionRaw);
                    $eventMsg = EventMessage::createFromPublishMessage($message, $subscription->getId());
                    // do some additional conditions
                    $subscription->sendEventMessage($eventMsg);
                }
            }
        }

        if ($message->acknowledge()) {
            $session->sendMessage(new PublishedMessage($message->getRequestId(), $message->getPublicationId()));
        }
    }

    protected function getSubscription(string $groupKey, string $key, ?array $raw = null): ?Subscription
    {
        if (isset($this->subscriptionGroups[$groupKey][$key])) {
            return $this->subscriptionGroups[$groupKey][$key];
        }

        $session = $this->sessionStorage->getSessionUsingTransportId($raw['transportId']);
        if ($session === null) {
            return null;
        }
        $subscribeMessage = SubscribeMessage::createMessageFromArray($raw['message']);

        $subscribeMessage = Subscription::createSubscriptionFromSubscribeMessage($session, $subscribeMessage);
        $subscribeMessage->setId($raw['subscriptionId']);

        return $subscribeMessage;
    }

    public function onSubscribeMessage(Session $session, SubscribeMessage $message): void
    {
        if (!isset($this->subscriptionGroups[$message->getUri()])) {
            $this->subscribeChan->push([self::TYPE_SUBSCRIBE, $session, $message]);
        } else {
            $this->handleSubscribeMessage($session, $message);
        }
    }

    protected function handleSubscribeMessage(Session $session, SubscribeMessage $message): void
    {
        $subscription = Subscription::createSubscriptionFromSubscribeMessage($session, $message);
        $uri = $subscription->getUri();

        $this->subscriptionGroups[$uri][$subscription->getId()] = $subscription;
        $this->adapter->setField('sub:' . $uri, $subscription->getId(), [
            'sessionId' => $session->getId(),
            'transportId' => $session->getTransportId(),
            'subscriptionId' => $subscription->getId(),
            'message' => $message->getMessageParts(),
        ]);

        $subscribedMessage = new SubscribedMessage($message->getRequestId(), $subscription->getId());
        $session->sendMessage($subscribedMessage);
    }

    public function onUnsubscribeMessage(Session $session, UnsubscribeMessage $message): void
    {
        $subscription = null;
        foreach ($this->subscriptionGroups as $subscriptions) {
            /** @var Subscription $subscription */
            $result = $subscriptions[$message->getSubscriptionId()] ?? null;
            if ($result instanceof Subscription) {
                if ($result->getSession()->getId() === $session->getId()) {
                    $subscription = $result;
                }
                break;
            }
        }

        if ($subscription === null) {
            $error = ErrorMessage::createErrorMessageFromMessage($message);
            $error->setErrorURI('wamp.error.no_such_subscription');
            $session->sendMessage($error);
            return;
        }

        $this->removeSubscription($subscription);
        $session->sendMessage(new UnsubscribedMessage($message->getRequestId()));
    }

    public function onLeaveRealmEvent(Session $session, LeaveRealmEvent $event): void
    {
        foreach ($this->subscriptionGroups as $subscriptions) {
            /** @var Subscription $subscription */
            foreach ($subscriptions as $subscription) {
                if ($subscription->getSession()->getId() === $session->getId()) {
                    $this->removeSubscription($subscription);
                }
            }
        }
    }

    protected function removeSubscription(Subscription $subscription): void
    {
        if ($this->subscriptionGroups[$subscription->getUri()]) {
            unset($this->subscriptionGroups[$subscription->getUri()][$subscription->getId()]);
        }
        $this->adapter->del($subscription->getUri(), [$subscription->getId()]);
        $this->subscribeChan->push([self::TYPE_REMOVE_SUBSCRIPTION, $subscription->getUri()]);
    }

    protected function removeSubscriptionGroup(string $uri): void
    {
        if (empty($this->subscriptionGroups[$uri])) {
            unset($this->subscriptionGroups[$uri]);
            if ($this->adapter->countFields($uri) === 0) {
                $this->adapter->del('sub:' . $uri);
            }
        }
    }
}