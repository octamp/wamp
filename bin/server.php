<?php
error_reporting(E_ALL ^ E_DEPRECATED);

use Octamp\Wamp\Adapter\RedisAdapter;
use Octamp\Wamp\Config\AdapterConfiguration;
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
    $transportConfig = (object)$processor->processConfiguration(new TransportConfiguration(), Yaml::parseFile($rootPath . $_ENV['TRANSPORT_FILE']));
    $transportProviderConfig = new TransportProviderConfig(
        host: $transportConfig->host,
        port: $transportConfig->port,
        workerNum: $transportConfig->workerNum,
        realms: $transportConfig->realms ?? [],
        auth: $transportConfig->auths ?? []
    );
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

$wamp = new Wamp($transportProviderConfig, $adapter);

$wamp->run();