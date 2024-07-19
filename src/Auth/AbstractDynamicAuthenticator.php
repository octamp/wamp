<?php

namespace Octamp\Wamp\Auth;

use Octamp\Wamp\Connection\WithEventDispatcherInterface;
use Octamp\Wamp\Helper\IDHelper;
use Octamp\Wamp\Promise\Deferred;
use Octamp\Wamp\Promise\PromiseInterface;
use Octamp\Wamp\Realm\RealmManager;
use Octamp\Wamp\Session\Event\MessageEvent;
use Thruway\Message\CallMessage;
use Thruway\Message\ErrorMessage;
use Thruway\Message\HelloMessage;
use Thruway\Message\Message;
use Thruway\Message\ResultMessage;

abstract class AbstractDynamicAuthenticator extends AbstractAuthenticator implements WithRealmManagerInterface
{
    protected ?RealmManager $realmManager;

    protected function sendMessageToAuthenticator(HelloMessage $message, array $details = []): PromiseInterface
    {
        $helloDetails = $message->getDetails();
        $args = [
            'authmethod' => $this->getMethod(),
        ];
        if ($helloDetails->authid) {
            $args['authid'] = $helloDetails->authid;
        }
        if (isset($helloDetails->authextra)) {
            $args['authextra'] = $helloDetails->authextra;
        }

        $procedureName = $this->config['authenticator'];
        $realm = $this->realmManager->getRealm($this->config['authenticator-realm']);
        $realmSession = $realm->getMetaSession();
        $requestId = IDHelper::incrementSessionWampID($realmSession);

        $callMessage = new CallMessage($requestId, [], $procedureName, [
            $realm->name,
            $args['authid'] ?? null,
            array_merge($args, $details),
        ]);

        $deferred = new Deferred();

        $connection = $realmSession->getTransport()->getConnection();
        if ($connection instanceof WithEventDispatcherInterface) {
            $connection->once('Message:' . Message::MSG_CALL . ':' . $callMessage->getRequestId(), function (MessageEvent $event): void {
                $this->realmManager->dispatch($event->session, $event->message);
            });
            $connection->once(
                'Message:' . Message::MSG_RESULT . ':' . $callMessage->getRequestId(),
                function (MessageEvent $event) use ($connection, $callMessage, $deferred): void {
                    $connection->removeListenersForEvent('Message:' . Message::MSG_ERROR . ':' .$callMessage->getRequestId());
                    /** @var ResultMessage $message */
                    $message = $event->message;
                    $data = $message->getArguments();
                    if (empty($data)) {
                        $deferred->reject([]);
                        return;
                    }

                    $result = $data[0];
                    $success = $result->status ?? true;

                    if (!$success) {
                        $deferred->reject($result);
                    } else {
                        $deferred->resolve($result);
                    }
                }
            );
            $connection->once(
                'Message:' . Message::MSG_ERROR . ':' . $callMessage->getRequestId(),
                function (MessageEvent $event) use ($connection, $callMessage, $deferred): void {
                    $connection->removeListenersForEvent('Message:' . Message::MSG_RESULT . ':' .$callMessage->getRequestId());
                    /** @var ErrorMessage $message */
                    $message = $event->message;

                    $deferred->reject([
                        'error_uri' => $message->getErrorURI(),
                        'error_details' => $message->getDetails(),
                    ]);
                }
            );
        }

        $realmSession->sendMessage($callMessage);

        return $deferred->promise();
    }

    public function setRealmManager(RealmManager $realmManager): void
    {
        $this->realmManager = $realmManager;
    }
}