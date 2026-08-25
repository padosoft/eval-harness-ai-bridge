<?php

declare(strict_types=1);

namespace Padosoft\EvalHarnessAiBridge\Tests\Unit\Trajectories;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Events\StepFailed;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Padosoft\EvalHarnessAiBridge\Tests\Support\FakeTool;
use Padosoft\EvalHarnessAiBridge\Trajectories\RunTrajectoryRecorder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RunTrajectoryRecorderTest extends TestCase
{
    private function agent(): Agent
    {
        return $this->createStub(Agent::class);
    }

    private function provider(): TextProvider
    {
        return $this->createStub(TextProvider::class);
    }

    private function stepCompleted(string $invocationId, int $number, FinishReason $reason = FinishReason::Stop): StepCompleted
    {
        return new StepCompleted(
            $invocationId, $number, $this->agent(), $this->provider(), 'gpt-4o-mini', false,
            new StepResponse(
                text: 'hi',
                toolCalls: [],
                finishReason: $reason,
                usage: new Usage(promptTokens: 10, completionTokens: 2),
                meta: new Meta(provider: 'openai', model: 'gpt-4o-mini'),
            ),
            120.0,
        );
    }

    private function prompt(): AgentPrompt
    {
        return new AgentPrompt($this->agent(), 'hi', [], $this->provider(), 'gpt-4o-mini');
    }

    private function response(string $invocationId): AgentResponse
    {
        return new AgentResponse($invocationId, 'the answer', new Usage, new Meta(provider: 'openai', model: 'gpt-4o-mini'));
    }

    public function test_a_run_that_failed_still_produces_a_trajectory(): void
    {
        $recorder = new RunTrajectoryRecorder;

        $recorder->during('sample-1', function () use ($recorder): void {
            $recorder->handleStepCompleted($this->stepCompleted('inv_1', 1, FinishReason::ToolCalls));
            $recorder->handleToolInvoked(new ToolInvoked('inv_1', 'ti_1', $this->agent(), new FakeTool, ['id' => 7], 'ok', 40.0));
            $recorder->handleStepFailed(new StepFailed(
                'inv_1', 2, $this->agent(), $this->provider(), 'gpt-4o-mini', true,
                new RuntimeException('upstream exploded'), 900.0,
            ));
            $recorder->handleAgentFailed(new AgentFailed('inv_1', $this->prompt(), new RuntimeException('gave up')));
        });

        $trajectory = $recorder->trajectoryFor('sample-1');

        // This is the case AgentResponseTrajectory structurally cannot see:
        // there is no response to map.
        $this->assertNotNull($trajectory);
        $this->assertSame('error', $trajectory->finishReason);
        $this->assertSame(2, $trajectory->steps);
        $this->assertCount(1, $trajectory->toolCalls);
        $this->assertSame(RuntimeException::class, $trajectory->metadata['error_class']);
        $this->assertSame('upstream exploded', $trajectory->metadata['error_message']);
    }

    public function test_tool_calls_carry_the_duration_the_response_does_not_have(): void
    {
        $recorder = new RunTrajectoryRecorder;

        $recorder->during('sample-2', function () use ($recorder): void {
            $recorder->handleToolInvoked(new ToolInvoked('inv_2', 'ti_1', $this->agent(), new FakeTool('refund_order'), ['id' => 7], 'refunded', 250.0));
            $recorder->handleAgentPrompted(new AgentPrompted('inv_2', $this->prompt(), $this->response('inv_2')));
        });

        $call = $recorder->trajectoryFor('sample-2')?->toolCalls[0] ?? null;

        $this->assertNotNull($call);
        $this->assertSame('refund_order', $call->name);
        $this->assertSame(250, $call->durationMs);
        $this->assertSame('refunded', $call->result);
    }

    public function test_a_tool_that_threw_is_a_failed_call_with_the_time_it_burned_first(): void
    {
        $recorder = new RunTrajectoryRecorder;

        $recorder->during('sample-3', function () use ($recorder): void {
            $recorder->handleToolFailed(new ToolFailed(
                'inv_3', 'ti_1', $this->agent(), new FakeTool, ['id' => 7],
                new RuntimeException('timed out'), 9_000.0,
            ));
            $recorder->handleAgentPrompted(new AgentPrompted('inv_3', $this->prompt(), $this->response('inv_3')));
        });

        $call = $recorder->trajectoryFor('sample-3')?->toolCalls[0] ?? null;

        $this->assertNotNull($call);
        $this->assertTrue($call->failed());
        $this->assertSame('timed out', $call->error);
        // Nine seconds before the throw is a timeout; zero is a rejection.
        $this->assertSame(9_000, $call->durationMs);
    }

    public function test_an_agent_used_as_a_tool_does_not_replace_the_trajectory_under_test(): void
    {
        $recorder = new RunTrajectoryRecorder;

        $recorder->during('sample-4', function () use ($recorder): void {
            // Root run, claimed first.
            $recorder->handleStepCompleted($this->stepCompleted('inv_root', 1, FinishReason::ToolCalls));

            // The sub-agent's own run, started inside the root's tool call.
            $recorder->handleStepCompleted($this->stepCompleted('inv_child', 1));
            $recorder->handleAgentPrompted(new AgentPrompted('inv_child', $this->prompt(), $this->response('inv_child')));

            $recorder->handleAgentPrompted(new AgentPrompted('inv_root', $this->prompt(), $this->response('inv_root')));
        });

        $trajectory = $recorder->trajectoryFor('sample-4');

        $this->assertNotNull($trajectory);
        $this->assertSame('inv_root', $trajectory->metadata['invocation_id']);
        $this->assertSame(1, $trajectory->metadata['delegated_runs']);
    }

    public function test_events_outside_a_scope_are_not_attributed_to_any_sample(): void
    {
        $recorder = new RunTrajectoryRecorder;

        $recorder->handleStepCompleted($this->stepCompleted('inv_stray', 1));
        $recorder->handleAgentPrompted(new AgentPrompted('inv_stray', $this->prompt(), $this->response('inv_stray')));

        $this->assertNull($recorder->trajectoryFor('inv_stray'));
    }

    public function test_the_scope_is_restored_when_the_run_throws(): void
    {
        $recorder = new RunTrajectoryRecorder;

        try {
            $recorder->during('sample-5', function (): void {
                throw new RuntimeException('the agent blew up');
            });
        } catch (RuntimeException) {
            // Expected: a failed run must still fail the eval.
        }

        // The scope closed, so a later stray event belongs to nobody.
        $recorder->handleStepCompleted($this->stepCompleted('inv_later', 1));
        $recorder->handleAgentPrompted(new AgentPrompted('inv_later', $this->prompt(), $this->response('inv_later')));

        $this->assertNull($recorder->trajectoryFor('sample-5'));
    }
}
