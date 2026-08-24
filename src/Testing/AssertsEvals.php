<?php

declare(strict_types=1);

namespace Padosoft\EvalHarnessAiBridge\Testing;

use Padosoft\EvalHarness\Contracts\SampleRunner;
use Padosoft\EvalHarness\EvalEngine;
use Padosoft\EvalHarness\Reports\EvalReport;
use PHPUnit\Framework\Assert;

/**
 * PHPUnit surface: assert an agent against a golden dataset from a test.
 *
 * ```php
 * final class SupportAgentTest extends TestCase
 * {
 *     use AssertsEvals;
 *
 *     public function test_the_support_agent_holds_its_ground(): void
 *     {
 *         $this->assertPassesEval(
 *             'support.agent',
 *             fn (array $input) => Ai::agent(SupportAgent::class)->prompt($input['question']),
 *             minMacroF1: 0.85,
 *         );
 *     }
 * }
 * ```
 *
 * The failure message names the rows that broke, so a red build is actionable
 * without opening a report.
 */
trait AssertsEvals
{
    /**
     * @param  callable|SampleRunner  $systemUnderTest  a laravel/ai agent callable, or any eval-harness runner
     */
    protected function assertPassesEval(
        string $dataset,
        callable|SampleRunner $systemUnderTest,
        float $minMacroF1 = 0.8,
        ?float $minPassRate = null,
        ?int $repetitions = null,
        ?float $budgetUsd = null,
    ): EvalReport {
        $assertion = EvalAssertion::run(
            engine: $this->evalEngine(),
            dataset: $dataset,
            systemUnderTest: $systemUnderTest,
            minMacroF1: $minMacroF1,
            minPassRate: $minPassRate,
            repetitions: $repetitions,
            budgetUsd: $budgetUsd,
        );

        Assert::assertTrue($assertion->passed, $assertion->message);

        return $assertion->report;
    }

    /**
     * Assert a report that has already been produced.
     *
     * Useful when one run feeds several assertions: an eval is expensive, and
     * running it three times to check three thresholds is three bills.
     */
    protected function assertEvalReportPasses(
        EvalReport $report,
        float $minMacroF1 = 0.8,
        ?float $minPassRate = null,
    ): EvalReport {
        $assertion = EvalAssertion::judge($report, $minMacroF1, $minPassRate);

        Assert::assertTrue($assertion->passed, $assertion->message);

        return $report;
    }

    private function evalEngine(): EvalEngine
    {
        /** @var EvalEngine $engine */
        $engine = app(EvalEngine::class);

        return $engine;
    }
}
