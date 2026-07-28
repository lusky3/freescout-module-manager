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

        // FreeScout's own Option::set() never updates its static $cache --
        // only Option::get() populates it, on a cache miss (see vendored
        // app/Option.php, which even has its own "todo: implement caching"
        // comment acknowledging the gap). Without this, a get() later in
        // the same request after this set() returns the *pre-set* value,
        // silently discarding this write. This module's controller can
        // legitimately call SavedRepoStore twice in one request (e.g.
        // markInstalled() then markUpdated() after a fresh install) --
        // confirmed live: without this line, the second call's read-modify-
        // write cycle was clobbering the first call's change.
        \Option::$cache[$key] = $value;
    }
}
