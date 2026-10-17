<?php

namespace App\Http\Controllers;

use App\Models\MediaAsset;
use App\Services\Tools\MediaLibrary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MediaController extends Controller
{
    public function index(MediaLibrary $library): Response
    {
        $library->reconcile();

        return Inertia::render('media/index', [
            'assets' => MediaAsset::query()
                ->orderBy('status')
                ->orderBy('platform')
                ->orderBy('slug')
                ->get()
                ->map(fn (MediaAsset $asset): array => $this->assetPayload($asset))
                ->all(),
            'summary' => $library->summary(),
            'platforms' => array_keys(MediaLibrary::PLATFORM_FOLDERS),
            'mediaRoot' => $library->mediaRoot(),
        ]);
    }

    public function rescan(MediaLibrary $library): RedirectResponse
    {
        $library->reconcile();

        return back();
    }

    public function updateStatus(Request $request, MediaAsset $mediaAsset, MediaLibrary $library): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'in:posted,unposted'],
            'platform' => ['nullable', 'string', 'in:'.implode(',', array_keys(MediaLibrary::PLATFORM_FOLDERS))],
        ]);

        if ($validated['status'] === MediaAsset::STATUS_POSTED) {
            $library->markPosted($mediaAsset, $validated['platform'] ?? $mediaAsset->platform);
        } else {
            $library->markUnposted($mediaAsset);
        }

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function assetPayload(MediaAsset $asset): array
    {
        return [
            'id' => $asset->id,
            'slug' => $asset->slug,
            'title' => $asset->title,
            'filename' => $asset->filename,
            'path' => $asset->path,
            'platform' => $asset->platform,
            'status' => $asset->status,
            'posted_at' => $asset->posted_at?->toIso8601String(),
            'external_id' => $asset->external_id,
            'external_url' => $asset->external_url,
            'size_bytes' => $asset->size_bytes,
            'source' => $asset->source,
        ];
    }
}
