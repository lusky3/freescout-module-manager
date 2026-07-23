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
 * The constructor itself enforces the class's own invariant -- id/owner/
 * repo/ref/label must be non-empty strings -- by throwing
 * \InvalidArgumentException. This matters because SavedRepoStore::add()
 * constructs a SavedRepo directly (`new SavedRepo(...)`), not via
 * fromArray() below, so relying on fromArray() alone to guard the invariant
 * would leave add() depending entirely on upstream Laravel validation to
 * stay valid.
 *
 * toArray()/fromArray() are the (de)serialization boundary for the
 * underlying `Option` JSON storage; fromArray() is also where malformed
 * stored data is rejected (returns null, by catching the constructor's
 * \InvalidArgumentException) so a corrupted entry degrades gracefully
 * instead of throwing.
 *
 * installedCommitSha/latestKnownRef/latestKnownLabel/latestKnownUrl/
 * latestCheckedAt support update checking (Services\UpdateChecker,
 * SavedRepoStore::recordUpdateCheck()/markUpdated()) -- see
 * isUpdateAvailable() below for how they're compared.
 */
class SavedRepo
{
    private const REQUIRED_STRING_KEYS = ['id', 'owner', 'repo', 'ref', 'label'];

    private const OPTIONAL_STRING_KEYS = [
        'installedAlias' => 'installed_alias',
        'installedFolder' => 'installed_folder',
        'installedCommitSha' => 'installed_commit_sha',
        'latestKnownRef' => 'latest_known_ref',
        'latestKnownLabel' => 'latest_known_label',
        'latestKnownUrl' => 'latest_known_url',
        'latestCheckedAt' => 'latest_checked_at',
    ];

    public string $id;
    public string $owner;
    public string $repo;
    public string $ref;
    public string $label;
    public ?string $installedAlias;
    public ?string $installedFolder;
    public ?string $installedCommitSha;
    public ?string $latestKnownRef;
    public ?string $latestKnownLabel;
    public ?string $latestKnownUrl;
    public ?string $latestCheckedAt;

    /**
     * @throws \InvalidArgumentException if id/owner/repo/ref/label is not a
     *     non-empty string.
     */
    public function __construct(
        string $id,
        string $owner,
        string $repo,
        string $ref,
        string $label,
        ?string $installedAlias = null,
        ?string $installedFolder = null,
        ?string $installedCommitSha = null,
        ?string $latestKnownRef = null,
        ?string $latestKnownLabel = null,
        ?string $latestKnownUrl = null,
        ?string $latestCheckedAt = null
    ) {
        $values = compact('id', 'owner', 'repo', 'ref', 'label');

        foreach (self::REQUIRED_STRING_KEYS as $key) {
            if ($values[$key] === '') {
                throw new \InvalidArgumentException("SavedRepo: '{$key}' must be a non-empty string.");
            }
        }

        $this->id = $id;
        $this->owner = $owner;
        $this->repo = $repo;
        $this->ref = $ref;
        $this->label = $label;
        $this->installedAlias = $installedAlias;
        $this->installedFolder = $installedFolder;
        $this->installedCommitSha = $installedCommitSha;
        $this->latestKnownRef = $latestKnownRef;
        $this->latestKnownLabel = $latestKnownLabel;
        $this->latestKnownUrl = $latestKnownUrl;
        $this->latestCheckedAt = $latestCheckedAt;
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
            'installed_commit_sha' => $this->installedCommitSha,
            'latest_known_ref' => $this->latestKnownRef,
            'latest_known_label' => $this->latestKnownLabel,
            'latest_known_url' => $this->latestKnownUrl,
            'latest_checked_at' => $this->latestCheckedAt,
        ];
    }

    /**
     * Compares this repo's currently-installed state against the last
     * known "latest available" check result. Returns null when no check
     * has ever run (latestKnownRef is null) -- "unknown", not "no update"
     * -- so the UI can show a distinct "not checked yet" state instead of
     * a false "up to date".
     *
     * The comparison key is deliberately mode-agnostic: for a repo tracked
     * by release tag, $ref itself IS the version marker
     * (SavedRepoStore::markUpdated() sets it to the tag just installed),
     * so $installedCommitSha stays null and $ref is what's compared. For a
     * repo with no releases (tracked by commit on a branch), $ref is just
     * the branch *name* and never changes, so $installedCommitSha (the
     * actual commit last installed) is compared instead.
     */
    public function isUpdateAvailable(): ?bool
    {
        if ($this->latestKnownRef === null) {
            return null;
        }

        $installedMarker = $this->installedCommitSha ?? $this->ref;

        return $this->latestKnownRef !== $installedMarker;
    }

    /**
     * Validates and builds a SavedRepo from a decoded storage entry.
     * Returns null (instead of throwing) for anything malformed, so a
     * single corrupted stored entry doesn't take down the whole list.
     *
     * The isset()/is_string()/empty-string checks below are still done
     * here (rather than leaning solely on the constructor's own
     * InvalidArgumentException) because $data may be missing required
     * keys entirely or have the wrong type -- the constructor's parameter
     * types only guard against empty *strings*, not against, say, a
     * missing 'owner' key or an 'owner' that decoded as an int.
     */
    public static function fromArray(array $data): ?self
    {
        foreach (self::REQUIRED_STRING_KEYS as $key) {
            if (!isset($data[$key]) || !is_string($data[$key]) || $data[$key] === '') {
                return null;
            }
        }

        $optional = [];
        foreach (self::OPTIONAL_STRING_KEYS as $property => $storageKey) {
            $value = $data[$storageKey] ?? null;

            if ($value !== null && !is_string($value)) {
                return null;
            }

            $optional[$property] = $value !== null && $value !== '' ? $value : null;
        }

        try {
            return new self(
                $data['id'],
                $data['owner'],
                $data['repo'],
                $data['ref'],
                $data['label'],
                $optional['installedAlias'],
                $optional['installedFolder'],
                $optional['installedCommitSha'],
                $optional['latestKnownRef'],
                $optional['latestKnownLabel'],
                $optional['latestKnownUrl'],
                $optional['latestCheckedAt']
            );
        } catch (\InvalidArgumentException $e) {
            return null;
        }
    }
}
