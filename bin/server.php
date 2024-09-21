<?php
error_reporting(E_ALL ^ E_DEPRECATED);

use Octamp\Wamp\Adapter\RedisAdapter;
use Octamp\Wamp\Config\AdapterConfiguration;
use Octamp\Wamp\Config\RealmConfiguration;
use Octamp\Wamp\Config\TransportConfiguration;
use Octamp\Wamp\Config\TransportProviderConfig;
use Octamp\Wamp\Wamp;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Yaml\Yaml;

$loader = require_once __DIR__ . '/../vendor/autoload.php';

$rootPath = dirname(__DIR__);

$env = new Dotenv();
$env->loadEnv($rootPath . '/.env');

try {
    $processor = new Processor();
    $realmConfig = $processor->processConfiguration(new RealmConfiguration(), Yaml::parseFile($rootPath . $_ENV['REALM_FILE']));
    $transportConfig = $processor->processConfiguration(new TransportConfiguration(), Yaml::parseFile($rootPath . $_ENV['TRANSPORT_FILE']));
    $serverConfig = new \Octamp\Wamp\Config\ServerConfig($transportConfig, $realmConfig, $_ENV['SERVER_WORKERNUM']);

    $adapterConfig = (object)$processor->processConfiguration(new AdapterConfiguration(), Yaml::parseFile($rootPath . $_ENV['ADAPTER_FILE']));
    $adapter = new RedisAdapter(
        $adapterConfig->host,
        $adapterConfig->port,
        $adapterConfig->auth?->username ?? null,
        $adapterConfig->auth?->password ?? null,
        $adapterConfig->options ?? []
    );
} catch (InvalidConfigurationException $exception) {
    printf('[INVALID][%s]: %s', $exception->getPath(), $exception->getMessage());
    exit(1);
}

$wamp = new Wamp($serverConfig, $adapter);

$wamp->run();