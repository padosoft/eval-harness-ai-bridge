<?php

declare(strict_types=1);

namespace Padosoft\EvalHarnessAiBridge\Tests\Unit\Runners;

use Illuminate\Support\Collection;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\Usage;
use Padosoft\EvalHarness\Contracts\SampleInvocation;
use Padosoft\EvalHarness\Exceptions\EvalRunException;
use Padosoft\EvalHarness\Trajectory\TrajectoryRecorder;
use Padosoft\EvalHarnessAiBridge\Runners\AgentSampleRunner;
use PHPUnit\Framework\TestCase;

final class AgentSampleRunnerTest extends TestCase
{
    public function test_it_returns_the_response_text_as_the_answer(): void
    {
        $runner = new AgentSampleRunner(
            fn (): AgentResponse => $this->response('your order ships Tuesday'),
            new TrajectoryRecorder,
        );

        $this->assertSame('your order ships Tuesday', $runner->run($this->invocation()));
    }

    /**
     * The half that says whether the answer was earned or guessed: an agent
     * that replies without calling the lookup scores 1.0 on every text metric
     * there is.
     */
    public function test_the_trajectory_is_recorded_against_the_sample(): void
    {
        $recorder = new TrajectoryRecorder;
        $runner = new AgentSampleRunner(
            fn (): AgentResponse => $this->response('shipped', [new ToolCall('c1', 'lookup_order', ['id' => 7])]),
            $recorder,
        );

        $runner->run($this->invocation());
        $trajectory = $recorder->for('row-1');

        $this->assertNotNull($trajectory);
        $this->assertTrue($trajectory->called('lookup_order'));
        $this->assertTrue($trajectory->calledWith('lookup_order', ['id' => 7]));
    }

    public function test_the_callable_receives_the_row_input_and_the_invocation(): void
    {
        $seen = [];
        $runner = new AgentSampleRunner(
            function (array $input, SampleInvocation $sample) use (&$seen): AgentResponse {
                $seen = ['input' => $input, 'id' => $sample->id];

                return $this->response('ok');
            },
            new TrajectoryRecorder,
        );

        $runner->run($this->invocation());

        $this->assertSame(['question' => 'when does it ship?'], $seen['input']);
        $this->assertSame('row-1', $seen['id']);
    }

    /**
     * Returning the wrong thing here is a wiring mistake, and a silent
     * stringify would turn it into a dataset of empty answers scored 0.0 —
     * blaming the pipeline for the harness's own wiring.
     */
    public function test_a_non_response_return_value_raises_with_the_row_named(): void
    {
        $runner = new AgentSampleRunner(static fn (): string => 'just a string', new TrajectoryRecorder);

        $this->expectException(EvalRunException::class);
        $this->expectExceptionMessage("sample 'row-1' must return a laravel/ai response");

        $runner->run($this->invocation());
    }

    /**
     * A missing recorder costs the trajectory metrics, not the run: the runner
     * must still work in a plain unit test with no container behind it.
     */
    public function test_it_runs_without_a_recorder(): void
    {
        $runner = new AgentSampleRunner(fn (): AgentResponse => $this->response('ok'));

        $this->assertSame('ok', $runner->run($this->invocation()));
    }

    public function test_a_denied_tool_is_visible_in_the_recorded_trajectory(): void
    {
        $recorder = new TrajectoryRecorder;
        $runner = new AgentSampleRunner(
            fn (): AgentResponse => $this->response(
                'I have submitted the refund',
                [new ToolCall('c1', 'refund_order', ['id' => 7])],
                [new ToolResult('c1', 'refund_order', ['id' => 7], 'rejected', denied: true)],
            ),
            $recorder,
        );

        $runner->run($this->invocation());

        $this->assertTrue($recorder->for('row-1')?->toolCalls[0]->failed());
    }

    /**
     * @param  list<ToolCall>  $toolCalls
     * @param  list<ToolResult>  $toolResults
     */
    private function response(string $text, array $toolCalls = [], array $toolResults = []): AgentResponse
    {
        $response = new AgentResponse('inv-1', $text, new Usage, new Meta);
        $response->withToolCallsAndResults(new Collection($toolCalls), new Collection($toolResults));

        return $response;
    }

    private function invocation(): SampleInvocation
    {
        return new SampleInvocation('row-1', ['question' => 'when does it ship?']);
    }
}
