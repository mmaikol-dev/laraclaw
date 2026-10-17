<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CredentialsTest extends TestCase
{
    use RefreshDatabase;

    private string $credentialsPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->credentialsPath = storage_path('framework/testing-credentials-'.uniqid().'.env');
        config()->set('agent.credentials_file', $this->credentialsPath);
    }

    protected function tearDown(): void
    {
        File::delete($this->credentialsPath);

        parent::tearDown();
    }

    public function test_credentials_page_is_displayed_with_existing_contents()
    {
        File::put($this->credentialsPath, "YOUTUBE_REFRESH_TOKEN=abc123\n");

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('credentials.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/credentials')
                ->where('path', $this->credentialsPath)
                ->where('exists', true)
                ->where('content', "YOUTUBE_REFRESH_TOKEN=abc123\n"),
            );
    }

    public function test_credentials_page_reports_missing_file()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('credentials.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('settings/credentials')
                ->where('exists', false)
                ->where('content', ''),
            );
    }

    public function test_credentials_can_be_saved()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('credentials.update'), [
                'content' => "INSTAGRAM_ACCESS_TOKEN=xyz789\n",
            ])
            ->assertRedirect();

        $this->assertSame(
            'INSTAGRAM_ACCESS_TOKEN=xyz789',
            File::get($this->credentialsPath),
        );
    }

    public function test_credentials_file_is_created_when_directory_missing()
    {
        $nested = dirname($this->credentialsPath).'/nested-'.uniqid();
        config()->set('agent.credentials_file', $nested.'/.env');

        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('credentials.update'), [
                'content' => "FACEBOOK_ACCESS_TOKEN=created\n",
            ])
            ->assertRedirect();

        $this->assertFileExists($nested.'/.env');
        $this->assertSame('FACEBOOK_ACCESS_TOKEN=created', File::get($nested.'/.env'));

        File::deleteDirectory($nested);
    }

    public function test_credentials_require_content()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put(route('credentials.update'), ['content' => ''])
            ->assertSessionHasErrors('content');

        $this->assertFileDoesNotExist($this->credentialsPath);
    }

    public function test_guests_cannot_access_credentials_page()
    {
        $this->get(route('credentials.edit'))->assertRedirect(route('login'));
    }
}
