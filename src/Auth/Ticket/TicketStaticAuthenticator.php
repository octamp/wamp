<?php

namespace Octamp\Wamp\Auth\Ticket;

use Octamp\Wamp\Auth\AbstractAuthenticator;
use Octamp\Wamp\Auth\Response\AuthErrorResponse;
use Octamp\Wamp\Auth\Response\AuthSuccessResponse;
use Octamp\Wamp\Auth\Response\HelloErrorResponse;
use Octamp\Wamp\Auth\Response\HelloSuccessResponse;
use Octamp\Wamp\Session\Session;
use OpenSwoole\Table;
use Thruway\Message\AuthenticateMessage;
use Thruway\Message\HelloMessage;

class TicketStaticAuthenticator extends AbstractAuthenticator
{
    protected Table $table;

    protected function init(): void
    {
        $principals = $this->config['principals'] ?? [];

        $maxAuthIdLen = max(array_column($principals, 'authid'));
        $maxSecretLen = max(array_column($principals, 'ticket'));
        $maxRoleLen = max(array_column($principals, 'role'));

        $this->table = new Table(count($principals));
        $this->table->column('authid', Table::TYPE_STRING, $maxAuthIdLen);
        $this->table->column('ticket', Table::TYPE_STRING, $maxSecretLen);
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

        return $this->generateChallengeResponse(['authid' => $authId]);
    }

    public function processAuthenticate(Session $session, AuthenticateMessage $message): AuthSuccessResponse|AuthErrorResponse
    {
        $authId = $session->getAuthenticationDetails()->getAuthId();
        if ($authId === null) {
            return $this->generatedErrorResponse('wamp.error.authentication_required', ['message' => 'authid required']);
        }
        if (!$this->table->exists($authId)) {
            return $this->generatedErrorResponse('wamp.error.no_such_principal', ['message' => 'authid "' . $authId . '" does not exists']);
        }

        $principal = $this->table->get($authId);
        if ($principal['ticket'] !== $message->getSignature()) {
            return $this->generatedErrorResponse('wamp.error.authentication_denied', ['message' => 'Invalid ticket / signature']);
        }

        return $this->generateSuccessResponse([
            'authid' => $authId,
            'authrole' => $principal['role'],
        ]);
    }

    public function getMethod(): string
    {
        return 'ticket';
    }
}