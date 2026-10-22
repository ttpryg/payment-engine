<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Tests\Support;

use Psr\EventDispatcher\EventDispatcherInterface;

final class RecordingEventDispatcher implements EventDispatcherInterface
{
    /** @var array<class-string, list<callable>> */
    private array $listeners = [];

    /**
     * @param  class-string  $eventClass
     */
    public function addListener(string $eventClass, callable $listener): void
    {
        $this->listeners[$eventClass][] = $listener;
    }

    public function dispatch(object $event): object
    {
        foreach ($this->listeners[$event::class] ?? [] as $listener) {
            $listener($event);
        }

        return $event;
    }
}
