<?php

declare(strict_types=1);

namespace Padosoft\EvalHarnessAiBridge\Trajectories;

use Illuminate\Contracts\Support\Arrayable;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Responses\Data\Citation;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\ToolCall as AiToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\TextResponse;
use Padosoft\EvalHarness\Trajectory\ToolCall;
use Padosoft\EvalHarness\Trajectory\Trajectory;

/**
 * Translates a `laravel/ai` response into a scoreable trajectory.
 *
 * ## Why the translation lives here and not in the harness
 *
 * `padosoft/eval-harness` scores a `Trajectory` — a plain DTO of tool calls,
 * steps, a finish reason and approvals. It deliberately knows nothing about
 * any agent SDK, because the assertions in a golden dataset outlive whichever
 * runtime the team is on this year: an eval written today against `laravel/ai`
 * should still run after a move to a custom orchestrator, to MCP, or to
 * `laravel-flow` saga steps, without rewriting the dataset.
 *
 * That is the whole design difference from tools whose agent assertions are
 * welded to one SDK's response object: there, changing runtime means throwing
 * away the eval suite. Here it means writing one adapter — this file.
 *
 * ## What maps onto what
 *
 * | `laravel/ai` | `Trajectory` |
 * |---|---|
 * | `$response->toolCalls` | `toolCalls[]` — name and arguments |
 * | `$response->toolResults` | the `result`/`error` on the matching call |
 * | `$response->steps` | `steps` |
 * | last `Step::$finishReason` | `finishReason` |
 * | `$response->pendingApprovals` | `pendingApprovals` |
 * | approved tool results | `approvals[]` |
 * | usage, provider, model | `metadata` |
 *
 * Calls and results are joined by **tool-call id**, not by position: a result
 * can be missing (the call is still pending approval), out of order (parallel
 * tools), or denied, and matching by index would silently attach one call's
 * outcome to another's.
 */
final class AgentResponseTrajectory
{
    public static function fromResponse(TextResponse $response): Trajectory
    {
        $results = self::resultsById($response);

        $calls = [];

        foreach ($response->toolCalls as $call) {
            if (! $call instanceof AiToolCall) {
                continue;
            }

            $calls[] = self::toolCall($call, $results[$call->id] ?? null);
        }

        return new Trajectory(
            toolCalls: $calls,
            steps: self::stepCount($response),
            finishReason: self::finishReason($response),
            pendingApprovals: $response->pendingApprovals->count(),
            approvals: self::approvals($response),
            metadata: self::metadata($response),
        );
    }

    /**
     * @return array<string, ToolResult>
     */
    private static function resultsById(TextResponse $response): array
    {
        $results = [];

        foreach ($response->toolResults as $result) {
            if ($result instanceof ToolResult) {
                $results[$result->id] = $result;
            }
        }

        return $results;
    }

    private static function toolCall(AiToolCall $call, ?ToolResult $result): ToolCall
    {
        return new ToolCall(
            name: $call->name,
            arguments: $call->arguments,
            // A denied call is not a call that succeeded quietly: the tool
            // never ran, and a trajectory metric asking "did it look the
            // order up?" must not be satisfied by a rejection.
            result: $result === null || $result->denied ? null : self::stringify($result->result),
            error: $result?->denied === true ? 'denied' : null,
            extra: array_filter([
                'tool_call_id' => $call->id,
                'result_id' => $result?->resultId,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }

    private static function stepCount(TextResponse $response): ?int
    {
        $steps = $response->steps->count();

        // No steps recorded and no tools called is a single-shot completion,
        // which is one step; reporting null there would make `steps-below`
        // unscoreable for the simplest possible agent.
        return $steps > 0 ? $steps : ($response->toolCalls->isEmpty() ? 1 : null);
    }

    private static function finishReason(TextResponse $response): ?string
    {
        // Checked BEFORE the recorded steps, not after. An agent that reaches an
        // approval-gated tool produces both: steps whose last finish reason is
        // `tool_calls` (or `stop`), and a pending approval. Reading the step
        // reason first would report such a run as finished — which is exactly
        // the case this branch exists to catch, and the one where text saying
        // "I have submitted that refund" is untrue.
        if ($response->pendingApprovals->isNotEmpty()) {
            return 'pending_approval';
        }

        $last = $response->steps->last();

        return $last instanceof Step ? $last->finishReason->value : null;
    }

    /**
     * Actions that made it past an approval gate.
     *
     * Identified by tool name, because that is what a dataset can write down:
     * a call id is generated per run and cannot appear in a golden file.
     *
     * @return list<string>
     */
    private static function approvals(TextResponse $response): array
    {
        $approved = [];

        foreach ($response->toolResults as $result) {
            if ($result instanceof ToolResult && ! $result->denied) {
                $approved[$result->name] = true;
            }
        }

        foreach ($response->pendingApprovals as $pending) {
            // Still pending is not approved, whatever an earlier call of the
            // same tool did.
            if ($pending instanceof PendingApproval) {
                unset($approved[$pending->tool]);
            }
        }

        return array_keys($approved);
    }

    /**
     * @return array<string, mixed>
     */
    private static function metadata(TextResponse $response): array
    {
        $pending = [];

        foreach ($response->pendingApprovals as $approval) {
            if ($approval instanceof PendingApproval) {
                $pending[] = ['tool' => $approval->tool, 'reason' => $approval->reason];
            }
        }

        return array_filter([
            'provider' => $response->meta->provider,
            'model' => $response->meta->model,
            // The same shape ProviderUsageDetails produces, so a run costed by
            // eval-harness sees agent spend alongside judge spend instead of
            // pretending the agent was free.
            'usage' => array_filter([
                'prompt_tokens' => $response->usage->promptTokens,
                'completion_tokens' => $response->usage->completionTokens,
                'model' => $response->meta->model,
            ], static fn (mixed $value): bool => $value !== null && $value !== 0),
            'pending_approvals' => $pending,
            // Web-fetch and web-search citations, when the provider reported any
            // (laravel/ai surfaces them on Meta since 0.11, and on the streaming
            // path too). A grounding metric can only score sources it can see,
            // and until now the only sources a trajectory carried were the ones a
            // *tool* returned — a model that answered from a provider-side web
            // fetch looked, to citation-groundedness, exactly like a model that
            // made the answer up.
            'citations' => $response->meta->citations
                ->map(static fn (Citation $citation): array => $citation instanceof Arrayable
                    ? $citation->toArray()
                    : ['title' => $citation->title])
                ->all(),
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    private static function stringify(mixed $result): ?string
    {
        if ($result === null) {
            return null;
        }

        if (is_string($result)) {
            return $result;
        }

        if (is_scalar($result)) {
            return (string) $result;
        }

        $encoded = json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? null : $encoded;
    }
}
