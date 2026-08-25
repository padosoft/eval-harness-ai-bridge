<?php

declare(strict_types=1);

namespace Padosoft\EvalHarnessAiBridge;

use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Events\StepFailed;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Events\ToolInvoked;
use Padosoft\EvalHarnessAiBridge\Trajectories\RunTrajectoryRecorder;

/**
 * Registers the run-event listeners that let a trajectory be built from what
 * actually happened rather than from what came back.
 *
 * Auto-discovered. Everything it registers is guarded on `laravel/ai` being
 * installed and on the 0.11 event classes existing, so the package keeps working
 * exactly as before on an older SDK — the response mapper is unaffected.
 */
class EvalHarnessAiBridgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singleton: the recorder holds the in-flight run buffer and the scope
        // that ties an invocation to the sample under test. A fresh instance per
        // resolution would lose both.
        $this->app->singleton(RunTrajectoryRecorder::class);
    }

    public function boot(): void
    {
        if (! class_exists(StepCompleted::class)) {
            return;
        }

        $events = $this->app['events'];

        $events->listen(StepCompleted::class, [RunTrajectoryRecorder::class, 'handleStepCompleted']);
        $events->listen(StepFailed::class, [RunTrajectoryRecorder::class, 'handleStepFailed']);
        $events->listen(ToolInvoked::class, [RunTrajectoryRecorder::class, 'handleToolInvoked']);
        $events->listen(ToolFailed::class, [RunTrajectoryRecorder::class, 'handleToolFailed']);
        $events->listen(AgentPrompted::class, [RunTrajectoryRecorder::class, 'handleAgentPrompted']);
        $events->listen(AgentFailed::class, [RunTrajectoryRecorder::class, 'handleAgentFailed']);
    }
}
