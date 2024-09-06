<?php
error_reporting(E_ALL ^ E_DEPRECATED);
use Octamp\Wamp\Adapter\RedisAdapter;
use Octamp\Wamp\Config\TransportProviderConfig;
use Octamp\Wamp\Wamp;
use Symfony\Component\Dotenv\Dotenv;

$loader = require_once __DIR__ . '/../vendor/autoload.php';

$env = new Dotenv();
$env->loadEnv(dirname(__DIR__) . '/.env');


$redisOptions = [
    'options' => [
        'database' => $_ENV['REDIS_DATABASE'] ?? 0,
    ]
];

$adapter = new RedisAdapter(
    $_ENV['REDIS_HOST'],
    $_ENV['REDIS_PORT'],
    $_ENV['REDIS_USERNAME'] ?? null,
    $_ENV['REDIS_PASSWORD'] ?? null,
    $redisOptions
);
$transportConfig = new TransportProviderConfig(
    host: $_ENV['SERVER_HOST'],
    port: $_ENV['SERVER_PORT'],
    workerNum: $_ENV['SERVER_WORKERNUM'],
    realms: [
        [
            'name' => 'realm1'
        ],
    ],
    auth: [
        [
            'method' => 'ticket',
            'type' => 'dynamic',
            'authenticator' => 'testing',
            'authenticator-realm' => 'realm1',
            'realms' => ['realm1']
        ],
        [
            'method' => 'wampcra',
            'type' => 'static',
            'users' => [
                [
                    'authid' => 'auth',
                    'secret' => 'qa2/QVmmjSx1JJuyH5EI2gMDQf+ARnfwMcLOpUfln74=',
                    'role' => 'auth',
                    'salt' => 'salt1',
                    'keylen' => 32,
                    'iterations' => 1000
                ],
            ],
        ]
    ],
);
$wamp = new Wamp($transportConfig, $adapter);

$wamp->run();