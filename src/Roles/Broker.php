<?php

declare(strict_types=1);

namespace Octamp\Wamp\Roles;

use Octamp\Wamp\Adapter\AdapterInterface;
use Octamp\Wamp\Event\JoinRealmEvent;
use Octamp\Wamp\Event\LeaveRealmEvent;
use Octamp\Wamp\Helper\IDHelper;
use Octamp\Wamp\Helper\UriHelper;
use Octamp\Wamp\Matcher\Matcher;
use Octamp\Wamp\Matcher\UnExistMatchException;
use Octamp\Wamp\Session\Session;
use Octamp\Wamp\Session\SessionStorage;
use Octamp\Wamp\Subscription\Subscription;
use Octamp\Wamp\Subscription\SubscriptionGroup;
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
     * @var SubscriptionGroup[]
     */
    protected array $subscriptionGroups = [];
    protected bool $stopped = false;
    protected Channel $subscribeChan;

    public function __construct(AdapterInterface $adapter, SessionStorage $sessionStorage, protected Matcher $matcher, protected string $serverId)
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
                    /** @var Session $session */
                    $session = $data[1];
                    /** @var SubscribeMessage $message */
                    $message = $data[2];
                    /** @var string $hash */
                    $hash = $data[3];
                    try {
                        if (!isset($this->subscriptionGroups[$hash])) {
                            $this->subscriptionGroups[$hash] = new SubscriptionGroup(
                                $this->matcher->getMatch($message->getMatchType()),
                                $session->getRealm()->name,
                                $message->getUri(),
                                $message->getOptions(),
                                $this->adapter,
                                $this->sessionStorage,
                                $this->serverId);
                            $this->subscriptionGroups[$hash]->save();
                        }
                        Coroutine::create(function() use ($session, $message) {
                            $this->handleSubscribeMessage($session, $message);
                        });
                    } catch (UnExistMatchException) {
                        $errorMessage = ErrorMessage::createErrorMessageFromMessage($message, 'wamp.error.option_not_allowed');
                        $session->sendMessage($errorMessage);
                    }
                } elseif ($type === self::TYPE_REMOVE_SUBSCRIPTION) {
                    $hash = $data[1];
                    $this->removeSubscriptionGroup($hash);
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
        if (!UriHelper::uriIsValidStrict($message->getUri(), false, $session->isTrusted())) {
            $session->sendMessage(ErrorMessage::createErrorMessageFromMessage($message, 'wamp.error.invalid_uri'));
            return;
        }

        if ($message->getPublicationId() === null) {
            $message->setPublicationId(IDHelper::generateGlobalWampID());
        }

        foreach ($this->loopAllSubscriptionGroup() as $subscriptionGroup) {
            if ($session->getRealm()->name !== $subscriptionGroup->getRealmName()) {
                continue;
            }

            if (!$subscriptionGroup->isPublishMatch($message)) {
                continue;
            }
            $subscriptionGroup->publishMessage($session, $message);
        }

        if ($message->acknowledge()) {
            $session->sendMessage(new PublishedMessage($message->getRequestId(), $message->getPublicationId()));
        }
    }

    public function onSubscribeMessage(Session $session, SubscribeMessage $message): void
    {
        $useExactMatch = ($message->getOptions()->match ?? 'exact') === 'exact';
        if (!UriHelper::uriIsValidStrict($message->getUri(), !$useExactMatch, true)) {
            $session->sendMessage(ErrorMessage::createErrorMessageFromMessage($message, 'wamp.error.invalid_uri'));
            return;
        }

        if (!$useExactMatch && !($session->hasFeature('subscriber', 'pattern_based_subscription') && $this->hasFeature('pattern_based_subscription'))) {
            $session->sendMessage(ErrorMessage::createErrorMessageFromMessage($message, 'wamp.error.feature_not_supported'));
            return;
        }


        $hash = SubscriptionGroup::generateHash($session->getRealm()->name, $message->getUri(), $message->getOptions());
        if (!isset($this->subscriptionGroups[$hash])) {
            $this->subscribeChan->push([self::TYPE_SUBSCRIBE, $session, $message, $hash]);
        } else {
            $this->handleSubscribeMessage($session, $message);
        }
    }

    protected function handleSubscribeMessage(Session $session, SubscribeMessage $message): void
    {
        $subscription = Subscription::createSubscriptionFromSubscribeMessage($session, $message);
        $subscriptionGroup = $this->getSubscriptionGroup($subscription);
        $subscriptionGroup->addSubscription($subscription);

        $subscribedMessage = new SubscribedMessage($message->getRequestId(), $subscription->getId());
        $session->sendMessage($subscribedMessage);
    }

    public function onUnsubscribeMessage(Session $session, UnsubscribeMessage $message): void
    {
        foreach ($this->subscriptionGroups as $subscriptionGroup) {
            /** @var Subscription $subscription */
            $result = $subscriptionGroup->getSubscription($message->getSubscriptionId());
            if ($result instanceof Subscription) {
                if ($result->getSession()->getId() === $session->getId()) {
                    $this->removeSubscription($subscriptionGroup, $subscription);
                    $session->sendMessage(new UnsubscribedMessage($message->getRequestId()));
                }
                return;
            }
        }

        $error = ErrorMessage::createErrorMessageFromMessage($message);
        $error->setErrorURI('wamp.error.no_such_subscription');
        $session->sendMessage($error);
    }

    public function onLeaveRealmEvent(Session $session, LeaveRealmEvent $event): void
    {
        foreach ($this->subscriptionGroups as $subscriptionGroup) {
            if ($subscriptionGroup->getRealmName() !== $session->getRealm()->name) {
                continue;
            }

            $subscriptions = $subscriptionGroup->getSessionSubscriptions($session);
            foreach ($subscriptions as $subscription) {
                $this->removeSubscription($subscriptionGroup, $subscription);
            }
        }
    }

    protected function getSubscriptionGroup(Subscription $subscription): SubscriptionGroup
    {
        foreach ($this->subscriptionGroups as $subscriptionGroup) {
            if ($subscriptionGroup->getRealmName() === $subscription->getRealm()->name && $subscriptionGroup->isSubscriptionMatch($subscription)) {
                return $subscriptionGroup;
            }
        }

        return new SubscriptionGroup($this->matcher->getMatch($subscription->getMatch()), $subscription->getRealm()->name, $subscription->getUri(), $subscription->getOptions(), $this->adapter, $this->sessionStorage, $this->serverId);
    }

    protected function getSubscriptionGroupByHash(string $hash, bool $global = false): ?SubscriptionGroup
    {
        if (isset($this->subscriptionGroups[$hash])) {
            return $this->subscriptionGroups[$hash];
        }

        if ($global) {
            $raw = $this->adapter->get('subg:' . $hash);
            if ($raw !== null) {
                if (!isset($raw['match'])) {
                    return null;
                }
                return new SubscriptionGroup($this->matcher->getMatch($raw['match']), $raw['realm'], $raw['uri'], $raw['options'], $this->adapter, $this->sessionStorage, $this->serverId);
            }
        }

        return null;
    }

    /**
     * @return SubscriptionGroup[]
     */
    protected function loopAllSubscriptionGroup(): \Generator
    {
        $keys = $this->adapter->keys('subg:*');
        foreach ($keys as $key) {
            [,$hash] = explode(':', $key);
            $group = $this->getSubscriptionGroupByHash($hash, true);
            if ($group !== null) {
                yield $group;
            }
        }
    }

    protected function removeSubscription(SubscriptionGroup $subscriptionGroup, Subscription $subscription): void
    {
        $subscriptionGroup->removeSubscription($subscription->getId());
        $this->subscribeChan->push([self::TYPE_REMOVE_SUBSCRIPTION, $subscriptionGroup->hash(), $subscription->getId()]);
    }

    protected function removeSubscriptionGroup(string $hash): void
    {
        if (!isset($this->subscriptionGroups[$hash])) {
            return;
        }

        if ($this->subscriptionGroups[$hash]->isEmpty()) {
            unset($this->subscriptionGroups[$hash]);
            if ($this->adapter->countFields('sub:' . $hash) === 0) {
                $this->adapter->del('sub:' . $hash);
                $this->adapter->del('subg:' . $hash);
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
        $features->pattern_based_subscription = true;
        $features->session_meta_api = true;

        return $features;
    }
}
