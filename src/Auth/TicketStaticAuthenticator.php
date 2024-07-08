<?php

namespace Octamp\Wamp\Auth;

use Octamp\Wamp\Promise\Promise;
use Octamp\Wamp\Promise\PromiseInterface;
use Octamp\Wamp\Session\Session;
use OpenSwoole\Table;
use Thruway\Message\AuthenticateMessage;
use Thruway\Message\ChallengeMessage;
use Thruway\Message\HelloMessage;

class TicketStaticAuthenticator extends AbstractAuthenticator
{
    protected Table $table;

    protected function init(): void
    {
        parent::__construct($this->config);

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

    public function processHello(Session $session, HelloMessage $message): PromiseInterface
    {
        return new Promise(function (callable $resolve) use ($message) : void{
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

            if (!$this->table->exists($authId)) {
                $resolve([
                    'status' => AuthManager::STATUS_FAILURE,
                    'error_uri' => 'wamp.error.no_such_principal',
                    'error_details' => ['message' => 'authid "' . $authId . '" does not exists'],
                ]);

                return;
            }


            $resolve([
                'status' => AuthManager::STATUS_CHALLENGE,
                'auth_details' => [
                    'authid' => $authId,
                ],
                'challenge_details' => [
                    'challenge_method' => $this->getMethod(),
                ],
            ]);
        });
    }

    public function processAuthenticate(Session $session, AuthenticateMessage $message): PromiseInterface
    {
        return new Promise(function (callable $resolve) use ($session, $message) : void {
            $authId = $session->getAuthenticationDetails()->getAuthId();
            if ($authId === null) {
                $resolve([
                    'status' => AuthManager::STATUS_FAILURE,
                    'error_uri' => 'wamp.error.authentication_required',
                    'error_details' => ['message' => 'authid required'],
                ]);
                return;
            }

            if (!$this->table->exists($authId)) {
                $resolve([
                    'status' => AuthManager::STATUS_FAILURE,
                    'error_uri' => 'wamp.error.no_such_principal',
                    'error_details' => ['message' => 'authid "' . $authId . '" does not exists'],
                ]);
                return;
            }

            $principal = $this->table->get($authId);
            if ($principal['ticket'] !== $message->getSignature()) {
                $resolve([
                    'status' => AuthManager::STATUS_FAILURE,
                    'error_uri' => 'wamp.error.authentication_denied',
                    'error_details' => ['message' => 'Invalid ticket / signature'],
                ]);
                return;
            }

            $resolve([
                'status' => AuthManager::STATUS_SUCCESS,
                'auth_details' => [
                    'authid' => $authId,
                    'authrole' => $principal['role'],
                ],
            ]);
        });
    }

    public function getMethod(): string
    {
        return 'ticket';
    }
}