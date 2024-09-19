<?php

declare(strict_types=1);

namespace Octamp\Wamp\Auth\Exception;

class AuthenticationException extends \Exception
{
    public function __construct(protected string $uri, protected array $errorDetails, ?string $message = null, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message ?? $this->uri, $code, $previous);
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function getErrorDetails(): array
    {
        return $this->errorDetails;
    }
}
