<?php

namespace App\Services\Tools;

abstract class BaseTool
{
    abstract public function getName(): string;

    abstract public function getDescription(): string;

    /**
     * @return array<string, mixed>
     */
    abstract public function getParameters(): array;

    /**
     * @param  array<string, mixed>  $arguments
     */
    abstract public function execute(array $arguments): string;

    public function isEnabled(): bool
    {
        return true;
    }

    public function truncate(string $output, int $maxLines = 500): string
    {
        $lines = preg_split("/\r\n|\n|\r/", $output) ?: [];

        if (count($lines) <= $maxLines) {
            return $output;
        }

        $visibleLines = array_slice($lines, 0, $maxLines);
        $hiddenLines = count($lines) - $maxLines;

        return implode("\n", $visibleLines)."\n\n[Output truncated: {$hiddenLines} additional lines omitted]";
    }
}
