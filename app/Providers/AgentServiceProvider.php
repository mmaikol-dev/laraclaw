<?php

namespace App\Providers;

use App\Services\Agent\AffectiveStateEngine;
use App\Services\Agent\AffectiveStateStore;
use App\Services\Agent\AgentIdentityService;
use App\Services\Agent\AgentRunState;
use App\Services\Agent\AgentService;
use App\Services\Agent\GoalOwnershipService;
use App\Services\Agent\MissionService;
use App\Services\Agent\OllamaService;
use App\Services\Agent\OpenCodeDelegationGuard;
use App\Services\Agent\OpenCodeService;
use App\Services\Agent\ProactiveMonitoringService;
use App\Services\Agent\RoleProfileService;
use App\Services\Agent\ToolRegistry;
use App\Services\Embedding\EmbeddingService;
use App\Services\Embedding\VectorStore;
use App\Services\TaskEngine\FailureClassifier;
use App\Services\TaskEngine\LoopDetector;
use App\Services\TaskEngine\ModelRouter;
use App\Services\TaskEngine\SpecialistAgents;
use App\Services\TaskEngine\TaskContext;
use App\Services\TaskEngine\TaskEngine;
use App\Services\TaskEngine\TaskSupervisor;
use App\Services\TaskEngine\VerificationService;
use App\Services\Tools\BrowserTool;
use App\Services\Tools\DocumentTool;
use App\Services\Tools\FileTool;
use App\Services\Tools\GoogleSheetsTool;
use App\Services\Tools\MemoryTool;
use App\Services\Tools\MissionTool;
use App\Services\Tools\OpenCodeTool;
use App\Services\Tools\ProjectTool;
use App\Services\Tools\ScheduledTaskTool;
use App\Services\Tools\ShellTool;
use App\Services\Tools\SkillTool;
use App\Services\Tools\TaskEngineTool;
use App\Services\Tools\TriggerTool;
use App\Services\Tools\WebTool;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\ServiceProvider;

class AgentServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(OllamaService::class, fn (): OllamaService => new OllamaService);
        $this->app->singleton(
            AgentRunState::class,
            fn ($app): AgentRunState => new AgentRunState($app->make(CacheFactory::class)->store('file')),
        );
        $this->app->singleton(
            AffectiveStateStore::class,
            fn ($app): AffectiveStateStore => new AffectiveStateStore($app->make(CacheFactory::class)->store('file')),
        );
        $this->app->singleton(
            AffectiveStateEngine::class,
            fn ($app): AffectiveStateEngine => new AffectiveStateEngine($app->make(AffectiveStateStore::class)),
        );
        $this->app->singleton(GoalOwnershipService::class, fn (): GoalOwnershipService => new GoalOwnershipService);
        $this->app->singleton(RoleProfileService::class, fn (): RoleProfileService => new RoleProfileService);
        $this->app->singleton(ProactiveMonitoringService::class, fn (): ProactiveMonitoringService => new ProactiveMonitoringService);
        $this->app->singleton(AgentIdentityService::class, fn (): AgentIdentityService => new AgentIdentityService);
        $this->app->singleton(VectorStore::class, fn (): VectorStore => new VectorStore);
        $this->app->singleton(
            EmbeddingService::class,
            fn ($app): EmbeddingService => new EmbeddingService(
                $app->make(OllamaService::class),
                $app->make(VectorStore::class),
            ),
        );
        $this->app->singleton(OpenCodeDelegationGuard::class, fn (): OpenCodeDelegationGuard => new OpenCodeDelegationGuard);
        $this->app->singleton(OpenCodeService::class, fn (): OpenCodeService => new OpenCodeService);
        $this->app->singleton(ToolRegistry::class, function ($app): ToolRegistry {
            $registry = new ToolRegistry($app->make(OpenCodeDelegationGuard::class));
            $registry->register(new FileTool);
            $registry->register(new ShellTool);
            $registry->register(new WebTool);
            $registry->register(new GoogleSheetsTool);
            $registry->register(new BrowserTool);
            $registry->register(new DocumentTool($app->make(EmbeddingService::class)));
            $registry->register(new SkillTool);
            $registry->register(new MemoryTool);
            $registry->register(new ScheduledTaskTool);
            $registry->register(new ProjectTool);
            $registry->register(new TriggerTool);
            $registry->register(new OpenCodeTool($app->make(OpenCodeService::class)));
            $registry->register(new MissionTool($app->make(MissionService::class)));
            $registry->register(new TaskEngineTool($app));

            return $registry;
        });
        $this->app->singleton(
            AgentService::class,
            fn ($app): AgentService => new AgentService(
                $app->make(OllamaService::class),
                $app->make(ToolRegistry::class),
                $app->make(AgentRunState::class),
                $app->make(AffectiveStateEngine::class),
                $app->make(GoalOwnershipService::class),
                $app->make(RoleProfileService::class),
                $app->make(ProactiveMonitoringService::class),
                $app->make(AgentIdentityService::class),
            ),
        );

        // Task engine services
        $this->app->singleton(ModelRouter::class);
        $this->app->singleton(FailureClassifier::class);
        $this->app->singleton(LoopDetector::class);
        $this->app->singleton(VerificationService::class);
        $this->app->singleton(SpecialistAgents::class, fn (): SpecialistAgents => new SpecialistAgents);
        $this->app->singleton(TaskContext::class, fn (): TaskContext => new TaskContext);
        $this->app->singleton(TaskEngine::class, fn ($app): TaskEngine => new TaskEngine(
            $app->make(ToolRegistry::class),
            $app->make(ModelRouter::class),
            $app->make(FailureClassifier::class),
            $app->make(LoopDetector::class),
            $app->make(VerificationService::class),
            $app->make(AgentService::class),
            $app->make(SpecialistAgents::class),
            $app->make(TaskContext::class),
        ));
        $this->app->singleton(TaskSupervisor::class, fn ($app): TaskSupervisor => new TaskSupervisor(
            $app->make(TaskEngine::class),
        ));
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
