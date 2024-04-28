<?php

namespace Octamp\Wamp\Transport;

use Octamp\Server\Connection\Connection;
use Thruway\Message\Message;

class DummyTransport extends AbstractTransport
{
    public function __construct()
    {
        parent::__construct();
    }


    public function getId(): string
    {
    }

    public function getForGenerationId(): string
    {
    }

    public function getTransportDetails()
    {
    }

    public function sendMessage(Message $msg)
    {
    }
}