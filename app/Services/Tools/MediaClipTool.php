<?php

namespace App\Services\Tools;

use App\Models\AgentSetting;
use App\Models\MediaAsset;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Cut a long video into short clips with ffmpeg and track the results in the
 * media library (CLIPPED MEDIA -> INSTAGRAM / YOUTUBE / FACEBOOK) so the agent
 * can name clips uniquely, see what has been posted, and never publish the same
 * video twice.
 */
class MediaClipTool extends BaseTool
{
    public function __construct(private ?MediaLibrary $library = null) {}

    public function getName(): string
    {
        return 'media_clip';
    }

    public function getDescription(): string
    {
        return 'Cut a long video into short clips with ffmpeg, and track the social-media media library. '
            .'The media root is '.config('agent.media_root').'. Clipped clips land in "CLIPPED MEDIA" by default; '
            .'when posting to a platform, copy the finished clip from "CLIPPED MEDIA" into the platform folder '
            .'(INSTAGRAM, YOUTUBE, FACEBOOK) so the social tools can upload it from there. '
            .'Give every clip a distinct, descriptive name: names are slugified and must be unique, so re-using one is rejected and the same video cannot be posted twice. '
            .'Actions: clip, list, copy, library, mark_posted, mark_unposted. '
            .'clip cuts one source video into one or more clips (pass start/end times like 00:00:10 or 10 plus a descriptive name) and records each clip as unposted; '
            .'list shows the raw files in a platform folder; '
            .'library lists posted vs unposted clips so you can pick something new; '
            .'copy stages a file into another platform folder; '
            .'mark_posted marks a clip as published (the social tools do this automatically).';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['clip', 'list', 'copy', 'library', 'mark_posted', 'mark_unposted'],
                    'description' => 'The media operation to perform.',
                ],
                'platform' => [
                    'type' => 'string',
                    'enum' => array_keys(MediaLibrary::PLATFORM_FOLDERS),
                    'description' => 'Platform folder under the media root. Defaults to "clipped" (CLIPPED MEDIA), where raw cuts belong.',
                ],
                'source' => [
                    'type' => 'string',
                    'description' => 'clip: path to the source video. Relative paths are resolved against the media root.',
                ],
                'clips' => [
                    'type' => 'array',
                    'description' => 'clip: array of clip specs, each {start, end, name}. start/end are timestamps (00:00:10 or 10). name is the clip\'s descriptive name; it is slugified into the filename and must be unique across the library.',
                ],
                'filename' => [
                    'type' => 'string',
                    'description' => 'copy: the file (inside the source platform folder) to copy, e.g. "funny-cat.mp4".',
                ],
                'from_platform' => [
                    'type' => 'string',
                    'enum' => array_keys(MediaLibrary::PLATFORM_FOLDERS),
                    'description' => 'copy: platform folder to copy from (default "clipped").',
                ],
                'to_platform' => [
                    'type' => 'string',
                    'enum' => array_keys(MediaLibrary::PLATFORM_FOLDERS),
                    'description' => 'copy: platform folder to copy to (required for copy).',
                ],
                'asset' => [
                    'type' => 'string',
                    'description' => 'mark_posted/mark_unposted: the clip to update. Accepts its slug, filename, or path (e.g. "funny-cat", "funny-cat.mp4").',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => ['all', 'unposted', 'posted'],
                    'description' => 'library: filter by posting status (default all).',
                ],
                'external_id' => [
                    'type' => 'string',
                    'description' => 'mark_posted: the published post/video ID on the platform.',
                ],
                'external_url' => [
                    'type' => 'string',
                    'description' => 'mark_posted: the public URL of the published post/video.',
                ],
            ],
            'required' => ['action'],
        ];
    }

    public function isEnabled(): bool
    {
        return (bool) AgentSetting::get('enable_media_clip', true);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(array $arguments): string
    {
        return match ($arguments['action'] ?? null) {
            'clip' => $this->clip($arguments),
            'list' => $this->list($this->platform($arguments, 'platform')),
            'copy' => $this->copy($arguments),
            'library' => $this->listLibrary($arguments),
            'mark_posted' => $this->markPosted($arguments),
            'mark_unposted' => $this->markUnposted($arguments),
            default => throw new RuntimeException('Unsupported media_clip action. Use one of: clip, list, copy, library, mark_posted, mark_unposted.'),
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function clip(array $arguments): string
    {
        $source = $this->resolveSource($this->string($arguments, 'source'));
        $platform = $this->platform($arguments, 'platform');
        $destination = $this->platformDirectory($platform);

        $specs = $this->clipSpecs($arguments);

        if ($specs === []) {
            throw new RuntimeException('clip requires a "clips" array with {start, end} entries.');
        }

        $this->assertFfmpegAvailable();

        if (! is_file($source)) {
            throw new RuntimeException("Source video not found: {$source}");
        }

        $library = $this->library();

        // Validate every name up front so a duplicate fails before we cut.
        $planned = [];
        $seen = [];

        foreach ($specs as $index => $spec) {
            $title = $spec['name'] !== '' ? $spec['name'] : 'clip '.($index + 1);
            $slug = $library->slugify($title);
            $output = rtrim($destination, '/').'/'.$slug.'.mp4';

            if (isset($seen[$slug])) {
                throw new RuntimeException("Duplicate clip name \"{$slug}\" in this batch. Give each clip a distinct, descriptive name.");
            }

            $library->assertSlugAvailable($slug);

            if (is_file($output)) {
                throw new RuntimeException('A file named '.basename($output)." already exists in the {$platform} folder. Choose a different name.");
            }

            $seen[$slug] = true;
            $planned[] = [
                'slug' => $slug,
                'title' => $title,
                'start' => $spec['start'],
                'end' => $spec['end'],
                'output' => $output,
            ];
        }

        $lines = ['Clipping '.basename($source).' into '.count($planned).' clip(s) -> '.$destination];
        $created = [];

        foreach ($planned as $clip) {
            $this->runFfmpeg($source, $clip['start'], $clip['end'], $clip['output']);

            $library->recordClip($clip['output'], $source, $clip['title'], $clip['slug']);

            $created[] = $clip['output'];
            $lines[] = sprintf(
                '  - %s [%s -> %s]',
                basename($clip['output']),
                $clip['start'],
                $clip['end'],
            );
        }

        $lines[] = '';
        $lines[] = 'Created '.count($created).' clip(s), each recorded as unposted.';
        $lines[] = 'Use media_clip action=copy with to_platform=instagram|youtube|facebook to stage a clip for posting.';

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<int, array{start: string, end: string, name: string}>
     */
    private function clipSpecs(array $arguments): array
    {
        $raw = $arguments['clips'] ?? null;

        if (! is_array($raw) || $raw === []) {
            return [];
        }

        $specs = [];

        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $start = trim((string) ($entry['start'] ?? ''));
            $end = trim((string) ($entry['end'] ?? ''));

            if ($start === '' || $end === '') {
                continue;
            }

            $specs[] = [
                'start' => $start,
                'end' => $end,
                'name' => trim((string) ($entry['name'] ?? '')),
            ];
        }

        return $specs;
    }

    private function list(string $platform): string
    {
        $directory = $this->platformDirectory($platform);

        if (! is_dir($directory)) {
            return "No such platform folder: {$directory}";
        }

        $entries = array_values(array_filter(scandir($directory) ?: [], fn (string $entry): bool => $entry !== '.' && $entry !== '..'));

        if ($entries === []) {
            return "No files in {$directory}.";
        }

        $lines = ["Files in {$directory} (".count($entries).')'];

        foreach ($entries as $entry) {
            $path = $directory.'/'.$entry;
            $size = is_file($path) ? $this->formatBytes((int) filesize($path)) : 'dir';
            $lines[] = "  - {$entry} ({$size})";
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function listLibrary(array $arguments): string
    {
        $library = $this->library();
        $library->reconcile();

        $status = strtolower($this->string($arguments, 'status'));

        $query = MediaAsset::query()->orderBy('platform')->orderBy('slug');

        if (in_array($status, [MediaAsset::STATUS_POSTED, MediaAsset::STATUS_UNPOSTED], true)) {
            $query->where('status', $status);
        } elseif ($status !== '' && $status !== 'all') {
            throw new RuntimeException('library "status" must be unposted, posted, or all.');
        }

        $assets = $query->get();
        $summary = $library->summary();

        $lines = ["Media library: {$summary['unposted']} unposted, {$summary['posted']} posted."];

        $render = function (string $heading, $group) use (&$lines): void {
            if ($group->isEmpty()) {
                return;
            }

            $lines[] = '';
            $lines[] = $heading.':';

            foreach ($group as $asset) {
                $lines[] = '  - '.$this->describeAsset($asset);
            }
        };

        $render('UNPOSTED (safe to post)', $assets->where('status', MediaAsset::STATUS_UNPOSTED));
        $render('POSTED (already published)', $assets->where('status', MediaAsset::STATUS_POSTED));

        if ($assets->isEmpty()) {
            $lines[] = 'No clips match.';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function markPosted(array $arguments): string
    {
        $asset = $this->resolveAsset($arguments);
        $platform = $this->platform($arguments, 'platform');

        $this->library()->markPosted(
            $asset,
            $platform,
            $this->string($arguments, 'external_id') ?: null,
            $this->string($arguments, 'external_url') ?: null,
        );

        return "Marked \"{$asset->slug}\" as posted on {$platform}.";
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function markUnposted(array $arguments): string
    {
        $asset = $this->resolveAsset($arguments);

        $this->library()->markUnposted($asset);

        return "Marked \"{$asset->slug}\" as unposted.";
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function resolveAsset(array $arguments): MediaAsset
    {
        $handle = $this->string($arguments, 'asset');

        if ($handle === '') {
            throw new RuntimeException('This action requires an "asset" (a clip slug, filename, or path).');
        }

        $asset = $this->library()->resolve($handle);

        if ($asset === null) {
            throw new RuntimeException("No library clip matches \"{$handle}\". Run media_clip action=library to list clips.");
        }

        return $asset;
    }

    private function describeAsset(MediaAsset $asset): string
    {
        $line = $asset->slug;

        if ($asset->isPosted()) {
            $line .= ' ['.$asset->platform.']';

            if ($asset->external_url) {
                $line .= ' '.$asset->external_url;
            }

            if ($asset->posted_at) {
                $line .= ' posted '.$asset->posted_at->toDateTimeString();
            }
        } else {
            $line .= ' ['.$asset->platform.' -> '.$asset->path.']';
        }

        return $line;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function copy(array $arguments): string
    {
        $filename = trim($this->string($arguments, 'filename'));

        if ($filename === '') {
            throw new RuntimeException('copy requires a "filename" (the file inside the source folder).');
        }

        $from = $this->platform($arguments, 'from_platform');
        $to = $this->platform($arguments, 'to_platform');

        $source = rtrim($this->platformDirectory($from), '/').'/'.$filename;

        if (! is_file($source)) {
            throw new RuntimeException("File not found: {$source}");
        }

        $destinationDir = rtrim($this->platformDirectory($to), '/');
        $destination = $destinationDir.'/'.$filename;

        if (! is_dir($destinationDir) && ! mkdir($destinationDir, 0775, true) && ! is_dir($destinationDir)) {
            throw new RuntimeException("Unable to create folder: {$destinationDir}");
        }

        if (! copy($source, $destination)) {
            throw new RuntimeException("Failed to copy {$source} to {$destination}.");
        }

        $library = $this->library();
        $asset = $library->findByRelativePath($library->relativePath($source));

        if ($asset !== null) {
            $asset->forceFill([
                'platform' => $library->normalizePlatform($to),
                'path' => $library->relativePath($destination),
            ])->save();
        }

        return "Copied {$filename} from {$from} to {$to}.\n{$destination}";
    }

    private function runFfmpeg(string $source, string $start, string $end, string $output): void
    {
        $process = new Process([
            'ffmpeg',
            '-y',
            '-nostdin',
            '-ss', $start,
            '-to', $end,
            '-i', $source,
            '-c', 'copy',
            $output,
        ]);

        $process->setTimeout((int) config('agent.media_clip_timeout', 900));
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('ffmpeg failed for '.$start.'-'.$end.': '.trim($process->getErrorOutput()));
        }

        if (! is_file($output) || filesize($output) === 0) {
            throw new RuntimeException('ffmpeg produced no output for '.$start.'-'.$end.'.');
        }
    }

    private function assertFfmpegAvailable(): void
    {
        $process = new Process(['ffmpeg', '-version']);
        $process->setTimeout(15);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('ffmpeg is not available on this host. Install it (e.g. apt install ffmpeg) to use media_clip.');
        }
    }

    private function resolveSource(string $source): string
    {
        if ($source === '') {
            throw new RuntimeException('clip requires a "source" path to the video to cut.');
        }

        if ($this->isAbsolutePath($source)) {
            return $source;
        }

        return rtrim($this->mediaRoot(), '/').'/'.$source;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function platform(array $arguments, string $key): string
    {
        $platform = strtolower(trim((string) ($arguments[$key] ?? '')));

        if ($platform === '') {
            if ($key === 'to_platform') {
                throw new RuntimeException('copy requires a "to_platform" (instagram, youtube, or facebook).');
            }

            return 'clipped';
        }

        if (! array_key_exists($platform, MediaLibrary::PLATFORM_FOLDERS)) {
            throw new RuntimeException("Unknown platform \"{$platform}\". Use one of: ".implode(', ', array_keys(MediaLibrary::PLATFORM_FOLDERS)).'.');
        }

        return $platform;
    }

    private function platformDirectory(string $platform): string
    {
        return $this->library()->platformDirectory($platform);
    }

    private function mediaRoot(): string
    {
        return $this->library()->mediaRoot();
    }

    private function library(): MediaLibrary
    {
        return $this->library ??= app(MediaLibrary::class);
    }

    private function isAbsolutePath(string $path): bool
    {
        return $path !== '' && ($path[0] === '/' || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function string(array $arguments, string $key): string
    {
        return trim((string) ($arguments[$key] ?? ''));
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $index = 0;

        while ($bytes >= 1024 && $index < count($units) - 1) {
            $bytes /= 1024;
            $index++;
        }

        return round($bytes, 1).' '.$units[$index];
    }
}
