<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAgentSettingsRequest;
use App\Models\AgentSetting;
use App\Models\Conversation;
use App\Models\TaskLog;
use Database\Seeders\AgentSettingsSeeder;
use Illuminate\Http\JsonResponse;

class SettingsController extends Controller
{
    public function index(): JsonResponse
    {
        $defaults = collect(AgentSettingsSeeder::defaults())
            ->mapWithKeys(fn (array $setting): array => [
                $setting['key'] => AgentSetting::castStoredValue($setting['type'], $setting['value']),
            ])
            ->all();

        return response()->json([
            'data' => [
                ...$defaults,
                ...$this->storedSettings(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function storedSettings(): array
    {
        return AgentSetting::allAsArray();
    }

    public function update(UpdateAgentSettingsRequest $request): JsonResponse
    {
        foreach ($request->validated('settings', []) as $key => $value) {
            AgentSetting::set($key, $value);
        }

        return response()->json([
            'status' => 'saved',
        ]);
    }

    public function clearTasks(): JsonResponse
    {
        TaskLog::query()->delete();

        return response()->json([
            'status' => 'cleared',
        ]);
    }

    public function archiveConversations(): JsonResponse
    {
        Conversation::query()->update([
            'is_archived' => true,
        ]);

        return response()->json([
            'status' => 'archived',
        ]);
    }
}
