<?php

namespace Octamp\Wamp\Subscription;

use Octamp\Wamp\Session\Session;
use Thruway\Common\Utils;
use Thruway\Message\EventMessage;
use Thruway\Message\SubscribeMessage;
use Thruway\Message\Traits\OptionsTrait;
use Thruway\Subscription\SubscriptionGroup;

class Subscription
{
    use OptionsTrait;

    private string $id;

    private Session $session;

    private string $uri;

    private bool $pausedForState;

    private \SplQueue $pauseQueue;

    private bool $disclosePublisher;

    /**
     * @var SubscriptionGroup
     */
    private $subscriptionGroup;

    public function __construct(string $uri, Session $session, array|object|null $options = null)
    {

        $this->uri               = $uri;
        $this->session           = $session;
        $this->id                = Utils::getUniqueId();
        $this->disclosePublisher = false;
        $this->pausedForState    = false;
        $this->pauseQueue        = new \SplQueue();

        $this->setOptions($options);

    }

    public static function createSubscriptionFromSubscribeMessage(Session $session, SubscribeMessage $msg): static
    {
        $options      = $msg->getOptions();
        $subscription = new static($msg->getTopicName(), $session, $options);

        if (isset($options->disclose_publisher) && $options->disclose_publisher === true) {
            $subscription->setDisclosePublisher(true);
        }

        return $subscription;
    }

    public function getId(): string
    {
        return $this->id;
    }


    public function setUri(string $uri): void
    {
        $this->uri = $uri;
    }

    /**
     * Get URI
     *
     * @return string
     */
    public function getUri(): string
    {
        return $this->uri;
    }

    public function getSession(): Session
    {
        return $this->session;
    }

    public function setSession(Session $session): void
    {
        $this->session = $session;
    }

    /**
     * @return SubscriptionGroup
     */
    public function getSubscriptionGroup()
    {
        return $this->subscriptionGroup;
    }

    /**
     * @param SubscriptionGroup $subscriptionGroup
     */
    public function setSubscriptionGroup(SubscriptionGroup $subscriptionGroup)
    {
        $this->subscriptionGroup = $subscriptionGroup;
    }

    public function isDisclosePublisher(): bool
    {
        return $this->disclosePublisher;
    }


    public function setDisclosePublisher($disclosePublisher): bool
    {
        $this->disclosePublisher = $disclosePublisher;
    }

    public function pauseForState(): void
    {
        if ($this->pausedForState) {
            throw new \Exception("Tried to paused already paused subscription");
        }
        $this->pausedForState = true;
    }

    public function isPausedForState(): bool
    {
        return $this->pausedForState;
    }

    public function unPauseForState(string|int|null $lastPublicationId = null): void
    {
        if (!$this->pausedForState) {
            throw new \Exception("Tried to unpaused subscription that was not paused");
        }

        $this->pausedForState = false;

        $this->processStateQueue($lastPublicationId);
    }

    private function processStateQueue(string|int|null $lastPublicationId = null): void
    {
        if ($lastPublicationId !== null) {
            // create an array of pub ids
            // if we can't find the lastPublicationId in the queue
            // then we are going to assume it was before our time
            $pubIds = [];

            /** @var EventMessage $msg */
            foreach ($this->pauseQueue as $msg) {
                $pubIds[] = $msg->getPublicationId();
            }

            if (!in_array($lastPublicationId, $pubIds)) {
                $lastPublicationId = null;
            }
        }

        while (!$this->pauseQueue->isEmpty()) {
            $msg = $this->pauseQueue->dequeue();
            if ($lastPublicationId === null) {
                $this->sendEventMessage($msg);
            }
            if ($lastPublicationId == $msg->getPublicationId()) {
                $lastPublicationId = null;
            }
        }
    }

    public function sendEventMessage(EventMessage $msg): void
    {
        if ($this->pausedForState && !$msg->isRestoringState()) {
            $this->pauseQueue->enqueue($msg);
            return;
        }

        $this->getSession()->sendMessage($msg);
    }
}