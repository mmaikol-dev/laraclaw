<?php

namespace Tests\Unit;

use App\Services\Tools\ShellTool;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ShellToolQuotingTest extends TestCase
{
    public static function unbalancedQuoteProvider(): array
    {
        return [
            'unclosed single quote' => ["find . -name '*.php"],
            'unclosed double quote' => ['echo "unterminated'],
            'unclosed backtick' => ['echo `ls'],
            'stray closing quote' => ["echo value'"],
        ];
    }

    #[DataProvider('unbalancedQuoteProvider')]
    public function test_it_rejects_unbalanced_quotes_with_clean_error(string $command): void
    {
        $tool = new ShellTool;

        try {
            $tool->execute(['command' => $command, 'timeout' => 5]);
            $this->fail('Expected RuntimeException for unbalanced quotes.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('quotes are unbalanced', $exception->getMessage());
            $this->assertStringContainsString('rewrite the command', $exception->getMessage());
        }
    }

    public function test_it_rejects_unbalanced_quotes_in_streaming_mode(): void
    {
        $tool = new ShellTool;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('quotes are unbalanced');

        $tool->executeStreaming(['command' => 'echo "unclosed'], fn (): string => '');
    }

    public function test_it_allows_balanced_quotes(): void
    {
        $tool = new ShellTool;

        $output = $tool->execute(['command' => "echo 'single' \"double\"", 'timeout' => 5]);

        $this->assertStringContainsString('single', $output);
        $this->assertStringContainsString('double', $output);
    }

    public function test_it_treats_quote_inside_double_quotes_as_literal(): void
    {
        $tool = new ShellTool;

        $output = $tool->execute(['command' => "echo \"it's fine\"", 'timeout' => 5]);

        $this->assertStringContainsString("it's fine", $output);
    }

    public function test_it_allows_escaped_quote_outside_quoting(): void
    {
        $tool = new ShellTool;

        $output = $tool->execute(['command' => "echo it\\'s fine", 'timeout' => 5]);

        $this->assertStringContainsString("it's fine", $output);
    }
}
