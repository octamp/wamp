<?php

namespace Octamp\Wamp\Registration\Handler;

use Octamp\Wamp\Adapter\AdapterInterface;
use Octamp\Wamp\Registration\CallInvocationStorage;
use Octamp\Wamp\Session\Session;
use Octamp\Wamp\Session\SessionStorage;
use Thruway\Message\ErrorMessage;
use Thruway\Message\Message;
use Thruway\Message\ResultMessage;
use Thruway\Message\YieldMessage;

class YieldMessageHandler
{
    public function __construct(protected SessionStorage $sessionStorage, protected CallInvocationStorage $callInvocationStorage, protected AdapterInterface $adapter, protected string $serverId)
    {
        $this->adapter->subscribe('process:yield', function (string $fromServer, string $server, string $realm, string $sessionId, string|int $requestId, array $message) {
            if ($server !== $this->serverId) {
                return;
            }

            $caller = $this->sessionStorage->getSession($realm, $sessionId);
            $yieldMessage = Message::createMessageFromArray($message);
            $this->processYieldMessage($caller, $yieldMessage, $requestId);
        });
    }

    public function handleMessage(Session $session, YieldMessage $message): void
    {
        $invocation = $this->callInvocationStorage->getInvocationUsingSession($session, $message->getRequestId());
        if ($invocation !== null && !$invocation->hasReceivedResponse() && !$invocation->isCanceled()) {
            $invocation->setToReceivedResponse();
            $callerSession = $invocation->callerSession;
            if ($callerSession->getServerId() !== $this->serverId) {
                $this->adapter->publish('process:yield', [
                    $callerSession->getServerId(),
                    $callerSession->getRealm(),
                    $callerSession->getSessionId(),
                    $invocation->callMessage->getRequestId(),
                    $message->getMessageParts(),
                ], $callerSession->getServerId());
            } else {
                $this->processYieldMessage($invocation->callerSession, $message, $invocation->callMessage->getRequestId());
            }
        }
        $this->callInvocationStorage->removeInvocationUsingSession($session, $message->getRequestId());
    }

    protected function processYieldMessage(Session $callerSession, YieldMessage $message, string|int $requestId): void
    {
        $call = $this->callInvocationStorage->getCallUsingSession($callerSession, $requestId);
        if ($call === null || $call->isSentResult()) {
            return;
        }

        $resultMessage = new ResultMessage(
            $requestId,
            new \stdClass(),
            $message->getArguments(),
            $message->getArgumentsKw()
        );
        $callerSession->sendMessage($resultMessage);
        $call->setSentResult();
        $this->callInvocationStorage->removeCallUsingSession($callerSession, $requestId);
    }
}
