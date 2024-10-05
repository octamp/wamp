<?php

declare(strict_types=1);

namespace Octamp\Wamp\Matcher;

interface MatchInterface
{
    public function getName(): string;

    public function isMatched(string $uri, string $uri2, array &$matches = []): bool;
}