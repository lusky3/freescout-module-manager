<?php
// Services/Support/UpdateTarget.php

namespace Modules\ModuleManager\Services\Support;

/**
 * The result of one Services\UpdateChecker::findLatest() call: whatever
 * GitHub reports as the latest available version for a saved repo, in
 * whichever of the two tracking modes applied.
 *
 * Never persisted directly -- SavedRepoStore::recordUpdateCheck() and
 * markUpdated() copy its fields onto the relevant SavedRepo instead (see
 * SavedRepo::isUpdateAvailable()), so this stays a short-lived transfer
 * object between UpdateChecker and the controller, not a stored shape that
 * would need its own fromArray()/toArray() and malformed-data handling like
 * CatalogEntry or SavedRepo have. Built only from already-validated,
 * trusted GitHub API responses (never from untrusted stored/external data),
 * so -- like InstallResult -- its constructor doesn't validate.
 */
class UpdateTarget
{
    public const MODE_TAG = 'tag';
    public const MODE_COMMIT = 'commit';

    public string $mode;
    public string $ref;
    public string $label;
    public ?string $url;

    public function __construct(string $mode, string $ref, string $label, ?string $url)
    {
        $this->mode = $mode;
        $this->ref = $ref;
        $this->label = $label;
        $this->url = $url;
    }
}
