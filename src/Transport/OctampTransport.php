<?php

namespace Octamp\Wamp\Transport;

use Octamp\Client\Promise\Deferred;
use Octamp\Client\Promise\Promise;
use OpenSwoole\Timer;
use OpenSwoole\WebSocket\Frame;
use Thruway\Message\Message;

class OctampTransport extends AbstractTransport
{
    protected float $pingSeq = 1;

    protected array $pingRequests = [];

    public function getTransportDetails(): array
    {
        return [
            'type' => 'octamp',
            'headers' => $this->connection->getRequest()->header,
            'server' => $this->connection->getRequest()->server,
        ];
    }

    public function sendMessage(Message $msg): void
    {
        $this->connection->send($this->getSerializer()->serialize($msg), $this->getSerializer()->opcode());
    }

    public function close(): void
    {
        $this->connection->close();
    }

    public function ping(int $timeout = 10): ?Promise
    {
        $seq = $this->pingSeq;
        $this->connection->ping($seq);

        if ($timeout > 0) {
            $this->pingSeq++;

            $deferred = new Deferred();

            $timer = Timer::after(5000, function ($seq) {
                if (isset($this->pingRequests[$seq])) {
                    $this->pingRequests[$seq]['deferred']->reject('timeout');
                    unset($this->pingRequests[$seq]);
                }
            }, $seq);


            $this->pingRequests[$seq] = [
                'seq' => $seq,
                'deferred' => $deferred,
                'timer' => $timer
            ];

            return $deferred->promise();
        }

        return null;
    }

    public function onPong(Frame $frame): void
    {
        $seq = (int) $frame->data;

        if (isset($this->pingRequests[$seq]['deferred'])) {
            $this->pingRequests[$seq]['deferred']->resolve($seq);
            $timer = $this->pingRequests[$seq]['timer'];
            Timer::clear($timer);

            unset($this->pingRequests[$seq]);
        }
    }

    public function getId(): string
    {
        return $this->connection->getId();
    }

    public function getForGenerationId(): string
    {
        return $this->connection->getServerId();
    }
}