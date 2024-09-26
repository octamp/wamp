<?php

namespace Octamp\Wamp\Session;

use Octamp\Wamp\Helper\IDHelper;
use Octamp\Wamp\Meta\Error;
use Octamp\Wamp\Meta\ErrorException;
use Octamp\Wamp\Meta\Publication;
use Octamp\Wamp\Meta\Registration;
use Octamp\Wamp\Meta\Result;
use Octamp\Wamp\Meta\Subscription;
use Octamp\Wamp\Promise\Deferred;
use Octamp\Wamp\Promise\PromiseInterface;
use Octamp\Wamp\Session\Event\MessageEvent;
use Thruway\Message\CallMessage;
use Thruway\Message\ErrorMessage;
use Thruway\Message\EventMessage;
use Thruway\Message\InvocationMessage;
use Thruway\Message\Message;
use Thruway\Message\PublishedMessage;
use Thruway\Message\PublishMessage;
use Thruway\Message\RegisteredMessage;
use Thruway\Message\RegisterMessage;
use Thruway\Message\ResultMessage;
use Thruway\Message\SubscribedMessage;
use Thruway\Message\SubscribeMessage;
use Thruway\Message\YieldMessage;

class SessionMeta extends Session
{
    /**
     * @var Registration[]
     */
    private array $registrations = [];

    /**
     * @var Subscription[]
     */
    private array $subscriptions = [];

    public function init(): void
    {
        $this->addListener('Message:' . Message::MSG_INVOCATION, function (MessageEvent $event) {
            /** @var InvocationMessage $message */
            $message = $event->message;
            $registrationId = $message->getRegistrationId();
            $registration = $this->registrations[$registrationId] ?? null;

            if ($registration === null) {
                $errorMessage = ErrorMessage::createErrorMessageFromMessage($message, 'wamp.error.unavailable');
                $this->getRealm()->handle($this, $errorMessage);
                return;
            }

            try {
                $result = call_user_func($registration->callback, $event->session, $message->getArguments(), $message->getArgumentsKw(), $message->getDetails());
                if ($result instanceof Result) {
                    $yieldMessage = new YieldMessage($message->getRequestId(), new \stdClass(), $result->args, $result->kwargs);
                    $this->getRealm()->handle($this, $yieldMessage);
                    return;
                } elseif (is_object($result)) {
                    $yieldMessage = new YieldMessage($message->getRequestId(), new \stdClass(), [(object)((array) $result)]);
                    $this->getRealm()->handle($this, $yieldMessage);
                    return;
                }
                $yieldMessage = new YieldMessage($message->getRequestId(), new \stdClass(), [$result]);
                $this->getRealm()->handle($this, $yieldMessage);
            } catch (ErrorException $exception) {
                $errorMessage = ErrorMessage::createErrorMessageFromMessage($message, $exception->getError()->uri);
                $errorMessage->setArguments($exception->getError()->args);
                $errorMessage->setArgumentsKw($exception->getError()->kwargs);
                $this->getRealm()->handle($this, $errorMessage);
            }
        });

        $this->addListener('Message:' . Message::MSG_EVENT, function (MessageEvent $event) {
            /** @var EventMessage $message */
            $message = $event->message;
            $subscriptionId = $message->getSubscriptionId();
            $subscription = $this->subscriptions[$subscriptionId] ?? null;

            if ($subscription === null) {
                return;
            }

            call_user_func($subscription->callback, $message->getArguments(), $message->getArgumentsKw(), $message->getDetails());
        });
    }

    public function publish(string $topic, array $args = [], object $kwargs = new \stdClass(), object $options = new \stdClass()): PromiseInterface
    {
        $message = new PublishMessage(IDHelper::incrementSessionWampID($this), $options, $topic, $args, $kwargs);
        $deferred = new Deferred();
        $this->addListenerOnce('Message:' . Message::MSG_PUBLISHED . ':' . $message->getRequestId(), function (MessageEvent $event) use ($deferred) {
            /** @var PublishedMessage $message */
            $message = $event->message;
            $this->removeErrorMessageListener($message);
            $deferred->resolve(new Publication($message->getPublicationId()));
        });
        $this->addErrorMessageListenerOnce($message, Message::MSG_PUBLISHED, $deferred);
        $this->getRealm()->handle($this, $message);

        return $deferred->promise();
    }

    public function subscribe(string $topic, callable $handler, object $options = new \stdClass()): PromiseInterface
    {
        $deferred = new Deferred();
        $message = new SubscribeMessage(IDHelper::incrementSessionWampID($this), $options, $handler);
        $this->addListenerOnce('Message:' . Message::MSG_SUBSCRIBED . ':' . $message->getRequestId(), function (MessageEvent $event) use($handler, $topic, $deferred) {
            /** @var SubscribedMessage $message */
            $message = $event->message;
            $this->subscriptions[$message->getSubscriptionId()] = new Subscription($message->getSubscriptionId(), $topic, $handler);
            $this->removeErrorMessageListener($message);

            $deferred->resolve($this->subscriptions[$message->getSubscriptionId()]);
        });
        $this->addErrorMessageListenerOnce($message, Message::MSG_SUBSCRIBED, $deferred);
        $this->getRealm()->handle($this, $message);

        return $deferred->promise();
    }

    public function register(string $procedure, callable $callback): PromiseInterface
    {
        $deferred = new Deferred();

        $message = new RegisterMessage(IDHelper::incrementSessionWampID($this), new \stdClass(), $procedure);
        $this->addListenerOnce('Message:' . Message::MSG_REGISTERED . ':' . $message->getRequestId(), function(MessageEvent $event) use($callback, $procedure, $deferred) {
            /** @var RegisteredMessage $message */
            $message = $event->message;
            $this->registrations[$message->getRegistrationId()] = new Registration($message->getRegistrationId(), $procedure, $callback);
            $this->removeAllListenerByEvent('Message:' . Message::MSG_ERROR . ':' . Message::MSG_CALL . ':' . $message->getRequestId());
            $deferred->resolve($this->registrations[$message->getRegistrationId()]);
        });
        $this->addErrorMessageListenerOnce($message, Message::MSG_REGISTERED, $deferred);

        $this->getRealm()->handle($this, $message);

        return $deferred->promise();
    }

    public function call(string $procedure, array $args = [], object $kwargs = new \stdClass(), object $options = new \stdClass()): PromiseInterface
    {
        $deferred = new Deferred();
        $message = new CallMessage(IDHelper::incrementSessionWampID($this), $options, $procedure, $args, $kwargs);
        $this->addListenerOnce('Message:' . Message::MSG_RESULT . ':' . $message->getRequestId(), function (MessageEvent $event) use ($deferred) {
            /** @var ResultMessage $message */
            $message = $event->message;
            $this->removeAllListenerByEvent('Message:' . Message::MSG_ERROR . ':' . Message::MSG_CALL . ':' . $message->getRequestId());
            $deferred->resolve(new Result($message->getArguments(), $message->getArgumentsKw()));
        });
        $this->addErrorMessageListenerOnce($message, Message::MSG_RESULT, $deferred);
        $this->getRealm()->handle($this, $message);

        return $deferred->promise();
    }

    protected function removeErrorMessageListener(Message $message): void
    {
        if (method_exists($message, 'getRequestId')) {
            $this->removeAllListenerByEvent('Message:' . Message::MSG_ERROR . ':' . $message->getRequestId());
        }
    }

    protected function addErrorMessageListenerOnce(Message $message, int $removeMsgCode, Deferred $deferred): void
    {
        if (method_exists($message, 'getRequestId')) {
            $eventName = 'Message:' . Message::MSG_ERROR . ':' . $message->getRequestId();
            $this->addListenerOnce($eventName, function(MessageEvent $event) use ($deferred, $removeMsgCode) {
                /** @var ErrorMessage $message */
                $message = $event->message;
                $this->removeAllListenerByEvent('Message:' . $removeMsgCode . ':' . $message->getRequestId());
                $deferred->reject(new Error($message->getErrorURI(), $message->getArguments(), $message->getArgumentsKw()));
            });
        }
    }
}