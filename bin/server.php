<?php

use Symfony\Component\Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';

$env = new Dotenv();
$env->loadEnv(dirname(__DIR__ . '') . '/.env');


$redisOptions = [
    'options' => [
        'database' => $_ENV['REDIS_DATABASE'] ?? 0,
    ]
];
if (!$_ENV['REDIS_PASSWORD']) {
    $redisOptions['options']['password'] = $_ENV['REDIS_PASSWORD'];
}
if (!$_ENV['REDIS_USERNAME']) {
    $redisOptions['options']['username'] = $_ENV['REDIS_USERNAME'];
}

$adapter = new \Octamp\Wamp\Adapter\RedisAdapter(
    $_ENV['REDIS_HOST'],
    $_ENV['REDIS_PORT'],
    $redisOptions
);
$transportConfig = new \Octamp\Wamp\Config\TransportProviderConfig(
    host: $_ENV['SERVER_HOST'],
    port: $_ENV['SERVER_PORT'],
    workerNum: $_ENV['SERVER_WORKERNUM'],
);
$wamp = new \Octamp\Wamp\Wamp($transportConfig, $adapter);

$wamp->run();