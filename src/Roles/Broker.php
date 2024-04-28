<?php

namespace Octamp\Wamp\Roles;

use Octamp\Wamp\Roles\RoleInterface;
use Octamp\Wamp\Session\Session;
use Octamp\Wamp\Subscription\Subscription;
use Thruway\Message\Message;
use Thruway\Message\PublishMessage;
use Thruway\Message\SubscribeMessage;
use Thruway\Message\UnsubscribeMessage;

class Broker extends AbstractRole implements RoleInterface
{

    public function onPublishMessage(Session $session, PublishMessage $message): void
    {
    }

    public function onSubscribeMessage(Session $session, SubscribeMessage $message): void
    {
        $subscription = Subscription::createSubscriptionFromSubscribeMessage($session, $message);

        $data = [
            ''
        ];
    }

    public function onUnsubscribeMessage(Session $session, UnsubscribeMessage $message): void
    {

    }
}