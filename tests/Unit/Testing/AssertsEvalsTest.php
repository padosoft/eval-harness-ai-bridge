<?php

declare(strict_types=1);

namespace Padosoft\EvalHarnessAiBridge\Tests\Unit\Testing;

use Illuminate\Support\Collection;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Padosoft\EvalHarness\Costs\BudgetOutcome;
use Padosoft\EvalHarness\Datasets\DatasetSample;
use Padosoft\EvalHarness\EvalEngine;
use Padosoft\EvalHarness\Reports\EvalReport;
use Padosoft\EvalHarnessAiBridge\Runners\AgentSampleRunner;
use Padosoft\EvalHarnessAiBridge\Testing\AssertsEvals;
use Padosoft\EvalHarnessAiBridge\Testing\EvalAssertion;
use Padosoft\EvalHarnessAiBridge\Tests\TestCase;
use PHPUnit\Framework\AssertionFailedError;

final class AssertsEvalsTest extends TestCase
{
    use AssertsEvals;

    public function test_a_passing_agent_satisfies_the_threshold(): void
    {
        $this->registerDataset('agent.pass');

        $report = $this->assertPassesEval(
            'agent.pass',
            fn (): AgentResponse => $this->response('Paris'),
            minMacroF1: 1.0,
        );

        $this->assertSame(1.0, $report->macroF1());
    }

    /**
     * "macro-F1 0.71 < 0.80" tells nobody what to open, so the failure names
     * the rows that broke, worst first.
     */
    public function test_a_failing_agent_names_the_rows_that_broke(): void
    {
        $this->registerDataset('agent.fail');

        try {
            $this->assertPassesEval(
                'agent.fail',
                fn (): AgentResponse => $this->response('Berlin'),
                minMacroF1: 0.9,
            );

            $this->fail('The assertion should have failed.');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('macro-F1', $e->getMessage());
            $this->assertStringContainsString('failing row(s), worst first', $e->getMessage());
            $this->assertStringContainsString('capital', $e->getMessage());
            $this->assertStringContainsString('eval-harness:brief', $e->getMessage());
        }
    }

    /**
     * An eval is expensive; running it three times to check three thresholds
     * is three bills.
     */
    public function test_one_run_can_feed_several_assertions(): void
    {
        $this->registerDataset('agent.reuse');

        $engine = $this->resolve(EvalEngine::class);
        $report = $engine->run('agent.reuse', new AgentSampleRunner(
            fn (): AgentResponse => $this->response('Paris'),
        ));

        $this->assertEvalReportPasses($report, minMacroF1: 1.0);
        $this->assertEvalReportPasses($report, minMacroF1: 0.5, minPassRate: 1.0);
    }

    public function test_a_pass_rate_floor_is_enforced_separately(): void
    {
        $this->registerDataset('agent.passrate');

        $engine = $this->resolve(EvalEngine::class);
        $report = $engine->run('agent.passrate', new AgentSampleRunner(
            fn (): AgentResponse => $this->response('Berlin'),
        ));

        $assertion = EvalAssertion::judge($report, minMacroF1: 0.0, minPassRate: 1.0);

        $this->assertFalse($assertion->passed);
        $this->assertStringContainsString('pass rate', $assertion->message);
    }

    /**
     * A halted run is incomplete data: the rows that never executed are
     * disproportionately the ones that would have failed, so it can never
     * satisfy a threshold.
     */
    public function test_a_run_halted_on_its_budget_can_never_pass(): void
    {
        $this->registerDataset('agent.halted');

        $engine = $this->resolve(EvalEngine::class);
        $report = $engine->run(
            'agent.halted',
            new AgentSampleRunner(fn (): AgentResponse => $this->response('Paris')),
            budgetUsd: null,
        );

        // Judged directly rather than through a real halt: the point under test
        // is that the assertion refuses a halted report, whatever halted it.
        $halted = new EvalReport(
            datasetName: $report->datasetName,
            sampleResults: $report->sampleResults,
            failures: $report->failures,
            startedAt: $report->startedAt,
            finishedAt: $report->finishedAt,
            budget: new BudgetOutcome(1.0, 1.2, true, 1, 'Spent $1.2000 of a $1.0000 budget after 1 row.'),
        );

        $assertion = EvalAssertion::judge($halted, minMacroF1: 0.0);

        $this->assertFalse($assertion->passed);
        $this->assertStringContainsString('halted on its budget', $assertion->message);
    }

    public function test_the_trajectory_recorded_by_the_runner_is_scored(): void
    {
        $engine = $this->resolve(EvalEngine::class);
        $engine->dataset('agent.tools')
            ->withSamples([new DatasetSample(
                id: 'ships',
                input: ['question' => 'when does it ship?'],
                expectedOutput: 'Tuesday',
                metadata: ['trajectory' => ['tools' => ['lookup_order']]],
            )])
            ->withMetrics(['tool-called'])
            ->register();

        // The agent answers correctly but never looks anything up: a guess that
        // happens to land, which every text metric scores 1.0.
        $guessing = EvalAssertion::judge(
            $engine->run('agent.tools', new AgentSampleRunner(
                fn (): AgentResponse => $this->response('Tuesday'),
            )),
            minMacroF1: 0.5,
        );

        $this->assertFalse($guessing->passed, 'an unearned answer must not pass a tool-called gate');

        $grounded = EvalAssertion::judge(
            $engine->run('agent.tools', new AgentSampleRunner(
                fn (): AgentResponse => $this->response('Tuesday', [new ToolCall('c1', 'lookup_order', [])]),
            )),
            minMacroF1: 0.5,
        );

        $this->assertTrue($grounded->passed);
    }

    private function registerDataset(string $name): void
    {
        $this->resolve(EvalEngine::class)
            ->dataset($name)
            ->withSamples([new DatasetSample(id: 'capital', input: ['question' => 'capital of France?'], expectedOutput: 'Paris')])
            ->withMetrics(['exact-match'])
            ->register();
    }

    /**
     * @param  list<ToolCall>  $toolCalls
     */
    private function response(string $text, array $toolCalls = []): AgentResponse
    {
        $response = new AgentResponse('inv-1', $text, new Usage, new Meta);
        $response->withToolCallsAndResults(new Collection($toolCalls), new Collection);

        return $response;
    }
}
