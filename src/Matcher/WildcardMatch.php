<?php

declare(strict_types=1);

namespace Octamp\Wamp\Matcher;

class WildcardMatch implements MatchInterface
{

    public function getName(): string
    {
        return 'wildcard';
    }

    public function isMatched(string $uri, string $uri2): bool
    {
        if ($uri === $uri2) {
            return true;
        }

        $len = strlen($uri);
        if ($uri[$len - 1] === '.' || $uri[$len - 1] === '-') {
            $uri = substr_replace($uri, ')(.*)(\.|-)', $len - 1, 1);
        } else {
            $uri .= ')';
        }

        if ($uri[0] === '.' || $uri[0] === '-') {
            $uri = substr_replace($uri, '(.*)(\.|-)(', 0, 1);
        } else {
            $uri = '(' . $uri;
        }


        $uri = str_replace('..', ')(\.|-)(.*)(\.|-)(', $uri);
        $uri = str_replace('.-', ')(\.|-)(.*)(\.|-)(', $uri);
        $uri = str_replace('-.', ')(\.|-)(.*)(\.|-)(', $uri);
        $uri = str_replace('--', ')(\.|-)(.*)(\.|-)(', $uri);

        $patterns = '/^' . $uri . '$/';

        return preg_match($patterns, $uri2) === 1;
    }
}