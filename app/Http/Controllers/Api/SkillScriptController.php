<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Skill;
use App\Models\SkillScript;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SkillScriptController extends Controller
{
    public function store(Request $request, Skill $skill): JsonResponse
    {
        $validated = $request->validate([
            'filename' => ['required', 'string', 'max:255', 'unique:skill_scripts,filename,NULL,id,skill_id,'.$skill->id],
            'description' => ['sometimes', 'string', 'max:255'],
            'content' => ['required', 'string'],
        ]);

        $script = $skill->scripts()->create($validated);

        return response()->json(['data' => $script], 201);
    }

    public function update(Request $request, Skill $skill, SkillScript $script): JsonResponse
    {
        abort_if($script->skill_id !== $skill->id, 404);

        $validated = $request->validate([
            'filename' => ['sometimes', 'string', 'max:255', 'unique:skill_scripts,filename,'.$script->id.',id,skill_id,'.$skill->id],
            'description' => ['sometimes', 'string', 'max:255'],
            'content' => ['sometimes', 'string'],
        ]);

        $script->update($validated);

        return response()->json(['data' => $script]);
    }

    public function destroy(Skill $skill, SkillScript $script): JsonResponse
    {
        abort_if($script->skill_id !== $skill->id, 404);

        $script->delete();

        return response()->json(null, 204);
    }
}
