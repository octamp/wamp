<?php

declare(strict_types=1);

namespace Octamp\Wamp\Auth;

use Octamp\Wamp\Auth\Response\HelloErrorResponse;
use Octamp\Wamp\Auth\Response\HelloSuccessResponse;
use Octamp\Wamp\Promise\PromiseErrorException;
use Octamp\Wamp\Realm\RealmManager;
use Octamp\Wamp\Session\Session;
use OpenSwoole\Coroutine;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thruway\Message\AuthenticateMessage;
use Thruway\Message\ChallengeMessage;
use Thruway\Message\HelloMessage;
use Thruway\Message\WelcomeMessage;

class AuthManager implements WithRealmManagerInterface
{
    public const STATUS_CHALLENGE = 1;
    public const STATUS_NO_CHALLENGE = 2;
    public const STATUS_SUCCESS = 3;
    public const STATUS_FAILURE = 4;


    /**
     * @var AuthenticatorInterface[]
     */
    protected array $authenticators = [];

    protected ?RealmManager $realmManager = null;

    public function __construct(array $auths, protected EventDispatcherInterface $eventDispatcher)
    {
        foreach ($auths as $auth) {
            try {
                $this->addAuthenticator($this->generateAuthenticator($auth));
            } catch (\Exception $exception) {
                // TODO log exception
            }
        }

        if (empty($this->authenticators)) {
            $this->addAuthenticator(new AnonymousStaticAuthenticator([]));
        }
    }

    public function addAuthenticator(AuthenticatorInterface $authenticator): void
    {
        if ($authenticator instanceof WithRealmManagerInterface && $this->realmManager !== null) {
            $authenticator->setRealmManager($this->realmManager);
        }
        $this->authenticators[] = $authenticator;
    }

    public function generateAuthenticator(array $data): ?AuthenticatorInterface
    {
        $class = '\\Octamp\\Wamp\\Auth\\' . ucwords($data['method']) . ucwords($data['type']) . 'Authenticator';
        if (!class_exists($class)) {
            throw new \Exception($data['method'] . ' with type "' . $data['type'] . '" is not known authenticator');
        }

        return new $class($data);
    }

    public function processHelloMessage(Session $session, HelloMessage $message): void
    {
        Coroutine::create(function () use ($session, $message) {
            $authenticators = $this->getAuthenticators($session, $message);
            if (empty($authenticators)) {
                $session->abort((object) ['message' => 'No matching authentication method'], ' wamp.error.no_matching_auth_method');
                return;
            }

            $errorUri = 'wamp.error.authentication_failed';
            $errorDetails = new \stdClass();

            $status = self::STATUS_FAILURE;
            /* @var HelloSuccessResponse|HelloErrorResponse $res */
            $res = null;
            $successAuthenticator = null;
            foreach ($authenticators as $authenticator) {
                /* @var HelloSuccessResponse|HelloErrorResponse $res */
                $res = $authenticator->processHello($session, $message);

                $successAuthenticator = $authenticator;
                $status = $res->status;
                if ($status !== self::STATUS_FAILURE) {
                    break;
                }

                $errorUri = $res->errorUri ?: $errorUri;
                $errorDetails = $res->errorDetails ?? $errorDetails;
            }

            if ($status === self::STATUS_FAILURE) {
                $session->abort($errorDetails, $errorUri);
                return;
            }

            $authDetailsRaw = $res->authDetails ?: new \stdClass();
            $authDetails = new AuthenticationDetails();
            $authDetails->setAuthId($authDetailsRaw->authid ?? null);
            $authDetails->setAuthMethod($successAuthenticator->getMethod());
            $authDetails->setAuthenticator($successAuthenticator);

            if (isset($authDetailsRaw->authrole)) {
                $authDetails->addAuthRole($authDetailsRaw->authrole);
            }
            if (isset($authDetailsRaw->authroles)) {
                $authDetails->addAuthRole($authDetailsRaw->authroles);
            }
            if (isset($authDetailsRaw->authextra)) {
                $authDetails->setAuthExtra($authDetailsRaw->authextra);
            }
            $session->setAuthenticationDetails($authDetails);

            if ($status === self::STATUS_CHALLENGE) {
                $challengeDetails = $res->challengeDetails;
                $authMethod = $res->challengeMethod;
                $challenge = $challengeDetails?->challenge ?? [];

                $session->getAuthenticationDetails()->setChallengeDetails($challengeDetails ?: []);
                $session->getAuthenticationDetails()->setChallenge($challenge);
                if ($res->verifyDetails !== null) {
                    $session->getAuthenticationDetails()->setVerificationDetails($res->verifyDetails);
                }

                $challengeDetails = $session->getAuthenticationDetails()->getChallengeDetails() ?: new \stdClass();
                $session->sendMessage(new ChallengeMessage($authMethod, $challengeDetails));
            } elseif ($status === self::STATUS_NO_CHALLENGE) {
                $session->setAuthenticated(true);
                $details = $session->getAuthenticationDetails()->jsonSerialize();
                // todo update roles for details
                $details = array_merge($details, (array) $message->getDetails());
                $session->sendMessage(new WelcomeMessage(
                    $session->getSessionId(),
                    $details
                ));
            }
        });
    }

    public function processAuthenticateMessage(Session $session, AuthenticateMessage $message): void
    {
        if ($session->getAuthenticationDetails() === null) {
            // TODO log
            return;
        }
        $authenticator = $session->getAuthenticationDetails()->getAuthenticator();
        if ($authenticator === null) {
            $session->abort((object) [], 'wamp.error.authentication_failed');
            return;
        }

        $errorUri = 'wamp.error.authentication_failed';
        $errorDetails = [];

        $res = $authenticator->processAuthenticate($session, $message);
        $status = $res->status ?? self::STATUS_FAILURE;

        if ($status !== self::STATUS_SUCCESS) {
            $session->abort((object) ($res->errorDetails ?: $errorDetails), $res->errorUri ?: $errorUri);

            return;
        }

        $authDetailsRaw = $res->authDetails ?: (object) [];

        if (isset($authDetailsRaw->authid)) {
            $session->getAuthenticationDetails()->setAuthId($authDetailsRaw->authid);
        }

        if (isset($authDetailsRaw->authrole)) {
            $session->getAuthenticationDetails()->addAuthRole($authDetailsRaw->authrole);
        }
        if (isset($authDetailsRaw->authroles)) {
            $session->getAuthenticationDetails()->addAuthRole($authDetailsRaw->authroles);
        }
        if (isset($authDetailsRaw->authextra)) {
            $session->getAuthenticationDetails()->setAuthExtra($authDetailsRaw->authextra);
        }
        if (isset($authDetailsRaw->authprovider)) {
            $session->getAuthenticationDetails()->setAuthProvider($authDetailsRaw->authprovider);
        }

        $session->setAuthenticated(true);
        $details = $session->getAuthenticationDetails()->jsonSerialize();
        // todo update roles for details
        $details = array_merge($details, (array) $session->getHelloMessage()->getDetails());

        $session->sendMessage(new WelcomeMessage(
            $session->getSessionId(),
            $details
        ));
    }

    /**
     * @param Session $session
     * @param HelloMessage $helloMessage
     * @return AuthenticatorInterface[]
     */
    protected function getAuthenticators(Session $session, HelloMessage $helloMessage): array
    {
        $authenticators = [];
        $authMethods = $helloMessage->getDetails()->authmethods ?? ['anonymous'];
        foreach ($this->authenticators as $authenticator) {
            if ($authenticator->canAuthenticate($session, $helloMessage, $authMethods)) {
                $authenticators[] = $authenticator;
            }
        }

        return $authenticators;
    }

    public function setRealmManager(RealmManager $realmManager): void
    {
        $this->realmManager = $realmManager;

        foreach ($this->authenticators as $authenticator) {
            if ($authenticator instanceof WithRealmManagerInterface) {
                $authenticator->setRealmManager($this->realmManager);
            }
        }
    }
}