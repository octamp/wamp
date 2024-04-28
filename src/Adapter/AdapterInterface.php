<?php

namespace Octamp\Wamp\Adapter;

interface AdapterInterface
{
    public function start(string $serverId): void;

    public function subscribe(string $topic, callable $callback): void;

    public function publish(string $topic, array $payload = [], ?string $serverId = null): void;

    public function set(string $key, array $data = []): void;

    public function del(string $key, array $fields = []): void;

    public function get(string $key, array $fields = []): ?array;

    public function find(string $search): array;

    public function keys(string $search): array;

    public function addToList(string $key, mixed $value): bool;

    public function getList(string $key): array;

    public function inc(string $key, int $increment = 1, ?string $field = null): int;
}