<?php

namespace Modules\ModuleManager\Services\Support;

class InstallResult
{
    public bool $success;
    public ?string $alias;
    public ?string $name;
    public ?string $error;

    public function __construct(bool $success, ?string $alias = null, ?string $name = null, ?string $error = null)
    {
        $this->success = $success;
        $this->alias = $alias;
        $this->name = $name;
        $this->error = $error;
    }

    public static function ok(string $alias, string $name): self
    {
        return new self(true, $alias, $name, null);
    }

    public static function fail(string $error): self
    {
        return new self(false, null, null, $error);
    }
}
