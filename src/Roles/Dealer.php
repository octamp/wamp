<?php

namespace Octamp\Wamp\Roles;

use Octamp\Wamp\Adapter\AdapterInterface;
use Octamp\Wamp\Event\LeaveRealmEvent;
use Octamp\Wamp\Registration\Procedure;
use Octamp\Wamp\Registration\Registration;
use Octamp\Wamp\Session\Session;
use Octamp\Wamp\Session\SessionStorage;
use Thruway\Message\ErrorMessage;
use Thruway\Message\RegisterMessage;
use Thruway\Message\UnregisterMessage;

class Dealer extends AbstractRole implements RoleInterface
{
    /**
     * @var Procedure[]
     */
    protected array $procedures = [];
    protected \SplObjectStorage $registrationsBySession;

    public function __construct(AdapterInterface $adapter, SessionStorage $sessionStorage)
    {
        parent::__construct($adapter, $sessionStorage);
        $this->registrationsBySession = new \SplObjectStorage();
    }

    public function onRegisterMessage(Session $session, RegisterMessage $message): void
    {
        $procedure = $this->procedures[$message->getProcedureName()] ?? new Procedure($message->getProcedureName());
        if ($procedure->processRegister($session, $message)) {
            if (!$this->registrationsBySession->contains($session)) {
                $this->registrationsBySession->attach($session, []);
            }
            $registrationsForThisSession = $this->registrationsBySession[$session];

            if (!in_array($procedure, $registrationsForThisSession)) {
                $registrationsForThisSession[] = $procedure;
                $this->registrationsBySession[$session] = $registrationsForThisSession;
            }
        }
    }

    public function onUnregisterMessage(Session $session, UnregisterMessage $message): void
    {
        $registration = $this->getRegistrationById($message->getRegistrationId());

        if ($registration && $this->procedures[$registration->getProcedureName()]) {
            $procedure = $this->procedures[$registration->getProcedureName()];
            if ($procedure !== null) {
                if ($procedure->processUnregister($session, $message)) {
                    // Unregistration was successful - remove from this sessions
                    // list of registrations
                    if ($this->registrationsBySession->contains($session) &&
                        in_array($procedure, $this->registrationsBySession[$session])
                    ) {
                        $registrationsInSession = $this->registrationsBySession[$session];
                        array_splice($registrationsInSession, array_search($procedure, $registrationsInSession), 1);
                    }
                }
            }

            return;
        }

        // apparently we didn't find anything to unregister
        $session->sendMessage(ErrorMessage::createErrorMessageFromMessage($message, 'wamp.error.no_such_procedure'));
    }

    protected function getRegistrationById(int $registrationId): ?Registration
    {
        foreach ($this->procedures as $procedure) {
            /** @var Registration $registration */
            $registration = $procedure->getRegistrationById($registrationId);

            if ($registration !== null) {
                return $registration;
            }
        }

        return null;
    }

    public function onLeaveRealmEvent(Session $session, LeaveRealmEvent $event): void
    {
//        foreach ($this->subscriptionGroups as $subscriptions) {
//            /** @var Subscription $subscription */
//            foreach ($subscriptions as $subscription) {
//                if ($subscription->getSession()->getId() === $session->getId()) {
//                    $this->removeSubscription($subscription);
//                }
//            }
//        }
    }
}