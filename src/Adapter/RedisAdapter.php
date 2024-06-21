<?php

namespace Octamp\Wamp\Adapter;

use OpenSwoole\Coroutine;
use OpenSwoole\Timer;

class RedisAdapter extends \Octamp\Server\Adapter\RedisAdapter implements AdapterInterface
{
    public function addToList(string $key, mixed $value): bool
    {
        $client = $this->createPredis();
        $response = $client->sadd($key, [$value]);
        $client->quit();

        return (bool) $response;
    }

    public function getList(string $key): array
    {
        $client = $this->createPredis();
        $response = $client->smembers($key);
        $client->quit();

        return $response;
    }

    public function inc(string $key, int $increment = 1, ?string $field = null): int
    {
        $client = $this->createPredis();
        if ($field !== null) {
            $value = $client->hincrby($key, $field, $increment);
        } else {
            $value = $client->incrby($key, $increment);
        }
        $client->quit();

        return $value;
    }

    public function setField(string $key, string $field, mixed $data): void
    {
        $this->set($key, [$field => $data]);
    }

    public function countFields(string $key): int
    {
        $client = $this->createPredis();
        $count = $client->hlen($key);
        $client->quit();

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
        $client = $this->createPredis();
        $result = $client->get($key);
        if ($result === $value) {
            $client->del($key);
        }
        $client->quit();

        return $result === $value;
    }

    protected function lockCallback(Coroutine\Channel $chan, string $key, int|string $value, int $seconds = 1): void
    {
        $client = $this->createPredis();
        if ($client->setnx($key, $value) === 1) {
            $chan->push(true);
        }
        $client->quit();

        if ($chan->errCode === Coroutine\Channel::CHANNEL_OK) {
            Timer::after(500, [$this, 'lockCallback'], $chan, $key, $value, $seconds);
        }
    }

    public function exists(string $key): bool
    {
        $client = $this->createPredis();
        $exists = $client->exists($key);
        $client->quit();

        return (bool) $exists;
    }

    public function hkeys(string $key): array
    {
        $client = $this->createPredis();
        $keys = $client->hkeys($key);
        $client->quit();

        return $keys;
    }

    public function findWithRetainKey(string $search): array
    {
        $client = $this->createPredis();
        $keys = $client->keys($search);
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->decodeData($client->hgetall($key));
        }

        $client->quit();

        return $results;
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
}