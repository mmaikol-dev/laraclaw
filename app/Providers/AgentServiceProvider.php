<?php

namespace App\Providers;

use App\Services\Agent\AgentRunState;
use App\Services\Agent\AgentService;
use App\Services\Agent\OllamaService;
use App\Services\Agent\ToolRegistry;
use App\Services\Embedding\EmbeddingService;
use App\Services\Embedding\VectorStore;
use App\Services\Tools\BrowserTool;
use App\Services\Tools\DocumentTool;
use App\Services\Tools\FileTool;
use App\Services\Tools\ShellTool;
use App\Services\Tools\SkillTool;
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
        $this->app->singleton(VectorStore::class, fn (): VectorStore => new VectorStore);
        $this->app->singleton(
            EmbeddingService::class,
            fn ($app): EmbeddingService => new EmbeddingService(
                $app->make(OllamaService::class),
                $app->make(VectorStore::class),
            ),
        );
        $this->app->singleton(ToolRegistry::class, function ($app): ToolRegistry {
            $registry = new ToolRegistry;
            $registry->register(new FileTool);
            $registry->register(new ShellTool);
            $registry->register(new WebTool);
            $registry->register(new BrowserTool);
            $registry->register(new DocumentTool($app->make(EmbeddingService::class)));
            $registry->register(new SkillTool);

            return $registry;
        });
        $this->app->singleton(
            AgentService::class,
            fn ($app): AgentService => new AgentService(
                $app->make(OllamaService::class),
                $app->make(ToolRegistry::class),
                $app->make(AgentRunState::class),
            ),
        );
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
