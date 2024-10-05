<?php

namespace Octamp\Wamp\Registration\Handler;

use Octamp\Wamp\Adapter\AdapterInterface;
use Octamp\Wamp\Registration\CallInvocationStorage;
use Octamp\Wamp\Session\Session;
use Octamp\Wamp\Session\SessionStorage;
use Thruway\Message\ErrorMessage;
use Thruway\Message\Message;

class ErrorMessageHandler
{
    public function __construct(protected SessionStorage $sessionStorage, protected CallInvocationStorage $callInvocationStorage, protected AdapterInterface $adapter, protected string $serverId)
    {
        $this->adapter->subscribe('process:invocationError', function (string $fromServer, string $server, string $realm, string $sessionId, string|int $requestId, array $message) {
            if ($server !== $this->serverId) {
                return;
            }

            $caller = $this->sessionStorage->getSession($realm, $sessionId);
            $yieldMessage = Message::createMessageFromArray($message);
            $this->processInvocationError($caller, $yieldMessage, $requestId);
        });
    }

    public function handlerMessage(Session $session, ErrorMessage $message): void
    {
        if ($message->getErrorMsgCode() === Message::MSG_INVOCATION) {
            $this->handleInvocationError($session, $message);
        }
    }

    protected function handleInvocationError(Session $session, ErrorMessage $message): void
    {
        $invocation = $this->callInvocationStorage->getInvocationUsingSession($session, $message->getRequestId());
        if ($invocation === null) {
            $session->sendMessage(ErrorMessage::createErrorMessageFromMessage($message));
        } elseif ($invocation->hasReceivedResponse()) {
            $session->sendMessage(ErrorMessage::createErrorMessageFromMessage($message, 'wamp.error.invocation_already_received_yield'));
        } elseif (!$invocation->isCanceled()) {
            $invocation->setToReceivedResponse();
            $callerSession = $invocation->callerSession;
            if ($callerSession->getServerId() !== $this->serverId) {
                $this->adapter->publish('process:invocationError', [
                    $callerSession->getServerId(),
                    $callerSession->getRealm(),
                    $callerSession->getSessionId(),
                    $invocation->callMessage->getRequestId(),
                    $message->getMessageParts(),
                ], $callerSession->getServerId());
            } else {
                $this->processInvocationError($invocation->callerSession, $message, $invocation->callMessage->getRequestId());
            }
        }

        $this->callInvocationStorage->removeInvocationUsingSession($session, $message->getRequestId());
    }

    protected function processInvocationError(Session $callerSession, ErrorMessage $message, string|int $requestId): void
    {
        $call = $this->callInvocationStorage->getCallUsingSession($callerSession, $requestId);
        if ($call->isSentResult()) {
            return;
        }

        $errorMessage = ErrorMessage::createErrorMessageFromMessage($call->message);
        $errorMessage->setDetails($message->getDetails());
        $errorMessage->setErrorURI($message->getErrorURI());
        $errorMessage->setArguments($message->getArguments());
        $errorMessage->setArgumentsKw($message->getArgumentsKw());

        $callerSession->sendMessage($errorMessage);
        $call->setSentResult();
        $this->callInvocationStorage->removeCallUsingSession($callerSession, $requestId);
    }
}