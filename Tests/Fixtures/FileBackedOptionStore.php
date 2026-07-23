<?php

namespace Tests\Fixtures;

use Modules\ModuleManager\Services\Support\OptionStoreInterface;

/**
 * A JSON-file-backed OptionStoreInterface implementation, used only by
 * concurrency regression tests that need real, separate OS processes to
 * observe the *same* persisted state -- something FakeOptionStore's
 * private in-memory array can't do, since each PHP process gets its own
 * copy.
 *
 * Read-modify-write of the whole file is deliberately unlocked here,
 * mirroring FreeScout core's real Option::get()/set() (see
 * SavedRepoStore's own docblock on why it needs its own locking): the
 * classes under test are responsible for their own locking, not this
 * fixture.
 */
class FileBackedOptionStore implements OptionStoreInterface
{
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;

        if (!is_file($this->path)) {
            file_put_contents($this->path, json_encode([]));
        }
    }

    public function get(string $key, $default = null)
    {
        $values = $this->readAll();

        return array_key_exists($key, $values) ? $values[$key] : $default;
    }

    public function set(string $key, $value): void
    {
        $values = $this->readAll();
        $values[$key] = $value;
        file_put_contents($this->path, json_encode($values));
    }

    private function readAll(): array
    {
        $raw = @file_get_contents($this->path);

        if ($raw === false || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
