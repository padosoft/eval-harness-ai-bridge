<?php

declare(strict_types=1);

namespace Padosoft\EvalHarnessAiBridge\Tests\Unit\Trajectories;

use Illuminate\Support\Collection;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\Usage;
use Padosoft\EvalHarnessAiBridge\Trajectories\AgentResponseTrajectory;
use PHPUnit\Framework\TestCase;

final class AgentResponseTrajectoryTest extends TestCase
{
    public function test_it_carries_tool_calls_in_order_with_their_arguments(): void
    {
        $trajectory = AgentResponseTrajectory::fromResponse($this->response(
            toolCalls: [
                new ToolCall('call-1', 'lookup_order', ['id' => 44192]),
                new ToolCall('call-2', 'check_stock', ['sku' => 'BOOT-9']),
            ],
        ));

        $this->assertSame(['lookup_order', 'check_stock'], $trajectory->toolNames());
        $this->assertTrue($trajectory->calledWith('lookup_order', ['id' => 44192]));
        $this->assertTrue($trajectory->followedOrder(['lookup_order', 'check_stock']));
    }

    /**
     * Results arrive out of order for parallel tools, and matching by position
     * would attach one call's outcome to another's.
     */
    public function test_results_are_joined_by_call_id_not_by_position(): void
    {
        $trajectory = AgentResponseTrajectory::fromResponse($this->response(
            toolCalls: [
                new ToolCall('call-1', 'lookup_order', ['id' => 1]),
                new ToolCall('call-2', 'check_stock', ['sku' => 'X']),
            ],
            toolResults: [
                new ToolResult('call-2', 'check_stock', ['sku' => 'X'], 'in stock'),
                new ToolResult('call-1', 'lookup_order', ['id' => 1], 'order found'),
            ],
        ));

        $this->assertSame('order found', $trajectory->toolCalls[0]->result);
        $this->assertSame('in stock', $trajectory->toolCalls[1]->result);
    }

    /**
     * A denied call is not a call that quietly succeeded: the tool never ran,
     * and "did it look the order up?" must not be satisfied by a rejection.
     */
    public function test_a_denied_call_is_recorded_as_failed_with_no_result(): void
    {
        $trajectory = AgentResponseTrajectory::fromResponse($this->response(
            toolCalls: [new ToolCall('call-1', 'refund_order', ['id' => 1])],
            toolResults: [new ToolResult('call-1', 'refund_order', ['id' => 1], 'rejected', denied: true)],
        ));

        $this->assertNull($trajectory->toolCalls[0]->result);
        $this->assertTrue($trajectory->toolCalls[0]->failed());
        $this->assertSame([$trajectory->toolCalls[0]], $trajectory->failedCalls());
    }

    public function test_a_call_still_awaiting_a_result_has_neither_result_nor_error(): void
    {
        $trajectory = AgentResponseTrajectory::fromResponse($this->response(
            toolCalls: [new ToolCall('call-1', 'refund_order', ['id' => 1])],
        ));

        $this->assertNull($trajectory->toolCalls[0]->result);
        $this->assertFalse($trajectory->toolCalls[0]->failed());
    }

    public function test_steps_and_finish_reason_come_from_the_recorded_steps(): void
    {
        $trajectory = AgentResponseTrajectory::fromResponse($this->response(
            toolCalls: [new ToolCall('call-1', 'lookup_order', [])],
            steps: [
                $this->step(FinishReason::ToolCalls),
                $this->step(FinishReason::Stop),
            ],
        ));

        $this->assertSame(2, $trajectory->steps);
        $this->assertSame('stop', $trajectory->finishReason);
    }

    /**
     * Reporting null steps for the simplest possible agent would make
     * `steps-below` unscoreable on it.
     */
    public function test_a_single_shot_completion_counts_as_one_step(): void
    {
        $trajectory = AgentResponseTrajectory::fromResponse($this->response());

        $this->assertSame(1, $trajectory->steps);
    }

    /**
     * Text that says "I have submitted that" while an approval is pending reads
     * as success and is not, so the finish reason has to say so.
     */
    public function test_a_run_stopped_on_an_approval_does_not_report_as_finished(): void
    {
        $trajectory = AgentResponseTrajectory::fromResponse($this->response(
            toolCalls: [new ToolCall('call-1', 'refund_order', ['id' => 1])],
            pendingApprovals: [new PendingApproval('ap-1', 'refund_order', ['id' => 1], 'over threshold')],
        ));

        $this->assertSame(1, $trajectory->pendingApprovals);
        $this->assertSame('pending_approval', $trajectory->finishReason);
    }

    public function test_completed_tool_results_count_as_approvals(): void
    {
        $trajectory = AgentResponseTrajectory::fromResponse($this->response(
            toolCalls: [new ToolCall('call-1', 'refund_order', ['id' => 1])],
            toolResults: [new ToolResult('call-1', 'refund_order', ['id' => 1], 'done')],
        ));

        $this->assertTrue($trajectory->hasApproval('refund_order'));
    }

    /**
     * A second call to the same tool that is still waiting must not inherit the
     * first call's approval.
     */
    public function test_a_tool_still_pending_is_not_reported_as_approved(): void
    {
        $trajectory = AgentResponseTrajectory::fromResponse($this->response(
            toolCalls: [
                new ToolCall('call-1', 'refund_order', ['id' => 1]),
                new ToolCall('call-2', 'refund_order', ['id' => 2]),
            ],
            toolResults: [new ToolResult('call-1', 'refund_order', ['id' => 1], 'done')],
            pendingApprovals: [new PendingApproval('ap-1', 'refund_order', ['id' => 2])],
        ));

        $this->assertFalse($trajectory->hasApproval('refund_order'));
    }

    public function test_usage_and_provider_metadata_travel_with_the_trajectory(): void
    {
        $trajectory = AgentResponseTrajectory::fromResponse($this->response(
            usage: new Usage(promptTokens: 1200, completionTokens: 80),
            meta: new Meta(provider: 'openai', model: 'gpt-4o-mini'),
        ));

        $this->assertSame('openai', $trajectory->metadata['provider']);
        $this->assertSame(1200, $trajectory->metadata['usage']['prompt_tokens']);
        // The same key eval-harness's cost ledger reads, so agent spend is not
        // silently treated as free next to judge spend.
        $this->assertSame('gpt-4o-mini', $trajectory->metadata['usage']['model']);
    }

    public function test_a_structured_tool_result_is_serialised_rather_than_dropped(): void
    {
        $trajectory = AgentResponseTrajectory::fromResponse($this->response(
            toolCalls: [new ToolCall('call-1', 'lookup_order', [])],
            toolResults: [new ToolResult('call-1', 'lookup_order', [], ['status' => 'shipped'])],
        ));

        $this->assertSame('{"status":"shipped"}', $trajectory->toolCalls[0]->result);
    }

    public function test_a_response_with_no_tools_produces_an_empty_trajectory(): void
    {
        $trajectory = AgentResponseTrajectory::fromResponse($this->response());

        $this->assertSame([], $trajectory->toolCalls);
        $this->assertSame(0, $trajectory->pendingApprovals);
    }

    /**
     * @param  list<ToolCall>  $toolCalls
     * @param  list<ToolResult>  $toolResults
     * @param  list<Step>  $steps
     * @param  list<PendingApproval>  $pendingApprovals
     */
    private function response(
        array $toolCalls = [],
        array $toolResults = [],
        array $steps = [],
        array $pendingApprovals = [],
        ?Usage $usage = null,
        ?Meta $meta = null,
    ): AgentResponse {
        $response = new AgentResponse('inv-1', 'an answer', $usage ?? new Usage, $meta ?? new Meta);

        $response->withToolCallsAndResults(new Collection($toolCalls), new Collection($toolResults));
        $response->withSteps(new Collection($steps));
        $response->withPendingApprovals(new Collection($pendingApprovals));

        return $response;
    }

    private function step(FinishReason $reason): Step
    {
        return new Step('', [], [], $reason, new Usage, new Meta);
    }
}
