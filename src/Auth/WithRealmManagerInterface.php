<?php

namespace Octamp\Wamp\Auth;

use Octamp\Wamp\Realm\RealmManager;

interface WithRealmManagerInterface
{
    public function setRealmManager(RealmManager $realmManager): void;
}