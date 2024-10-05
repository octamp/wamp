<?php

namespace Octamp\Wamp\Registration;

use Adbar\Dot;
use Octamp\Wamp\Adapter\AdapterInterface;
use Octamp\Wamp\Helper\UriHelper;
use Octamp\Wamp\Matcher\Matcher;
use Octamp\Wamp\Realm\Realm;
use Octamp\Wamp\Session\Session;
use Octamp\Wamp\Session\SessionStorage;
use Predis\Command\Argument\Search\AggregateArguments;
use Predis\Command\Argument\Search\CreateArguments;
use Predis\Command\Argument\Search\SchemaFields\AbstractField;
use Predis\Command\Argument\Search\SchemaFields\TagField;
use Predis\Command\Argument\Search\SchemaFields\TextField;
use Predis\Command\Argument\Search\SearchArguments;
use Thruway\Message\CallMessage;
use Thruway\Message\RegisterMessage;

class RegistrationStorage
{
    protected const INDEX_KEY = 'registrations';
    protected const INDEX_REGISTRATION_KEY = 'registrations';
    protected const INDEX_PROCEDURE_KEY = 'procedures';
    /**
     * @var Registration[]
     */
    protected array $registrations = [];

    public function __construct(protected AdapterInterface $adapter, protected SessionStorage $sessionStorage, protected Matcher $matcher)
    {
        $this->init();
    }

    protected function init(): void
    {
        $args = new CreateArguments();
        $args->prefix(['reg:']);
        $this->adapter->alterCreateIndex(self::INDEX_REGISTRATION_KEY, [
            new TagField('id'),
            new TagField('realm'),
            new TagField('sessionId'),
            new TagField('serverId'),
            new TagField('procedure'),
            new TextField(identifier: 'procedure', alias: 'procedure_text', withSuffixTrie: true),
            new TagField('registeredAt', '', true),
            new TagField('lastCallStartedAt', '', true),
            new TagField('options.match', 'match'),
            new TagField('options.invoke', 'invoke'),
        ], 1, $args);

        $procedureArgs = new CreateArguments();
        $procedureArgs->prefix(['proc:']);
        $this->adapter->alterCreateIndex(self::INDEX_PROCEDURE_KEY, [
            new TagField('realm'),
            new TagField('procedure'),
            new TextField(identifier: 'procedure', alias: 'procedure_text', withSuffixTrie: true),
            new TagField('createdAt', '', true),
            new TagField('options.match', 'match'),
            new TagField('options.invoke', 'invoke'),
        ], 1, $args);
    }

    public function saveRegistration(Registration $registration): void
    {
        $id = $this->generateRegistrationGlobalIdFromRegistration($registration);
        $this->registrations[$id] = $registration;
        $this->adapter->set($id, dot($registration->toArrayFormatted())->flatten());

        $procedureId = $this->generateProcedureGlobalId($registration->getProcedureName(), $registration->getRealm()->getRealmName(), $registration->getMatch());
        if (!$this->adapter->exists($procedureId)) {
            $procedure = [
                'realm' => $registration->getRealm()->getRealmName(),
                'procedure' => $registration->getProcedureName(),
                'createdAt' => $registration->getRegisteredAt(),
                'options' => $registration->getOptions(),
            ];
            $this->adapter->set($procedureId, dot($procedure)->flatten());
        }
    }

    public function getRegistrationUsingRegisterMessage(Session $session, RegisterMessage $message): ?Registration
    {
        $result = $this->searchRegistrations(
            $message->getProcedureName(),
            $session->getRealm()->getRealmName(),
            $message->getOptions()->invoke ?? 'exact',
            false,
            1,
            0
        );

        if (!empty($result)) {
            return $result[0];
        }

        return null;
    }

    /**
     * @return Registration[]
     */
    public function searchRegistrations(
        string $procedure,
        string $realm,
        string $match,
        bool $useText = false,
        int $limit = 10,
        int $offset = 0,
        ?string $sortAttribute = null,
        string $orderBy = 'asc'
    ): array {
        $conditions = [
            '@realm:{' . $realm. '}',
            '@match:{' . $match . '}',
        ];

        if ($useText) {
            $conditions[] = '@procedure_text:(' . $procedure . ')=>{$inorder: true;}';
        } else {
            $conditions[] = '@procedure:{' . $procedure . '}';
        }

        $searchArgs = new SearchArguments();
        if ($limit === 0) {
            $limit = $this->adapter->searchIndex(self::INDEX_KEY, $conditions, $searchArgs->limit(0, 0))->count;
        }
        if ($sortAttribute !== null) {
            $searchArgs->sortBy($sortAttribute, $orderBy);
        }
        $searchArgs->limit($offset, $limit);

        $data = $this->adapter->searchIndex(self::INDEX_KEY, $conditions, $searchArgs);
        if ($data->count > 0) {
            return array_map(function (object $item) {
                $item = dot($item, true);
                if ($this->hasRegistrationInLocal($item->get('realm'), $item->get('id'))) {
                    return $this->getFromLocal($item->get('realm'), $item->get('id'));
                }
                return $this->generateRegistrationFromRaw($item->all());
            }, $data->result);
        }

        return [];
    }

    public function findRawRegistrations(array $filters = [], array $fields = [], int $limit = 10, int $offset = 0,  ?string $sortAttribute = null, string $orderBy = 'asc'): object
    {
        $conditions = [];
        foreach ($filters as $filter) {
            $condition = '@' . $filter['field'] . ':';
            $condition .= match ($filter['type']) {
                'tag' => '{' . $filter['value'] . '}',
                'text' => '(' . $filter['value'] . ')=>{$inorder: true;}',
                default => $filter['value'],
            };
            $conditions[] = $condition;
        }
        $searchArgs = new SearchArguments();
        if ($limit === 0) {
            $limit = $this->adapter->searchIndex(self::INDEX_KEY, $conditions, $searchArgs->limit(0, 0))->count;
        }
        if ($sortAttribute !== null) {
            $searchArgs->sortBy($sortAttribute, $orderBy);
        }
        $searchArgs->limit($offset, $limit);
        if (!empty($fields)) {
            $searchArgs->addReturn(count($fields), ...$fields);
        }

        return $this->adapter->searchIndex(self::INDEX_KEY, $conditions, $searchArgs);
    }

    public function countRegistration(
        string $procedure,
        string $realm,
        string $match,
        bool $useText = false
    ): int {
        $conditions = [
            '@realm:{' . $realm. '}',
            '@match:{' . $match . '}',
        ];

        if ($useText) {
            $conditions[] = '@procedure_text:(' . $procedure . ')=>{$inorder: true;}';
        } else {
            $conditions[] = '@procedure:{' . $procedure . '}';
        }

        $searchArgs = new SearchArguments();
        $searchArgs->limit(0, 0);

        $data = $this->adapter->searchIndex(self::INDEX_KEY, $conditions, $searchArgs);

        return $data->count;
    }

    public function getProcedures(string $procedure, string $realm, string $match = 'exact', int $limit = 10, int $offset = 0): array
    {
        $conditions = [
            '@realm:{' . $realm. '}',
            '@match:{' . $match . '}',
        ];

        if ($match === 'exact') {
            $conditions[] = '@procedure:{' . $procedure . '}';
        } else {
            $conditions[] = '@procedure_text:(' . $procedure . ')=>{$inorder: true;}';
        }

        $searchArgs = new SearchArguments();
        if ($limit === 0) {
            $limit = $this->adapter->searchIndex(self::INDEX_KEY, $conditions, $searchArgs->limit(0, 0))->count;
        }
        $searchArgs->limit($offset, $limit);

        $data = $this->adapter->searchIndex(self::INDEX_KEY, $conditions, $searchArgs);
        if ($data->count > 0) {
            return $data->result;
        }

        return [];
    }

    public function deleteProcedure(string $procedure, string $realm, string $match = 'exact'): void
    {
        $this->adapter->del($this->generateProcedureGlobalId($procedure, $realm, $match));
    }

    public function getRegistrationById(string $realm, string $id): ?Registration
    {
        if ($this->hasRegistrationInLocal($realm, $id)) {
            return $this->getFromLocal($realm, $id);
        }

        $searchArgs = new SearchArguments();
        $searchArgs->limit(0, 1);
        $conditions = [
            '@id:{' . $id . '}',
            '@realm:{' . $realm. '}',
        ];

        $data = $this->adapter->searchIndex(self::INDEX_KEY, $conditions, $searchArgs);
        if ($data->count > 0) {
            $data = dot($data->result[0], true);

            return $this->generateRegistrationFromRaw($data->all());
        }

        return null;
    }

    public function hasRegistrationInLocal(string $realm, string $id): bool
    {
        $globalId = $this->generateRegistrationGlobalId($realm, $id);

        return isset($this->registrations[$globalId]);
    }

    public function getFromLocal(string $realm, string $id): null
    {
        $globalId = $this->generateRegistrationGlobalId($realm, $id);

        return $this->registrations[$globalId] ?? null;
    }

    protected function generateRegistrationFromRaw(object|array $data): Registration
    {
        if (!($data instanceof Dot)) {
            $data = dot($data, true);
        }
        $session = $this->sessionStorage->getSession($data->get('realm'), $data->get('sessionId'));

        $registration = $this->generateRegistration($session, $data->get('procedure'), (object)$data->get('options', new \stdClass()), $data->get('id'));
        if ($data->has('registeredAt')) {
            $registeredAt = $data->get('registeredAt');
            if ($registeredAt instanceof \DateTimeInterface) {
                $registration->setRegisteredAt(\DateTimeImmutable::createFromInterface($registeredAt));
            } else {
                $registration->setRegisteredAt(\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $registeredAt));
            }
        }

        return $registration;
    }

    public function generateRegistration(Session $session, string $procedure, object $options = new \stdClass(), ?string $id = null): Registration
    {
        return new Registration($session, $this->adapter, $procedure, $options, $id);
    }

    public function generateRegistrationFromRegisterMessage(Session $session, RegisterMessage $message): Registration
    {
        return new Registration($session, $this->adapter, $message->getProcedureName(), $message->getOptions());
    }

    public function deleteRegistrationById(string $realm, string $id): void
    {
        $globalId = $this->generateRegistrationGlobalId($realm, $id);
        $this->adapter->del($this->generateRegistrationGlobalId($realm, $id));
        unset($this->registrations[$globalId]);
    }

    public function tryDeleteProcedureFromRegistration(Registration $registration): bool
    {
        $totalRegistration = $this->countRegistration($registration->getProcedureName(), $registration->getRealm()->getRealmName(), $registration->getMatch());
        if ($totalRegistration > 0) {
            return false;
        }
        $this->deleteProcedure($registration->getProcedureName(), $registration->getRealm()->getRealmName(), $registration->getMatch());

        return true;
    }

    public function deleteRegistrationBySessionLocal(string $realm, string $sessionId, callable $callback): void
    {
        $registrations = array_filter($this->registrations, function (Registration $registration) use ($realm, $sessionId) {
            return $registration->getRealm()->getRealmName() === $realm && $registration->getSession()->getSessionId() === $sessionId;
        });

        foreach ($registrations as $registration) {
            $this->deleteRegistrationById($realm, $registration->getId());
            call_user_func($callback, $registration);
        }
    }

    protected function generateRegistrationGlobalIdFromRegistration(Registration $registration): string
    {
        return $this->generateRegistrationGlobalId($registration->getRealm()->getRealmName(), $registration->getId());
    }

    protected function generateRegistrationGlobalId(string $realm, string $id): string
    {
        return sprintf('reg:%s:%s', $realm, $id);
    }

    protected function generateProcedureGlobalId(string $procedure, string $realm, string $match): string
    {
        return sprintf('proc:%s:%s:%s', $realm, $procedure, $match);
    }

    public function getProcedure(string $procedure, string $realmName): ?Procedure
    {
        // exact
        $procedures = $this->getProcedures($procedure, $realmName, 'exact', 0, 1);
        if (!empty($procedures)) {
            return $this->getExactProcedure($procedure, $procedures);
        }

        // prefix
        $procedures = $this->getProcedures($procedure, $realmName, 'prefix', 0, 1);
        if (!empty($procedures)) {
            return $this->getPrefixProcedure($procedure, $procedures);
        }

        // wildcard
        $procedures = $this->getProcedures($procedure, $realmName, 'wildcard', 0, 1);
        if (!empty($procedures)) {
            return $this->getWildcardProcedure($procedure, $procedures);
        }

        return null;
    }

    protected function getExactProcedure(string $procedureUri, array $procedures): ?object
    {
        foreach ($procedures as $procedure) {
            if ($this->matcher->isMatch($procedure->procedure, $procedureUri, 'exact')) {
                return $procedure;
            }
        }

        return null;
    }

    protected function getPrefixProcedure(string $procedureUri, array $procedures): ?object
    {
        $matchedProcedures = [];
        foreach ($procedures as $procedure) {
            if ($this->matcher->isMatch($procedure->procedure, $procedureUri, 'prefix')) {
                $matchedProcedures = $procedure;
            }
        }

        if (empty($matchedProcedures)) {
            return null;
        } elseif (count($matchedProcedures) === 1) {
            return $matchedProcedures[0];
        }

        // check
        $procedure = array_shift($matchedProcedures);
        $procedureCount = count(preg_split('/(\.|\-)/', $procedure->procedure));
        foreach ($matchedProcedures as $matchedProcedure) {
            $matchedProcedureCount = count(preg_split('/(\.|\-)/', $matchedProcedure->procedure));
            if ($matchedProcedureCount > $procedureCount) {
                $procedure = $matchedProcedure;
                $procedureCount = $matchedProcedureCount;
            }
        }

        return $procedure;
    }

    protected function getWildcardProcedure(string $procedureUri, array $procedures): ?object
    {
        $matchedProcedures = [];
        foreach ($procedures as $procedure) {
            if ($this->matcher->isMatch($procedure->procedure, $procedureUri, 'wildcard')) {
                $matchedProcedures = $procedure;
            }
        }

        if (empty($matchedProcedures)) {
            return null;
        } elseif (count($matchedProcedures) === 1) {
            return $matchedProcedures[0];
        }

        // check
        $procedure = array_shift($matchedProcedures);
        $parts = preg_split('/(\-|\.){2}/', $procedure->procedure);
        $partsCount = array_map(function ($part) {
            return count(preg_split('/(\-|\.)/', $part));
        }, $parts);
        foreach ($matchedProcedures as $matchedProcedure) {
            $matchedParts = preg_split('/(\-|\.){2}/', $matchedProcedure->procedure);
            $matchedCount = array_map(function ($part) {
                return count(preg_split('/(\-|\.)/', $part));
            }, $matchedParts);
            foreach ($matchedCount as $key => $count) {
                $currentCount = $partsCount[$key] ?? 0;
                if ($count > $currentCount) {
                    $partsCount = $matchedCount;
                    $procedure = $matchedProcedure;
                    break;
                }
            }
        }

        return $procedure;
    }

    public function getRegistrationByProcedure(object $procedure): ?Registration
    {
        $invoke = $procedure->optoins?->invoke ?? Registration::SINGLE_REGISTRATION;
        if ($this->procedureIsAllowedMultiple($procedure)) {
            return $this->getFirstRegistration($procedure);
        } elseif (strcasecmp($invoke, Registration::FIRST_REGISTRATION)) {
            return $this->getFirstRegistration($procedure);
        } elseif (strcasecmp($invoke, Registration::LAST_REGISTRATION)) {
            return $this->getLastRegistration($procedure);
        } elseif (strcasecmp($invoke, Registration::RANDOM_REGISTRATION)) {
            return $this->getRandomRegistration($procedure);
        } elseif (strcasecmp($invoke, Registration::ROUNDROBIN_REGISTRATION)) {
            return $this->getRoundrobinRegistration($procedure);
        }

        return null;
    }

    protected function procedureIsAllowedMultiple(object $procedure): bool
    {
        $invoke = $procedure->optoins?->invoke ?? Registration::SINGLE_REGISTRATION;

        return strcasecmp($invoke, Registration::SINGLE_REGISTRATION) === 0;
    }

    protected function getFirstRegistration(object $procedure): ?Registration
    {
        $registrations = $this->searchRegistrations(
            $procedure->procedure,
            $procedure->realm,
            $procedure->options?->match ?? 'exact',
            false,
            1,
            0,
            'registeredAt'
        );
        if (empty($registrations)) {
            return null;
        }

        return $registrations[0];
    }

    protected function getLastRegistration(object $procedure): ?Registration
    {
        $registrations = $this->searchRegistrations(
            $procedure->procedure,
            $procedure->realm,
            $procedure->options?->match ?? 'exact',
            false,
            1,
            0,
            'registeredAt',
            'desc'
        );
        if (empty($registrations)) {
            return null;
        }

        return $registrations[0];
    }

    protected function getRandomRegistration(object $procedure): ?Registration
    {
        $total = $this->countRegistration($procedure->procedure, $procedure->realm, $procedure->options?->match ?? 'exact');
        if ($total === 0) {
            return null;
        }

        $offset = mt_rand(0, $total - 1);

        $registrations = $this->searchRegistrations(
            $procedure->procedure,
            $procedure->realm,
            $procedure->options?->match ?? 'exact',
            false,
            1,
            $offset,
            'registeredAt',
            'desc'
        );

        if (empty($registrations)) {
            return null;
        }

        return $registrations[0];
    }

    protected function getRoundrobinRegistration(object $procedure): ?Registration
    {
        $registrations = $this->searchRegistrations(
            $procedure->procedure,
            $procedure->realm,
            $procedure->options?->match ?? 'exact',
            false,
            1,
            0,
            'lastCallStartedAt'
        );

        if (empty($registrations)) {
            return null;
        }

        return $registrations[0];
    }
}