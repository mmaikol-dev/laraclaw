<?php

namespace Tests\Unit;

use App\Models\MediaAsset;
use App\Services\Agent\ToolRegistry;
use App\Services\Tools\MediaClipTool;
use App\Services\Tools\MediaLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class MediaClipToolTest extends TestCase
{
    use RefreshDatabase;

    private string $mediaRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mediaRoot = sys_get_temp_dir().'/media-clip-'.uniqid();
        config()->set('agent.media_root', $this->mediaRoot);

        foreach (['CLIPPED MEDIA', 'INSTAGRAM', 'YOUTUBE', 'FACEBOOK'] as $folder) {
            mkdir($this->mediaRoot.'/'.$folder, 0775, true);
        }
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->mediaRoot);

        parent::tearDown();
    }

    public function test_the_media_clip_tool_is_registered(): void
    {
        $this->assertInstanceOf(MediaClipTool::class, app(ToolRegistry::class)->get('media_clip'));
    }

    public function test_the_tool_description_points_at_the_media_root(): void
    {
        $this->assertStringContainsString($this->mediaRoot, (new MediaClipTool)->getDescription());
    }

    public function test_it_lists_files_in_a_platform_folder(): void
    {
        file_put_contents($this->mediaRoot.'/CLIPPED MEDIA/clip_1.mp4', 'x');

        $result = (new MediaClipTool)->execute(['action' => 'list']);

        $this->assertStringContainsString('CLIPPED MEDIA', $result);
        $this->assertStringContainsString('clip_1.mp4', $result);
    }

    public function test_it_copies_a_clip_into_a_platform_folder(): void
    {
        file_put_contents($this->mediaRoot.'/CLIPPED MEDIA/clip_1.mp4', 'video-bytes');

        $result = (new MediaClipTool)->execute([
            'action' => 'copy',
            'filename' => 'clip_1.mp4',
            'to_platform' => 'youtube',
        ]);

        $this->assertStringContainsString('youtube', $result);
        $this->assertFileExists($this->mediaRoot.'/YOUTUBE/clip_1.mp4');
        $this->assertSame('video-bytes', file_get_contents($this->mediaRoot.'/YOUTUBE/clip_1.mp4'));
    }

    public function test_it_copies_from_a_non_default_source_platform(): void
    {
        file_put_contents($this->mediaRoot.'/YOUTUBE/clip_2.mp4', 'yt-bytes');

        (new MediaClipTool)->execute([
            'action' => 'copy',
            'filename' => 'clip_2.mp4',
            'from_platform' => 'youtube',
            'to_platform' => 'instagram',
        ]);

        $this->assertFileExists($this->mediaRoot.'/INSTAGRAM/clip_2.mp4');
    }

    public function test_copy_requires_an_existing_file(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('File not found');

        (new MediaClipTool)->execute([
            'action' => 'copy',
            'filename' => 'nope.mp4',
            'to_platform' => 'youtube',
        ]);
    }

    public function test_copy_requires_a_to_platform(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('copy requires a "to_platform"');

        (new MediaClipTool)->execute([
            'action' => 'copy',
            'filename' => 'clip_1.mp4',
        ]);
    }

    public function test_it_cuts_a_source_video_into_clips(): void
    {
        if (! $this->ffmpegAvailable()) {
            $this->markTestSkipped('ffmpeg is not available on this host.');
        }

        $source = $this->mediaRoot.'/source.mp4';
        $this->createTestVideo($source, 4);

        $result = (new MediaClipTool)->execute([
            'action' => 'clip',
            'source' => 'source.mp4',
            'clips' => [
                ['start' => '0', 'end' => '2', 'name' => 'part_one'],
                ['start' => '2', 'end' => '4', 'name' => 'part_two'],
            ],
        ]);

        $this->assertStringContainsString('part-one.mp4', $result);
        $this->assertStringContainsString('part-two.mp4', $result);
        $this->assertFileExists($this->mediaRoot.'/CLIPPED MEDIA/part-one.mp4');
        $this->assertFileExists($this->mediaRoot.'/CLIPPED MEDIA/part-two.mp4');
        $this->assertGreaterThan(0, filesize($this->mediaRoot.'/CLIPPED MEDIA/part-one.mp4'));
    }

    public function test_clip_records_each_clip_as_unposted_in_the_ledger(): void
    {
        $this->requireFfmpeg();

        $source = $this->mediaRoot.'/source.mp4';
        $this->createTestVideo($source, 2);

        (new MediaClipTool)->execute([
            'action' => 'clip',
            'source' => 'source.mp4',
            'clips' => [['start' => '0', 'end' => '1', 'name' => 'Funny Cat Fail']],
        ]);

        $this->assertDatabaseHas('media_assets', [
            'slug' => 'funny-cat-fail',
            'status' => MediaAsset::STATUS_UNPOSTED,
            'platform' => 'clipped',
            'title' => 'Funny Cat Fail',
        ]);
    }

    public function test_clip_rejects_a_name_that_is_already_used(): void
    {
        $this->requireFfmpeg();

        MediaAsset::factory()->create(['slug' => 'funny-cat-fail']);

        $source = $this->mediaRoot.'/source.mp4';
        $this->createTestVideo($source, 2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already used by another clip');

        (new MediaClipTool)->execute([
            'action' => 'clip',
            'source' => 'source.mp4',
            'clips' => [['start' => '0', 'end' => '1', 'name' => 'Funny Cat Fail']],
        ]);
    }

    public function test_clip_rejects_duplicate_names_in_one_batch(): void
    {
        $this->requireFfmpeg();

        $source = $this->mediaRoot.'/source.mp4';
        $this->createTestVideo($source, 3);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Duplicate clip name');

        (new MediaClipTool)->execute([
            'action' => 'clip',
            'source' => 'source.mp4',
            'clips' => [
                ['start' => '0', 'end' => '1', 'name' => 'Same Name'],
                ['start' => '1', 'end' => '2', 'name' => 'Same Name'],
            ],
        ]);
    }

    public function test_clip_can_target_a_platform_folder_directly(): void
    {
        if (! $this->ffmpegAvailable()) {
            $this->markTestSkipped('ffmpeg is not available on this host.');
        }

        $source = $this->mediaRoot.'/source.mp4';
        $this->createTestVideo($source, 2);

        (new MediaClipTool)->execute([
            'action' => 'clip',
            'source' => 'source.mp4',
            'platform' => 'instagram',
            'clips' => [['start' => '0', 'end' => '1', 'name' => 'ig_clip']],
        ]);

        $this->assertFileExists($this->mediaRoot.'/INSTAGRAM/ig-clip.mp4');
    }

    public function test_library_lists_unposted_and_posted_clips(): void
    {
        MediaAsset::factory()->create(['slug' => 'fresh-clip', 'status' => MediaAsset::STATUS_UNPOSTED]);
        MediaAsset::factory()->posted('youtube')->create(['slug' => 'old-clip']);

        $result = (new MediaClipTool)->execute(['action' => 'library']);

        $this->assertStringContainsString('UNPOSTED', $result);
        $this->assertStringContainsString('fresh-clip', $result);
        $this->assertStringContainsString('POSTED', $result);
        $this->assertStringContainsString('old-clip', $result);
    }

    public function test_library_can_filter_to_unposted(): void
    {
        MediaAsset::factory()->create(['slug' => 'fresh-clip', 'status' => MediaAsset::STATUS_UNPOSTED]);
        MediaAsset::factory()->posted('youtube')->create(['slug' => 'old-clip']);

        $result = (new MediaClipTool)->execute(['action' => 'library', 'status' => 'unposted']);

        $this->assertStringContainsString('fresh-clip', $result);
        $this->assertStringNotContainsString('old-clip', $result);
    }

    public function test_mark_posted_updates_the_asset(): void
    {
        $asset = MediaAsset::factory()->create(['slug' => 'fresh-clip', 'status' => MediaAsset::STATUS_UNPOSTED]);

        $result = (new MediaClipTool)->execute([
            'action' => 'mark_posted',
            'asset' => 'fresh-clip',
            'platform' => 'youtube',
            'external_id' => 'abc123',
            'external_url' => 'https://youtu.be/abc123',
        ]);

        $this->assertStringContainsString('posted on youtube', $result);

        $asset->refresh();
        $this->assertSame(MediaAsset::STATUS_POSTED, $asset->status);
        $this->assertSame('youtube', $asset->platform);
        $this->assertSame('abc123', $asset->external_id);
        $this->assertSame('https://youtu.be/abc123', $asset->external_url);
        $this->assertNotNull($asset->posted_at);
    }

    public function test_mark_unposted_reverts_the_asset(): void
    {
        $asset = MediaAsset::factory()->posted('youtube')->create(['slug' => 'old-clip']);

        (new MediaClipTool)->execute(['action' => 'mark_unposted', 'asset' => 'old-clip']);

        $asset->refresh();
        $this->assertSame(MediaAsset::STATUS_UNPOSTED, $asset->status);
        $this->assertNull($asset->posted_at);
        $this->assertNull($asset->external_id);
    }

    public function test_mark_posted_requires_a_known_asset(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No library clip matches');

        (new MediaClipTool)->execute(['action' => 'mark_posted', 'asset' => 'ghost-clip']);
    }

    public function test_copy_moves_the_tracked_asset_to_the_new_platform(): void
    {
        file_put_contents($this->mediaRoot.'/CLIPPED MEDIA/holiday.mp4', 'clip-bytes');

        $library = app(MediaLibrary::class);
        $library->reconcile();

        $asset = $library->findBySlug('holiday');
        $this->assertNotNull($asset);
        $this->assertSame('clipped', $asset->platform);

        (new MediaClipTool)->execute([
            'action' => 'copy',
            'filename' => 'holiday.mp4',
            'to_platform' => 'youtube',
        ]);

        $asset->refresh();
        $this->assertSame('youtube', $asset->platform);
        $this->assertSame('YOUTUBE/holiday.mp4', $asset->path);
    }

    public function test_clip_requires_clips(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('clip requires a "clips" array');

        (new MediaClipTool)->execute([
            'action' => 'clip',
            'source' => 'source.mp4',
        ]);
    }

    public function test_clip_requires_a_source(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('clip requires a "source"');

        (new MediaClipTool)->execute([
            'action' => 'clip',
            'clips' => [['start' => '0', 'end' => '1']],
        ]);
    }

    public function test_clip_fails_when_the_source_is_missing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Source video not found');

        (new MediaClipTool)->execute([
            'action' => 'clip',
            'source' => 'missing.mp4',
            'clips' => [['start' => '0', 'end' => '1']],
        ]);
    }

    public function test_it_rejects_an_unknown_platform(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown platform');

        (new MediaClipTool)->execute(['action' => 'list', 'platform' => 'tiktok']);
    }

    public function test_it_rejects_unknown_actions(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported media_clip action');

        (new MediaClipTool)->execute(['action' => 'explode']);
    }

    private function ffmpegAvailable(): bool
    {
        $binary = trim((string) shell_exec('command -v ffmpeg 2>/dev/null'));

        return $binary !== '';
    }

    private function requireFfmpeg(): void
    {
        if (! $this->ffmpegAvailable()) {
            $this->markTestSkipped('ffmpeg is not available on this host.');
        }
    }

    private function createTestVideo(string $path, int $seconds): void
    {
        shell_exec(sprintf(
            'ffmpeg -y -nostdin -f lavfi -i testsrc=duration=%d:size=128x96:rate=10 -c:v libx264 -preset veryfast -crf 30 %s 2>/dev/null',
            $seconds,
            escapeshellarg($path),
        ));
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
