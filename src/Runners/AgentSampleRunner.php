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
    ) {
        $this->agent = Closure::fromCallable($agent);
    }

    public function run(SampleInvocation $sample): string
    {
        $response = ($this->agent)($sample->input, $sample);

        if (! $response instanceof TextResponse) {
            throw new EvalRunException(sprintf(
                "The agent for sample '%s' must return a laravel/ai response (%s or a subclass); got %s.",
                $sample->id,
                TextResponse::class,
                get_debug_type($response),
            ));
        }

        $this->recorder()?->record($sample->id, AgentResponseTrajectory::fromResponse($response));

        return $response->text;
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
