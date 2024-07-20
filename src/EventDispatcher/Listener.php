<?php

declare(strict_types=1);

namespace Octamp\Wamp\EventDispatcher;

use Symfony\Component\EventDispatcher\Event;

class Listener
{
    protected bool $calledOnce = false;

    public function __construct(protected mixed $listener, public readonly bool $once = false)
    {

    }

    public function shouldRemove(): bool
    {
        return $this->calledOnce && $this->once;
    }

    public function __invoke(Event $event)
    {
        $listener = $this->listener;
        if (is_callable($listener)) {
            if ($this->once && $this->calledOnce) {
                return;
            }
            $this->calledOnce = true;
            $listener($event);
        }
    }
}
