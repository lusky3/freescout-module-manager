<?php

namespace Modules\ModuleManager\Services\Support;

class LaravelOptionStore implements OptionStoreInterface
{
    public function get(string $key, $default = null)
    {
        return \Option::get($key, $default);
    }

    public function set(string $key, $value): void
    {
        \Option::set($key, $value);
    }
}
