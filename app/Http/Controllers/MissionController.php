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

        $models = [];

        try {
            if ($this->openCode->isAvailable()) {
                $models = $this->openCode->models();
            }
        } catch (\Throwable) {
            $models = [];
        }

        return Inertia::render('missions/index', [
            'missions' => $missions,
            'opencodeAvailable' => $this->openCode->isAvailable(),
            'opencodeModels' => $models,
        ]);
    }
}
