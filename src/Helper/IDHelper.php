<?php

namespace Octamp\Wamp\Helper;

use Octamp\Wamp\Adapter\AdapterInterface;
use Octamp\Wamp\Session\Adapter\AdapterInterface as SessionAdapterInterface;
use Octamp\Wamp\Session\Session;

class IDHelper
{
    private static ?AdapterInterface $adapter = null;
    private static ?SessionAdapterInterface $sessionAdapter = null;


    public static function setAdapter(AdapterInterface $adapter): void
    {
        static::$adapter = $adapter;
    }

    public static function setSessionAdapter(SessionAdapterInterface $adapter): void
    {
        static::$sessionAdapter = $adapter;
    }

    public static function generateGlobalWampID(): int|float
    {
        return static::$adapter->inc('wamp:id', 1, 'global');
    }

    public static function generateRouterWampID(string $serverId, ): string
    {
        $result = static::$adapter->inc('wamp:id', 1, $serverId);

        return $serverId . ':' . $result;
    }

    public static function incrementSessionWampID(Session $session): int|float
    {
        return $session->incrementWampId();
    }
}