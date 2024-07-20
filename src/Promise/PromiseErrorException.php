<?php

declare(strict_types=1);

namespace Octamp\Wamp\Promise;

class PromiseErrorException extends \Exception
{
    public function __construct(protected mixed $data, ?Throwable $previous = null)
    {
        parent::__construct('Promise Error', 0, $previous);
    }

    public function getData(): mixed
    {
        return $this->data;
    }
}
