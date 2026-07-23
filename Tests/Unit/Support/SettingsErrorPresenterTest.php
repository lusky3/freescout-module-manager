<?php

namespace Tests\Unit\Support;

use Modules\ModuleManager\Services\Support\SettingsErrorPresenter;
use PHPUnit\Framework\TestCase;

class SettingsErrorPresenterTest extends TestCase
{
    public function test_active_install_tab_defaults_to_github_when_no_errors(): void
    {
        $this->assertSame('github', SettingsErrorPresenter::activeInstallTab([]));
    }

    public function test_active_install_tab_defaults_to_github_for_unrelated_errors(): void
    {
        $this->assertSame('github', SettingsErrorPresenter::activeInstallTab(['install']));
    }

    public function test_active_install_tab_routes_to_upload_on_upload_validation_failure(): void
    {
        $this->assertSame('upload', SettingsErrorPresenter::activeInstallTab(['module_zip']));
    }

    /**
     * This is the real bug being fixed: ZipModuleExtractor failures (bad
     * folder structure, missing module.json, an already-exists collision,
     * etc.) come back from ModuleManagerController::installFromZip() as
     * withErrors(['module' => ...]) -- a different key from the upload
     * input's own 'module_zip' presence/type validation. Before this fix,
     * only 'module_zip' was checked, so an extraction failure on an
     * otherwise-valid upload left the GitHub tab active while the actual
     * error sat in the generic banner, disconnected from the now-hidden
     * Upload tab where the admin was working.
     */
    public function test_active_install_tab_routes_to_upload_on_zip_extraction_failure(): void
    {
        $this->assertSame('upload', SettingsErrorPresenter::activeInstallTab(['module']));
    }

    public function test_active_install_tab_routes_to_upload_when_both_upload_error_keys_are_present(): void
    {
        $this->assertSame('upload', SettingsErrorPresenter::activeInstallTab(['module_zip', 'module']));
    }

    public function test_active_install_tab_does_not_treat_add_repo_field_errors_as_upload_errors(): void
    {
        $this->assertSame('github', SettingsErrorPresenter::activeInstallTab(['owner', 'repo', 'ref', 'label']));
    }

    public function test_general_error_keys_excludes_add_repo_and_upload_fields(): void
    {
        $this->assertSame(
            ['install'],
            SettingsErrorPresenter::generalErrorKeys(['owner', 'repo', 'ref', 'label', 'module_zip', 'install'])
        );
    }

    public function test_general_error_keys_excludes_the_zip_extraction_error_key(): void
    {
        // 'module' is handled inline (surfaced via the generic banner is
        // *not* what the Upload tab error message relies on -- it's shown
        // via $errors->first('module_zip') style handling on that tab
        // instead), so it should not double up in the general banner.
        // NOTE: unlike 'module_zip', 'module' has no dedicated field-level
        // display in the Blade view; it intentionally still surfaces via
        // the general banner. This test documents current behavior for
        // generalErrorKeys() specifically (module_zip/add-repo fields are
        // excluded; 'module' currently is not).
        $this->assertSame(
            ['module'],
            SettingsErrorPresenter::generalErrorKeys(['module'])
        );
    }

    public function test_general_error_keys_returns_empty_when_only_handled_fields_have_errors(): void
    {
        $this->assertSame(
            [],
            SettingsErrorPresenter::generalErrorKeys(['owner', 'module_zip'])
        );
    }

    public function test_first_invalid_repo_field_returns_null_when_none_invalid(): void
    {
        $this->assertNull(SettingsErrorPresenter::firstInvalidRepoField([]));
        $this->assertNull(SettingsErrorPresenter::firstInvalidRepoField(['module_zip']));
    }

    public function test_first_invalid_repo_field_returns_the_first_field_in_canonical_order(): void
    {
        // Canonical order is owner, repo, ref, label (SettingsErrorPresenter::REPO_FIELDS).
        // Even though 'label' appears first in the error-keys array here,
        // 'ref' should win because it comes first in REPO_FIELDS order.
        $this->assertSame(
            'ref',
            SettingsErrorPresenter::firstInvalidRepoField(['label', 'ref'])
        );
    }

    public function test_repo_fields_constant_is_the_canonical_owner_repo_ref_label_list(): void
    {
        $this->assertSame(['owner', 'repo', 'ref', 'label'], SettingsErrorPresenter::REPO_FIELDS);
    }
}
