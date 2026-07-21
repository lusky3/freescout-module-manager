<?php

namespace Tests\Unit\Support;

use Modules\ModuleManager\Services\Support\InstallResult;
use PHPUnit\Framework\TestCase;

class InstallResultTest extends TestCase
{
    public function test_ok_result_reports_success_with_alias_and_name(): void
    {
        $result = InstallResult::ok('themes', 'Themes');

        $this->assertTrue($result->success);
        $this->assertSame('themes', $result->alias);
        $this->assertSame('Themes', $result->name);
        $this->assertNull($result->error);
    }

    public function test_fail_result_reports_failure_with_error_message(): void
    {
        $result = InstallResult::fail('ZIP could not be opened.');

        $this->assertFalse($result->success);
        $this->assertNull($result->alias);
        $this->assertNull($result->name);
        $this->assertSame('ZIP could not be opened.', $result->error);
    }
}
