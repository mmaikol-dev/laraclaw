<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class ToolsPageTest extends TestCase
{
    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get(route('tools.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_view_the_tools_page(): void
    {
        $user = User::factory()->make([
            'id' => 1,
            'email_verified_at' => now(),
        ]);
        $this->actingAs($user);

        $response = $this->get(route('tools.index'));

        $response->assertOk();
        $response->assertSee('&quot;component&quot;:&quot;tools\/index&quot;', false);
        $response->assertSee('&quot;name&quot;:&quot;shell&quot;', false);
        $response->assertSee('&quot;name&quot;:&quot;file&quot;', false);
        $response->assertSee('&quot;parameter_count&quot;:', false);
    }
}
