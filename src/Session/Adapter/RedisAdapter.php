<?php

declare(strict_types=1);

namespace Octamp\Wamp\Session\Adapter;

use Octamp\Wamp\Session\Session;
use Predis\Command\Argument\Search\CreateArguments;
use Predis\Command\Argument\Search\SchemaFields\TagField;
use Predis\Command\Argument\Search\SchemaFields\TextField;
use Predis\Command\Argument\Search\SearchArguments;

class RedisAdapter implements AdapterInterface
{
    public function __construct(protected \Octamp\Wamp\Adapter\RedisAdapter $adapter)
    {
        $this->init();
    }

    protected function init(): void
    {
            $args = new CreateArguments();
            $args->prefix(['ses:']);
            $this->adapter->alterCreateIndex('session', [
                new TagField('id'),
                new TagField('transportId'),
                new TagField('realm'),
                new TagField('serverId'),
                new TagField('authRole'),
            ], 2, $args);

            $this->adapter->alterIndex('session', [new TagField('authenticated')], 3);
            $this->adapter->alterIndex('session', [new TagField('authId')], 4);
    }

    public function generateId(): string
    {
        $prefix = date('Ymd');
        $id = $this->adapter->inc('sesid:current', 1, $prefix);

        return $prefix . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    public function saveSession(Session $session): void
    {
        $details = [
            'id' => $session->getId(),
            'transportId' => $session->getTransportId(),
            'authenticated' => $session->isAuthenticated(),
            'realm' => $session->getRealm()?->name ?? null,
            'trusted' => $session->isTrusted(),
            'transportClass' => get_class($session->getTransport()),
            'serializerClass' => get_class($session->getTransport()->getSerializer()),
            'websocketProtocol' => $session->getTransport()->getSerializer()->protocolName(),
            'serverId' => $session->getServerId(),
            'helloMessage' => $session->getHelloMessage()?->getMessageParts() ?? null,
            'authDetails' => $session->getAuthenticationDetails()?->jsonSerialize(true) ?? null,
            'authRole' => $session->getAuthenticationDetails()?->getAuthRole() ?? null,
            'authId' => $session->getAuthenticationDetails()?->getAuthId() ?? null,
        ];

        $this->adapter->set($this->getKeyBySession($session), $details);
    }

    public function getSession(string $realm, string $id): ?object
    {
        $args = new SearchArguments();
        $args->dialect('2');
        $args->limit(0, 1);

        $result = $this->adapter->searchIndex('session', $this->generateCondition(['id' => $id, 'authenticated' => 1, 'realm' => $realm]));
        if ($result->count === 0) {
            return null;
        }

        return $result->result[0];
    }

    public function find(array $condition): array
    {
        $args = new SearchArguments();
        $args->dialect('2');

        $result = $this->adapter->searchIndex('session', $this->generateCondition($condition));
        if ($result->count === 0) {
            return [];
        }

        return $result->result;
    }

    public function findReturnKey(array $condition): array
    {
        $args = new SearchArguments();
        $args->addReturn(1, 'id');
        $args->dialect('2');

        $data = $this->adapter->searchIndex('session', $this->generateCondition($condition), $args);
        if ($data->count === 0) {
            return [];
        }

        return array_map(fn(object $result)  => $result->id, $data->result);
    }

    public function count(array $condition): int
    {
        $args = new SearchArguments();
        $args->noContent();

        $data = $this->adapter->searchIndex('session', $this->generateCondition($condition), $args);

        return $data[0];
    }

    public function findByOne(string $key, mixed $value): ?array
    {
    }

    public function get(string $id): ?array
    {
        $client = $this->adapter->createPredis();
        $keys = $client->keys('ses:' . $id);
        $data = null;
        if (!empty($keys)) {
            $data = $client->hgetall($keys[0]);
            $data = $this->decodeData($data);
        }
        $client->quit();

        return $data;
    }

    public function remove(Session $session): void
    {
        $this->adapter->del($this->getKeyBySession($session));
    }

    private function decodeData(array $data): array
    {
        foreach ($data as &$value) {
            try {
                $newValue = json_decode($value, true);
                if (is_array($newValue)) {
                    $value = $newValue;
                }
            } catch (\Exception $exception) {
            }
        }

        return $data;
    }

    private function getKeyBySession(Session $session): string
    {
        return $this->getKeyByIds($session->getId(), $session->getTransportId());
    }

    private function getKeyByIds(string $sessionId, string $transportId): string
    {
        return 'ses:' . $sessionId . ':' . base64_encode($transportId);
    }

    public function incWampIdName(Session $session, string $idName): int
    {
        $id = $this->getKeyBySession($session);

        return $this->adapter->inc($id, 1, $idName);
    }

    public function inc(string $key, int $inc = 1, ?string $field = null): int
    {
        return $this->adapter->inc($key, $inc, $field);
    }

    public function dec(string $key, int $dec = 1, ?string $field = null): int
    {
        return $this->adapter->dec($key, $dec, $field);
    }

    public function getField(string $key, string $field): mixed
    {
        return $this->adapter->getField($key, $field);
    }

    public function savePrincipal(Session $session): void
    {
        if ($session->isAuthenticated() && $session->getAuthenticationDetails() !== null) {
            $key = 'pp:' . $session->getRealm()->getRealmName() . ':' . $session->getAuthenticationDetails()->getAuthId() . ':' . $session->getAuthenticationDetails()->getAuthRole();
            $this->adapter->setField($key, $this->getKeyBySession($session), 1);
        }
    }

    protected function generateCondition(array $conditions): array
    {
        $formattedConditions = [];
        foreach ($conditions as $key => $value) {
            if (empty($value)) {
                continue;
            } elseif (is_array($value)) {
                $value = implode(' | ', '"' . $value . '"');
            } else {
                $value = '"' . $value . '"';
            }
            $formattedConditions[] = sprintf('@%s:{%s}', $key, $value);
        }

        return $formattedConditions;
    }
}
