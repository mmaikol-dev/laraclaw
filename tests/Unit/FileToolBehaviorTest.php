<?php

namespace Tests\Unit;

use App\Services\Tools\FileTool;
use ReflectionClass;
use Tests\TestCase;

class FileToolBehaviorTest extends TestCase
{
    public function test_search_without_a_path_scans_all_allowed_roots(): void
    {
        $workspaceRoot = sys_get_temp_dir().'/laraclaw-file-tool-workspace';
        $homeRoot = sys_get_temp_dir().'/laraclaw-file-tool-home';

        @mkdir($workspaceRoot, 0777, true);
        @mkdir($homeRoot, 0777, true);
        file_put_contents($homeRoot.'/test_vision.py', 'print("vision")');

        config()->set('agent.home_dir', $homeRoot);

        $tool = new FileTool();
        $reflection = new ReflectionClass($tool);

        $workingDir = $reflection->getProperty('workingDir');
        $workingDir->setValue($tool, $workspaceRoot);

        $allowedPaths = $reflection->getProperty('allowedPaths');
        $allowedPaths->setValue($tool, [$workspaceRoot, $homeRoot]);

        $search = $reflection->getMethod('search');
        $result = $search->invoke($tool, '', 'test_vision.py', true);

        $this->assertStringContainsString($homeRoot.'/test_vision.py', $result);
    }

    public function test_list_without_a_path_prefers_the_allowed_home_directory(): void
    {
        $workspaceRoot = sys_get_temp_dir().'/laraclaw-file-tool-workspace-list';
        $homeRoot = sys_get_temp_dir().'/laraclaw-file-tool-home-list';

        @mkdir($workspaceRoot, 0777, true);
        @mkdir($homeRoot, 0777, true);
        @mkdir($homeRoot.'/Documents', 0777, true);

        config()->set('agent.home_dir', $homeRoot);

        $tool = new FileTool();
        $reflection = new ReflectionClass($tool);

        $workingDir = $reflection->getProperty('workingDir');
        $workingDir->setValue($tool, $workspaceRoot);

        $allowedPaths = $reflection->getProperty('allowedPaths');
        $allowedPaths->setValue($tool, [$workspaceRoot, $homeRoot]);

        $list = $reflection->getMethod('list');
        $result = $list->invoke($tool, '', false);

        $this->assertStringContainsString('[dir] '.$homeRoot.'/Documents', $result);
    }
}
