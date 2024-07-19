<?php

namespace Octamp\Wamp\Auth\Response;

readonly class HelloErrorResponse
{
    public ?\stdClass $errorDetails;

    public function __construct(
        public int $status,
        public string $challengeMethod,
        public ?string $errorUri = null,
        \stdClass|array|null $errorDetails = null
    ) {
        $this->errorDetails = is_array($errorDetails) ? (object) $errorDetails : $errorDetails;
    }
}