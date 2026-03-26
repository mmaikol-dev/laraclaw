<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateAgentSettingsRequest;
use App\Models\AgentSetting;
use App\Models\Conversation;
use App\Models\TaskLog;
use Illuminate\Http\JsonResponse;

class SettingsController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => AgentSetting::allAsArray(),
        ]);
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
