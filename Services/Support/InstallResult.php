<?php

namespace Modules\ModuleManager\Services\Support;

class InstallResult
{
    public bool $success;
    public ?string $alias;
    public ?string $name;
    public ?string $folder;
    public ?string $error;

    public function __construct(bool $success, ?string $alias = null, ?string $name = null, ?string $folder = null, ?string $error = null)
    {
        $this->success = $success;
        $this->alias = $alias;
        $this->name = $name;
        $this->folder = $folder;
        $this->error = $error;
    }

    public static function ok(string $alias, string $name, ?string $folder = null): self
    {
        return new self(true, $alias, $name, $folder, null);
    }

    public static function fail(string $error): self
    {
        return new self(false, null, null, null, $error);
    }
}
