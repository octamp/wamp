<?php

declare(strict_types=1);

namespace Octamp\Wamp\Matcher;

class ExactMatch implements MatchInterface
{

    public function getName(): string
    {
        return 'exact';
    }

    public function isMatched($uri, $uri2, array &$matches = []): bool
    {
        return $uri === $uri2;
    }
}