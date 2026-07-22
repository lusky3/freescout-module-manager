<?php

namespace Modules\ModuleManager\Services\Support;

/**
 * A single saved GitHub repository entry.
 *
 * This is the typed counterpart of the associative arrays that used to be
 * passed around by SavedRepoStore, the controller, and the Blade view with
 * magic string keys and no static guarantee they stayed in sync -- mirrors
 * the InstallResult value-object pattern already used elsewhere in this
 * module for exactly this kind of shape-sensitive data.
 *
 * toArray()/fromArray() are the (de)serialization boundary for the
 * underlying `Option` JSON storage; fromArray() is also where malformed
 * stored data is rejected (returns null) so a corrupted entry degrades
 * gracefully instead of throwing.
 */
class SavedRepo
{
    private const REQUIRED_STRING_KEYS = ['id', 'owner', 'repo', 'ref', 'label'];

    public string $id;
    public string $owner;
    public string $repo;
    public string $ref;
    public string $label;
    public ?string $installedAlias;
    public ?string $installedFolder;

    public function __construct(
        string $id,
        string $owner,
        string $repo,
        string $ref,
        string $label,
        ?string $installedAlias = null,
        ?string $installedFolder = null
    ) {
        $this->id = $id;
        $this->owner = $owner;
        $this->repo = $repo;
        $this->ref = $ref;
        $this->label = $label;
        $this->installedAlias = $installedAlias;
        $this->installedFolder = $installedFolder;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'owner' => $this->owner,
            'repo' => $this->repo,
            'ref' => $this->ref,
            'label' => $this->label,
            'installed_alias' => $this->installedAlias,
            'installed_folder' => $this->installedFolder,
        ];
    }

    /**
     * Validates and builds a SavedRepo from a decoded storage entry.
     * Returns null (instead of throwing) for anything malformed, so a
     * single corrupted stored entry doesn't take down the whole list.
     */
    public static function fromArray(array $data): ?self
    {
        foreach (self::REQUIRED_STRING_KEYS as $key) {
            if (!isset($data[$key]) || !is_string($data[$key]) || $data[$key] === '') {
                return null;
            }
        }

        $installedAlias = $data['installed_alias'] ?? null;
        $installedFolder = $data['installed_folder'] ?? null;

        if ($installedAlias !== null && !is_string($installedAlias)) {
            return null;
        }

        if ($installedFolder !== null && !is_string($installedFolder)) {
            return null;
        }

        return new self(
            $data['id'],
            $data['owner'],
            $data['repo'],
            $data['ref'],
            $data['label'],
            $installedAlias !== null && $installedAlias !== '' ? $installedAlias : null,
            $installedFolder !== null && $installedFolder !== '' ? $installedFolder : null
        );
    }
}
