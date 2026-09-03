<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\FileController;
use App\Http\Requests\FileBrowseRequest;
use App\Http\Requests\FileCreateRequest;
use App\Http\Requests\FileDeleteRequest;
use App\Http\Requests\FileDirectoryRequest;
use App\Http\Requests\FileMoveRequest;
use App\Http\Requests\FileReadRequest;
use App\Http\Requests\FileWriteRequest;
use PHPUnit\Framework\TestCase;

class FileApiContractTest extends TestCase
{
    public function test_file_requests_are_authorized_and_define_expected_rules(): void
    {
        $this->assertTrue((new FileBrowseRequest())->authorize());
        $this->assertSame(['path' => ['nullable', 'string', 'max:1000']], (new FileBrowseRequest())->rules());
        $this->assertSame(['path' => ['required', 'string', 'max:1000']], (new FileReadRequest())->rules());
        $this->assertArrayHasKey('content', (new FileWriteRequest())->rules());
        $this->assertArrayHasKey('destination', (new FileMoveRequest())->rules());
        $this->assertArrayHasKey('path', (new FileDeleteRequest())->rules());
        $this->assertArrayHasKey('path', (new FileCreateRequest())->rules());
        $this->assertArrayHasKey('path', (new FileDirectoryRequest())->rules());
    }

    public function test_file_controller_exposes_expected_methods(): void
    {
        $controller = new FileController();

        $this->assertTrue(method_exists($controller, 'browse'));
        $this->assertTrue(method_exists($controller, 'read'));
        $this->assertTrue(method_exists($controller, 'write'));
        $this->assertTrue(method_exists($controller, 'createFile'));
        $this->assertTrue(method_exists($controller, 'createDirectory'));
        $this->assertTrue(method_exists($controller, 'move'));
        $this->assertTrue(method_exists($controller, 'delete'));
    }
}
