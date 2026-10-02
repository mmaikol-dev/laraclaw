<?php

namespace Tests\Feature;

use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The task engine migrations were edited after they had already run on some
 * databases, leaving `tasks` and `task_steps` without timestamps. Nothing in
 * the request path caught it — the failure only surfaced as a SQLSTATE error
 * inside a tool result. These tests assert the persisted schema directly so a
 * missing column fails the suite instead of a user's agent run.
 */
class TaskSchemaContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<int, array{0: string, 1: array<int, string>}>
     */
    public static function taskTables(): array
    {
        return [
            'tasks' => ['tasks', ['created_at', 'updated_at']],
            'task_steps' => ['task_steps', ['created_at', 'updated_at']],
            'task_checkpoints' => ['task_checkpoints', ['created_at', 'updated_at']],
        ];
    }

    #[DataProvider('taskTables')]
    public function test_task_engine_tables_have_timestamp_columns(string $table, array $columns): void
    {
        $this->assertTrue(Schema::hasTable($table), "Table [{$table}] is missing.");

        foreach ($columns as $column) {
            $this->assertTrue(
                Schema::hasColumn($table, $column),
                "Table [{$table}] is missing the [{$column}] column required by its model.",
            );
        }
    }

    public function test_a_task_and_its_related_records_persist_with_timestamps(): void
    {
        $task = Task::create(['goal' => 'Persist with timestamps']);
        $step = $task->steps()->create([
            'sort_order' => 1,
            'description' => 'Do the thing',
            'prompt' => 'Do the thing',
        ]);
        $checkpoint = $task->checkpoints()->create([
            'step_sort_order' => 1,
            'action_taken' => 'Planned the work',
            'task_status' => 'running',
        ]);

        $this->assertNotNull($task->created_at);
        $this->assertNotNull($step->created_at);
        $this->assertNotNull($checkpoint->created_at);

        $task->update(['current_step' => 1]);

        $this->assertSame(1, $task->fresh()->current_step);
        $this->assertNotNull($task->fresh()->updated_at);
    }
}
