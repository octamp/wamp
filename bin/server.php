<?php

require_once __DIR__ . '/../vendor/autoload.php';

$wamp = new \Octamp\Wamp\Wamp();

$wamp->run();