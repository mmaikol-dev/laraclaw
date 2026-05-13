<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table): void {
            if (! Schema::hasColumn('conversations', 'role_profile_id')) {
                $table->foreignId('role_profile_id')->nullable()->after('id')->constrained('agent_role_profiles')->nullOnDelete();
            }

            if (! Schema::hasColumn('conversations', 'identity_label')) {
                $table->string('identity_label')->nullable()->after('model');
            }

            if (! Schema::hasColumn('conversations', 'active_goal')) {
                $table->text('active_goal')->nullable()->after('identity_label');
            }

            if (! Schema::hasColumn('conversations', 'completion_criteria')) {
                $table->json('completion_criteria')->nullable()->after('active_goal');
            }

            if (! Schema::hasColumn('conversations', 'verification_status')) {
                $table->string('verification_status')->default('unverified')->after('completion_criteria');
            }

            if (! Schema::hasColumn('conversations', 'verification_notes')) {
                $table->text('verification_notes')->nullable()->after('verification_status');
            }

            if (! Schema::hasColumn('conversations', 'next_action')) {
                $table->text('next_action')->nullable()->after('verification_notes');
            }

            if (! Schema::hasColumn('conversations', 'resumable_state')) {
                $table->json('resumable_state')->nullable()->after('next_action');
            }

            if (! Schema::hasColumn('conversations', 'last_verified_at')) {
                $table->timestamp('last_verified_at')->nullable()->after('resumable_state');
            }

            if (! Schema::hasColumn('conversations', 'last_resumed_at')) {
                $table->timestamp('last_resumed_at')->nullable()->after('last_verified_at');
            }
        });

        Schema::table('agent_memories', function (Blueprint $table): void {
            if (! Schema::hasColumn('agent_memories', 'scope')) {
                $table->string('scope')->default('global')->after('category');
            }

            if (! Schema::hasColumn('agent_memories', 'subject_type')) {
                $table->string('subject_type')->nullable()->after('scope');
            }

            if (! Schema::hasColumn('agent_memories', 'subject_id')) {
                $table->unsignedBigInteger('subject_id')->nullable()->after('subject_type');
            }

            if (! Schema::hasColumn('agent_memories', 'source')) {
                $table->string('source')->nullable()->after('subject_id');
            }

            if (! Schema::hasColumn('agent_memories', 'confidence')) {
                $table->decimal('confidence', 4, 2)->default(1)->after('source');
            }

            if (! Schema::hasColumn('agent_memories', 'last_observed_at')) {
                $table->timestamp('last_observed_at')->nullable()->after('confidence');
            }
        });

        Schema::table('agent_role_profiles', function (Blueprint $table): void {
            if (! Schema::hasColumn('agent_role_profiles', 'slug')) {
                $table->string('slug')->nullable()->unique()->after('id');
            }

            if (! Schema::hasColumn('agent_role_profiles', 'name')) {
                $table->string('name')->nullable()->after('slug');
            }

            if (! Schema::hasColumn('agent_role_profiles', 'description')) {
                $table->text('description')->nullable()->after('name');
            }

            if (! Schema::hasColumn('agent_role_profiles', 'system_prompt')) {
                $table->text('system_prompt')->nullable()->after('description');
            }

            if (! Schema::hasColumn('agent_role_profiles', 'affective_profile')) {
                $table->json('affective_profile')->nullable()->after('system_prompt');
            }

            if (! Schema::hasColumn('agent_role_profiles', 'preferred_tools')) {
                $table->json('preferred_tools')->nullable()->after('affective_profile');
            }

            if (! Schema::hasColumn('agent_role_profiles', 'workflow_patterns')) {
                $table->json('workflow_patterns')->nullable()->after('preferred_tools');
            }

            if (! Schema::hasColumn('agent_role_profiles', 'permissions')) {
                $table->json('permissions')->nullable()->after('workflow_patterns');
            }

            if (! Schema::hasColumn('agent_role_profiles', 'responsibility_scope')) {
                $table->text('responsibility_scope')->nullable()->after('permissions');
            }

            if (! Schema::hasColumn('agent_role_profiles', 'escalation_rules')) {
                $table->text('escalation_rules')->nullable()->after('responsibility_scope');
            }

            if (! Schema::hasColumn('agent_role_profiles', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('escalation_rules');
            }
        });

        Schema::table('proactive_findings', function (Blueprint $table): void {
            if (! Schema::hasColumn('proactive_findings', 'category')) {
                $table->string('category')->nullable()->after('id');
            }

            if (! Schema::hasColumn('proactive_findings', 'severity')) {
                $table->string('severity')->default('medium')->after('category');
            }

            if (! Schema::hasColumn('proactive_findings', 'status')) {
                $table->string('status')->default('open')->after('severity');
            }

            if (! Schema::hasColumn('proactive_findings', 'title')) {
                $table->string('title')->nullable()->after('status');
            }

            if (! Schema::hasColumn('proactive_findings', 'summary')) {
                $table->text('summary')->nullable()->after('title');
            }

            if (! Schema::hasColumn('proactive_findings', 'details')) {
                $table->longText('details')->nullable()->after('summary');
            }

            if (! Schema::hasColumn('proactive_findings', 'fingerprint')) {
                $table->string('fingerprint')->nullable()->unique()->after('details');
            }

            if (! Schema::hasColumn('proactive_findings', 'source')) {
                $table->string('source')->nullable()->after('fingerprint');
            }

            if (! Schema::hasColumn('proactive_findings', 'meta')) {
                $table->json('meta')->nullable()->after('source');
            }

            if (! Schema::hasColumn('proactive_findings', 'detected_at')) {
                $table->timestamp('detected_at')->nullable()->after('meta');
            }

            if (! Schema::hasColumn('proactive_findings', 'resolved_at')) {
                $table->timestamp('resolved_at')->nullable()->after('detected_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('proactive_findings', function (Blueprint $table): void {
            $columns = [
                'resolved_at',
                'detected_at',
                'meta',
                'source',
                'fingerprint',
                'details',
                'summary',
                'title',
                'status',
                'severity',
                'category',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('proactive_findings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('agent_role_profiles', function (Blueprint $table): void {
            if (Schema::hasColumn('agent_role_profiles', 'slug')) {
                $table->dropUnique(['slug']);
            }

            $columns = [
                'is_active',
                'escalation_rules',
                'responsibility_scope',
                'permissions',
                'workflow_patterns',
                'preferred_tools',
                'affective_profile',
                'system_prompt',
                'description',
                'name',
                'slug',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('agent_role_profiles', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('agent_memories', function (Blueprint $table): void {
            $columns = [
                'last_observed_at',
                'confidence',
                'source',
                'subject_id',
                'subject_type',
                'scope',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('agent_memories', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('conversations', function (Blueprint $table): void {
            if (Schema::hasColumn('conversations', 'role_profile_id')) {
                $table->dropConstrainedForeignId('role_profile_id');
            }

            $columns = [
                'last_resumed_at',
                'last_verified_at',
                'resumable_state',
                'next_action',
                'verification_notes',
                'verification_status',
                'completion_criteria',
                'active_goal',
                'identity_label',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('conversations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
