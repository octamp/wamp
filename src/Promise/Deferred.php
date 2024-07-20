<?php

declare(strict_types=1);

namespace Octamp\Wamp\Promise;

class Deferred
{
    /**
     * @var PromiseInterface[]
     */
    private array $promise = [];
    private mixed $resolveCallback;
    private mixed $rejectCallback;

    public function promise(): PromiseInterface
    {
        if (empty($this->promise)) {
            $this->promise[] = new Promise(function ($resolve, $reject) {
                $this->resolveCallback = $resolve;
                $this->rejectCallback = $reject;
            });
        }

        return $this->promise[0];
    }

    public function resolve(mixed $value = null): void
    {
        $this->promise();
        call_user_func($this->resolveCallback, $value);
    }

    public function reject(mixed $reason): void
    {
        $this->promise();
        call_user_func($this->rejectCallback, $reason);
    }

    public function __destruct()
    {
        unset($this->promise[0]);
    }
}
