<?php

namespace App\Services\Agent;

use App\Models\AgentSetting;

class OpenCodeDelegationGuard
{
    /**
     * File extensions considered source code.
     *
     * @var array<int, string>
     */
    private const CODE_EXTENSIONS = [
        'php', 'phtml', 'ts', 'tsx', 'js', 'jsx', 'mjs', 'cjs', 'vue', 'svelte',
        'py', 'rb', 'go', 'rs', 'java', 'kt', 'kts', 'swift', 'dart',
        'c', 'h', 'cpp', 'hpp', 'cc', 'hh', 'cs', 'm', 'mm',
        'sh', 'bash', 'zsh', 'sql', 'lua', 'pl', 'r', 'jl',
        'ex', 'exs', 'erl', 'hs', 'scala', 'groovy', 'clj', 'zig',
        'html', 'css', 'scss', 'sass', 'less',
    ];

    /**
     * Files whose presence marks a directory as a coding project.
     *
     * @var array<int, string>
     */
    private const PROJECT_MARKERS = [
        'composer.json', 'package.json', 'artisan', '.git',
        'go.mod', 'Cargo.toml', 'pyproject.toml', 'requirements.txt',
        'Gemfile', 'pom.xml', 'build.gradle', 'build.gradle.kts', 'mix.exs',
        'pubspec.yaml', 'deno.json', 'bun.lockb', 'pnpm-lock.yaml', 'yarn.lock',
        'package-lock.json', 'composer.lock', 'vite.config.ts', 'vite.config.js',
        'tsconfig.json', 'webpack.config.js', 'Makefile', 'CMakeLists.txt',
    ];

    /**
     * Maximum number of parent directories to inspect when locating a project root.
     */
    private const MAX_ANCESTOR_DEPTH = 8;

    /**
     * Determine whether a tool call attempts to modify code files directly.
     *
     * Returns a refusal message when the call must be delegated to OpenCode,
     * or null when the call may proceed.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function intercept(string $toolName, array $arguments): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        return match ($toolName) {
            'file' => $this->interceptFileTool($arguments),
            'shell' => $this->interceptShellTool($arguments),
            default => null,
        };
    }

    private function isEnabled(): bool
    {
        return (bool) AgentSetting::get('enable_opencode_guard', true);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function interceptFileTool(array $arguments): ?string
    {
        $action = (string) ($arguments['action'] ?? '');

        if (! in_array($action, ['write', 'create', 'delete', 'move', 'copy'], true)) {
            return null;
        }

        $targets = array_filter([
            (string) ($arguments['path'] ?? ''),
            (string) ($arguments['destination'] ?? ''),
        ], fn (string $path): bool => $path !== '');

        foreach ($targets as $target) {
            if ($this->isCodeWriteAttempt($target, (string) config('agent.working_dir', '/tmp/laraclaw'))) {
                return $this->refusalMessage($target);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function interceptShellTool(array $arguments): ?string
    {
        $command = trim((string) ($arguments['command'] ?? ''));

        if ($command === '') {
            return null;
        }

        if ($this->isScaffoldingCommand($command)) {
            return $this->scaffoldRefusalMessage();
        }

        $workingDirectory = (string) ($arguments['working_dir'] ?? config('agent.working_dir', '/tmp/laraclaw'));

        foreach ($this->extractShellWriteTargets($command) as $target) {
            $resolvedTarget = str_starts_with($target, '/')
                ? $target
                : rtrim($workingDirectory, '/').'/'.$target;

            if ($this->isCodeWriteAttempt($resolvedTarget, $workingDirectory)) {
                return $this->refusalMessage($target);
            }
        }

        return null;
    }

    /**
     * Extract plausible code-file targets from shell commands that show write
     * intent: redirection (>, >>), tee, in-place sed edits, cp/mv/rm.
     *
     * @return array<int, string>
     */
    private function extractShellWriteTargets(string $command): array
    {
        $hasRedirect = preg_match('/>{1,2}\s*\S/', $command) === 1;
        $hasTee = preg_match('/\btee\b/', $command) === 1;
        $hasSedInPlace = preg_match('/\bsed\b[^;&|]*\s-i\b|\bsed\s+-i\b/', $command) === 1;
        $hasFileOperation = preg_match('/\b(?:cp|mv|rm)\b\s+\S+/', $command) === 1;

        if (! $hasRedirect && ! $hasTee && ! $hasSedInPlace && ! $hasFileOperation) {
            return [];
        }

        $targets = [];

        if ($hasRedirect) {
            preg_match_all('/>{1,2}\s*(?:"([^"\s]+)"|([^\s;&|]+))/', $command, $matches);

            foreach ($matches[0] ?? [] as $index => $_) {
                $candidate = $matches[1][$index] !== '' ? $matches[1][$index] : $matches[2][$index];

                if ($candidate !== '') {
                    $targets[] = trim($candidate, '\'"');
                }
            }
        }

        foreach (preg_split('/[\s;&|]+/', $command) ?: [] as $token) {
            $candidate = trim($token, '\'"');

            if ($candidate !== '' && $this->hasCodeExtension($candidate)) {
                $targets[] = $candidate;
            }
        }

        return array_values(array_unique($targets));
    }

    /**
     * Detect project scaffolding commands that generate source trees
     * (composer create-project, laravel new, framework installers, npm init).
     */
    private function isScaffoldingCommand(string $command): bool
    {
        $patterns = [
            '/\bcomposer\s+(?:create-project|create_project)\b/',
            '/\blaravel\s+new\b/',
            '/\blaravel-installer\b/',
            '/\b(?:npm|pnpm|yarn|bun)\s+(?:create|init)\b/',
            '/\bnpx\s+create-/',
            '/\bbun\s+x?\s*create-/',
            '/\bcargo\s+new\b/',
            '/\bgo\s+mod\s+init\b/',
            '/\brails\s+new\b/',
            '/\bdjango-admin\s+startproject\b|\bpython\s+-m\s+django\s+startproject\b/',
            '/\bsymfony\s+new\b/',
            '/\bdotnet\s+new\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $command) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decide whether a path points at a source-code file inside a project directory.
     */
    private function isCodeWriteAttempt(string $path, string $fallbackDirectory): string|false
    {
        $trimmedPath = trim($path);

        if ($trimmedPath === '' || ! $this->hasCodeExtension($trimmedPath)) {
            return false;
        }

        $directory = dirname(str_starts_with($trimmedPath, '/') ? $trimmedPath : rtrim($fallbackDirectory, '/').'/'.$trimmedPath);

        return $this->locateProjectRoot($directory);
    }

    private function hasCodeExtension(string $path): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return $extension !== '' && in_array($extension, self::CODE_EXTENSIONS, true);
    }

    /**
     * Walk up from a directory looking for project markers.
     *
     * @return string|false The project root when found, false otherwise.
     */
    private function locateProjectRoot(string $directory): string|false
    {
        $current = rtrim($directory, '/');
        $depth = 0;

        while (! is_dir($current)) {
            $parent = dirname($current);

            if ($parent === $current || $depth >= self::MAX_ANCESTOR_DEPTH) {
                return false;
            }

            $current = $parent;
            $depth++;
        }

        for (; $depth < self::MAX_ANCESTOR_DEPTH; $depth++) {
            foreach (self::PROJECT_MARKERS as $marker) {
                if (str_contains($marker, '*')) {
                    $globMatches = glob($current.'/'.$marker);

                    if ($globMatches !== [] && $globMatches !== false) {
                        return $current;
                    }

                    continue;
                }

                if (file_exists($current.'/'.$marker)) {
                    return $current;
                }
            }

            $parent = dirname($current);

            if ($parent === $current) {
                return false;
            }

            $current = $parent;
        }

        return false;
    }

    private function refusalMessage(string $target): string
    {
        return sprintf(
            'Blocked: [%s] looks like source code inside a coding project. All coding work must be delegated to the OpenCode agent instead of writing files directly. '
            .'Use the opencode tool (action: run — supports long-running tasks without the shell timeout cap), or the shell tool with: $HOME/.opencode/bin/opencode run --model <model> "<task description>" '
            .'(run opencode list_models first and ask the user which model to use if not already chosen). See the opencode-coder skill.',
            $target,
        );
    }

    private function scaffoldRefusalMessage(): string
    {
        return 'Blocked: project scaffolding must be delegated to the OpenCode agent. '
            .'Use the opencode tool (action: run — supports long-running tasks without the shell timeout cap), or the shell tool with: $HOME/.opencode/bin/opencode run --model <model> "scaffold <framework> project at <path>" '
            .'(run opencode list_models first and ask the user which model to use if not already chosen). See the opencode-coder skill.';
    }
}
