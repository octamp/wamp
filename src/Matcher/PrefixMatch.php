<?php

declare(strict_types=1);

namespace Octamp\Wamp\Matcher;

class PrefixMatch implements MatchInterface
{

    public function getName(): string
    {
        return 'prefix';
    }

    public function isMatched(string $uri, string $uri2): bool
    {
        if ($uri === $uri2) {
            return true;
        }

        $pattern = '/^(' . $uri . ')(\.|-).*$/';

        return preg_match($pattern, $uri2) === 1;
    }
}