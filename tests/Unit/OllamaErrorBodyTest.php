<?php

namespace Tests\Unit;

use App\Services\Agent\OllamaService;
use ReflectionMethod;
use Tests\TestCase;

class OllamaErrorBodyTest extends TestCase
{
    private function describe(string $body): string
    {
        $service = new OllamaService;

        return (new ReflectionMethod($service, 'describeErrorBody'))->invoke($service, $body);
    }

    public function test_it_surfaces_the_ollama_error_field(): void
    {
        $body = json_encode(['error' => "model 'gemma4:cloud' requires sign-in"]);

        $this->assertSame(": model 'gemma4:cloud' requires sign-in", $this->describe($body));
    }

    public function test_it_falls_back_to_flattened_text_when_not_json(): void
    {
        $this->assertSame(': Gone', $this->describe("Gone\n"));
    }

    public function test_it_reports_a_bare_period_when_the_body_is_empty(): void
    {
        $this->assertSame('.', $this->describe(''));
        $this->assertSame('.', $this->describe("   \n "));
    }

    public function test_it_truncates_very_long_bodies(): void
    {
        $described = $this->describe(str_repeat('x', 5000));

        $this->assertLessThanOrEqual(302, mb_strlen($described));
    }
}
