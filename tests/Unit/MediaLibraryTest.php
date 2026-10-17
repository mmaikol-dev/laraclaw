<?php

namespace Tests\Unit;

use App\Models\MediaAsset;
use App\Services\Tools\MediaLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class MediaLibraryTest extends TestCase
{
    use RefreshDatabase;

    private string $mediaRoot;

    private MediaLibrary $library;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mediaRoot = sys_get_temp_dir().'/media-library-'.uniqid();
        config()->set('agent.media_root', $this->mediaRoot);

        foreach (MediaLibrary::PLATFORM_FOLDERS as $folder) {
            mkdir($this->mediaRoot.'/'.$folder, 0775, true);
        }

        $this->library = app(MediaLibrary::class);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->mediaRoot);

        parent::tearDown();
    }

    public function test_slugify_produces_filesystem_safe_handles(): void
    {
        $this->assertSame('funny-cat-fail', $this->library->slugify('Funny Cat Fail!'));
        $this->assertSame('clip', $this->library->slugify('   '));
    }

    public function test_assert_slug_available_throws_for_a_taken_name(): void
    {
        MediaAsset::factory()->create(['slug' => 'taken']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already used by another clip');

        $this->library->assertSlugAvailable('taken');
    }

    public function test_unique_slug_appends_a_suffix_when_taken(): void
    {
        MediaAsset::factory()->create(['slug' => 'sunset']);
        MediaAsset::factory()->create(['slug' => 'sunset-2']);

        $this->assertSame('sunset-3', $this->library->uniqueSlug('sunset'));
    }

    public function test_record_clip_creates_an_unposted_asset(): void
    {
        $path = $this->mediaRoot.'/CLIPPED MEDIA/my clip.mp4';
        file_put_contents($path, 'abc');

        $asset = $this->library->recordClip($path, '/src/source.mp4', 'My Clip');

        $this->assertSame('my-clip', $asset->slug);
        $this->assertSame(MediaAsset::STATUS_UNPOSTED, $asset->status);
        $this->assertSame('clipped', $asset->platform);
        $this->assertSame('CLIPPED MEDIA/my clip.mp4', $asset->path);
        $this->assertSame(3, $asset->size_bytes);
        $this->assertTrue($asset->isPosted() === false);
    }

    public function test_record_clip_uses_an_explicit_slug_when_given(): void
    {
        $path = $this->mediaRoot.'/YOUTUBE/whatever.mp4';
        file_put_contents($path, 'abc');

        $asset = $this->library->recordClip($path, null, 'Display Title', 'custom-slug');

        $this->assertSame('custom-slug', $asset->slug);
        $this->assertSame('youtube', $asset->platform);
    }

    public function test_reconcile_imports_untracked_files_once(): void
    {
        file_put_contents($this->mediaRoot.'/CLIPPED MEDIA/first.mp4', 'x');
        file_put_contents($this->mediaRoot.'/INSTAGRAM/second.mp4', 'y');

        $this->assertSame(2, $this->library->reconcile());
        $this->assertSame(0, $this->library->reconcile());

        $this->assertDatabaseHas('media_assets', ['slug' => 'first', 'platform' => 'clipped']);
        $this->assertDatabaseHas('media_assets', ['slug' => 'second', 'platform' => 'instagram']);
    }

    public function test_resolve_finds_an_asset_by_slug_filename_or_path(): void
    {
        $asset = MediaAsset::factory()->create([
            'slug' => 'holiday-cut',
            'filename' => 'holiday-cut.mp4',
            'path' => 'CLIPPED MEDIA/holiday-cut.mp4',
        ]);

        $this->assertTrue($asset->is($this->library->resolve('holiday-cut')));
        $this->assertTrue($asset->is($this->library->resolve('holiday-cut.mp4')));
        $this->assertTrue($asset->is($this->library->resolve('CLIPPED MEDIA/holiday-cut.mp4')));
        $this->assertNull($this->library->resolve('missing'));
    }

    public function test_asset_for_file_tracks_inside_the_root_and_ignores_outside(): void
    {
        $inside = $this->mediaRoot.'/CLIPPED MEDIA/tracked.mp4';
        file_put_contents($inside, 'x');

        $this->assertNotNull($this->library->assetForFile($inside));
        $this->assertNull($this->library->assetForFile('/etc/hosts'));
        $this->assertNull($this->library->assetForFile(sys_get_temp_dir().'/outside.mp4'));
    }

    public function test_mark_posted_and_unposted_round_trip(): void
    {
        $asset = MediaAsset::factory()->create(['slug' => 'roundtrip']);

        $this->library->markPosted($asset, 'instagram', 'media-1', 'https://instagram.com/p/1');

        $asset->refresh();
        $this->assertSame(MediaAsset::STATUS_POSTED, $asset->status);
        $this->assertSame('instagram', $asset->platform);
        $this->assertSame('media-1', $asset->external_id);
        $this->assertNotNull($asset->posted_at);

        $this->library->markUnposted($asset);

        $asset->refresh();
        $this->assertSame(MediaAsset::STATUS_UNPOSTED, $asset->status);
        $this->assertNull($asset->posted_at);
        $this->assertNull($asset->external_url);
    }

    public function test_summary_counts_posted_and_unposted(): void
    {
        MediaAsset::factory()->count(2)->create(['status' => MediaAsset::STATUS_UNPOSTED]);
        MediaAsset::factory()->count(3)->posted('youtube')->create();

        $this->assertSame(['posted' => 3, 'unposted' => 2], $this->library->summary());
    }

    public function test_platform_for_relative_path_maps_each_folder(): void
    {
        $this->assertSame('youtube', $this->library->platformForRelativePath('YOUTUBE/clip.mp4'));
        $this->assertSame('instagram', $this->library->platformForRelativePath('INSTAGRAM/clip.mp4'));
        $this->assertSame('clipped', $this->library->platformForRelativePath('CLIPPED MEDIA/clip.mp4'));
        $this->assertSame('clipped', $this->library->platformForRelativePath('unknown/clip.mp4'));
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
