<?php

declare(strict_types=1);

namespace Octamp\Wamp\Matcher;

use Octamp\Wamp\Helper\UriHelper;

class WildcardMatch implements MatchInterface
{

    public function getName(): string
    {
        return 'wildcard';
    }

    public function isMatched(string $uri, string $uri2, array &$matches = []): bool
    {
        if ($uri === $uri2) {
            return true;
        }

        $uri = '('. UriHelper::escapedUri($uri) . ')';
        $inbetween = ')((([0-9a-z_]+(\.|\-)?))*([0-9a-z_]))?(';

        $uri = str_replace('\.\.',  '\.)' . $inbetween . '(\.', $uri);
        $uri = str_replace('\-\.',  '\-)' . $inbetween . '(\.', $uri);
        $uri = str_replace('\.\-',  '\.)' . $inbetween . '(\-', $uri);
        $uri = str_replace('\-\-',  '\-)' . $inbetween . '(\-', $uri);

        $patterns = '/^' . $uri . '$/';

        return preg_match($patterns, $uri2) === 1;
    }
}