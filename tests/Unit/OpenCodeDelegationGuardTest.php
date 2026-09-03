<?php

namespace Tests\Unit;

use App\Models\AgentSetting;
use App\Services\Agent\OpenCodeDelegationGuard;
use App\Services\Agent\ToolRegistry;
use App\Services\Tools\BaseTool;
use App\Services\Tools\FileTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpenCodeDelegationGuardTest extends TestCase
{
    use RefreshDatabase;

    private string $projectRoot;

    private string $plainRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectRoot = sys_get_temp_dir().'/laraclaw-guard-project';
        $this->plainRoot = sys_get_temp_dir().'/laraclaw-guard-plain';

        @mkdir($this->projectRoot.'/app/Http/Controllers', 0777, true);
        @mkdir($this->plainRoot, 0777, true);
        file_put_contents($this->projectRoot.'/composer.json', '{"name": "acme/demo"}');

        AgentSetting::query()->where('key', 'enable_opencode_guard')->delete();
    }

    protected function tearDown(): void
    {
        @unlink($this->projectRoot.'/composer.json');
        @rmdir($this->projectRoot.'/app/Http/Controllers');
        @rmdir($this->projectRoot.'/app/Http');
        @rmdir($this->projectRoot.'/app');
        @rmdir($this->projectRoot);
        @rmdir($this->plainRoot);

        parent::tearDown();
    }

    public function test_blocks_file_write_of_code_file_inside_project(): void
    {
        $guard = new OpenCodeDelegationGuard;

        $refusal = $guard->intercept('file', [
            'action' => 'write',
            'path' => $this->projectRoot.'/app/Http/Controllers/UserController.php',
            'content' => '<?php class UserController {}',
        ]);

        $this->assertNotNull($refusal);
        $this->assertStringContainsString('OpenCode', $refusal);
    }

    public function test_blocks_file_create_via_project_relative_walk_up(): void
    {
        $guard = new OpenCodeDelegationGuard;

        $refusal = $guard->intercept('file', [
            'action' => 'create',
            'path' => $this->projectRoot.'/app/helpers.ts',
            'content' => 'export {}',
        ]);

        $this->assertNotNull($refusal);
    }

    public function test_blocks_file_delete_of_code_file_inside_project(): void
    {
        $guard = new OpenCodeDelegationGuard;

        $refusal = $guard->intercept('file', [
            'action' => 'delete',
            'path' => $this->projectRoot.'/app/legacy.py',
        ]);

        $this->assertNotNull($refusal);
    }

    public function test_blocks_file_move_with_code_destination_inside_project(): void
    {
        $guard = new OpenCodeDelegationGuard;

        $refusal = $guard->intercept('file', [
            'action' => 'move',
            'path' => '/tmp/laraclaw/notes.txt',
            'destination' => $this->projectRoot.'/app/main.go',
        ]);

        $this->assertNotNull($refusal);
    }

    public function test_allows_non_code_file_write_inside_project(): void
    {
        $guard = new OpenCodeDelegationGuard;

        $refusal = $guard->intercept('file', [
            'action' => 'write',
            'path' => $this->projectRoot.'/storage/report.txt',
            'content' => 'meeting notes',
        ]);

        $this->assertNull($refusal);
    }

    public function test_allows_code_file_write_outside_any_project(): void
    {
        $guard = new OpenCodeDelegationGuard;

        $refusal = $guard->intercept('file', [
            'action' => 'write',
            'path' => $this->plainRoot.'/scratch.php',
            'content' => '<?php echo 1;',
        ]);

        $this->assertNull($refusal);
    }

    public function test_allows_read_only_file_actions_inside_project(): void
    {
        $guard = new OpenCodeDelegationGuard;

        foreach (['read', 'list', 'search'] as $action) {
            $this->assertNull($guard->intercept('file', [
                'action' => $action,
                'path' => $this->projectRoot.'/app',
            ]));
        }
    }

    public function test_blocks_shell_redirect_to_code_file_in_project_working_dir(): void
    {
        $guard = new OpenCodeDelegationGuard;

        $refusal = $guard->intercept('shell', [
            'command' => 'echo "class Demo {}" > src/Demo.php',
            'working_dir' => $this->projectRoot,
        ]);

        $this->assertNotNull($refusal);
    }

    public function test_blocks_shell_append_to_absolute_code_path_in_project(): void
    {
        $guard = new OpenCodeDelegationGuard;

        $refusal = $guard->intercept('shell', [
            'command' => 'echo "// todo" >> '.$this->projectRoot.'/app/bootstrap.js',
        ]);

        $this->assertNotNull($refusal);
    }

    public function test_blocks_shell_sed_edit_of_code_file_in_project(): void
    {
        $guard = new OpenCodeDelegationGuard;

        $refusal = $guard->intercept('shell', [
            'command' => 'sed -i s/foo/bar/g app/models/user.rb',
            'working_dir' => $this->projectRoot,
        ]);

        $this->assertNotNull($refusal);
    }

    public function test_allows_benign_shell_commands(): void
    {
        $guard = new OpenCodeDelegationGuard;

        foreach (['ls -la', 'cat /tmp/laraclaw/notes.txt', 'php artisan list', 'git status'] as $command) {
            $this->assertNull($guard->intercept('shell', [
                'command' => $command,
                'working_dir' => $this->projectRoot,
            ]));
        }
    }

    public function test_allows_shell_writes_outside_projects(): void
    {
        $guard = new OpenCodeDelegationGuard;

        $this->assertNull($guard->intercept('shell', [
            'command' => 'echo "hello" > output.log',
            'working_dir' => $this->plainRoot,
        ]));
    }

    public function test_ignores_other_tools(): void
    {
        $guard = new OpenCodeDelegationGuard;

        $this->assertNull($guard->intercept('memory', [
            'action' => 'set',
            'key' => 'demo',
        ]));
    }

    public function test_can_be_disabled_via_setting(): void
    {
        $this->storeGuardSetting(false);

        try {
            $guard = new OpenCodeDelegationGuard;

            $this->assertNull($guard->intercept('file', [
                'action' => 'write',
                'path' => $this->projectRoot.'/app/blocked.php',
                'content' => 'x',
            ]));
        } finally {
            $this->storeGuardSetting(true);
        }
    }

    private function storeGuardSetting(bool $enabled): void
    {
        AgentSetting::query()->updateOrCreate(
            ['key' => 'enable_opencode_guard'],
            [
                'value' => $enabled ? 'true' : 'false',
                'type' => 'bool',
                'label' => 'OpenCode delegation guard',
                'description' => 'Block direct code writes; delegate coding to OpenCode.',
            ],
        );
    }

    public function test_blocks_scaffolding_commands(): void
    {
        $guard = new OpenCodeDelegationGuard;

        $commands = [
            'composer create-project laravel/laravel accounting',
            'laravel new accounting',
            'npm create vite@latest frontend',
            'npx create-next-app@latest site',
            'cargo new rust_service',
            'go mod init example.com/m',
            'rails new blog',
        ];

        foreach ($commands as $command) {
            $refusal = $guard->intercept('shell', ['command' => $command]);

            $this->assertNotNull($refusal, "Failed to block: {$command}");
            $this->assertStringContainsString('scaffolding', $refusal);
        }
    }

    public function test_allows_dependency_and_artisan_commands(): void
    {
        $guard = new OpenCodeDelegationGuard;

        foreach (['composer install', 'npm install', 'php artisan migrate', 'php artisan test'] as $command) {
            $this->assertNull(
                $guard->intercept('shell', ['command' => $command]),
                "Incorrectly blocked: {$command}",
            );
        }
    }

    public function test_registry_returns_refusal_error_for_blocked_tool_call(): void
    {
        $registry = new ToolRegistry(new OpenCodeDelegationGuard);
        $registry->register(new FakeNoopTool);
        $registry->register(new FileTool);

        $blocked = $registry->execute('file', [
            'action' => 'write',
            'path' => $this->projectRoot.'/app/Foo.tsx',
            'content' => 'export const foo = 1;',
        ]);

        $this->assertNotNull($blocked['error']);
        $this->assertStringContainsString('opencode run', $blocked['error']);
        $this->assertSame('', $blocked['output']);

        $allowed = $registry->execute('fake_noop', []);

        $this->assertNull($allowed['error']);
        $this->assertSame('noop', $allowed['output']);
    }
}

class FakeNoopTool extends BaseTool
{
    public function getName(): string
    {
        return 'fake_noop';
    }

    public function getDescription(): string
    {
        return 'A tool that does nothing.';
    }

    /**
     * @return array<string, mixed>
     */
    public function getParameters(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(array $arguments): string
    {
        return 'noop';
    }
}
