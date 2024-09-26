<?php

declare(strict_types=1);

namespace Octamp\Wamp\Helper;

class UriHelper
{
    protected const reservedUri = [
        'wamp.error.invalid_uri',
        'wamp.error.not_authorized',
        'wamp.error.no_such_procedure',
        'wamp.error.procedure_already_exists',
        'wamp.error.no_such_registration',
        'wamp.error.no_such_subscription',
        'wamp.error.invalid_argument',
        'wamp.error.canceled',
        'wamp.error.payload_size_exceeded',
        'wamp.close.system_shutdown',
        'wamp.close.close_realm',
        'wamp.close.goodbye_and_out',
        'wamp.error.protocol_violation',
        'wamp.error.not_authorized',
        'wamp.error.authorization_failed',
        'wamp.error.no_such_realm',
        'wamp.error.no_such_role',
        'wamp.close.killed',
        'wamp.error.no_matching_auth_method',
        'wamp.error.no_such_realm',
        'wamp.error.no_such_role',
        'wamp.error.no_such_principal',
        'wamp.error.authentication_denied',
        'wamp.error.authentication_failed',
        'wamp.error.authentication_required',
        'wamp.error.authorization_denied',
        'wamp.error.authorization_failed',
        'wamp.error.authorization_required',
        'wamp.error.timeout',
        'wamp.error.option_not_allowed',
        'wamp.error.option_disallowed.disclose_me',
        'wamp.error.network_failure',
        'wamp.error.unavailable',
        'wamp.error.no_available_callee',
        'wamp.error.feature_not_supported',
    ];

    public static function uriIsValid(string $uri, bool $allowEmpty = false, bool $strict = false): bool
    {
        $regex = $allowEmpty ? '/^(([^\s\.#]+\.)|\.)*([^\s\.#]+)?$/' : '/^([^\s\.#]+\.)*([^\s\.#]+)$/';
        if ($strict) {
            $regex = $allowEmpty ? '/^(([0-9a-z_]+\.)|\.)*([0-9a-z_]+)?$/' : '/^([0-9a-z_]+\.)*([0-9a-z_]+)$/';
        }

        return !!preg_match($regex, $uri);
    }

    public static function uriIsValidStrict(string $uri, bool $allowEmpty = false, bool $ignoreReserved = false): bool
    {
        if (!$ignoreReserved) {
            $uriParts = explode('.', $uri);
            if ($uriParts[0] === 'wamp') {
                return false;
            }
        }

        return static::uriIsValid($uri, $allowEmpty, true);
    }
}
