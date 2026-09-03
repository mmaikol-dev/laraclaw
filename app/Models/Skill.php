<?php

namespace App\Models;

use Database\Factories\SkillFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Skill extends Model
{
    /** @use HasFactory<SkillFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'description',
        'category',
        'instructions',
        'is_active',
        'created_by',
        'usage_count',
        'version',
        'dependencies',
        'template',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'is_active' => 'boolean',
        'usage_count' => 'integer',
        'version' => 'integer',
        'dependencies' => 'array',
    ];

    public const CATEGORIES = [
        'coding',
        'research',
        'writing',
        'system',
        'data',
        'analysis',
        'communication',
        'general',
    ];

    public const TEMPLATES = [
        'web-scraper' => [
            'category' => 'research',
            'description' => 'Scrape and extract structured data from websites.',
            'instructions' => "Use the browser tool to navigate to the target URL.\nIdentify the data structure (table, list, cards).\nExtract the required fields using get_text or evaluate.\nReturn data as a structured list or JSON.",
        ],
        'code-reviewer' => [
            'category' => 'coding',
            'description' => 'Review code for bugs, style issues, and improvements.',
            'instructions' => "Read the target file(s) using the shell tool.\nCheck for: syntax errors, logic bugs, security issues, performance problems.\nSuggest specific improvements with line references.\nProvide a summary score (1–10) with reasoning.",
        ],
        'file-organiser' => [
            'category' => 'system',
            'description' => 'Organise files in a directory by type or date.',
            'instructions' => "List files in the target directory with the shell tool.\nGroup by extension or date modified.\nCreate subdirectories as needed.\nMove files using mv commands.\nReport a summary of changes made.",
        ],
        'report-writer' => [
            'category' => 'writing',
            'description' => 'Generate a structured report from data or research.',
            'instructions' => "Gather the required data using available tools.\nOrganise into sections: Summary, Findings, Details, Recommendations.\nUse clear headings and bullet points.\nKeep the tone professional and concise.",
        ],
        'data-analyser' => [
            'category' => 'data',
            'description' => 'Analyse a dataset and surface key insights.',
            'instructions' => "Load the dataset using the shell tool (cat, head, wc -l).\nIdentify columns, types, and row count.\nCalculate basic stats: min, max, mean, nulls.\nHighlight anomalies or interesting patterns.\nSummarise findings in plain language.",
        ],
    ];

    public function scripts(): HasMany
    {
        return $this->hasMany(SkillScript::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SkillVersion::class)->orderByDesc('version');
    }

    public function incrementUsage(): void
    {
        $this->increment('usage_count');
    }

    public function snapshotVersion(string $changedBy = 'agent', ?string $changeNote = null): void
    {
        $this->versions()->create([
            'version' => $this->version,
            'instructions' => $this->instructions,
            'description' => $this->description,
            'changed_by' => $changedBy,
            'change_note' => $changeNote,
        ]);
    }
}
