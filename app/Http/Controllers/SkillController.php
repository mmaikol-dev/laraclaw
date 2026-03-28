<?php

namespace App\Http\Controllers;

use App\Models\Skill;
use Inertia\Inertia;
use Inertia\Response;

class SkillController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('skills/index', [
            'skills' => Skill::with('scripts')->orderByDesc('usage_count')->orderBy('name')->get(),
            'categories' => Skill::CATEGORIES,
        ]);
    }
}
