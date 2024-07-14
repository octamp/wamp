<?php

namespace Octamp\Wamp\Auth;

use Octamp\Wamp\Promise\Promise;
use Octamp\Wamp\Promise\PromiseInterface;
use Octamp\Wamp\Realm\RealmManager;
use Octamp\Wamp\Session\Session;
use Thruway\Message\AuthenticateMessage;
use Thruway\Message\HelloMessage;

class AnonymousDynamicAuthenticator extends AbstractDynamicAuthenticator
{
    protected ?RealmManager $realmManager;

    public function processHello(Session $session, HelloMessage $message): PromiseInterface
    {
        $promise = $this->sendMessageToAuthenticator($message, []);
        return $promise->then(function ($result) {
            $authDetails = [];
            if (isset($result['authid'])) {
                $authDetails['authid'] = $result['authid'];
            }
            return [
                'status' => AuthManager::STATUS_NO_CHALLENGE,
                'auth_details' => $authDetails,
                'verify_details' => $result,
                'challenge_details' => [
                    'challenge_method' => $this->getMethod(),
                ],
            ];
        }, function ($result) {
            $response = [
                'status' => AuthManager::STATUS_FAILURE,
            ];
            if (isset($result['error_uri'])) {
                $response['error_uri'] = $result['error_uri'];
            }

            if (isset($result['error_details'])) {
                $response['error_details'] = $result['error_details'];
            }

            return $response;
        });
    }

    public function processAuthenticate(Session $session, AuthenticateMessage $message): PromiseInterface
    {
        return new Promise(function (callable $resolve) {
            $resolve(['status' => AuthManager::STATUS_SUCCESS]);
        });
    }

    public function getMethod(): string
    {
        return 'anonymous';
    }

    public function setRealmManager(RealmManager $realmManager): void
    {
        $this->realmManager = $realmManager;
    }
}