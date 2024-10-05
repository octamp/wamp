<?php

declare(strict_types=1);

namespace Octamp\Wamp\Adapter;

use Predis\Command\Argument\Search\AggregateArguments;
use Predis\Command\Argument\Search\AlterArguments;
use Predis\Command\Argument\Search\CreateArguments;
use Predis\Command\Argument\Search\SearchArguments;

interface AdapterInterface extends \Octamp\Server\Adapter\AdapterInterface
{
    public function start(string $serverId): void;

    public function subscribe(string $topic, callable $callback): void;

    public function publish(string $topic, array $payload = [], ?string $serverId = null, ?string $fromServerId = null): void;

    public function set(string $key, array $data = []): void;

    public function setField(string $key, string $field, mixed $data): void;

    public function getField(string $key, string $field): mixed;

    public function del(string $key, array $fields = []): void;

    public function get(string $key, array $fields = []): ?array;

    public function find(string $search): array;

    public function findOne(string $search): ?array;

    public function findWithRetainKey(string $search): array;

    public function keys(string $search): array;

    public function hkeys(string $search): array;

    public function addToList(string $key, mixed $value): bool;

    public function getList(string $key): array;

    public function inc(string $key, int $increment = 1, ?string $field = null): int;

    public function dec(string $key, int $decrement = 1, ?string $field = null): int;

    public function countFields(string $key): int;

    public function lock(string $key, int|string $value, int $seconds = 1): bool;

    public function unlock(string $key, int|string $value): bool;

    public function exists(string $key): bool;

    public function createIndex(string $index, array $schema, int $version, ?CreateArguments $arguments = null): void;

    public function alterIndex(string $index, array $schema, int $version, ?AlterArguments $arguments = null): void;

    public function alterCreateIndex(string $index, array $schema, int $version, ?CreateArguments $arguments = null): void;

    public function searchIndex(string $index, array $queries = [], ?SearchArguments $arguments = null): object;

    public function aggregate(string $index, array $queries = [], ?AggregateArguments $arguments = null): object;

    public function count(string $index, array $queries = [], ?SearchArguments $arguments = null): int;
}
