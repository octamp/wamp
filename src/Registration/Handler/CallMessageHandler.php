<?php

namespace Octamp\Wamp\Registration\Handler;

use Octamp\Wamp\Helper\UriHelper;
use Octamp\Wamp\Matcher\ExactMatch;
use Octamp\Wamp\Matcher\PrefixMatch;
use Octamp\Wamp\Matcher\WildcardMatch;
use Octamp\Wamp\Registration\Procedure;
use Octamp\Wamp\Registration\RegistrationStorage;
use Octamp\Wamp\Session\Session;
use Thruway\Message\CallMessage;
use Thruway\Message\ErrorMessage;

class CallMessageHandler
{
    protected ExactMatch $exactMatch;
    protected PrefixMatch $prefixMatch;
    protected WildcardMatch $wildcardMatch;

    public function __construct(protected RegistrationStorage $registrationStorage, protected ProcedureHandler $procedureHandler)
    {
        $this->exactMatch = new ExactMatch();
        $this->prefixMatch = new PrefixMatch();
        $this->wildcardMatch = new WildcardMatch();
    }

    public function handleMessage(Session $session, CallMessage $message): void
    {
        if (!UriHelper::uriIsValidStrict($message->getUri(), false, true)) {
            $session->sendMessage(ErrorMessage::createErrorMessageFromMessage($message, 'wamp.error.invalid_uri'));
            return;
        }

        $procedure = $this->registrationStorage->getProcedure($message->getProcedureName(), $session->getRealm()->getRealmName());
        if ($procedure === null) {
            $error = ErrorMessage::createErrorMessageFromMessage($message, 'wamp.error.no_such_procedure');
            $error->setArgumentsKw((object)['procedure' => $message->getProcedureName()]);
            $session->sendMessage($error);
            return;
        }

        $this->procedureHandler->handleCallMessage($session, $message, $procedure);
    }
}