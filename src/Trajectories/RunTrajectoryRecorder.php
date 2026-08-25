<?php

declare(strict_types=1);

namespace Padosoft\EvalHarnessAiBridge\Trajectories;

use Closure;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Events\StepFailed;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Tools\ToolNameResolver;
use Padosoft\EvalHarness\Trajectory\ToolCall;
use Padosoft\EvalHarness\Trajectory\Trajectory;
use Padosoft\EvalHarness\Trajectory\TrajectoryRecorder;
use Throwable;

/**
 * Builds a {@see Trajectory} from the run events `laravel/ai` emits, rather than
 * from the response it returns.
 *
 * {@see AgentResponseTrajectory} reads the finished response, and that is the
 * right tool for a run that finished. It has two blind spots, and both of them
 * are where the interesting evals live:
 *
 *  - **A run that failed has no response at all.** The agent that threw on its
 *    third step is the one you most want in the dataset, and it is exactly the
 *    one the response mapper never sees.
 *  - **Timing is not on the response.** `Laravel\Ai\Responses\Data\Step` carries
 *    text, tool calls, tool results, finish reason, usage and meta — no
 *    duration. `ToolCall::$durationMs` and `ToolCall::$error` therefore could
 *    never be populated from it, and a tool that threw looked the same as a
 *    tool that returned nothing.
 *
 * Correlation is by scope. The runner wraps its call in {@see during()}, and the
 * first invocation whose events arrive inside that scope is the run under test;
 * an agent used as a tool starts its own invocation, which is counted in the
 * metadata rather than mistaken for the run being evaluated.
 *
 * One run at a time per process, which is what an eval pass does: repetitions are
 * sequential and the batch modes fan out to separate queue workers.
 *
 * @api
 */
final class RunTrajectoryRecorder
{
    /**
     * Events accumulated per in-flight invocation.
     *
     * @var array<string, array{sampleId: ?string, isRoot: bool, toolCalls: list<ToolCall>, steps: int, finishReason: ?string, error: ?array<string, string>, delegated: int}>
     */
    private array $runs = [];

    /**
     * Trajectories finalised for a sample, keyed by sample id.
     *
     * @var array<string, Trajectory>
     */
    private array $finished = [];

    private ?string $currentSampleId = null;

    /** Whether the current scope has already claimed its root invocation. */
    private bool $rootClaimed = false;

    public function __construct(private readonly ?TrajectoryRecorder $trajectories = null) {}

    /**
     * Run the callback with events attributed to the given sample.
     *
     * The callback's exception is deliberately not caught: a failed run must
     * still fail the eval. The events have already been recorded by the time it
     * propagates, so the trajectory survives the throw.
     */
    public function during(string $sampleId, Closure $callback): mixed
    {
        $previousSample = $this->currentSampleId;
        $previousRoot = $this->rootClaimed;

        $this->currentSampleId = $sampleId;
        $this->rootClaimed = false;

        try {
            return $callback();
        } finally {
            $this->currentSampleId = $previousSample;
            $this->rootClaimed = $previousRoot;
        }
    }

    /** The trajectory built for a sample, if its run produced any events. */
    public function trajectoryFor(string $sampleId): ?Trajectory
    {
        return $this->finished[$sampleId] ?? null;
    }

    public function flush(): void
    {
        $this->runs = [];
        $this->finished = [];
    }

    public function handleStepCompleted(StepCompleted $event): void
    {
        $run = &$this->run($event->invocationId);

        $run['steps']++;
        $run['finishReason'] = $event->response->finishReason->value;
    }

    public function handleStepFailed(StepFailed $event): void
    {
        $run = &$this->run($event->invocationId);

        $run['steps']++;
        $run['error'] = $this->describe($event->exception);
    }

    public function handleToolInvoked(ToolInvoked $event): void
    {
        $run = &$this->run($event->invocationId);

        $run['toolCalls'][] = new ToolCall(
            name: ToolNameResolver::resolve($event->tool),
            arguments: $event->arguments,
            result: $this->stringify($event->result),
            durationMs: (int) round($event->time),
        );
    }

    public function handleToolFailed(ToolFailed $event): void
    {
        $run = &$this->run($event->invocationId);

        $run['toolCalls'][] = new ToolCall(
            name: ToolNameResolver::resolve($event->tool),
            arguments: $event->arguments,
            error: $event->exception->getMessage(),
            // How long it ran before throwing: a nine-second timeout and an
            // instant rejection are different failures, and only this tells
            // them apart.
            durationMs: (int) round($event->time),
        );
    }

    /**
     * The run reached a terminal failure. This is the case the response mapper
     * structurally cannot see, so the trajectory is finalised from the events.
     */
    public function handleAgentFailed(AgentFailed $event): void
    {
        $run = $this->runs[$event->invocationId] ?? null;

        if ($run === null) {
            return;
        }

        $run['error'] ??= $this->describe($event->exception);

        $this->finalise($event->invocationId, $run, new Trajectory(
            toolCalls: $run['toolCalls'],
            steps: $run['steps'] === 0 ? null : $run['steps'],
            // Not a provider finish reason: the run never reached one. Named so
            // a `finish-reason` assertion reads as failed rather than as absent.
            finishReason: 'error',
            metadata: $this->metadata($run, $event->invocationId),
        ));
    }

    /**
     * The run succeeded. The response is authoritative for what it knows —
     * pending approvals, the real finish reason — and the events fill in the
     * timing and tool failures it does not carry.
     */
    public function handleAgentPrompted(AgentPrompted $event): void
    {
        $run = $this->runs[$event->invocationId] ?? null;

        if ($run === null) {
            return;
        }

        // StreamedAgentResponse extends AgentResponse, so the event's response is
        // always one: the mapper is authoritative for what the response knows.
        $fromResponse = AgentResponseTrajectory::fromResponse($event->response);

        $this->finalise($event->invocationId, $run, new Trajectory(
            // Event tool calls win: they are the only ones with a duration, and
            // the only ones that record a tool that threw.
            toolCalls: $run['toolCalls'] === [] ? $fromResponse->toolCalls : $run['toolCalls'],
            steps: $run['steps'] === 0 ? $fromResponse->steps : $run['steps'],
            finishReason: $fromResponse->finishReason ?? $run['finishReason'],
            pendingApprovals: $fromResponse->pendingApprovals,
            approvals: $fromResponse->approvals,
            metadata: array_merge($fromResponse->metadata, $this->metadata($run, $event->invocationId)),
        ));
    }

    /**
     * @param  array{sampleId: ?string, isRoot: bool, toolCalls: list<ToolCall>, steps: int, finishReason: ?string, error: ?array<string, string>, delegated: int}  $run
     */
    private function finalise(string $invocationId, array $run, Trajectory $trajectory): void
    {
        unset($this->runs[$invocationId]);

        $sampleId = $run['sampleId'];

        // A delegated run finishing does not end the sample: it is a tool call
        // inside the run under test, and its own trajectory is not the one the
        // metrics are scoring.
        if ($sampleId === null || ! $run['isRoot']) {
            if ($sampleId !== null) {
                $this->countDelegated($sampleId);
            }

            return;
        }

        $this->finished[$sampleId] = $trajectory;
        $this->trajectories?->record($sampleId, $trajectory);
    }

    private function countDelegated(string $sampleId): void
    {
        foreach ($this->runs as $invocationId => $run) {
            if ($run['sampleId'] === $sampleId && $run['isRoot']) {
                $this->runs[$invocationId]['delegated']++;

                return;
            }
        }
    }

    /**
     * @return array{sampleId: ?string, isRoot: bool, toolCalls: list<ToolCall>, steps: int, finishReason: ?string, error: ?array<string, string>, delegated: int}
     */
    private function &run(string $invocationId): array
    {
        if (! isset($this->runs[$invocationId])) {
            $isRoot = $this->currentSampleId !== null && ! $this->rootClaimed;

            if ($isRoot) {
                $this->rootClaimed = true;
            }

            $this->runs[$invocationId] = [
                'sampleId' => $this->currentSampleId,
                'isRoot' => $isRoot,
                'toolCalls' => [],
                'steps' => 0,
                'finishReason' => null,
                'error' => null,
                'delegated' => 0,
            ];
        }

        return $this->runs[$invocationId];
    }

    /**
     * @param  array{sampleId: ?string, isRoot: bool, toolCalls: list<ToolCall>, steps: int, finishReason: ?string, error: ?array<string, string>, delegated: int}  $run
     * @return array<string, mixed>
     */
    private function metadata(array $run, string $invocationId): array
    {
        return array_filter([
            'invocation_id' => $invocationId,
            'delegated_runs' => $run['delegated'] === 0 ? null : $run['delegated'],
            'error_class' => $run['error']['class'] ?? null,
            'error_message' => $run['error']['message'] ?? null,
        ], static fn ($value) => $value !== null);
    }

    /** @return array<string, string> */
    private function describe(Throwable $exception): array
    {
        return ['class' => $exception::class, 'message' => $exception->getMessage()];
    }

    private function stringify(mixed $result): ?string
    {
        return match (true) {
            $result === null => null,
            is_string($result) => $result,
            is_scalar($result) => (string) $result,
            $result instanceof \Stringable => (string) $result,
            default => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
    }
}
