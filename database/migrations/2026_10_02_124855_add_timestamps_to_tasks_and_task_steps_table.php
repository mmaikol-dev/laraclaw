<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repairs schema drift on databases created before the task engine migrations
 * declared timestamps(). Those files were edited after they had already been
 * recorded as ran, so `migrate` never revisits them and inserting a Task or
 * TaskStep fails with "Unknown column 'updated_at' in 'field list'".
 *
 * Adding the columns here rather than editing the original migrations keeps
 * already-migrated databases repairable. The guards keep it safe for fresh
 * installs and for any database that already has the columns.
 */
return new class extends Migration
{
    private const TABLES = ['tasks', 'task_steps'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            $this->applyTimestamps($table, add: true);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            $this->applyTimestamps($table, add: false);
        }
    }

    /**
     * Add or remove only the timestamp columns that are actually present or
     * absent, so a partially migrated table is corrected rather than erroring
     * on a duplicate column.
     */
    private function applyTimestamps(string $table, bool $add): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $changes = array_values(array_filter(
            ['created_at', 'updated_at'],
            fn (string $column): bool => $add
                ? ! Schema::hasColumn($table, $column)
                : Schema::hasColumn($table, $column),
        ));

        if ($changes === []) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($add, $changes): void {
            foreach ($changes as $column) {
                if ($add) {
                    $blueprint->timestamp($column)->nullable();

                    continue;
                }

                $blueprint->dropColumn($column);
            }
        });
    }
};
