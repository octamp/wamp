<?php

namespace Octamp\Wamp\Auth\Response;

readonly class HelloSuccessResponse
{
    public ?\stdClass $authDetails;
    public ?\stdClass $verifyDetails;
    public ?\stdClass $challengeDetails;

    public function __construct(
        public int $status,
        public string $challengeMethod,
        \stdClass|array|null $authDetails = null,
        \stdClass|array|null $verifyDetails = null,
        \stdClass|array|null $challengeDetails = null
    ) {
        $this->authDetails = is_array($authDetails) ? (object) $authDetails : $authDetails;
        $this->verifyDetails = is_array($verifyDetails) ? (object) $verifyDetails : $verifyDetails;
        $this->challengeDetails = is_array($challengeDetails) ? (object) $challengeDetails : $challengeDetails;
    }
}