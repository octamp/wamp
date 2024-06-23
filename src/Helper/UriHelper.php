<?php

namespace Octamp\Wamp\Helper;

class UriHelper
{
    protected const reservedUri = [
        'wamp.error.not_authorized',
        'wamp.error.procedure_already_exists',
    ];

    public static function uriIsValid(string $uri, bool $allowEmpty = false, bool $strict = false): bool
    {
        $regex = $allowEmpty ? '/^(([^\s\.#]+\.)|\.)*([^\s\.#]+)?$/' : '/^([^\s\.#]+\.)*([^\s\.#]+)$/';
        if ($strict) {
            $regex = $allowEmpty ? '/^(([0-9a-z_]+\.)|\.)*([0-9a-z_]+)?$/' : '/^([0-9a-z_]+\.)*([0-9a-z_]+)$/';
        }

        return !!preg_match($regex, $uri);
    }
}