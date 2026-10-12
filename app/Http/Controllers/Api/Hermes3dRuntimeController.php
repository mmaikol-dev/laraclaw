<?php

namespace App\Http\Controllers\Api;

use App\Enums\TaskStatus;
use App\Enums\TaskStepStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hermes3dAgentMessageRequest;
use App\Http\Requests\Hermes3dChatRequest;
use App\Models\AgentMessage;
use App\Models\AgentRoleProfile;
use App\Models\Conversation;
use App\Models\Mission;
use App\Models\MissionFeature;
use App\Models\ScheduledTask;
use App\Models\Task;
use App\Services\Agent\AgentOrchestrator;
use App\Services\Agent\AgentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Implements the Hermes3D custom runtime provider contract so the 3D office
 * can drive LaraClaw. Point a Hermes3D `custom` profile at
 * `{APP_URL}/api/hermes3d`.
 */
class Hermes3dRuntimeController extends Controller
{
    public function __construct(
        protected AgentService $agent,
        protected AgentOrchestrator $orchestrator,
    ) {}

    public function health(): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'status' => 'ok',
            'runtime' => 'laraclaw',
        ]);
    }

    public function state(): JsonResponse
    {
        $model = $this->activeModel();

        return response()->json([
            'profileName' => 'laraclaw',
            'runtime' => [
                'name' => 'LaraClaw',
                'vendor' => 'laraclaw',
                'status' => 'ok',
                'active_model' => $model,
                'governance' => 'local',
            ],
            'identity' => [
                'name' => 'LaraClaw',
                'role' => 'assistant',
                'lane' => 'main',
                'model_id' => $model,
            ],
            'active' => $this->activeAgents($model),
        ]);
    }

    public function registry(): JsonResponse
    {
        $model = $this->activeModel();

        return response()->json([
            'models' => [
                $model => ['id' => $model, 'provider' => 'ollama'],
            ],
        ]);
    }

    /**
     * Live office presence for Hermes3D's remote office viewer. Poll this to
     * watch LaraClaw's employees move as tasks, plans and cron runs progress.
     */
    public function presence(): JsonResponse
    {
        return response()->json([
            'workspaceId' => 'laraclaw',
            'timestamp' => now()->toISOString(),
            'agents' => $this->officeAgents(),
        ]);
    }

    /**
     * Office floor plan for Hermes3D's remote office viewer. Hermes3D derives
     * this URL from the presence URL (it swaps the trailing `presence` segment
     * for `layout`), so desks here carry the same ids as presence
     * `preferredDeskId` values and employees sit where they belong.
     */
    public function layout(): JsonResponse
    {
        $profiles = AgentRoleProfile::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['slug']);

        return response()->json([
            'gatewayUrl' => rtrim((string) config('app.url'), '/'),
            'timestamp' => now()->toISOString(),
            'width' => 1800,
            'height' => 720,
            'furniture' => $this->officeFurniture($profiles),
        ]);
    }

    /**
     * Chat surface for agent-to-agent traffic: the recent messages a role
     * agent has received (and sent) as part of a mission huddle or assignment.
     */
    public function agentMessages(string $agent): JsonResponse
    {
        $profile = AgentRoleProfile::query()->where('slug', $agent)->first();

        if ($profile === null) {
            return response()->json(['error' => ['message' => "Unknown agent '{$agent}'."]], 404);
        }

        $messages = AgentMessage::query()
            ->where(fn (Builder $query): Builder => $query
                ->where('to_agent', $profile->slug)
                ->orWhere('from_agent', $profile->slug))
            ->latest()
            ->limit(50)
            ->get(['id', 'thread_key', 'mission_id', 'from_agent', 'to_agent', 'kind', 'body', 'created_at']);

        return response()->json([
            'agentId' => $profile->slug,
            'name' => $profile->name,
            'messages' => $messages->map(fn (AgentMessage $message): array => [
                'id' => $message->id,
                'threadKey' => $message->thread_key,
                'missionId' => $message->mission_id,
                'from' => $message->from_agent,
                'to' => $message->to_agent,
                'kind' => $message->kind,
                'body' => $message->body,
                'createdAt' => $message->created_at?->toISOString(),
            ])->all(),
        ]);
    }

    /**
     * Post a directed agent-to-agent message (orchestrator → role agent, or
     * role → role) so a discussion can be driven from outside the app.
     */
    public function sendAgentMessage(string $agent, Hermes3dAgentMessageRequest $request): JsonResponse
    {
        $profile = AgentRoleProfile::query()->where('slug', $agent)->first();

        if ($profile === null) {
            return response()->json(['error' => ['message' => "Unknown agent '{$agent}'."]], 404);
        }

        $message = $this->orchestrator->post(
            from: (string) $request->validated('from', AgentMessage::FROM_ORCHESTRATOR),
            to: $profile->slug,
            kind: (string) $request->validated('kind', 'discussion'),
            body: (string) $request->validated('body'),
        );

        return response()->json([
            'id' => $message->id,
            'agentId' => $profile->slug,
            'kind' => $message->kind,
            'body' => $message->body,
            'createdAt' => $message->created_at?->toISOString(),
        ], 201);
    }

    public function chatCompletions(Hermes3dChatRequest $request): JsonResponse
    {
        set_time_limit(0);

        $sessionKey = (string) ($request->validated('conversation_id')
            ?? $request->validated('session_id')
            ?? 'agent:main:main');

        $userMessage = $this->lastUserMessage($request->validated('messages'));

        if ($userMessage === '') {
            return response()->json(['error' => ['message' => 'No user message provided.']], 422);
        }

        $conversation = $this->resolveConversation($sessionKey, $request->validated('role'));
        $message = $this->agent->run($conversation, $userMessage, "conversation.{$conversation->id}");

        return response()->json([
            'id' => 'chatcmpl-'.$message->id,
            'object' => 'chat.completion',
            'created' => $message->created_at?->timestamp ?? time(),
            'model' => $conversation->model ?: $this->activeModel(),
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => (string) $message->content,
                ],
                'finish_reason' => 'stop',
            ]],
        ]);
    }

    private function activeModel(): string
    {
        return (string) config('ollama.agent_model');
    }

    /**
     * Office-facing identities: one per active role profile.
     *
     * @return array<string, string>
     */
    private function activeAgents(string $model): array
    {
        return AgentRoleProfile::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('slug')
            ->mapWithKeys(fn (string $slug): array => [$slug => $model])
            ->all();
    }

    /**
     * One office agent per active role profile, plus a scheduler agent that
     * represents cron activity. `preferredDeskId` matches the desk ids emitted
     * by {@see self::layout()}.
     *
     * @return array<int, array{agentId: string, name: string, state: string, preferredDeskId: string}>
     */
    private function officeAgents(): array
    {
        $states = $this->roleOfficeStates();

        $agents = AgentRoleProfile::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'slug', 'name'])
            ->map(fn (AgentRoleProfile $profile): array => [
                'agentId' => $profile->slug,
                'name' => $profile->name,
                'state' => $states[$profile->id] ?? 'idle',
                'preferredDeskId' => $this->deskId($profile->slug),
            ])
            ->all();

        $agents[] = [
            'agentId' => 'scheduler',
            'name' => 'Scheduler',
            'state' => $this->schedulerOfficeState(),
            'preferredDeskId' => $this->deskId('scheduler'),
        ];

        return $agents;
    }

    private function deskId(string $slug): string
    {
        return 'desk-'.$slug;
    }

    /**
     * Furniture for the Hermes3D office map: a desk per active role profile
     * surrounded by a small lobby and break room so the scene is explorable.
     *
     * @param  Collection<int, AgentRoleProfile>  $profiles
     * @return array<int, array<string, mixed>>
     */
    private function officeFurniture(Collection $profiles): array
    {
        $furniture = [];

        foreach ($profiles->values() as $index => $profile) {
            $column = $index % 4;
            $row = intdiv($index, 4);

            $furniture = array_merge(
                $furniture,
                $this->deskFurniture($profile->slug, 100 + $column * 200, 300 + $row * 220),
            );
        }

        $furniture = array_merge($furniture, $this->deskFurniture('scheduler', 740, 60));

        return array_merge($furniture, $this->lobbyFurniture());
    }

    /**
     * A workstation: desk, chair, computer, keyboard, mouse and bin, wired so
     * the desk carries the id referenced by presence `preferredDeskId`.
     *
     * @return array<int, array<string, mixed>>
     */
    private function deskFurniture(string $slug, int $x, int $y): array
    {
        return [
            ['_uid' => 'desk-'.$slug, 'type' => 'desk_cubicle', 'x' => $x, 'y' => $y, 'id' => $this->deskId($slug)],
            ['_uid' => $slug.'-chair', 'type' => 'chair', 'x' => $x + 20, 'y' => $y - 10, 'facing' => 180],
            ['_uid' => $slug.'-computer', 'type' => 'computer', 'x' => $x + 20, 'y' => $y - 13],
            ['_uid' => $slug.'-keyboard', 'type' => 'keyboard', 'x' => $x + 30, 'y' => $y - 5],
            ['_uid' => $slug.'-mouse', 'type' => 'mouse', 'x' => $x + 52, 'y' => $y - 5],
            ['_uid' => $slug.'-trash', 'type' => 'trash', 'x' => $x + 70, 'y' => $y - 10],
        ];
    }

    /**
     * Shared lobby, meeting and break-room furniture that anchors the office.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lobbyFurniture(): array
    {
        return [
            ['_uid' => 'meeting-table', 'type' => 'round_table', 'x' => 140, 'y' => 90, 'r' => 90],
            ['_uid' => 'meeting-chair-1', 'type' => 'chair', 'x' => 230, 'y' => 90, 'facing' => 0],
            ['_uid' => 'meeting-chair-2', 'type' => 'chair', 'x' => 140, 'y' => 180, 'facing' => 180],
            ['_uid' => 'meeting-chair-3', 'type' => 'chair', 'x' => 60, 'y' => 90, 'facing' => 90],
            ['_uid' => 'meeting-chair-4', 'type' => 'chair', 'x' => 140, 'y' => 10, 'facing' => 270],
            ['_uid' => 'whiteboard', 'type' => 'whiteboard', 'x' => 40, 'y' => 220, 'w' => 10, 'h' => 60],
            ['_uid' => 'kanban-board', 'type' => 'kanban_board', 'x' => 460, 'y' => 60, 'facing' => 180],
            ['_uid' => 'bookshelf', 'type' => 'bookshelf', 'x' => 620, 'y' => 40, 'w' => 80, 'h' => 120],
            ['_uid' => 'coffee-machine', 'type' => 'coffee_machine', 'x' => 880, 'y' => 30, 'elevation' => 0.56],
            ['_uid' => 'kitchen-cabinet', 'type' => 'cabinet', 'x' => 840, 'y' => 30, 'w' => 80, 'h' => 40],
            ['_uid' => 'fridge', 'type' => 'fridge', 'x' => 1020, 'y' => 20, 'w' => 40, 'h' => 80],
            ['_uid' => 'water-cooler', 'type' => 'water_cooler', 'x' => 1080, 'y' => 30],
            ['_uid' => 'dining-table', 'type' => 'round_table', 'x' => 890, 'y' => 140, 'r' => 50],
            ['_uid' => 'dining-chair-1', 'type' => 'chair', 'x' => 950, 'y' => 140, 'facing' => 0],
            ['_uid' => 'dining-chair-2', 'type' => 'chair', 'x' => 890, 'y' => 210, 'facing' => 180],
            ['_uid' => 'dining-chair-3', 'type' => 'chair', 'x' => 830, 'y' => 140, 'facing' => 90],
            ['_uid' => 'lounge-couch', 'type' => 'couch', 'x' => 1000, 'y' => 380, 'w' => 100, 'h' => 40, 'facing' => 90],
            ['_uid' => 'lounge-table', 'type' => 'table_rect', 'x' => 980, 'y' => 380, 'w' => 60, 'h' => 30, 'facing' => 270],
            ['_uid' => 'beanbag-1', 'type' => 'beanbag', 'x' => 1000, 'y' => 320, 'color' => '#e65100', 'facing' => 90],
            ['_uid' => 'beanbag-2', 'type' => 'beanbag', 'x' => 1000, 'y' => 440, 'color' => '#1565c0', 'facing' => 90],
            ['_uid' => 'pingpong', 'type' => 'pingpong', 'x' => 950, 'y' => 600, 'w' => 100, 'h' => 60],
            ['_uid' => 'clock', 'type' => 'clock', 'x' => 550, 'y' => 5],
            ['_uid' => 'lamp-1', 'type' => 'lamp', 'x' => 430, 'y' => 120],
            ['_uid' => 'lamp-2', 'type' => 'lamp', 'x' => 980, 'y' => 390],
            ['_uid' => 'plant-1', 'type' => 'plant', 'x' => 40, 'y' => 40],
            ['_uid' => 'plant-2', 'type' => 'plant', 'x' => 660, 'y' => 30],
            ['_uid' => 'plant-3', 'type' => 'plant', 'x' => 340, 'y' => 700],
            ['_uid' => 'plant-4', 'type' => 'plant', 'x' => 450, 'y' => 450],
            ['_uid' => 'plant-5', 'type' => 'plant', 'x' => 1090, 'y' => 310],
            ['_uid' => 'plant-6', 'type' => 'plant', 'x' => 1100, 'y' => 490],
            ['_uid' => 'plant-7', 'type' => 'plant', 'x' => 530, 'y' => 700],
        ];
    }

    /**
     * Current office state per role profile, merging every activity signal we
     * have: durable task status, live mission choreography, and recent
     * agent-to-agent messages.
     *
     * @return array<int, string> Role profile id => office state.
     */
    private function roleOfficeStates(): array
    {
        $states = [];

        foreach ([$this->taskOfficeStates(), $this->missionOfficeStates(), $this->messageOfficeStates()] as $layer) {
            foreach ($layer as $roleId => $state) {
                $states[$roleId] = $this->highestPriority($states[$roleId] ?? 'idle', $state);
            }
        }

        return $states;
    }

    /**
     * Office state implied by durable task status.
     *
     * @return array<int, string> Role profile id => office state.
     */
    private function taskOfficeStates(): array
    {
        $recentFailure = now()->subMinutes(5);

        $tasks = Task::query()
            ->whereIn('status', [
                TaskStatus::Pending->value,
                TaskStatus::Planning->value,
                TaskStatus::Running->value,
                TaskStatus::Paused->value,
                TaskStatus::Waiting->value,
                TaskStatus::Retrying->value,
                TaskStatus::Verifying->value,
                TaskStatus::Failed->value,
            ])
            ->where(function (Builder $query) use ($recentFailure): void {
                $query->where('status', '!=', TaskStatus::Failed->value)
                    ->orWhere('completed_at', '>=', $recentFailure);
            })
            ->with('conversation:id,role_profile_id')
            ->with(['steps' => function ($query): void {
                $query->whereIn('status', [
                    TaskStepStatus::Pending->value,
                    TaskStepStatus::Running->value,
                    TaskStepStatus::Retrying->value,
                ])
                    ->orderBy('sort_order')
                    ->select(['id', 'task_id', 'status', 'role_profile_id']);
            }])
            ->get(['id', 'conversation_id', 'status', 'completed_at']);

        $states = [];

        foreach ($tasks as $task) {
            // Multi-agent tasks are shown by whoever owns the in-flight step;
            // singler-agent tasks fall back to the conversation's role.
            $roleId = $task->steps->first()?->role_profile_id
                ?? $task->conversation?->role_profile_id;

            if ($roleId === null) {
                continue;
            }

            $states[$roleId] = $this->highestPriority(
                $states[$roleId] ?? 'idle',
                $this->officeStateForTask($task->status),
            );
        }

        return $states;
    }

    /**
     * Mission choreography: while a mission is scoping the whole team huddles
     * in the meeting room; once it is active the role assigned the current
     * feature disperses to its desk and works.
     *
     * @return array<int, string> Role profile id => office state.
     */
    private function missionOfficeStates(): array
    {
        $profileIds = $this->activeProfileIdsBySlug();

        if ($profileIds === []) {
            return [];
        }

        $states = [];

        if (Mission::query()->where('status', 'scoping')->exists()) {
            foreach ($profileIds as $roleId) {
                $states[$roleId] = 'meeting';
            }
        }

        $activeMissions = Mission::query()
            ->where('status', 'active')
            ->whereNotNull('current_feature_id')
            ->get(['id', 'current_feature_id']);

        foreach ($activeMissions as $mission) {
            $feature = $mission->currentFeature();

            if (! $feature instanceof MissionFeature) {
                continue;
            }

            $roleId = $profileIds[$this->orchestrator->roleSlugForFeature($feature)] ?? null;

            if ($roleId !== null) {
                $states[$roleId] = $this->highestPriority($states[$roleId] ?? 'idle', 'working');
            }
        }

        return $states;
    }

    /**
     * Agent-to-agent messages: a fresh assignment sends the recipient to its
     * desk (working); spontaneous peer discussion draws it to the meeting
     * room. Mission kickoffs are intentionally excluded here — the huddle is
     * driven by mission scoping status so the team disperses once work starts.
     *
     * @return array<int, string> Role profile id => office state.
     */
    private function messageOfficeStates(): array
    {
        $profileIds = $this->activeProfileIdsBySlug();
        $window = max(1, (int) config('agent.office_message_window_minutes', 10));

        if ($profileIds === []) {
            return [];
        }

        $messages = AgentMessage::query()
            ->recent($window)
            ->whereNotNull('to_agent')
            ->get(['to_agent', 'kind']);

        $states = [];

        foreach ($messages as $message) {
            $roleId = $profileIds[(string) $message->to_agent] ?? null;

            if ($roleId === null) {
                continue;
            }

            $candidate = match ($message->kind) {
                'assignment' => 'working',
                'discussion' => 'meeting',
                default => null,
            };

            if ($candidate !== null) {
                $states[$roleId] = $this->highestPriority($states[$roleId] ?? 'idle', $candidate);
            }
        }

        return $states;
    }

    /**
     * @return array<string, int> Role slug => role profile id.
     */
    private function activeProfileIdsBySlug(): array
    {
        return AgentRoleProfile::query()
            ->where('is_active', true)
            ->pluck('id', 'slug')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function officeStateForTask(TaskStatus $status): string
    {
        return match ($status) {
            TaskStatus::Running,
            TaskStatus::Retrying,
            TaskStatus::Verifying => 'working',
            TaskStatus::Pending,
            TaskStatus::Planning => 'meeting',
            TaskStatus::Failed => 'error',
            TaskStatus::Paused,
            TaskStatus::Waiting => 'idle',
            default => 'idle',
        };
    }

    private function highestPriority(string $current, string $candidate): string
    {
        $rank = ['idle' => 0, 'meeting' => 1, 'error' => 2, 'working' => 3];

        return ($rank[$candidate] ?? 0) >= ($rank[$current] ?? 0) ? $candidate : $current;
    }

    private function schedulerOfficeState(): string
    {
        if ($this->scheduledJobRunning()) {
            return 'working';
        }

        $now = now();

        $dueSoon = ScheduledTask::query()
            ->where('is_active', true)
            ->whereBetween('next_run_at', [$now, $now->copy()->addMinutes(2)])
            ->exists();

        return $dueSoon ? 'meeting' : 'idle';
    }

    /**
     * A scheduled task runs on the queue; a reserved RunScheduledTaskJob row is
     * the only reliable "cron is executing right now" signal.
     */
    private function scheduledJobRunning(): bool
    {
        if (! Schema::hasTable('jobs')) {
            return false;
        }

        return DB::table('jobs')
            ->whereNotNull('reserved_at')
            ->where('payload', 'like', '%RunScheduledTaskJob%')
            ->exists();
    }

    private function resolveConversation(string $sessionKey, ?string $role): Conversation
    {
        $conversation = Conversation::query()->firstOrCreate(
            ['external_key' => $sessionKey],
            ['title' => $sessionKey, 'model' => $this->activeModel()],
        );

        $slug = trim((string) $role);

        if ($slug !== '') {
            $profile = AgentRoleProfile::query()->where('slug', $slug)->first();

            if ($profile !== null && $conversation->role_profile_id !== $profile->id) {
                $conversation->forceFill([
                    'role_profile_id' => $profile->id,
                    'identity_label' => $profile->name,
                ])->save();
            }
        }

        return $conversation;
    }

    /**
     * @param  array<int, array<string, mixed>>  $messages
     */
    private function lastUserMessage(array $messages): string
    {
        foreach (array_reverse($messages) as $message) {
            if (($message['role'] ?? null) === 'user') {
                return trim((string) ($message['content'] ?? ''));
            }
        }

        return '';
    }
}
