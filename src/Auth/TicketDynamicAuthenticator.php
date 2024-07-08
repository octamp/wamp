<?php

declare(strict_types=1);

namespace Octamp\Wamp\Auth;

use Octamp\Wamp\Auth\Event\ResultMessageEvent;
use Octamp\Wamp\Connection\Event\SendMessageEvent;
use Octamp\Wamp\Connection\WithEventDispatcherInterface;
use Octamp\Wamp\Helper\IDHelper;
use Octamp\Wamp\Promise\Promise;
use Octamp\Wamp\Promise\PromiseInterface;
use Octamp\Wamp\Realm\RealmManager;
use Octamp\Wamp\Session\Event\MessageEvent;
use Octamp\Wamp\Session\Session;
use Thruway\Message\AuthenticateMessage;
use Thruway\Message\CallMessage;
use Thruway\Message\ErrorMessage;
use Thruway\Message\HelloMessage;
use Thruway\Message\Message;
use Thruway\Message\ResultMessage;
use Thruway\Message\YieldMessage;

class TicketDynamicAuthenticator extends AbstractAuthenticator implements WithRealmManagerInterface
{
    protected ?RealmManager $realmManager;

    public function processHello(Session $session, HelloMessage $message): PromiseInterface
    {
        return new Promise(function (callable $resolve) use ($session, $message): void {
            $helloDetails = $message->getDetails();
            $authId = $helloDetails->authid ?? null;

            if ($authId === null) {
                $resolve([
                    'status' => AuthManager::STATUS_FAILURE,
                    'error_uri' => 'wamp.error.authentication_required',
                    'error_details' => ['message' => 'authid required'],
                ]);
                return;
            }

            $procedureName = $this->config['authenticator'];
            $realm = $this->realmManager->getRealm($this->config['authenticator-realm']);

            $realmSession = $realm->getMetaSession();
            $requestId = IDHelper::incrementSessionWampID($realmSession);
            $args = [
                'authid' => $authId,
                'authmethod' => $this->getMethod(),
            ];

            if (isset($helloDetails->authextra)) {
                $args['authextra'] = $helloDetails->authextra;
            }
            $callMessage = new CallMessage($requestId, [], $procedureName, [
                $realm->name,
                $authId,
                $args
            ]);

            $connection = $realmSession->getTransport()->getConnection();
            if ($connection instanceof WithEventDispatcherInterface) {
                $connection->once('Message:' . Message::MSG_CALL . ':' . $callMessage->getRequestId(), function (MessageEvent $event): void {
                    $this->realmManager->dispatch($event->session, $event->message);
                });
                $connection->once(
                    'Message:' . Message::MSG_RESULT . ':' . $callMessage->getRequestId(),
                    function (MessageEvent $event) use ($connection, $callMessage, $resolve, $authId): void {
                        $connection->removeListenersForEvent('Message:' . Message::MSG_ERROR . ':' .$callMessage->getRequestId());
                        /** @var ResultMessage $message */
                        $message = $event->message;
                        $data = $message->getArguments();
                        if (empty($data)) {
                            $resolve([
                                'status' => AuthManager::STATUS_FAILURE,
                            ]);
                            return;
                        }

                        $result = $data[0];
                        $success = $result['status'] ?? true;

                        if (!$success) {
                            $resolve([
                                'status' => AuthManager::STATUS_FAILURE,
                                'error_uri' => $result['error_uri'] ?? null,
                                'error_details' => $result['error_details'] ?? null,
                            ]);
                        } else {
                            $resolve([
                                'status' => AuthManager::STATUS_CHALLENGE,
                                'auth_details' => [
                                    'authid' => $authId,
                                ],
                                'verify_details' => $result,
                                'challenge_details' => [
                                    'challenge_method' => $this->getMethod(),
                                ],
                            ]);
                        }
                    }
                );
                $connection->once(
                    'Message:' . Message::MSG_ERROR . ':' . $callMessage->getRequestId(),
                    function (MessageEvent $event) use ($connection, $callMessage, $resolve): void {
                        $connection->removeListenersForEvent('Message:' . Message::MSG_RESULT . ':' .$callMessage->getRequestId());
                        /** @var ErrorMessage $message */
                        $message = $event->message;

                        $resolve([
                            'status' => AuthManager::STATUS_FAILURE,
                            'error_uri' => $message->getErrorURI(),
                            'error_details' => $message->getDetails(),
                        ]);
                    }
                );
            }

            $realmSession->sendMessage($callMessage);
        });
    }

    public function processAuthenticate(Session $session, AuthenticateMessage $message): PromiseInterface
    {
    }

    public function getMethod(): string
    {
        return 'ticket';
    }

    public function setRealmManager(RealmManager $realmManager): void
    {
        $this->realmManager = $realmManager;
    }
}