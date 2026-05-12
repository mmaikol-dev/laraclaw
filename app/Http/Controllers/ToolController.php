<?php

namespace App\Http\Controllers;

use App\Services\Agent\ToolRegistry;
use App\Services\Tools\BaseTool;
use Inertia\Inertia;
use Inertia\Response;

class ToolController extends Controller
{
    public function index(ToolRegistry $tools): Response
    {
        return Inertia::render('tools/index', [
            'tools' => collect($tools->all())
                ->sortKeys()
                ->map(fn (BaseTool $tool): array => $this->toolPayload($tool))
                ->values()
                ->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function toolPayload(BaseTool $tool): array
    {
        $parameters = $tool->getParameters();
        $properties = collect($parameters['properties'] ?? []);
        $requiredFields = collect($parameters['required'] ?? [])->map(fn (mixed $field): string => (string) $field);
        $actionDefinition = $properties->get('action');
        $actions = collect($actionDefinition['enum'] ?? [])
            ->map(fn (mixed $action): string => (string) $action)
            ->values()
            ->all();

        return [
            'name' => $tool->getName(),
            'description' => $tool->getDescription(),
            'enabled' => $tool->isEnabled(),
            'actions' => $actions,
            'required_fields' => $requiredFields->values()->all(),
            'parameter_count' => $properties->count(),
            'parameters' => $properties
                ->map(fn (mixed $definition, string $name): array => [
                    'name' => $name,
                    'type' => (string) ($definition['type'] ?? 'mixed'),
                    'description' => (string) ($definition['description'] ?? ''),
                    'required' => $requiredFields->contains($name),
                    'enum' => collect($definition['enum'] ?? [])
                        ->map(fn (mixed $value): string => (string) $value)
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
        ];
    }
}
