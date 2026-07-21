<?php

namespace Modules\ModuleManager\Services\Support;

interface OptionStoreInterface
{
    /** @return mixed */
    public function get(string $key, $default = null);

    /** @param mixed $value */
    public function set(string $key, $value): void;
}
