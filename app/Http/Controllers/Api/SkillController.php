<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Skill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SkillController extends Controller
{
    public function index(): JsonResponse
    {
        $skills = Skill::with('scripts')
            ->orderByDesc('usage_count')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $skills]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:skills,name'],
            'description' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'in:'.implode(',', Skill::CATEGORIES)],
            'instructions' => ['required', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $skill = Skill::create([...$validated, 'created_by' => 'user']);

        return response()->json(['data' => $skill], 201);
    }

    public function update(Request $request, Skill $skill): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100', 'unique:skills,name,'.$skill->id],
            'description' => ['sometimes', 'string', 'max:255'],
            'category' => ['sometimes', 'string', 'in:'.implode(',', Skill::CATEGORIES)],
            'instructions' => ['sometimes', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $skill->update($validated);

        return response()->json(['data' => $skill]);
    }

    public function destroy(Skill $skill): JsonResponse
    {
        $skill->delete();

        return response()->json(null, 204);
    }
}
