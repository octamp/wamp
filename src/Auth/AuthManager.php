<?php

namespace Octamp\Wamp\Auth;

use Octamp\Wamp\Realm\RealmManager;
use Octamp\Wamp\Session\Session;
use Thruway\Message\AuthenticateMessage;
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

    protected ?RealmManager $realmManager;

    public function __construct(array $auths)
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
        $authenticators = $this->getAuthenticators($session, $message);
        if (empty($authenticators)) {
            $session->abort((object) ['message' => 'No matching authentication method'], ' wamp.error.no_matching_auth_method');
            return;
        }

        $errorUri = 'wamp.error.authentication_failed';
        $errorDetails = [];

        foreach ($authenticators as $authenticator) {
            $res = $authenticator->processHello($session, $message);

            $authDetailsRaw = $res['auth_details'] ?? [];
            $status = $res['status'] ?? self::STATUS_FAILURE;

            if (in_array($status, [self::STATUS_CHALLENGE, self::STATUS_NO_CHALLENGE])) {
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
            } else {
                $errorUri = $res['error_uri'] ?? $errorUri;
                $errorDetails = $res['error_details'] ?? $errorDetails;
                continue;
            }

            if ($status === self::STATUS_CHALLENGE) {
                $challengeDetails = $res['challenge_details'];
                $authMethod = $challengeDetails['challenge_method'];
                $challenge = $challengeDetails['challenge'] ?? [];

                $session->getAuthenticationDetails()->setChallengeDetails($challengeDetails);
                $session->getAuthenticationDetails()->setChallenge($challenge);

                $challengeDetails = $session->getAuthenticationDetails()->getChallengeDetails();
                $session->sendMessage(new ChallengeMessage($authMethod, $challengeDetails));

                return;
            } elseif ($status === self::STATUS_NO_CHALLENGE) {
                $session->setAuthenticated(true);
                $session->sendMessage(new WelcomeMessage($session->getSessionId(), $session->getAuthenticationDetails()->jsonSerialize()));
                return;
            }
        }

        $session->abort((object) $errorDetails, $errorUri);
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
        foreach ($this->authenticators as $authenticator) {
            if ($authenticator->canAuthenticate($session, $helloMessage, $helloMessage->getAuthMethods())) {
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