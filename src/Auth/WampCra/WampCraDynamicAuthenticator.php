<?php

namespace Octamp\Wamp\Auth\WampCra;

use DateTimeInterface;
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

class WampCraDynamicAuthenticator extends AbstractDynamicAuthenticator
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

            $challengeDetails = [
                'challenge' => json_encode([
                    'authid' => $authId,
                    'authrole' => $result->role ?? '',
                    'authprovider' => $result->authprovider ?? 'dynamic',
                    'authmethod' => $this->getMethod(),
                    'nonce' => bin2hex(random_bytes(16)),
                    'timestamp' => (new \DateTime())->format(DateTimeInterface::ATOM),
                    'sesssion' => $session->getSessionId(),
                ]),
            ];

            if (isset($result->salt)) {
                $challengeDetails['salt'] = $result->salt;
                $challengeDetails['keylen'] = $result->keylen ?? 32;
                $challengeDetails['iterations'] = $result->iteration ?? 1000;
            }

            return $this->generateChallengeResponse($authDetails, $result, $challengeDetails);
        } catch (PromiseErrorException $exception) {
            if ($exception->getData() instanceof PromiseInterrupted) {
                return $this->generateFailureResponse('wamp.error.unknown', []);
            }
            return $this->generateFailureResponse($exception->getData()['error_uri'] ?? '', $exception->getData()['error_details'] ?? []);
        }
    }

    public function processAuthenticate(Session $session, AuthenticateMessage $message): AuthSuccessResponse|AuthErrorResponse
    {
        $challenge = $session->getAuthenticationDetails()->getChallenge();
        if ($challenge === null) {
            return $this->generatedErrorResponse('wamp.error.authentication_failed', []);
        }
        $authId = $session->getAuthenticationDetails()->getAuthId();
        if ($authId === null) {
            return $this->generatedErrorResponse('wamp.error.authentication_required', ['message' => 'authid required']);
        }
        $verificationDetails = $session->getAuthenticationDetails()->getVerificationDetails();
        $signature = $message->getSignature();
        $secret = $verificationDetails->secret;
        $token = base64_encode(hash_hmac('sha256', $challenge, $secret, true));
        if ($token !== $signature) {
            return $this->generatedErrorResponse('wamp.error.authentication_denied', ['message' => 'Invalid signature']);
        }

        $authDetails = [
            'authid' => $verificationDetails->authid,
            'authprovider' => $verificationDetails->authprovider ?? 'dynamic',
        ];

        if (isset($verificationDetails->role)) {
            $authDetails['authrole'] = $verificationDetails->role;
        }
        if (isset($verificationDetails->extra)) {
            $authDetails['authextra'] = $verificationDetails->extra;
        }
        if (isset($verificationDetails->roles)) {
            $authDetails['authroles'] = $verificationDetails->roles;
        }

        return $this->generateSuccessResponse($authDetails);
    }

    public function getMethod(): string
    {
        return 'wampcra';
    }
}