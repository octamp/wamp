<?php

declare(strict_types=1);

namespace Octamp\Wamp\Auth\Ticket;

use Octamp\Wamp\Auth\AbstractDynamicAuthenticator;
use Octamp\Wamp\Auth\Response\AuthErrorResponse;
use Octamp\Wamp\Auth\Response\AuthSuccessResponse;
use Octamp\Wamp\Auth\Response\HelloErrorResponse;
use Octamp\Wamp\Auth\Response\HelloSuccessResponse;
use Octamp\Wamp\Promise\PromiseErrorException;
use Octamp\Wamp\Promise\PromiseInterrupted;
use Octamp\Wamp\Session\Session;
use Thruway\Message\AuthenticateMessage;
use Thruway\Message\HelloMessage;

class TicketDynamicAuthenticator extends AbstractDynamicAuthenticator
{
    public function processHello(Session $session, HelloMessage $message): HelloSuccessResponse|HelloErrorResponse
    {
        $helloDetails = $message->getDetails();
        $authId = $helloDetails->authid ?? null;

        if ($authId === null) {
            return $this->generateFailureResponse('wamp.error.authentication_required', ['message' => 'authid required']);
        }

        try {
            $result = $this->sendMessageToAuthenticator($message, ['authid' => $authId])->wait();
            $authDetails = [];
            if (isset($result->authid)) {
                $authDetails['authid'] = $result->authid;
            } else {
                $authDetails['authid'] = $authId;
            }

            return $this->generateChallengeResponse($authDetails, $result);
        } catch (PromiseErrorException $exception) {
            if ($exception->getData() instanceof PromiseInterrupted) {
                return $this->generateFailureResponse('wamp.error.unknown', []);
            }
            return $this->generateFailureResponse($exception->getData()['error_uri'] ?? '', $exception->getData()['error_details'] ?? []);
        }
    }

    public function processAuthenticate(Session $session, AuthenticateMessage $message): AuthSuccessResponse|AuthErrorResponse
    {
        $verificationDetails = $session->getAuthenticationDetails()->getVerificationDetails();
        if ($verificationDetails === null) {
            return $this->generatedErrorResponse('wamp.error.authentication_denied', ['message' => 'Invalid ticket / signature']);
        }

        $ticket = $verificationDetails->ticket ?? null;
        if ($ticket !== $message->getSignature()) {
            return $this->generatedErrorResponse('wamp.error.authentication_denied', ['message' => 'Invalid ticket / signature']);
        }

        $authDetails = [];
        if (isset($verificationDetails->authid)) {
            $authDetails['authid'] = $verificationDetails->authid;
        }
        if (isset($verificationDetails->role)) {
            $authDetails['authrole'] = $verificationDetails->role;
        }
        if (isset($verificationDetails->authextra)) {
            $authDetails['authextra'] = $verificationDetails->extra;
        }
        if (isset($verificationDetails->role)) {
            $authDetails['authprovider'] = $verificationDetails->authprovider;
        }

        return $this->generateSuccessResponse($authDetails);
    }

    public function getMethod(): string
    {
        return 'ticket';
    }
}
