<?php

declare(strict_types=1);

namespace Octamp\Wamp\Auth\WampCra;

use DateTimeInterface;
use Octamp\Wamp\Auth\AbstractDynamicAuthenticator;
use Octamp\Wamp\Auth\Response\AuthErrorResponse;
use Octamp\Wamp\Auth\Response\AuthSuccessResponse;
use Octamp\Wamp\Auth\Response\HelloErrorResponse;
use Octamp\Wamp\Auth\Response\HelloSuccessResponse;
use Octamp\Wamp\Session\Session;
use OpenSwoole\Table;
use Thruway\Message\AuthenticateMessage;
use Thruway\Message\HelloMessage;

class WampCraStaticAuthenticator extends AbstractDynamicAuthenticator
{
    protected Table $table;

    protected function init(): void
    {
        $principals = $this->config['users'] ?? [];

        $principals = array_map(function ($principal) {
            return [
                'authid' => $principal['authid'],
                'secretDetails' => json_encode($principal),
                'role' => $principal['role'],
            ];
        }, $principals);
        $maxAuthIdLen = strlen(max(array_column($principals, 'authid')));
        $maxSecretLen = strlen(max(array_column($principals, 'secretDetails')));
        $maxRoleLen = strlen(max(array_column($principals, 'role')));

        $this->table = new Table(count($principals));
        $this->table->column('authid', Table::TYPE_STRING, $maxAuthIdLen);
        $this->table->column('secretDetails', Table::TYPE_STRING, $maxSecretLen);
        $this->table->column('role', Table::TYPE_STRING, $maxRoleLen);
        $this->table->create();

        foreach ($principals as $principal) {
            $this->table->set($principal['authid'], $principal);
        }
    }

    public function processHello(Session $session, HelloMessage $message): HelloSuccessResponse|HelloErrorResponse
    {
        $helloDetails = $message->getDetails();
        $authId = $helloDetails->authid ?? null;
        if ($authId === null) {
            return $this->generateFailureResponse('wamp.error.authentication_required', ['message' => 'authid required']);
        }

        if (!$this->table->exists($authId)) {
            return $this->generateFailureResponse('wamp.error.no_such_principal', ['message' => 'authid "' . $authId . '" does not exists']);
        }

        $details = $this->table->get($authId);
        $secretDetails = json_decode($details['secretDetails'], true);

        $challengeDetails = [
            'challenge' => json_encode([
                'authid' => $authId,
                'authrole' => $details['role'],
                'authprovider' => 'userdb',
                'authmethod' => $this->getMethod(),
                'nonce' => bin2hex(random_bytes(16)),
                'timestamp' => (new \DateTime())->format(DateTimeInterface::ATOM),
                'sesssion' => $session->getSessionId(),
            ]),
        ];

        if (isset($secretDetails['salt'])) {
            $challengeDetails['salt'] = $secretDetails['salt'];
            $challengeDetails['keylen'] = $secretDetails['keylen'];
            $challengeDetails['iterations'] = $secretDetails['iterations'];
        }


        return $this->generateChallengeResponse(['authid' => $authId], $secretDetails, $challengeDetails);
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

        return $this->generateSuccessResponse(authDetails: [
            'authid'       => $verificationDetails->authid,
            'authrole'     => $verificationDetails->role,
            'authprovider' => 'userdb',
        ]);
    }

    public function getMethod(): string
    {
        return 'wampcra';
    }
}
