<?php

namespace Octamp\Wamp\Session\Adapter;

use Octamp\Wamp\Session\Session;

class RedisAdapter implements AdapterInterface
{
    public function __construct(protected \Octamp\Wamp\Adapter\RedisAdapter $adapter)
    {
    }

    public function generateId(): string
    {
        $prefix = date('Ymd');
        $id = $this->adapter->inc('sesid:current', 1, $prefix);

        return $prefix . str_pad($id, 6, 0, STR_PAD_LEFT);
    }

    public function saveSession(Session $session): void
    {
        $details = [
            'id' => $session->getId(),
            'transportId' => $session->getTransportId(),
            'authenticated' => $session->isAuthenticated(),
            'realm' => $session->getRealm()?->name ?? null,
            'trusted' => $session->isTrusted(),
            'transportClass' => get_class($session->getTransport()),
            'serializerClass' => get_class($session->getTransport()->getSerializer()),
            'serverId' => $session->getServerId(),
        ];

        $this->adapter->set($this->getKeyBySession($session), $details);
    }

    public function getSession(string $id): ?array
    {

    }

    public function getAll(): array
    {

    }

    public function find(array $condition): array
    {

    }

    public function findByOne(string $key, mixed $value): ?array
    {

    }

    public function get(string $id): ?array
    {
        $client = $this->adapter->createPredis();
        $keys = $client->keys('ses:' . $id);
        $data = null;
        if (!empty($keys)) {
            $data = $client->hgetall($keys[0]);
            $data = $this->decodeData($data);
        }
        $client->quit();

        return $data;
    }

    public function remove(Session $session): void
    {
        $this->adapter->del($this->getKeyBySession($session));
    }

    private function decodeData(array $data): array
    {
        foreach ($data as &$value) {
            try {
                $newValue = json_decode($value, true);
                if (is_array($newValue)) {
                    $value = $newValue;
                }
            } catch (\Exception $exception) {
            }
        }

        return $data;
    }

    private function getKeyBySession(Session $session): string
    {
        return $this->getKeyByIds($session->getId(), $session->getTransportId());
    }

    private function getKeyByIds(string $sessionId, string $transportId): string
    {
        return 'ses:' . $sessionId . ':' . base64_encode($transportId);
    }
}