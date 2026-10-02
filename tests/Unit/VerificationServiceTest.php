<?php

namespace Tests\Unit;

use App\Models\Task;
use App\Services\TaskEngine\VerificationService;
use Tests\TestCase;

/**
 * The real verifier had no coverage — every task-engine test mocks it. Its
 * path-extraction regex did not compile, so any criterion containing
 * file/exist/present/created raised an ErrorException at verification time and
 * killed every task that used one.
 */
class VerificationServiceTest extends TestCase
{
    private VerificationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new VerificationService;
    }

    /**
     * @return array<int, string>
     */
    private function runCheck(string $criterion): array
    {
        return (new \ReflectionMethod($this->service, 'runCheck'))
            ->invoke($this->service, new Task(['goal' => 'probe']), $criterion);
    }

    public function test_it_extracts_backticked_paths_with_extensions(): void
    {
        $check = $this->runCheck('The file `/tmp/laraclaw/report.md` exists.');

        $this->assertSame('Required files', $check['name']);
        $this->assertSame('failed', $check['status']);
        $this->assertStringContainsString('/tmp/laraclaw/report.md', $check['details']);
    }

    public function test_it_extracts_double_and_single_quoted_paths(): void
    {
        foreach (['"/tmp/laraclaw/a.json"', "'/tmp/laraclaw/b.txt'"] as $quoted) {
            $check = $this->runCheck("The file {$quoted} exists.");

            $this->assertSame('Required files', $check['name'], "Failed for {$quoted}");
            $this->assertStringContainsString(trim($quoted, "\"'"), $check['details']);
        }
    }

    public function test_it_reports_passing_when_every_extracted_path_exists(): void
    {
        $path = '/tmp/laraclaw/verify-fixture.md';
        file_put_contents($path, '# fixture');

        try {
            $check = $this->runCheck("The file `{$path}` exists.");

            $this->assertSame('passed', $check['status']);
        } finally {
            @unlink($path);
        }
    }

    public function test_it_does_not_trip_when_a_criterion_contains_no_path(): void
    {
        $check = $this->runCheck('Every registered agent tool is named in the report.');

        $this->assertNotSame('Required files', $check['name']);
        $this->assertSame('passed', $check['status']);
    }

    public function test_it_never_treats_an_extensionless_phrase_as_a_path(): void
    {
        $check = $this->runCheck('The config value in `some value` exists.');

        $this->assertNotSame('Required files', $check['name']);
    }

    public function test_it_reports_every_missing_path(): void
    {
        $check = $this->runCheck('The file `/tmp/laraclaw/nope-one.md` and `/tmp/laraclaw/nope-two.txt` exist.');

        $this->assertSame('failed', $check['status']);
        $this->assertStringContainsString('nope-one.md', $check['details']);
        $this->assertStringContainsString('nope-two.txt', $check['details']);
    }
}
