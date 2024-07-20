<?php

declare(strict_types=1);

namespace Octamp\Wamp\Roles;

use Octamp\Wamp\Adapter\AdapterInterface;
use Octamp\Wamp\Event\JoinRealmEvent;
use Octamp\Wamp\Event\LeaveRealmEvent;
use Octamp\Wamp\Helper\IDHelper;
use Octamp\Wamp\Session\Session;
use Octamp\Wamp\Session\SessionStorage;
use Octamp\Wamp\Subscription\Subscription;
use OpenSwoole\Coroutine;
use OpenSwoole\Coroutine\Channel;
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
            $message->setPublicationId(IDHelper::generateGlobalWampID());
        }

        $options = $message->getOptions();
        $excludeSessions = $options->exclude ?? [];
        $excludeAuths = $options->exclude_authid ?? [];
        $excludeRoles = $options->exclude_authrole ?? [];

        $eligibleSession = $options->eligible ?? null;
        $eligibleAuths = $options->eligible_authid ?? null;
        $eligibleRoles = $options->eligible_authrole ?? null;

        $excludeMe = $message->excludeMe();

        $subscriptionGroupsUri = $this->adapter->keys('sub:*');
        foreach ($subscriptionGroupsUri as $subscriptionGroupUri) {
            $messageUri = substr($subscriptionGroupUri, 4);
            if ($messageUri === $message->getUri()) {
                $subscriptionsRaw = $this->adapter->get($subscriptionGroupUri);
                foreach ($subscriptionsRaw as $key => $subscriptionRaw) {
                    $subscription = $this->getSubscription($messageUri, $key, $subscriptionRaw);
                    $sessionId = $subscription->getSession()->getSessionId();
                    $authId = $subscription->getSession()->getAuthenticationDetails()->getAuthId();
                    $authRole = $subscription->getSession()->getAuthenticationDetails()->getAuthRole();

                    if (
                        ($eligibleSession !== null && !in_array($sessionId, $eligibleSession))
                        || ($eligibleAuths !== null && !in_array($authId, $eligibleAuths))
                        || ($eligibleRoles !== null && !in_array($authId, $eligibleRoles))
                    ) {
                        continue;
                    }
                    if (
                        in_array($sessionId, $excludeSessions)
                        || in_array($authId, $excludeAuths)
                        || in_array($authRole, $excludeRoles)
                    ) {
                        continue;
                    }

                    if ($excludeMe && $sessionId === $session->getSessionId()) {
                        continue;
                    }

                    $eventMsg = EventMessage::createFromPublishMessage($message, $subscription->getId());
                    $discloseMe = $message->getOptions()->disclose_me ?? false;
                    if ($discloseMe || $subscription->isDisclosePublisher()) {
                        $eventMsg->getDetails()->publisher = $session->getSessionId();
                        if ($authId !== null) {
                            $eventMsg->getDetails()->publisher_authid = $authId;
                        }
                        if ($authRole !== null) {
                            $eventMsg->getDetails()->publisher_authrole = $authId;
                        }
                    }

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

        return Subscription::createSubscriptionFromSubscribeMessage($session, $subscribeMessage, $raw['subscriptionId']);
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

        if (!$event->session->isAuthenticated()) {
            return;
        }

        $subscriptionGroupsUri = $this->adapter->keys('sub:');
        foreach ($subscriptionGroupsUri as $subscriptionGroupUri) {
            $messageUri = substr($subscriptionGroupUri, 4);
            if ($messageUri === 'wamp.session.on_leave') {
                $subscriptionsRaw = $this->adapter->get($subscriptionGroupUri);
                foreach ($subscriptionsRaw as $key => $subscriptionRaw) {
                    $subscription = $this->getSubscription($messageUri, $key, $subscriptionRaw);
                    $sessionId = $subscription->getSession()->getSessionId();
                    $authId = $subscription->getSession()->getAuthenticationDetails()->getAuthId();
                    $authRole = $subscription->getSession()->getAuthenticationDetails()->getAuthRole();

                    $eventMsg = EventMessage::createFromPublishMessage(new PublishMessage(IDHelper::generateGlobalWampID(), [], 'wamp.session.on_leave'), $subscription->getId());
                    $eventMsg->getDetails()->session = $sessionId;
                    $eventMsg->getDetails()->authid = $authId;
                    $eventMsg->getDetails()->authrole = $authRole;

                    $subscription->sendEventMessage($eventMsg);
                }
            }
        }
    }

    public function onJoinRealmEvent(Session $session, JoinRealmEvent $event): void
    {
        if (!$event->session->isAuthenticated()) {
            return;
        }

        $subscriptionGroupsUri = $this->adapter->keys('sub:');
        foreach ($subscriptionGroupsUri as $subscriptionGroupUri) {
            $messageUri = substr($subscriptionGroupUri, 4);
            if ($messageUri === 'wamp.session.on_join') {
                $subscriptionsRaw = $this->adapter->get($subscriptionGroupUri);
                foreach ($subscriptionsRaw as $key => $subscriptionRaw) {
                    $subscription = $this->getSubscription($messageUri, $key, $subscriptionRaw);
                    $sessionId = $subscription->getSession()->getSessionId();
                    $authId = $subscription->getSession()->getAuthenticationDetails()->getAuthId();
                    $authRole = $subscription->getSession()->getAuthenticationDetails()->getAuthRole();
                    $authMethod = $subscription->getSession()->getAuthenticationDetails()->getAuthMethod();
                    $authProvider = $subscription->getSession()->getAuthenticationDetails()->getAuthProvider();

                    $eventMsg = EventMessage::createFromPublishMessage(new PublishMessage(IDHelper::generateGlobalWampID(), [], 'wamp.session.on_join'), $subscription->getId());
                    $eventMsg->getDetails()->session = $sessionId;
                    $eventMsg->getDetails()->authid = $authId;
                    $eventMsg->getDetails()->authrole = $authRole;
                    $eventMsg->getDetails()->authmethod = $authMethod;
                    $eventMsg->getDetails()->authprovider = $authProvider;

                    $subscription->sendEventMessage($eventMsg);
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

    public function getName(): string
    {
        return 'broker';
    }

    public function getFeatures(): object
    {
        $features = new \stdClass();
        $features->subscriber_blackwhite_listing = true;
        $features->publisher_exclusion = true;
        $features->publisher_identification = true;

        return $features;
    }
}
