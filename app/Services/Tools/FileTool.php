<?php

namespace App\Services\Tools;

use App\Models\AgentSetting;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class FileTool extends BaseTool
{
    private string $workingDir;

    /**
     * @var array<int, string>
     */
    private array $allowedPaths = [];

    private int $maxFileSizeMb;

    public function getName(): string
    {
        return 'file';
    }

    public function getDescription(): string
    {
        return 'Read, write, create, delete, search, list, move, and copy files inside allowed filesystem paths. Use search to find files by name or extension, and when no path is provided search scans every allowed root.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'description' => 'One of: read, write, create, delete, list, search, move, copy.'],
                'path' => ['type' => 'string', 'description' => 'Absolute path or path relative to the working directory. Leave blank to use the default browse or search roots.'],
                'content' => ['type' => 'string'],
                'destination' => ['type' => 'string'],
                'pattern' => ['type' => 'string', 'description' => 'Filename text, extension like .py, or exact file name to search for.'],
                'recursive' => ['type' => 'boolean'],
            ],
            'required' => ['action'],
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(array $arguments): string
    {
        $this->refreshSettings();

        return match ($arguments['action'] ?? null) {
            'read' => $this->read((string) ($arguments['path'] ?? '')),
            'write' => $this->write((string) ($arguments['path'] ?? ''), (string) ($arguments['content'] ?? '')),
            'create' => $this->create((string) ($arguments['path'] ?? ''), (string) ($arguments['content'] ?? '')),
            'delete' => $this->delete((string) ($arguments['path'] ?? '')),
            'list' => $this->list((string) ($arguments['path'] ?? ''), (bool) ($arguments['recursive'] ?? false)),
            'search' => $this->search((string) ($arguments['path'] ?? ''), (string) ($arguments['pattern'] ?? ''), (bool) ($arguments['recursive'] ?? true)),
            'move' => $this->move((string) ($arguments['path'] ?? ''), (string) ($arguments['destination'] ?? '')),
            'copy' => $this->copy((string) ($arguments['path'] ?? ''), (string) ($arguments['destination'] ?? '')),
            default => throw new RuntimeException('Unsupported file action.'),
        };
    }

    private function read(string $path): string
    {
        $resolvedPath = $this->resolvePath($path);
        $this->guard($resolvedPath);

        if (! is_file($resolvedPath)) {
            throw new RuntimeException('File not found.');
        }

        $fileSize = filesize($resolvedPath) ?: 0;

        if ($fileSize > ($this->maxFileSizeMb * 1024 * 1024)) {
            throw new RuntimeException("File exceeds the {$this->maxFileSizeMb} MB limit.");
        }

        $content = file_get_contents($resolvedPath);

        if ($content === false) {
            throw new RuntimeException('Unable to read file.');
        }

        $lineCount = count(preg_split("/\r\n|\n|\r/", $content) ?: []);

        return $this->truncate("=== {$resolvedPath} ({$lineCount} lines) ===\n{$content}");
    }

    private function write(string $path, string $content): string
    {
        $resolvedPath = $this->resolvePath($path, true);
        $this->guard($resolvedPath);

        $directory = dirname($resolvedPath);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create the destination directory.');
        }

        file_put_contents($resolvedPath, $content);

        return "Wrote file: {$resolvedPath}";
    }

    private function create(string $path, string $content): string
    {
        $resolvedPath = $this->resolvePath($path, true);
        $this->guard($resolvedPath);

        if (file_exists($resolvedPath)) {
            throw new RuntimeException('File already exists.');
        }

        return $this->write($resolvedPath, $content);
    }

    private function delete(string $path): string
    {
        $resolvedPath = $this->resolvePath($path);
        $this->guard($resolvedPath);

        if (is_dir($resolvedPath)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($resolvedPath, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }

            rmdir($resolvedPath);

            return "Deleted directory: {$resolvedPath}";
        }

        if (is_file($resolvedPath)) {
            unlink($resolvedPath);

            return "Deleted file: {$resolvedPath}";
        }

        throw new RuntimeException('Path not found.');
    }

    private function list(string $path, bool $recursive): string
    {
        $resolvedPath = $path === ''
            ? $this->preferredBrowseRoot()
            : $this->resolvePath($path);
        $this->guard($resolvedPath);

        if (! is_dir($resolvedPath)) {
            throw new RuntimeException('Directory not found.');
        }

        $entries = [];

        if ($recursive) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($resolvedPath, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $item) {
                $entries[] = $this->formatEntry($item->getPathname());
            }
        } else {
            foreach (scandir($resolvedPath) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $entries[] = $this->formatEntry($resolvedPath.DIRECTORY_SEPARATOR.$entry);
            }
        }

        usort($entries, function (string $left, string $right): int {
            $leftIsDirectory = str_starts_with($left, '[dir]');
            $rightIsDirectory = str_starts_with($right, '[dir]');

            if ($leftIsDirectory !== $rightIsDirectory) {
                return $leftIsDirectory ? -1 : 1;
            }

            return strcasecmp($left, $right);
        });

        return $this->truncate(implode("\n", $entries));
    }

    private function search(string $path, string $pattern, bool $recursive): string
    {
        if ($pattern === '') {
            throw new RuntimeException('A search pattern is required.');
        }

        $matches = [];
        $roots = $path === ''
            ? $this->searchRoots()
            : [$this->resolvePath($path)];

        foreach ($roots as $root) {
            $this->guard($root);

            if (! is_dir($root)) {
                if ($path === '') {
                    continue;
                }

                throw new RuntimeException('Directory not found.');
            }

            if ($recursive) {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                );

                foreach ($iterator as $item) {
                    if (str_contains(strtolower($item->getFilename()), strtolower($pattern))) {
                        $matches[] = $item->getPathname();
                    }
                }
            } else {
                foreach (scandir($root) ?: [] as $entry) {
                    if ($entry !== '.' && $entry !== '..' && str_contains(strtolower($entry), strtolower($pattern))) {
                        $matches[] = $root.DIRECTORY_SEPARATOR.$entry;
                    }
                }
            }
        }

        if ($matches === []) {
            return 'No matching files found.';
        }

        $exactPattern = strtolower($pattern);
        $matches = array_values(array_unique($matches));
        usort($matches, function (string $left, string $right) use ($exactPattern): int {
            $leftName = strtolower(basename($left));
            $rightName = strtolower(basename($right));
            $leftExact = $leftName === $exactPattern;
            $rightExact = $rightName === $exactPattern;

            if ($leftExact !== $rightExact) {
                return $leftExact ? -1 : 1;
            }

            return strcasecmp($left, $right);
        });

        return $this->truncate(implode("\n", $matches));
    }

    private function move(string $path, string $destination): string
    {
        $sourcePath = $this->resolvePath($path);
        $destinationPath = $this->resolvePath($destination, true);
        $this->guard($sourcePath);
        $this->guard($destinationPath);

        $directory = dirname($destinationPath);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create the destination directory.');
        }

        rename($sourcePath, $destinationPath);

        return "Moved {$sourcePath} to {$destinationPath}";
    }

    private function copy(string $path, string $destination): string
    {
        $sourcePath = $this->resolvePath($path);
        $destinationPath = $this->resolvePath($destination, true);
        $this->guard($sourcePath);
        $this->guard($destinationPath);

        $directory = dirname($destinationPath);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create the destination directory.');
        }

        if (is_dir($sourcePath)) {
            throw new RuntimeException('Directory copy is not supported yet.');
        }

        copy($sourcePath, $destinationPath);

        return "Copied {$sourcePath} to {$destinationPath}";
    }

    private function refreshSettings(): void
    {
        $this->workingDir = (string) AgentSetting::get('working_dir', config('agent.working_dir', '/tmp/laraclaw'));
        $configuredPaths = array_filter(array_map(
            'trim',
            explode(',', (string) (AgentSetting::get('allowed_paths') ?: config('agent.allowed_paths', ''))),
        ));

        $this->allowedPaths = array_values(array_unique([
            $this->normalizeAbsolutePath($this->workingDir),
            $this->normalizeAbsolutePath('/tmp/laraclaw'),
            $this->normalizeAbsolutePath((string) config('agent.home_dir', '/tmp/laraclaw')),
            ...array_map(fn (string $path): string => $this->normalizeAbsolutePath($path), $configuredPaths),
        ]));
        $this->maxFileSizeMb = (int) AgentSetting::get('max_file_size_mb', 10);
    }

    private function resolvePath(string $path, bool $allowMissing = false): string
    {
        if ($path === '') {
            throw new RuntimeException('A path is required.');
        }

        $absolute = str_starts_with($path, '/')
            ? $path
            : $this->workingDir.DIRECTORY_SEPARATOR.$path;

        $normalized = $this->normalizeAbsolutePath($absolute);

        if (file_exists($normalized)) {
            $realPath = realpath($normalized);

            if ($realPath === false) {
                throw new RuntimeException('Unable to resolve the requested path.');
            }

            return $realPath;
        }

        if (! $allowMissing) {
            throw new RuntimeException('Path not found.');
        }

        return $normalized;
    }

    private function preferredBrowseRoot(): string
    {
        $homeDirectory = $this->normalizeAbsolutePath((string) config('agent.home_dir', '/tmp/laraclaw'));

        if (is_dir($homeDirectory)) {
            foreach ($this->allowedPaths as $allowedPath) {
                if ($homeDirectory === $allowedPath || str_starts_with($homeDirectory, $allowedPath.'/')) {
                    return $homeDirectory;
                }
            }
        }

        return $this->workingDir;
    }

    /**
     * @return array<int, string>
     */
    private function searchRoots(): array
    {
        return array_values(array_filter(
            array_unique([
                $this->preferredBrowseRoot(),
                ...$this->allowedPaths,
            ]),
            fn (string $path): bool => is_dir($path),
        ));
    }

    private function guard(string $path): void
    {
        $blockedRoots = ['/etc', '/sys', '/proc', '/boot', '/root', '/dev', '/run', '/snap'];

        foreach ($blockedRoots as $blockedRoot) {
            if ($path === $blockedRoot || str_starts_with($path, $blockedRoot.'/')) {
                throw new RuntimeException("Access denied for protected path [{$blockedRoot}].");
            }
        }

        foreach ($this->allowedPaths as $allowedPath) {
            if ($path === $allowedPath || str_starts_with($path, $allowedPath.'/')) {
                return;
            }
        }

        throw new RuntimeException('Access denied. The requested path is outside the allowed agent paths.');
    }

    private function normalizeAbsolutePath(string $path): string
    {
        $segments = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        return '/'.implode('/', $segments);
    }

    private function formatEntry(string $path): string
    {
        if (is_dir($path)) {
            return "[dir] {$path}";
        }

        return '[file] '.$path.' ('.$this->humanFileSize(filesize($path) ?: 0).')';
    }

    private function humanFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $index = 0;
        $size = (float) $bytes;

        while ($size >= 1024 && $index < count($units) - 1) {
            $size /= 1024;
            $index++;
        }

        return round($size, 2).' '.$units[$index];
    }
}
