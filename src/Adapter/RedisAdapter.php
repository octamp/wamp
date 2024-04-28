<?php

namespace Octamp\Wamp\Adapter;

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
}