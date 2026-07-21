<?php

namespace Tests\Fixtures;

use Modules\ModuleManager\Services\Support\OptionStoreInterface;

class FakeOptionStore implements OptionStoreInterface
{
    private array $values = [];

    public function get(string $key, $default = null)
    {
        return $this->values[$key] ?? $default;
    }

    public function set(string $key, $value): void
    {
        $this->values[$key] = $value;
    }
}
