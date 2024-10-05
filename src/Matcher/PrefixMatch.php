<?php

declare(strict_types=1);

namespace Octamp\Wamp\Matcher;

use Octamp\Wamp\Helper\UriHelper;

class PrefixMatch implements MatchInterface
{

    public function getName(): string
    {
        return 'prefix';
    }

    public function isMatched(string $uri, string $uri2, array &$matches = []): bool
    {
        if ($uri === $uri2) {
            return true;
        }
        $pattern = '/^(' . UriHelper::escapedUri($uri) . ')((([0-9a-z_]+(\.|\-)?))*([0-9a-z_]))?$/';
        $lastChar = $uri[strlen($uri) - 1];
        if ($lastChar !== '-' && $lastChar !== '.') {
            $pattern = '/^(' . UriHelper::escapedUri($uri) . ')((\.|\-)(([0-9a-z_]+(\.|\-)?))*([0-9a-z_]))?$/';
        }

        return preg_match($pattern, $uri2, $matches) === 1;
    }
}