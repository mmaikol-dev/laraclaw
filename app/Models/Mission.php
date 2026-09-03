<?php

namespace App\Models;

use Database\Factories\MissionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Mission extends Model
{
    /** @use HasFactory<MissionFactory> */
    use HasFactory;

    public const STATUSES = ['scoping', 'active', 'paused', 'completed', 'failed'];

    public const FEATURE_STATUSES = ['pending', 'in_progress', 'implemented', 'validated', 'blocked'];

    protected $fillable = [
        'name',
        'goal',
        'status',
        'orchestrator_model',
        'worker_model',
        'validator_model',
        'validation_contract',
        'conversation_id',
        'current_feature_id',
        'milestone_count',
        'context_notes',
        'spent_tokens',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'validation_contract' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'spent_tokens' => 'integer',
    ];

    public function features(): HasMany
    {
        return $this->hasMany(MissionFeature::class)->orderBy('sort_order');
    }

    public function handoffs(): HasMany
    {
        return $this->hasMany(MissionHandoff::class)->latest();
    }

    public function currentFeature(): ?MissionFeature
    {
        return $this->current_feature_id === null
            ? null
            : MissionFeature::find($this->current_feature_id);
    }

    /**
     * The next feature to implement, in serial order.
     */
    public function nextPendingFeature(): ?MissionFeature
    {
        return $this->features()->where('status', 'pending')->orderBy('sort_order')->first();
    }

    public function progressSummary(): string
    {
        $total = $this->features()->count();

        if ($total === 0) {
            return '0/0 features (plan not scoped yet)';
        }

        $done = $this->features()->whereIn('status', ['validated', 'implemented'])->count();

        return "{$done}/{$total} features";
    }

    public function contractAssertions(): array
    {
        return $this->validation_contract ?? [];
    }

    public function refreshMilestoneCount(): void
    {
        $this->update([
            'milestone_count' => $this->features()->whereNotNull('milestone')->distinct()->count('milestone'),
        ]);
    }

    public function markCompleted(): void
    {
        $this->update(['status' => 'completed', 'completed_at' => now()]);
    }

    /**
     * Assertions assigned to a specific feature (feature index in the plan),
     * falling back to all assertions when none are assigned per-feature.
     *
     * @return array<int, string>
     */
    public function assertionsForFeature(MissionFeature $feature): array
    {
        $contract = $this->contractAssertions();

        if ($contract === []) {
            return [];
        }

        $perFeature = array_values(array_filter($contract, fn ($assertion): bool => is_array($assertion)));

        foreach ($perFeature as $entry) {
            $titles = $entry['features'] ?? [];

            if (is_array($titles) && in_array($feature->title, $titles, true)) {
                return collect($entry['assertions'] ?? [])->filter(fn ($a): bool => is_string($a))->values()->all();
            }
        }

        return collect($contract)
            ->filter(fn ($assertion): bool => is_string($assertion))
            ->values()
            ->all();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
