<?php

namespace Octamp\Wamp\Promise;

use OpenSwoole\Coroutine;

class Promise implements PromiseInterface
{
    const STATE_PENDING   = 1;
    const STATE_FULFILLED = 0;
    const STATE_REJECTED  = -1;

    protected mixed $result;
    protected int $state = self::STATE_PENDING;

    /**
     * @var Coroutine\Channel[]
     */
    protected array $channels = [];

    public function __construct(callable $executor)
    {
        Coroutine::create(function (callable $executor, callable $resolve, callable $reject) {
            try {
                $executor($resolve, $reject);
            } catch (\Throwable $exception) {
                $reject($exception);
            }
        }, $executor, [$this, 'processResolve'], [$this, 'processReject']);
    }

    public function processResolve(mixed $value = null): void
    {
        $this->setResult($value);
        $this->setState(self::STATE_FULFILLED);

        foreach ($this->channels as $channel) {
            $channel->push($this->result);
        }
    }

    public function processReject(mixed $value = null): void
    {
        $this->setResult($value);
        $this->setState(self::STATE_REJECTED);

        foreach ($this->channels as $channel) {
            $channel->push($this->result);
        }
    }

    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): static
    {
        $executor = function (callable $resolve, callable $reject) use ($onFulfilled, $onRejected) {
            try {
                $result = $this->wait();
                $callable = $this->isFulfilled() ? $onFulfilled : $onRejected;
                if (!is_callable($callable)) {
                    $resolve($result);
                    return;
                }
                $resolve($callable($result));
            } catch (PromiseErrorException $exception) {
                $callable = $this->isFulfilled() ? $onFulfilled : $onRejected;
                if (is_callable($callable)) {
                    $resolve($callable($exception->getData()));
                    return;
                }
                $reject($exception->getData());
            } catch (\Throwable $error) {
                $callable = $this->isFulfilled() ? $onFulfilled : $onRejected;
                if (is_callable($callable)) {
                    $resolve($callable($error));
                    return;
                }
                $reject($error);
            }
        };

        return self::create($executor);
    }

    final public function catch(callable $onRejected): static
    {
        return $this->then(null, $onRejected);
    }

    final public function wait(int $timeout = -1): mixed
    {
        if (!$this->isPending()) {
            return $this->result;
        }

        $channel = new Coroutine\Channel(1);
        $this->channels[$channel->getId()] = $channel;

        $result = $channel->pop($timeout);
        $channel->close();
        unset($this->channels[$channel->getId()]);

        if ($this->isRejected()) {
            throw new PromiseErrorException($result);
        }

        return $result;
    }

    final public static function create(callable $promise): static
    {
        return new static($promise);
    }

    final protected function setState(int $state): void
    {
        $this->state = $state;
    }

    final protected function isPending(): bool
    {
        return $this->state == self::STATE_PENDING;
    }

    final protected function isFulfilled(): bool
    {
        return $this->state == self::STATE_FULFILLED;
    }

    final protected function isRejected(): bool
    {
        return $this->state == self::STATE_REJECTED;
    }

    private function setResult(mixed $value): void
    {
        if ($value instanceof PromiseInterface) {
            try {
                $result = $value->wait();
                $this->setResult($result);
            } catch (PromiseErrorException $exception) {
                $this->setResult($exception->getData());
                $this->setState(self::STATE_REJECTED);
            }
        } else {
            $this->result = $value;
        }
    }

    public function __destruct()
    {
        $this->processReject(new PromiseInterrupted());
        $this->clearChannels();
        // use cancel
    }

    private function clearChannels(): void
    {
        $keys = array_keys($this->channels);
        foreach ($keys as $key) {
            $this->channels[$key]->close();
            unset($this->channels[$key]);
        }
    }

    public static function resolve(mixed $result): PromiseInterface
    {
        return new Promise(function ($resolve, $reject) use ($result) {
            $resolve($result);
        });
    }

    public static function reject(mixed $result): PromiseInterface
    {
        return new Promise(function ($resolve, $reject) use ($result) {
            $reject($result);
        });
    }
}