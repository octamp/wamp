<?php

namespace Octamp\Wamp\Session\Adapter;

use Octamp\Wamp\Session\Session;

interface AdapterInterface
{
    public function generateId(): string;

    public function saveSession(Session $session): void;

    public function getSession(string $id): ?array;

    public function get(string $id): ?array;

    public function getAll(): array;

    public function find(array $condition): array;

    public function findByOne(string $key, mixed $value): ?array;

    public function remove(Session $session): void;

    public function incWampIdName(Session $session, string $idName): int;
}