<?php

namespace App\Services\Tools;

use App\Models\MediaAsset;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Central ledger for the social-media media library. Tracks every clip under
 * the media root so the agent can see what has been posted and what has not,
 * and so clips can be named uniquely to avoid publishing the same video twice.
 *
 * The filesystem stays the source of truth: reconcile() imports any files that
 * were added outside the app, and status is the only thing the ledger owns.
 */
class MediaLibrary
{
    /**
     * Folder name (under the media root) for each platform key. "clipped" is the
     * default destination for raw cuts; the platform folders are where clips are
     * staged for posting.
     *
     * @var array<string, string>
     */
    public const PLATFORM_FOLDERS = [
        'clipped' => 'CLIPPED MEDIA',
        'instagram' => 'INSTAGRAM',
        'youtube' => 'YOUTUBE',
        'facebook' => 'FACEBOOK',
    ];

    public function mediaRoot(): string
    {
        return rtrim((string) config('agent.media_root', '/home/atlas/laraclaw-media'), '/');
    }

    public function platformDirectory(string $platform): string
    {
        $folder = self::PLATFORM_FOLDERS[$platform] ?? self::PLATFORM_FOLDERS['clipped'];

        return $this->mediaRoot().'/'.$folder;
    }

    public function normalizePlatform(string $platform): string
    {
        $platform = strtolower(trim($platform));

        return array_key_exists($platform, self::PLATFORM_FOLDERS) ? $platform : 'clipped';
    }

    /**
     * Slugify a display name into a stable, filesystem-safe handle.
     */
    public function slugify(string $name): string
    {
        $slug = Str::slug($name);

        return $slug !== '' ? $slug : 'clip';
    }

    /**
     * Throw when a slug is already taken, so re-using a name is rejected rather
     * than silently overwriting or duplicating an existing clip.
     */
    public function assertSlugAvailable(string $slug): void
    {
        if (MediaAsset::query()->where('slug', $slug)->exists()) {
            throw new RuntimeException(
                "The name \"{$slug}\" is already used by another clip. Pick a distinct, descriptive name so the same video is not posted twice. "
                .'Run media_clip action=library to see existing clips.'
            );
        }
    }

    /**
     * Resolve a free slug, appending -2, -3, ... when the base is taken. Used
     * when importing files that were added outside the app.
     */
    public function uniqueSlug(string $base): string
    {
        $base = $this->slugify($base);
        $slug = $base;
        $suffix = 2;

        while (MediaAsset::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    /**
     * Record a freshly created clip in the ledger. Pass an explicit slug when
     * the caller has already validated uniqueness; otherwise one is derived.
     */
    public function recordClip(string $absolutePath, ?string $source = null, ?string $title = null, ?string $slug = null): MediaAsset
    {
        $relative = $this->relativePath($absolutePath);
        $platform = $this->platformForRelativePath($relative);
        $slug ??= $this->uniqueSlug(pathinfo($absolutePath, PATHINFO_FILENAME));

        return MediaAsset::query()->create([
            'slug' => $slug,
            'title' => $title,
            'filename' => basename($absolutePath),
            'path' => $relative,
            'platform' => $platform,
            'status' => MediaAsset::STATUS_UNPOSTED,
            'source' => $source,
            'size_bytes' => is_file($absolutePath) ? (int) filesize($absolutePath) : null,
        ]);
    }

    /**
     * Import any files under the media root that are not yet tracked. Returns
     * the number of assets added. Safe to call repeatedly.
     */
    public function reconcile(): int
    {
        $added = 0;

        foreach (array_keys(self::PLATFORM_FOLDERS) as $platform) {
            $directory = $this->platformDirectory($platform);

            if (! is_dir($directory)) {
                continue;
            }

            $entries = array_filter(scandir($directory) ?: [], fn (string $entry): bool => $entry !== '.' && $entry !== '..');

            foreach ($entries as $entry) {
                $absolute = $directory.'/'.$entry;

                if (! is_file($absolute)) {
                    continue;
                }

                $relative = $this->relativePath($absolute);

                if (MediaAsset::query()->where('path', $relative)->exists()) {
                    continue;
                }

                $this->recordClip($absolute);
                $added++;
            }
        }

        return $added;
    }

    public function findBySlug(string $slug): ?MediaAsset
    {
        $slug = $this->slugify($slug);

        return MediaAsset::query()->where('slug', $slug)->first();
    }

    public function findByRelativePath(string $relative): ?MediaAsset
    {
        return MediaAsset::query()->where('path', ltrim($relative, '/'))->first();
    }

    public function findByFilename(string $filename): ?MediaAsset
    {
        return MediaAsset::query()->where('filename', $filename)->orderBy('id')->first();
    }

    /**
     * Find (or lazily track) the asset for a file on disk. Returns null when the
     * file lives outside the media root.
     */
    public function assetForFile(string $absolutePath): ?MediaAsset
    {
        if (! $this->isInsideMediaRoot($absolutePath)) {
            return null;
        }

        $relative = $this->relativePath($absolutePath);
        $existing = $this->findByRelativePath($relative);

        if ($existing !== null) {
            return $existing;
        }

        if (! is_file($absolutePath)) {
            return null;
        }

        return $this->recordClip($absolutePath);
    }

    public function isInsideMediaRoot(string $absolutePath): bool
    {
        return str_starts_with($absolutePath, $this->mediaRoot().'/');
    }

    /**
     * Resolve an asset from any of the handles the agent might pass: a slug, a
     * filename, or a path (absolute or relative to the media root).
     */
    public function resolve(string $handle): ?MediaAsset
    {
        $handle = trim($handle);

        if ($handle === '') {
            return null;
        }

        return $this->findBySlug(pathinfo($handle, PATHINFO_FILENAME))
            ?? $this->findByRelativePath($handle)
            ?? $this->findByFilename(basename($handle))
            ?? $this->findByRelativePath(basename($handle));
    }

    public function markPosted(MediaAsset $asset, string $platform, ?string $externalId = null, ?string $externalUrl = null): MediaAsset
    {
        $asset->forceFill([
            'status' => MediaAsset::STATUS_POSTED,
            'platform' => $this->normalizePlatform($platform),
            'external_id' => $externalId,
            'external_url' => $externalUrl,
            'posted_at' => now(),
        ])->save();

        return $asset->refresh();
    }

    public function markUnposted(MediaAsset $asset): MediaAsset
    {
        $asset->forceFill([
            'status' => MediaAsset::STATUS_UNPOSTED,
            'external_id' => null,
            'external_url' => null,
            'posted_at' => null,
        ])->save();

        return $asset->refresh();
    }

    /**
     * @return array{posted: int, unposted: int}
     */
    public function summary(): array
    {
        return [
            'posted' => MediaAsset::query()->posted()->count(),
            'unposted' => MediaAsset::query()->unposted()->count(),
        ];
    }

    /**
     * Relative path, using the same slash style as stored rows.
     */
    public function relativePath(string $absolutePath): string
    {
        $root = $this->mediaRoot().'/';

        if (str_starts_with($absolutePath, $root)) {
            return substr($absolutePath, strlen($root));
        }

        return ltrim($absolutePath, '/');
    }

    public function platformForRelativePath(string $relative): string
    {
        $folder = explode('/', trim($relative, '/'))[0] ?? '';

        foreach (self::PLATFORM_FOLDERS as $platform => $name) {
            if ($name === $folder) {
                return $platform;
            }
        }

        return 'clipped';
    }
}
