<?php

declare(strict_types=1);

namespace Modules\Ai\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Modules\Ai\Livewire\Advisor\Configure as AdvisorConfigure;
use Modules\Ai\Livewire\Advisor\Form as AdvisorForm;
use Modules\Ai\Livewire\Advisor\Index as AdvisorIndex;
use Modules\Ai\Livewire\Knowledge\Index as KnowledgeIndex;
use Modules\Ai\Models\KnowledgeSource;
use Modules\Ai\Policies\KnowledgeSourcePolicy;
use Modules\Ai\Services\AiChatClient;
use Modules\Ai\Services\AiUsageRecorder;
use Modules\Ai\Services\OpenAiCompatibleChatClient;
use Modules\Ai\Services\RecordingAiChatClient;
use Nwidart\Modules\Support\ModuleServiceProvider;

class AiServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Ai';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'ai';

    /**
     * Registra los bindings del modulo. El cliente de chat es agnostico: por
     * defecto el adaptador OpenAI-compatible (Qwen/OpenAI/DeepSeek/Kimi). En las
     * pruebas se sustituye por un doble para NO llamar al proveedor real.
     */
    public function register(): void
    {
        parent::register();

        // El cliente que reciben los consumidores es el DECORADOR: envuelve al
        // transporte real y añade telemetría (ai_usage_events) para toda la IA del CRM.
        $this->app->bind(AiChatClient::class, function ($app): RecordingAiChatClient {
            return new RecordingAiChatClient(
                $app->make(OpenAiCompatibleChatClient::class),
                $app->make(AiUsageRecorder::class),
            );
        });
    }

    /**
     * Autorizacion y componentes del panel del modulo.
     */
    public function boot(): void
    {
        parent::boot();

        Gate::policy(KnowledgeSource::class, KnowledgeSourcePolicy::class);

        Livewire::component('ai.knowledge.index', KnowledgeIndex::class);
        Livewire::component('ai.advisor.configure', AdvisorConfigure::class);
        Livewire::component('ai.advisor.index', AdvisorIndex::class);
        Livewire::component('ai.advisor.form', AdvisorForm::class);
    }

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    // protected array $commands = [];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    /**
     * Define module schedules.
     *
     * @param  $schedule
     */
    // protected function configureSchedules(Schedule $schedule): void
    // {
    //     $schedule->command('inspire')->hourly();
    // }
}
