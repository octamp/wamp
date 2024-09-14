<?php

declare(strict_types=1);

namespace Octamp\Wamp\Subscription;

use Octamp\Wamp\Adapter\AdapterInterface;
use Octamp\Wamp\Matcher\MatchInterface;
use Octamp\Wamp\Session\Session;
use Octamp\Wamp\Session\SessionStorage;
use Thruway\Message\EventMessage;
use Thruway\Message\PublishMessage;

class SubscriptionGroup
{
    protected array $subscriptions = [];
    protected object $options;
    protected ?string $hash = null;

    public function __construct(protected MatchInterface $matcher, protected string $realmName, protected string $uri, array|object $options, protected AdapterInterface $adapter, protected SessionStorage $sessionStorage, protected string $serverId)
    {
        $this->options = (object) $options;
    }

    public function getMatcher(): MatchInterface
    {
        return $this->matcher;
    }

    public function isSubscriptionMatch(Subscription $subscription): bool
    {
        if ($this->matcher->getName() !== $subscription->getMatch()) {
            return false;
        }

        return $this->hash() === static::generateHash($subscription->getRealm()->name, $subscription->getUri(), $subscription->getOptions());
    }

    public function isPublishMatch(PublishMessage $message): bool
    {
        return $this->matcher->isMatched($this->uri, $message->getUri());
    }

    public function addSubscription(Subscription $subscription): void
    {
        $this->subscriptions[$subscription->getId()] = $subscription;
        $realm = $subscription->getSession()->getRealm();

        $this->adapter->setField('sub:' . $this->hash(), $subscription->getId(), [
            'sessionId' => $subscription->getSession()->getId(),
            'transportId' => $subscription->getSession()->getTransportId(),
            'subscriptionId' => $subscription->getId(),
            'subscription' => $subscription->toArray(),
            'realm' => $realm->name,
        ]);
    }

    public function save(): void
    {
        $this->adapter->set('subg:' . $this->hash(), [
            'match' => $this->matcher->getName(),
            'uri' => $this->uri,
            'options' => (array) $this->options,
            'realm' => $this->realmName,
        ]);
    }

    public function hash(): string
    {
        if ($this->hash === null) {
            $this->hash = static::generateHash($this->realmName, $this->uri, $this->options);
        }

        return $this->hash;
    }

    public function publishMessage(Session $session, PublishMessage $message, bool $includeSessionMeta = false)
    {
        $options = $message->getOptions();
        $excludeSessions = $options->exclude ?? [];
        $excludeAuths = $options->exclude_authid ?? [];
        $excludeRoles = $options->exclude_authrole ?? [];

        $eligibleSession = $options->eligible ?? null;
        $eligibleAuths = $options->eligible_authid ?? null;
        $eligibleRoles = $options->eligible_authrole ?? null;

        $excludeMe = $message->excludeMe();
        $subscriptionsRaw = $this->adapter->get('sub:' . $this->hash());
        foreach ($subscriptionsRaw as $subscriptionRaw) {
            $subscription = $this->generateSubscriptionFromRaw($subscriptionRaw);
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

            if ($includeSessionMeta) {
                foreach ($session->getMetaInfo() as $item => $value) {
                    $eventMsg->getDetails()->{$item} = $value;
                }
            }

            $subscription->sendEventMessage($eventMsg);
        }
    }

    public function generateSubscriptionFromRaw(array $raw): ?Subscription
    {
        $session = $this->sessionStorage->getSessionUsingTransportId($raw['transportId']);
        if ($session === null) {
            return null;
        }

        $subscriptionRaw = $raw['subscription'];
        if (!isset($subscriptionRaw['uri'])) {
            return null;
        }
        if (isset($subscriptionRaw['id']) && isset($this->subscriptions[$subscriptionRaw['id']])) {
            return $this->subscriptions[$subscriptionRaw['id']];
        }

        return new Subscription($subscriptionRaw['uri'], $session, $subscriptionRaw['options'] ?? [], $subscriptionRaw['id'] ?? null);
    }

    public function getSubscription(string|int $id, bool $global = false): ?Subscription
    {
        if (isset($this->subscriptions[$id])) {
            return $this->subscriptions[$id];
        }

        if ($global) {
            $raw = $this->adapter->getField('sub:' . $this->hash(), $id);
            if ($raw !== null) {
                return $this->generateSubscriptionFromRaw($raw);
            }
        }

        return null;
    }

    public function removeSubscription(string|int $id): void
    {
        if ($this->subscriptions[$id]) {
            unset($this->subscriptions[$id]);
        }
        $this->adapter->del('sub:' . $this->hash, [$id]);
    }

    /**
     * @return Subscription[]
     */
    public function getSessionSubscriptions(Session $session): array
    {
        return array_filter($this->subscriptions, function (Subscription $subscription) use ($session) {
            return $subscription->getSession()->getSessionId() === $session->getSessionId();
        });
    }

    public function isEmpty(): bool
    {
        return empty($this->subscriptions);
    }

    public static function generateHash(string $realm, string $uri, array|object $options): string
    {
        return hash('xxh128', $realm . ':' . $uri  . ':' . json_encode((array)$options));
    }

    public function getRealmName(): string
    {
        return $this->realmName;
    }
}