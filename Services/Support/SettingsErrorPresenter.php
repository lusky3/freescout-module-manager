<?php

namespace Modules\ModuleManager\Services\Support;

/**
 * Pure, framework-independent logic behind the settings page's view
 * composer (Providers/ModuleManagerServiceProvider::registerViewComposer()):
 * given the set of validation-error keys present on the current request,
 * decides which keys need the generic error banner, which add-repo field
 * (if any) should get autofocus, and which install tab should be active
 * after a redirect-with-errors.
 *
 * Deliberately has zero Illuminate/Laravel dependency (unlike the
 * ServiceProvider itself, which extends Illuminate\Support\ServiceProvider
 * and therefore can't be loaded standalone in this module's Laravel-free
 * PHPUnit environment) so this logic stays directly unit-testable: the
 * ServiceProvider hands it a plain array of error keys ($errors->keys())
 * rather than the ViewErrorBag object itself.
 *
 * REPO_FIELDS also doubles as the single source of truth for the "Add a
 * Repository" form's field names -- previously spelled out three separate
 * times (the view composer's $handledErrorFields, its
 * firstInvalidRepoField-finding loop, and the Blade view's own
 * $repoFormFields array) with nothing keeping them in sync.
 *
 * GITHUB_URL_FIELD ('github_url') is the "add by pasted URL" form's single
 * field -- a separate form from the four REPO_FIELDS above, but living on
 * the same GitHub tab. It gets its own excluded-from-general-errors and
 * own-autofocus treatment below, parallel to (but independent of)
 * firstInvalidRepoField()'s handling of the manual form, since the two
 * forms can't both be invalid at once (each is its own POST) but must not
 * fight over which one grabs autofocus when only one of them is.
 */
final class SettingsErrorPresenter
{
    public const REPO_FIELDS = ['owner', 'repo', 'ref', 'label'];

    public const GITHUB_URL_FIELD = 'github_url';

    /**
     * Error keys that aren't already surfaced next to a specific field
     * (an add-repo field, the paste-a-URL input, or the upload input) and
     * therefore need the generic error banner.
     *
     * @param string[] $errorKeys
     * @return string[]
     */
    public static function generalErrorKeys(array $errorKeys): array
    {
        $handledErrorFields = array_merge(self::REPO_FIELDS, [self::GITHUB_URL_FIELD, 'module_zip']);

        return array_values(array_diff($errorKeys, $handledErrorFields));
    }

    /**
     * The first add-repo field (in REPO_FIELDS order) that has a
     * validation error, if any -- used to decide which input gets
     * autofocus after a redirect-with-errors.
     *
     * @param string[] $errorKeys
     */
    public static function firstInvalidRepoField(array $errorKeys): ?string
    {
        foreach (self::REPO_FIELDS as $repoField) {
            if (in_array($repoField, $errorKeys, true)) {
                return $repoField;
            }
        }

        return null;
    }

    /**
     * Whether the paste-a-URL input (GITHUB_URL_FIELD) has a validation
     * error and should get autofocus. A plain boolean rather than
     * returning the field name itself (unlike firstInvalidRepoField()):
     * there's only one possible field here, so there's nothing to select
     * between -- the view just needs to know whether to autofocus it.
     *
     * @param string[] $errorKeys
     */
    public static function githubUrlFieldHasError(array $errorKeys): bool
    {
        return in_array(self::GITHUB_URL_FIELD, $errorKeys, true);
    }

    /**
     * Which install tab (github|upload) should be active after a
     * redirect-with-errors.
     *
     * Routes to 'upload' when EITHER 'module_zip' (the upload input's own
     * presence/type validation error) OR 'module' (the error key
     * ZipModuleExtractor failures come back under -- bad folder structure,
     * missing module.json, an already-exists collision, etc; see
     * ModuleManagerController::installFromZip()) is present. Both
     * originate from the Upload tab, but only 'module_zip' used to be
     * checked here, so a ZIP that passed upload validation but failed
     * extraction stranded its own error message behind the now-hidden
     * Upload tab while the GitHub tab was shown active instead.
     *
     * GITHUB_URL_FIELD does not need its own branch here: it's neither
     * 'module_zip' nor 'module', so it already falls through to the
     * 'github' default below, which is where it needs to land anyway.
     *
     * @param string[] $errorKeys
     */
    public static function activeInstallTab(array $errorKeys): string
    {
        if (in_array('module_zip', $errorKeys, true) || in_array('module', $errorKeys, true)) {
            return 'upload';
        }

        return 'github';
    }
}
