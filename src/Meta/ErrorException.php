<?php

namespace Octamp\Wamp\Meta;

class ErrorException extends \Exception
{
    public function __construct(protected Error $error)
    {
        parent::__construct($error->uri);
    }

    public function getError(): Error
    {
        return $this->error;
    }
}