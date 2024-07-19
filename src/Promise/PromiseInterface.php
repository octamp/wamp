<?php

namespace Octamp\Wamp\Promise;

interface PromiseInterface
{
    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): static;

    /**
     * @return mixed
     * @throws PromiseErrorException
     */
    public function wait(int $timeout = -1): mixed;

    /**
     * This method return a promise with rejected case only
     *
     * @param callable $onRejected
     * @return PromiseInterface
     */
    public function catch(callable $onRejected): static;

    /**
     * This method create new promise instance
     *
     * @param callable $promise
     * @return PromiseInterface
     */
    public static function create(callable $promise): static;

    public static function resolve(mixed $result): PromiseInterface;

    public static function reject(mixed $result): PromiseInterface;
}