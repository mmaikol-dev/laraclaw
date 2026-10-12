<?php

namespace App\Http\Controllers;

use App\Models\Mission;
use App\Services\Agent\OpenCodeService;
use Inertia\Inertia;
use Inertia\Response;

class MissionController extends Controller
{
    public function __construct(private readonly OpenCodeService $openCode) {}

    public function index(): Response
    {
        $missions = Mission::with(['features', 'handoffs' => fn ($q) => $q->take(5)])
            ->orderByRaw("FIELD(status,'active','scoping','paused','completed','failed')")
            ->orderBy('name')
            ->get();

        return Inertia::render('missions/index', [
            'missions' => $missions,
            // Lazy props: the page polls `missions` every few seconds, and
            // resolving the OpenCode model list spawns a process. Deferring
            // them to closures keeps those process spawns off the poll path.
            'opencodeAvailable' => fn (): bool => $this->openCode->isAvailable(),
            'opencodeModels' => fn (): array => $this->openCodeModels(),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function openCodeModels(): array
    {
        try {
            if ($this->openCode->isAvailable()) {
                return $this->openCode->models();
            }
        } catch (\Throwable) {
            // OpenCode is optional; a missing or broken binary must not break
            // the missions page.
        }

        return [];
    }
}
