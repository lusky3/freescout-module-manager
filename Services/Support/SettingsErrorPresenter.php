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
 */
final class SettingsErrorPresenter
{
    public const REPO_FIELDS = ['owner', 'repo', 'ref', 'label'];

    /**
     * Error keys that aren't already surfaced next to a specific field
     * (an add-repo field or the upload input) and therefore need the
     * generic error banner.
     *
     * @param string[] $errorKeys
     * @return string[]
     */
    public static function generalErrorKeys(array $errorKeys): array
    {
        $handledErrorFields = array_merge(self::REPO_FIELDS, ['module_zip']);

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
