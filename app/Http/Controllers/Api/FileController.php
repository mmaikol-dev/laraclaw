<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FileBrowseRequest;
use App\Http\Requests\FileCreateRequest;
use App\Http\Requests\FileDeleteRequest;
use App\Http\Requests\FileDirectoryRequest;
use App\Http\Requests\FileMoveRequest;
use App\Http\Requests\FileReadRequest;
use App\Http\Requests\FileWriteRequest;
use App\Models\AgentSetting;
use FilesystemIterator;
use Illuminate\Http\JsonResponse;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

class FileController extends Controller
{
    /**
     * @var array<int, string>
     */
    private array $blockedRoots = ['/etc', '/sys', '/proc', '/boot', '/root', '/dev', '/run', '/snap'];

    public function browse(FileBrowseRequest $request): JsonResponse
    {
        $path = $request->validated('path') ?: $this->workingDirectory();
        $resolvedPath = $this->resolvePath($path);
        $this->guard($resolvedPath);

        if (! is_dir($resolvedPath)) {
            throw new RuntimeException('Directory not found.');
        }

        $items = collect(scandir($resolvedPath) ?: [])
            ->reject(fn (string $entry): bool => in_array($entry, ['.', '..'], true))
            ->map(function (string $entry) use ($resolvedPath): array {
                $itemPath = $resolvedPath.DIRECTORY_SEPARATOR.$entry;
                $isDirectory = is_dir($itemPath);

                return [
                    'name' => $entry,
                    'path' => $itemPath,
                    'type' => $isDirectory ? 'directory' : 'file',
                    'size' => $isDirectory ? null : (filesize($itemPath) ?: 0),
                    'extension' => $isDirectory ? null : pathinfo($itemPath, PATHINFO_EXTENSION),
                    'modified_at' => date(DATE_ATOM, filemtime($itemPath) ?: time()),
                ];
            })
            ->sort(function (array $left, array $right): int {
                if ($left['type'] !== $right['type']) {
                    return $left['type'] === 'directory' ? -1 : 1;
                }

                return strcasecmp($left['name'], $right['name']);
            })
            ->values()
            ->all();

        return response()->json([
            'path' => $resolvedPath,
            'parent' => $resolvedPath !== '/' ? dirname($resolvedPath) : null,
            'items' => $items,
        ]);
    }

    public function read(FileReadRequest $request): JsonResponse
    {
        $path = $this->resolvePath($request->validated('path'));
        $this->guard($path);

        if (! is_file($path)) {
            throw new RuntimeException('File not found.');
        }

        $size = filesize($path) ?: 0;
        $limit = (int) AgentSetting::get('max_file_size_mb', 10) * 1024 * 1024;

        if ($size > $limit) {
            throw new RuntimeException('File exceeds the configured size limit.');
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new RuntimeException('Unable to read file.');
        }

        return response()->json([
            'path' => $path,
            'content' => $content,
            'extension' => pathinfo($path, PATHINFO_EXTENSION),
            'size' => $size,
        ]);
    }

    public function write(FileWriteRequest $request): JsonResponse
    {
        $path = $this->resolvePath($request->validated('path'), true);
        $this->guard($path);
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create the destination directory.');
        }

        file_put_contents($path, $request->validated('content'));

        return response()->json([
            'status' => 'saved',
            'path' => $path,
        ]);
    }

    public function createFile(FileCreateRequest $request): JsonResponse
    {
        $path = $this->resolvePath($request->validated('path'), true);
        $this->guard($path);

        if (file_exists($path)) {
            throw new RuntimeException('File already exists.');
        }

        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create the destination directory.');
        }

        file_put_contents($path, $request->validated('content') ?? '');

        return response()->json([
            'status' => 'created',
            'path' => $path,
        ], 201);
    }

    public function createDirectory(FileDirectoryRequest $request): JsonResponse
    {
        $path = $this->resolvePath($request->validated('path'), true);
        $this->guard($path);

        if (! is_dir($path) && ! mkdir($path, 0775, true) && ! is_dir($path)) {
            throw new RuntimeException('Unable to create directory.');
        }

        return response()->json([
            'status' => 'created',
            'path' => $path,
        ], 201);
    }

    public function move(FileMoveRequest $request): JsonResponse
    {
        $path = $this->resolvePath($request->validated('path'));
        $destination = $this->resolvePath($request->validated('destination'), true);
        $this->guard($path);
        $this->guard($destination);

        $directory = dirname($destination);

        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create the destination directory.');
        }

        rename($path, $destination);

        return response()->json([
            'status' => 'moved',
            'path' => $destination,
        ]);
    }

    public function delete(FileDeleteRequest $request): JsonResponse
    {
        $path = $this->resolvePath($request->validated('path'));
        $this->guard($path);

        if (is_dir($path)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );

            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }

            rmdir($path);
        } elseif (is_file($path)) {
            unlink($path);
        } else {
            throw new RuntimeException('Path not found.');
        }

        return response()->json([
            'status' => 'deleted',
            'path' => $path,
        ]);
    }

    private function resolvePath(string $path, bool $allowMissing = false): string
    {
        $absolute = str_starts_with($path, '/')
            ? $path
            : $this->workingDirectory().DIRECTORY_SEPARATOR.$path;

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

    private function guard(string $path): void
    {
        foreach ($this->blockedRoots as $blockedRoot) {
            if ($path === $blockedRoot || str_starts_with($path, $blockedRoot.'/')) {
                throw new RuntimeException("Access denied for protected path [{$blockedRoot}].");
            }
        }

        foreach ($this->allowedPaths() as $allowedPath) {
            if ($path === $allowedPath || str_starts_with($path, $allowedPath.'/')) {
                return;
            }
        }

        throw new RuntimeException('Access denied. The requested path is outside the allowed agent paths.');
    }

    /**
     * @return array<int, string>
     */
    private function allowedPaths(): array
    {
        $configuredPaths = array_filter(array_map(
            'trim',
            explode(',', (string) (AgentSetting::get('allowed_paths') ?: config('agent.allowed_paths', ''))),
        ));

        return array_values(array_unique([
            $this->normalizeAbsolutePath($this->workingDirectory()),
            $this->normalizeAbsolutePath('/tmp/laraclaw'),
            $this->normalizeAbsolutePath((string) config('agent.home_dir', '/tmp/laraclaw')),
            ...array_map(fn (string $path): string => $this->normalizeAbsolutePath($path), $configuredPaths),
        ]));
    }

    private function workingDirectory(): string
    {
        return $this->normalizeAbsolutePath((string) AgentSetting::get('working_dir', config('agent.working_dir', '/tmp/laraclaw')));
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
}
