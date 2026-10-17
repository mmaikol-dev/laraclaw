<?php

namespace Database\Factories;

use App\Models\MediaAsset;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MediaAsset>
 */
class MediaAssetFactory extends Factory
{
    protected $model = MediaAsset::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = $this->faker->unique()->slug(3);
        $platform = $this->faker->randomElement(['clipped', 'instagram', 'youtube', 'facebook']);

        return [
            'slug' => $slug,
            'title' => Str::headline($slug),
            'filename' => $slug.'.mp4',
            'path' => 'CLIPPED MEDIA/'.$slug.'.mp4',
            'platform' => $platform,
            'status' => MediaAsset::STATUS_UNPOSTED,
            'posted_at' => null,
            'source' => null,
            'size_bytes' => $this->faker->numberBetween(1_000_000, 50_000_000),
            'external_id' => null,
            'external_url' => null,
        ];
    }

    public function posted(string $platform = 'youtube'): static
    {
        return $this->state(fn (): array => [
            'status' => MediaAsset::STATUS_POSTED,
            'platform' => $platform,
            'posted_at' => now(),
            'external_id' => $this->faker->uuid(),
            'external_url' => 'https://example.com/'.$this->faker->uuid(),
        ]);
    }
}
