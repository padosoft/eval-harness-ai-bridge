<?php

declare(strict_types=1);

namespace Padosoft\EvalHarnessAiBridge\Runners;

use Closure;
use Laravel\Ai\Responses\TextResponse;
use Padosoft\EvalHarness\Contracts\SampleInvocation;
use Padosoft\EvalHarness\Contracts\SampleRunner;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Padosoft\EvalHarness\Trajectory\TrajectoryRecorder;
use Padosoft\EvalHarnessAiBridge\Trajectories\AgentResponseTrajectory;
use Padosoft\EvalHarnessAiBridge\Trajectories\RunTrajectoryRecorder;

/**
 * Runs a `laravel/ai` agent as an eval system-under-test, and records how it got
 * there.
 *
 * Without this, evaluating an agent means writing the same twelve lines in every
 * project: call the agent, take `$response->text`, and then throw away the tool
 * calls — which is the half that says whether the answer was *earned* or
 * guessed. An agent that replies "your order ships Tuesday" without calling the
 * order lookup scores 1.0 on every text metric there is.
 *
 * ```php
 * $eval->dataset('support.agent')
 *     ->loadFromYaml(database_path('evals/support.yaml'))
 *     ->withMetrics(['llm-as-judge', 'tool-called', 'no-pending-approvals'])
 *     ->register();
 *
 * $report = $eval->run('support.agent', new AgentSampleRunner(
 *     fn (array $input) => Ai::agent(SupportAgent::class)->prompt($input['question']),
 * ));
 * ```
 *
 * The callable returns whatever `laravel/ai` gave back; everything else —
 * extracting the text, translating the trajectory, keying it to the right
 * repetition — happens here.
 */
final class AgentSampleRunner implements SampleRunner
{
    /** @var Closure(array<string, mixed>, SampleInvocation): mixed */
    private readonly Closure $agent;

    /**
     * @param  callable(array<string, mixed>, SampleInvocation): mixed  $agent  returns a laravel/ai response
     */
    public function __construct(
        callable $agent,
        private readonly ?TrajectoryRecorder $trajectories = null,
        private readonly ?RunTrajectoryRecorder $runs = null,
    ) {
        $this->agent = Closure::fromCallable($agent);
    }

    public function run(SampleInvocation $sample): string
    {
        $runs = $this->runRecorder();

        // Scoped so the run events can be attributed to this sample — including
        // the events of a run that throws, which is the case the response mapper
        // below never gets to see. The exception is deliberately not caught: a
        // failed run must still fail the eval, and by the time it propagates the
        // trajectory has already been recorded.
        $response = $runs === null
            ? ($this->agent)($sample->input, $sample)
            : $runs->during($sample->id, fn () => ($this->agent)($sample->input, $sample));

        if (! $response instanceof TextResponse) {
            throw new EvalRunException(sprintf(
                "The agent for sample '%s' must return a laravel/ai response (%s or a subclass); got %s.",
                $sample->id,
                TextResponse::class,
                get_debug_type($response),
            ));
        }

        // The event recorder has already filed its trajectory when it saw the run
        // finish. Falling back to the response keeps the runner working on an SDK
        // older than 0.11, and in a plain unit test with no container at all.
        if ($runs === null || $runs->trajectoryFor($sample->id) === null) {
            $this->recorder()?->record($sample->id, AgentResponseTrajectory::fromResponse($response));
        }

        return $response->text;
    }

    /**
     * The event-based recorder, when the application has one bound. Optional for
     * the same reason the trajectory recorder is: its absence costs timing and
     * failed-run trajectories, not the run.
     */
    private function runRecorder(): ?RunTrajectoryRecorder
    {
        if ($this->runs !== null) {
            return $this->runs;
        }

        if (! function_exists('app')) {
            return null;
        }

        $recorder = app(RunTrajectoryRecorder::class);

        return $recorder instanceof RunTrajectoryRecorder ? $recorder : null;
    }

    /**
     * The recorder is optional so the runner still works in a plain unit test
     * with no container: a missing recorder costs the trajectory metrics, not
     * the run.
     */
    private function recorder(): ?TrajectoryRecorder
    {
        if ($this->trajectories !== null) {
            return $this->trajectories;
        }

        if (! function_exists('app')) {
            return null;
        }

        $recorder = app(TrajectoryRecorder::class);

        return $recorder instanceof TrajectoryRecorder ? $recorder : null;
    }
}
