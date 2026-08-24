<?php

declare(strict_types=1);

namespace Padosoft\EvalHarnessAiBridge\Testing;

use Padosoft\EvalHarness\Contracts\SampleRunner;
use Padosoft\EvalHarness\EvalEngine;
use Padosoft\EvalHarness\Reports\EvalReport;
use Padosoft\EvalHarness\Reports\SampleAggregate;
use Padosoft\EvalHarnessAiBridge\Runners\AgentSampleRunner;

/**
 * Runs an eval and turns the report into a pass/fail with a readable reason.
 *
 * ## Why an eval belongs in the test suite at all
 *
 * A golden dataset that only runs in a nightly job is a dataset nobody watches.
 * Running it from Pest or PHPUnit puts the same measurement next to the unit
 * tests, on the same red/green, in the loop where somebody is already looking.
 *
 * The catch is that an eval is not a unit test: it is *statistical*, it costs
 * money, and it is slow. So this is deliberately a **threshold** assertion
 * rather than an all-must-pass one — a suite that demands 100% on an LLM
 * pipeline is a suite that will be muted within a fortnight — and the failure
 * message names the rows, so a red build says which cases broke instead of
 * "macro-F1 0.71 < 0.80".
 */
final class EvalAssertion
{
    /** How many failing rows to name before pointing at the report. */
    private const MAX_NAMED_ROWS = 5;

    public function __construct(
        public readonly EvalReport $report,
        public readonly bool $passed,
        public readonly string $message,
    ) {}

    /**
     * @param  callable|SampleRunner  $systemUnderTest  a laravel/ai agent callable, or any eval-harness runner
     */
    public static function run(
        EvalEngine $engine,
        string $dataset,
        callable|SampleRunner $systemUnderTest,
        float $minMacroF1 = 0.8,
        ?float $minPassRate = null,
        ?int $repetitions = null,
        ?float $budgetUsd = null,
    ): self {
        $runner = $systemUnderTest instanceof SampleRunner
            ? $systemUnderTest
            : new AgentSampleRunner($systemUnderTest);

        $report = $engine->run($dataset, $runner, $repetitions, $budgetUsd);

        return self::judge($report, $minMacroF1, $minPassRate);
    }

    public static function judge(EvalReport $report, float $minMacroF1, ?float $minPassRate = null): self
    {
        $reasons = [];

        // Checked first: every other number below describes a partial run, and
        // the rows that never executed are disproportionately the ones that
        // would have failed.
        if ($report->wasHalted()) {
            $reasons[] = sprintf('the run halted on its budget (%s)', (string) $report->budget?->reason);
        }

        $macroF1 = $report->macroF1();

        if ($macroF1 < $minMacroF1) {
            $reasons[] = sprintf('macro-F1 %.4f is below the required %.4f', $macroF1, $minMacroF1);
        }

        if ($minPassRate !== null && $report->runPassRate() < $minPassRate) {
            $reasons[] = sprintf('pass rate %.4f is below the required %.4f', $report->runPassRate(), $minPassRate);
        }

        if ($reasons === []) {
            return new self($report, true, sprintf(
                "Dataset '%s' passed: macro-F1 %.4f over %d row(s).",
                $report->datasetName,
                $macroF1,
                $report->totalSamples(),
            ));
        }

        return new self($report, false, self::failureMessage($report, $reasons));
    }

    /**
     * @param  list<string>  $reasons
     */
    private static function failureMessage(EvalReport $report, array $reasons): string
    {
        $message = sprintf("Dataset '%s' failed: %s.", $report->datasetName, implode('; ', $reasons));

        $failing = [];

        foreach ($report->sampleAggregates() as $aggregate) {
            if ($aggregate->passRate < 1.0 || $aggregate->errored > 0) {
                $failing[] = $aggregate;
            }
        }

        if ($failing === []) {
            return $message;
        }

        usort($failing, static fn (SampleAggregate $left, SampleAggregate $right): int => ($left->scoreMean ?? -1.0) <=> ($right->scoreMean ?? -1.0));

        // Named, worst first: "macro-F1 0.71 < 0.80" tells nobody what to open.
        $message .= sprintf("\n%d failing row(s), worst first:", count($failing));

        foreach (array_slice($failing, 0, self::MAX_NAMED_ROWS) as $aggregate) {
            $message .= sprintf(
                "\n  - %s (score %s, pass rate %.0f%%)",
                $aggregate->sampleId,
                $aggregate->scoreMean === null ? 'n/a' : sprintf('%.4f', $aggregate->scoreMean),
                $aggregate->passRate * 100,
            );
        }

        if (count($failing) > self::MAX_NAMED_ROWS) {
            $message .= sprintf("\n  … and %d more.", count($failing) - self::MAX_NAMED_ROWS);
        }

        return $message."\nRun `php artisan eval-harness:brief <report>` for the full diagnosis.";
    }
}
