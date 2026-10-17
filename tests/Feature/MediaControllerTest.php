<?php

namespace Tests\Feature;

use App\Models\MediaAsset;
use App\Models\User;
use App\Services\Tools\MediaLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MediaControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $mediaRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mediaRoot = sys_get_temp_dir().'/media-controller-'.uniqid();
        config()->set('agent.media_root', $this->mediaRoot);

        foreach (MediaLibrary::PLATFORM_FOLDERS as $folder) {
            mkdir($this->mediaRoot.'/'.$folder, 0775, true);
        }
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->mediaRoot);

        parent::tearDown();
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get(route('media.index'))->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_view_the_media_page(): void
    {
        $this->actingAs($this->verifiedUser());

        $response = $this->get(route('media.index'));

        $response->assertOk();
        $response->assertSee('&quot;component&quot;:&quot;media\/index&quot;', false);
        $response->assertSee('&quot;mediaRoot&quot;', false);
    }

    public function test_index_reconciles_untracked_files(): void
    {
        file_put_contents($this->mediaRoot.'/CLIPPED MEDIA/imported.mp4', 'x');

        $this->actingAs($this->verifiedUser());

        $this->get(route('media.index'))->assertOk();

        $this->assertDatabaseHas('media_assets', ['slug' => 'imported', 'status' => MediaAsset::STATUS_UNPOSTED]);
    }

    public function test_rescan_imports_files(): void
    {
        file_put_contents($this->mediaRoot.'/YOUTUBE/rescanned.mp4', 'x');

        $this->actingAs($this->verifiedUser());

        $this->post(route('media.rescan'))->assertRedirect();

        $this->assertDatabaseHas('media_assets', ['slug' => 'rescanned', 'platform' => 'youtube']);
    }

    public function test_update_status_marks_a_clip_posted(): void
    {
        $asset = MediaAsset::factory()->create(['slug' => 'to-post']);

        $this->actingAs($this->verifiedUser());

        $this->patch(route('media.status', $asset), [
            'status' => MediaAsset::STATUS_POSTED,
            'platform' => 'youtube',
        ])->assertRedirect();

        $asset->refresh();
        $this->assertSame(MediaAsset::STATUS_POSTED, $asset->status);
        $this->assertSame('youtube', $asset->platform);
        $this->assertNotNull($asset->posted_at);
    }

    public function test_update_status_marks_a_clip_unposted(): void
    {
        $asset = MediaAsset::factory()->posted('instagram')->create(['slug' => 'was-posted']);

        $this->actingAs($this->verifiedUser());

        $this->patch(route('media.status', $asset), [
            'status' => MediaAsset::STATUS_UNPOSTED,
        ])->assertRedirect();

        $asset->refresh();
        $this->assertSame(MediaAsset::STATUS_UNPOSTED, $asset->status);
        $this->assertNull($asset->posted_at);
    }

    public function test_update_status_rejects_an_unknown_platform(): void
    {
        $asset = MediaAsset::factory()->create(['slug' => 'to-post']);

        $this->actingAs($this->verifiedUser());

        $this->patch(route('media.status', $asset), [
            'status' => MediaAsset::STATUS_POSTED,
            'platform' => 'tiktok',
        ])->assertSessionHasErrors('platform');
    }

    private function verifiedUser(): User
    {
        return User::factory()->make([
            'id' => 1,
            'email_verified_at' => now(),
        ]);
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
            $path = $directory.'/'.$entry;
            is_dir($path) ? $this->deleteDirectory($path) : @unlink($path);
        }

        @rmdir($directory);
    }
}
