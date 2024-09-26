<?php

declare(strict_types=1);

namespace Octamp\Wamp\Adapter;

use OpenSwoole\Coroutine;
use OpenSwoole\Timer;
use Octamp\Wamp\Pools\Pool;
use Predis\Client;
use Predis\Command\Argument\Search\AlterArguments;
use Predis\Command\Argument\Search\CommonArguments;
use Predis\Command\Argument\Search\CreateArguments;
use Predis\Command\Argument\Search\SearchArguments;
use Predis\Response\ServerException;

class RedisAdapter extends \Octamp\Server\Adapter\RedisAdapter implements AdapterInterface
{
    protected Pool $clients;

    public function __construct(string $host, int $port, ?string $username = null, ?string $password = null, array $options = [])
    {
        parent::__construct($host, $port, $username, $password, $options);

        $this->clients = new Pool('redis', 3 /* number of connections */, function() {
            return $this->createPredis();
        });
    }

    public function publish(string $topic, array $payload = [], ?string $serverId = null): void
    {
        $channel = ($serverId ?? 'global') . ':message';
        $client = $this->clients->pop();
        $client->getResource()->publish($channel, json_encode([$topic, $payload]));
        $this->clients->push($client);
    }

    public function set(string $key, array $data = []): void
    {
        $client = $this->clients->pop();;
        foreach ($data as $field => $value) {
            if (is_array($value)) {
                $client->getResource()->hset($key, (string) $field, json_encode($value));
            } else {
                $client->getResource()->hset($key, (string) $field, $value);
            }
        }
        $this->clients->push($client);
    }

    public function del(string $key, array $fields = []): void
    {
        $client = $this->clients->pop();;
        if (!empty($fields)) {
            $client->getResource()->hdel($key, $fields);
        } else {
            $client->getResource()->del($key);
        }
        $this->clients->push($client);
    }

    public function get(string $key, array $fields = []): ?array
    {
        $client = $this->clients->pop();;
        if (!$client->getResource()->exists($key)) {
            $this->clients->push($client);
            return null;
        }
        $result = [];

        if (empty($fields)) {
            $result = $client->getResource()->hgetall($key);
        } else {
            foreach ($fields as $field) {
                $result[$field] = $client->getResource()->hget($key, $field);
            }
        }

        $this->clients->push($client);

        return $this->decodeData($result);
    }

    public function find(string $search): array
    {
        $client = $this->clients->pop();;
        $keys = $client->getResource()->keys($search);
        $results = [];
        foreach ($keys as $key) {
            $results[] = $this->decodeData($client->getResource()->hgetall($key));
        }

        $this->clients->push($client);

        return $results;
    }

    public function keys(string $search): array
    {
        $client = $this->clients->pop();;
        $keys = $client->getResource()->keys($search);
        $this->clients->push($client);

        return $keys;
    }

    public function addToList(string $key, mixed $value): bool
    {
        $client = $this->clients->pop();
        $response = $client->getResource()->sadd($key, [$value]);
        $this->clients->push($client);

        return (bool) $response;
    }

    public function getList(string $key): array
    {
        $client = $this->clients->pop();
        $response = $client->getResource()->smembers($key);
        $this->clients->push($client);

        return $response;
    }

    public function inc(string $key, int $increment = 1, ?string $field = null): int
    {
        $client = $this->clients->pop();
        if ($field !== null) {
            $value = $client->getResource()->hincrby($key, $field, $increment);
        } else {
            $value = $client->getResource()->incrby($key, $increment);
        }
        $this->clients->push($client);

        return $value;
    }

    public function dec(string $key, int $decrement = 1, ?string $field = null): int
    {
        $client = $this->clients->pop();
        if ($field !== null) {
            $value = $client->getResource()->hdecrby($key, $field, $decrement);
        } else {
            $value = $client->getResource()->decrby($key, $decrement);
        }
        $this->clients->push($client);

        return $value;
    }

    public function setField(string $key, string $field, mixed $data): void
    {
        $this->set($key, [$field => $data]);
    }

    public function getField(string $key, string $field): mixed
    {
        $data = $this->runCommand(function (Client $client) use ($key, $field) {
            return $client->hget($key, $field);
        });

        if (is_array($data)) {
            return $this->decodeData($data);
        }

        return $data;
    }

    public function countFields(string $key): int
    {
        $client = $this->clients->pop();
        $count = $client->getResource()->hlen($key);
        $this->clients->push($client);

        return $count;
    }

    public function lock(string $key, int|string $value, int $seconds = 1, int $exp = 2): bool
    {
        $chan = new Coroutine\Channel(1);
        $this->lockCallback($chan, $key, $value, $seconds);
        $status = $chan->pop($exp);
        if ($chan->errCode === Coroutine\Channel::CHANNEL_TIMEOUT) {
            $status = false;
        }
        $chan->close();

        return $status;
    }

    public function unlock(string $key, int|string $value): bool
    {
        $client = $this->clients->pop();;
        $result = $client->getResource()->get($key);
        if ($result === $value) {
            $client->getResource()->del($key);
        }
        $this->clients->push($client);

        return $result === $value;
    }

    protected function lockCallback(Coroutine\Channel $chan, string $key, int|string $value, int $seconds = 1): void
    {
        $client = $this->clients->pop();;
        if ($client->getResource()->setnx($key, $value) === 1) {
            $chan->push(true);
        }
        $this->clients->push($client);

        if ($chan->errCode === Coroutine\Channel::CHANNEL_OK) {
            Timer::after(500, [$this, 'lockCallback'], $chan, $key, $value, $seconds);
        }
    }

    public function exists(string $key): bool
    {
        $client = $this->clients->pop();;
        $exists = $client->getResource()->exists($key);
        $this->clients->push($client);

        return (bool) $exists;
    }

    public function hkeys(string $search): array
    {
        $client = $this->clients->pop();;
        $keys = $client->getResource()->hkeys($search);
        $this->clients->push($client);

        return $keys;
    }

    public function findWithRetainKey(string $search): array
    {
        $client = $this->clients->pop();;
        $keys = $client->getResource()->keys($search);
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->decodeData($client->getResource()->hgetall($key));
        }

        $this->clients->push($client);

        return $results;
    }

    protected function decodeData(array $data): array
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

    public function findOne(string $search): ?array
    {
        $client = $this->clients->pop();
        $keys = $client->getResource()->keys($search);
        $result = null;
        if (!empty($keys)) {
            $result = $this->decodeData($client->getResource()->hgetall($keys[0]));
        }
        $this->clients->push($client);

        return $result;
    }

    protected function runCommand(callable $callable): mixed
    {
        $client = $this->clients->pop();
        $data = call_user_func($callable, $client->getResource());
        $this->clients->push($client);

        return $data;
    }

    public function createIndex(string $index, array $schema, int $version, ?CreateArguments $arguments = null): void
    {
        $this->runCommand(function (Client $client) use ($index, $schema, $version, $arguments) {
            $currentVersion = (int)$client->hget('indexes:version', $index);
            if ($version <= $currentVersion) {
                return;
            }
            $client->ftcreate($index, $schema, $arguments);
            $client->hset('indexes:version', $index, (string) $version);
        });
    }

    public function alterIndex(string $index, array $schema, int $version, ?AlterArguments $arguments = null): void
    {
        $this->runCommand(function (Client $client) use ($index, $schema, $version, $arguments) {
            $currentVersion = (int)$client->hget('indexes:version', $index);
            if ($version <= $currentVersion) {
                return;
            }
            $client->ftalter($index, $schema, $arguments);
            $client->hset('indexes:version', $index, (string) $version);
        });
    }

    public function alterCreateIndex(string $index, array $schema, int $version, ?CreateArguments $arguments = null): void
    {
        $this->runCommand(function (Client $client) use ($index, $schema, $version, $arguments) {
            $currentVersion = (int)$client->hget('indexes:version', $index);
            if ($currentVersion === 0) {
                try {
                    $client->ftdropindex($index);
                } catch (ServerException $serverException) {
                    // do nothing
                }
            }

            if ($version <= $currentVersion) {
                return;
            }
            try {
                $alterArguments = new AlterArguments();
                $client->ftalter($index, $schema, $alterArguments);
            } catch (ServerException $serverException) {
                $client->ftcreate($index, $schema, $arguments);
            }
            $client->hset('indexes:version', $index, (string)$version);
        });
    }

    public function searchIndex(string $index, array $queries = [], ?SearchArguments $arguments = null): object
    {
        $data = $this->runCommand(function (Client $client) use ($index, $queries, $arguments) {
            return $client->ftsearch($index, implode(' ', $queries), $arguments);
        });

        return $this->parseIndexSearchResult($data, $arguments);
    }

    protected function parseIndexSearchResult(array $result, CommonArguments $arguments): object
    {
        $withContent = !in_array('NOCONTENT', $arguments->toArray());
        $count = array_shift($result);
        if ($count === null) {
            $count = 0;
        }

        if ($count === 0) {
            return (object)['count' => $count];
        }

        $newResult = [];

        do {
            $key = array_shift($result);
            if (!$withContent) {
                $newResult[] = $key;
                continue;
            }
            $fieldData = array_shift($result);
            $recordData = new \stdClass();
            $recordData->_key = $key;
            do {
                $field = array_shift($fieldData);
                $fieldValue = array_shift($fieldData);
                $newFieldValue = json_decode($fieldValue, true);
                if (is_array($newFieldValue)) {
                    $recordData->{$field} = $this->decodeData($newFieldValue);
                } else {
                    $recordData->{$field} = $fieldValue;
                }
            } while (!empty($value));
            $newResult[] = $recordData;
        } while (!empty($result));

        return (object)[
            'count' => $count,
            'result' => $newResult,
        ];
    }
}
