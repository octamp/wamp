<?php

namespace Octamp\Wamp\Pools;

use OpenSwoole\Coroutine\Channel;
use Utopia\Pools\Connection;

class Pool extends \Utopia\Pools\Pool
{
    protected Channel $channelPool;

    public function __construct(string $name, int $size, callable $init)
    {
        parent::__construct($name, $size, $init);
        $this->channelPool = new Channel($size);
    }

    public function init(): void
    {
        for ($i = 0; $i < $this->size; $i++) {
            $this->channelPool->push(true);
        }
    }

    /**
     * Summary:
     *  1. Try to get a connection from the pool
     *  2. If no connection is available, wait for one to be released
     *  3. If still no connection is available, throw an exception
     *  4. If a connection is available, return it
     *
     * @return Connection
     * @throws \Exception
     */
    public function pop(): Connection
    {
        if (($this->channelPool->length() + count($this->active)) === 0) {
            $this->init();
        }

        $totalTimeout = $this->getRetryAttempts() * $this->getRetrySleep();

        $connection = $this->channelPool->pop($totalTimeout);
        if ($connection === false) {
            throw new \Exception("Pool '{$this->name}' is empty (size {$this->size})");
        }

        if ($connection === true) { // Pool has space, create connection
            $connection = new Connection(($this->init)());
        }

        if ($connection instanceof Connection) {
            $connection
                ->setID($this->getName().'-'.uniqid())
                ->setPool($this)
            ;

            $this->active[$connection->getID()] = $connection;
            return $connection;
        }

        throw new \Exception('Failed to get a connection from the pool');
    }

    /**
     * @param Connection $connection
     * @return self
     */
    public function push(Connection $connection): self
    {
        $this->channelPool->push($connection);
        unset($this->active[$connection->getID()]);

        return $this;
    }

    /**
     * @return int
     */
    public function count(): int
    {
        return $this->channelPool->length();
    }

    /**
     * @param Connection|null $connection
     * @return self
     */
    public function reclaim(Connection $connection = null): self
    {
        if ($connection !== null) {
            $this->push($connection);
            return $this;
        }

        foreach ($this->active as $connection) {
            $this->push($connection);
        }

        return $this;
    }


    /**
     * @param Connection|null $connection
     * @return self
     */
    public function destroy(Connection $connection = null): self
    {
        // no implementation

        return $this;
    }

    /**
     * @return bool
     */
    public function isEmpty(): bool
    {
        return $this->channelPool->isEmpty();
    }

    /**
     * @return bool
     */
    public function isFull(): bool
    {
        return $this->channelPool->isFull();
    }
}