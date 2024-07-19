<?php

namespace Octamp\Wamp\Auth;

use Octamp\Wamp\Auth\Response\AuthErrorResponse;
use Octamp\Wamp\Auth\Response\AuthSuccessResponse;
use Octamp\Wamp\Auth\Response\HelloErrorResponse;
use Octamp\Wamp\Auth\Response\HelloSuccessResponse;
use Octamp\Wamp\Promise\Promise;
use Octamp\Wamp\Promise\PromiseInterrupted;
use Octamp\Wamp\Promise\PromiseErrorException;
use Octamp\Wamp\Promise\PromiseInterface;
use Octamp\Wamp\Realm\RealmManager;
use Octamp\Wamp\Session\Session;
use Thruway\Message\AuthenticateMessage;
use Thruway\Message\HelloMessage;

class AnonymousDynamicAuthenticator extends AbstractDynamicAuthenticator
{
    protected ?RealmManager $realmManager;

    public function processHello(Session $session, HelloMessage $message): HelloSuccessResponse|HelloErrorResponse
    {
        try {
            $result =  $this->sendMessageToAuthenticator($message, [])->wait();
            $authDetails = [];
            if (isset($result->authid)) {
                $authDetails['authid'] = $result->authid;
            }
            return $this->generateNoChallengeResponse($authDetails, $result);
        } catch (PromiseErrorException $exception) {
            if ($exception->getData() instanceof PromiseInterrupted) {
                return $this->generateFailureResponse('wamp.error.unknown', []);
            }
            return $this->generateFailureResponse($exception->getData()['error_uri'] ?? '', $exception->getData()['error_details'] ?? []);
        }
    }

    public function processAuthenticate(Session $session, AuthenticateMessage $message): AuthSuccessResponse|AuthErrorResponse
    {
        return $this->generateSuccessResponse([]);
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