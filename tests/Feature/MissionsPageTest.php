<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Mission;
use App\Models\User;
use App\Services\Agent\OpenCodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * The missions page polls its `missions` prop for live updates. Resolving the
 * OpenCode model list spawns a process, so those props are lazy closures that
 * must not be resolved during a partial reload.
 */
class MissionsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_renders_missions_with_the_opencode_props(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        Mission::factory()->create(['name' => 'Office Team Demo', 'status' => 'active']);

        $openCode = Mockery::mock(OpenCodeService::class);
        $openCode->shouldReceive('isAvailable')->andReturnTrue();
        $openCode->shouldReceive('models')->once()->andReturn(['opencode/test-model']);
        $this->app->instance(OpenCodeService::class, $openCode);

        $this->actingAs($user)
            ->get('/missions')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('missions/index')
                ->has('missions', 1)
                ->where('missions.0.name', 'Office Team Demo')
                ->where('opencodeAvailable', true)
                ->where('opencodeModels', ['opencode/test-model'])
            );
    }

    public function test_partial_reload_of_missions_skips_the_opencode_props(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        Mission::factory()->create();

        $openCode = Mockery::mock(OpenCodeService::class);
        $openCode->shouldNotReceive('isAvailable');
        $openCode->shouldNotReceive('models');
        $this->app->instance(OpenCodeService::class, $openCode);

        $this->actingAs($user)
            ->get('/missions', [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
                'X-Inertia-Partial-Component' => 'missions/index',
                'X-Inertia-Partial-Data' => 'missions',
            ])
            ->assertOk()
            ->assertJsonPath('component', 'missions/index')
            ->assertJsonCount(1, 'props.missions')
            ->assertJsonMissingPath('props.opencodeModels')
            ->assertJsonMissingPath('props.opencodeAvailable');
    }
}
