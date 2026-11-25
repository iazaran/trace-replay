<?php

/*
|--------------------------------------------------------------------------
| Minimal Livewire 3+ stand-ins
|--------------------------------------------------------------------------
| Livewire is not a dev dependency. These stubs only record the hooks the
| service provider registers so the tests can fire them. They are loaded
| on demand, and only when the real package is not installed.
*/

namespace Livewire;

if (! class_exists(Livewire::class)) {
    class Livewire
    {
        /** @var array<string, array<int, callable>> */
        public static array $listeners = [];

        public static function listen(string $eventName, callable $callback): void
        {
            static::$listeners[$eventName][] = $callback;
        }

        public static function fire(string $eventName, mixed ...$params): void
        {
            foreach (static::$listeners[$eventName] ?? [] as $callback) {
                $callback(...$params);
            }
        }
    }
}

if (! class_exists(EventBus::class)) {
    class EventBus {}
}
