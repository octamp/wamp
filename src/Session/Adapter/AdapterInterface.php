<?php

declare(strict_types=1);

namespace Octamp\Wamp\Session\Adapter;

use Octamp\Wamp\Session\Session;

interface AdapterInterface
{
    public function generateId(): string;

    public function saveSession(Session $session): void;

    public function savePrincipal(Session $session): void;

    public function getSession(string $realm, string $id): ?object;

    public function get(string $id): ?array;

    public function find(array $condition): array;

    public function findReturnKey(array $condition): array;

    public function count(array $condition): int;

    public function findByOne(string $key, mixed $value): ?array;

    public function remove(Session $session): void;

    public function incWampIdName(Session $session, string $idName): int;

    public function inc(string $key, int $inc = 1, ?string $field = null): int;

    public function dec(string $key, int $dec = 1, ?string $field = null): int;

    public function getField(string $key, string $field): mixed;
}
