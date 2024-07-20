<?php

declare(strict_types=1);

namespace Octamp\Wamp\Auth;

use Octamp\Wamp\Realm\RealmManager;

interface WithRealmManagerInterface
{
    public function setRealmManager(RealmManager $realmManager): void;
}
