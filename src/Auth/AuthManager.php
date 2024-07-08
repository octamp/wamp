<?php

declare(strict_types=1);

namespace Octamp\Wamp\Auth;

use Octamp\Wamp\Promise\Promise;
use Octamp\Wamp\Promise\PromiseInterface;
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

    public const HELLO_SUCCESS = 1;
    public const HELLO_FAIL = 2;
    public const HELLO_NEXT = 3;


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
            $errorDetails = [];

            $promise = new Promise(function ($resolve) use ($authenticators, $session, $message, $errorUri, $errorDetails) {
                $this->processHelloCurrentAuthenticators($authenticators, $session, $message, $errorUri, $errorDetails, $resolve);
            });
            $promise->then(function ($result) use ($session, $message) {
                [$status,] = $result;
                if ($status === self::HELLO_FAIL) {
                    [, $uri, $details] = $result;
                    $session->abort((object) $details, $uri);
                    return;
                }
                [, $res, $authenticator] = $result;

                $authDetailsRaw = $res['auth_details'] ?? [];
                $status = $res['status'];

                $authDetails = new AuthenticationDetails();
                $authDetails->setAuthId($authDetailsRaw['authid']);
                $authDetails->setAuthMethod($authenticator->getMethod());
                $authDetails->setAuthenticator($authenticator);

                if (isset($authDetailsRaw['authrole'])) {
                    $authDetails->addAuthRole($authDetailsRaw['authrole']);
                }
                if (isset($authDetailsRaw['authroles'])) {
                    $authDetails->addAuthRole($authDetailsRaw['authroles']);
                }
                if (isset($authDetailsRaw['authextra'])) {
                    $authDetails->setAuthExtra($authDetailsRaw['authextra']);
                }
                $session->setAuthenticationDetails($authDetails);

                if ($status === self::STATUS_CHALLENGE) {
                    $challengeDetails = $res['challenge_details'];
                    $authMethod = $challengeDetails['challenge_method'];
                    $challenge = $challengeDetails['challenge'] ?? [];

                    $session->getAuthenticationDetails()->setChallengeDetails($challengeDetails);
                    $session->getAuthenticationDetails()->setChallenge($challenge);
                    if (isset($res['verify_details'])) {
                        $session->getAuthenticationDetails()->setVerificationDetails($res['verify_details']);
                    }

                    $challengeDetails = $session->getAuthenticationDetails()->getChallengeDetails();
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
        });
    }

    /**
     * @param AuthenticatorInterface[] $authenticators
     * @param Session $session
     * @param HelloMessage $message
     * @return void
     */
    public function processHelloCurrentAuthenticators(array &$authenticators, Session $session, HelloMessage $message, string $errorUri, array $errorDetails, callable $resolve): void
    {
        do {
            if (key($authenticators) === null) {
                $resolve([self::HELLO_FAIL, $errorUri, $errorDetails]);
                break;
            }

            $authenticator = current($authenticators);
            $promise = $authenticator->processHello($session, $message);
            $restPromise = $promise->then(function ($res) use ($resolve, $authenticator, &$authenticators, &$errorUri, &$errorDetails, $session, $message) {
                $status = $res['status'] ?? self::STATUS_FAILURE;
                if (in_array($status, [self::STATUS_CHALLENGE, self::STATUS_NO_CHALLENGE])) {
                    $resolve([self::HELLO_SUCCESS, $res, $authenticator]);

                    return false;
                } else {
                    $errorUri = $res['error_uri'] ?? $errorUri;
                    $errorDetails = $res['error_details'] ?? $errorDetails;
                    next($authenticators);
                    return true;
                }
            });
            $result = $restPromise->wait();
        } while ($result);
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
        $status = $res['status'] ?? self::STATUS_FAILURE;

        if ($status !== self::STATUS_SUCCESS) {
            $session->abort((object) ($res['error_details'] ?? $errorDetails), $res['error_uri'] ?? $errorUri);
            return;
        }

        $authDetailsRaw = $res['auth_details'] ?? [];

        if (isset($authDetailsRaw['authid'])) {
            $session->getAuthenticationDetails()->setAuthId($authDetailsRaw['authid']);
        }

        if (isset($authDetailsRaw['authrole'])) {
            $session->getAuthenticationDetails()->addAuthRole($authDetailsRaw['authrole']);
        }
        if (isset($authDetailsRaw['authroles'])) {
            $session->getAuthenticationDetails()->addAuthRole($authDetailsRaw['authroles']);
        }
        if (isset($authDetailsRaw['authextra'])) {
            $session->getAuthenticationDetails()->setAuthExtra($authDetailsRaw['authextra']);
        }
        if (isset($authDetailsRaw['authprovider'])) {
            $session->getAuthenticationDetails()->setAuthProvider($authDetailsRaw['authprovider']);
        }

        $session->setAuthenticated(true);
        $session->sendMessage(new WelcomeMessage($session->getSessionId(), $session->getAuthenticationDetails()->jsonSerialize()));
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