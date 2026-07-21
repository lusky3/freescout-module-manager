<?php

namespace Tests\Feature;

use Tests\TestCase;

class ModuleManagerAccessTest extends TestCase
{
    public function test_guest_is_redirected_away_from_settings_page(): void
    {
        $response = $this->get('/app-settings/modulemanager');

        $response->assertRedirect();
    }

    public function test_guest_cannot_add_a_repo(): void
    {
        $response = $this->post('/app-settings/modulemanager/repos', [
            'owner' => 'nielspeen',
            'repo' => 'AiAssistant',
            'ref' => 'main',
            'label' => 'AI Assistant',
        ]);

        $response->assertRedirect();
    }
}
