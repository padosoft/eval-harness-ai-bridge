<?php

declare(strict_types=1);

use Padosoft\EvalHarness\Contracts\SampleRunner;
use Padosoft\EvalHarness\EvalEngine;
use Padosoft\EvalHarnessAiBridge\Testing\AssertsEvals;
use Padosoft\EvalHarnessAiBridge\Testing\EvalAssertion;
use PHPUnit\Framework\Assert;

/**
 * Pest surface: `expect($agent)->toPassEval('support.agent')`.
 *
 * Autoloaded as a `files` entry so the expectation exists the moment the
 * package is installed — Pest has no service provider to hook, and asking every
 * host to remember a registration line in Pest.php is how a nice API becomes an
 * unused one.
 *
 * The whole file is a no-op without Pest: `expect()` is Pest's, and the
 * `extend` API only exists there. So installing this package in a PHPUnit-only
 * project adds a function definition that never runs, and the
 * {@see AssertsEvals} trait is the
 * surface that works everywhere.
 */
if (! function_exists('Padosoft\EvalHarnessAiBridge\Testing\registerPestExpectations')) {
    /**
     * @internal
     */
    function padosoft_eval_harness_ai_bridge_register_pest_expectations(): void
    {
        if (! function_exists('expect')) {
            return;
        }

        $expectation = expect();

        if (! method_exists($expectation, 'extend')) {
            return;
        }

        $expectation::extend('toPassEval', function (
            string $dataset,
            float $minMacroF1 = 0.8,
            ?float $minPassRate = null,
            ?int $repetitions = null,
            ?float $budgetUsd = null,
        ) {
            /** @var object{value: mixed} $this */
            $subject = $this->value;

            if (! is_callable($subject) && ! $subject instanceof SampleRunner) {
                throw new InvalidArgumentException(
                    'toPassEval() expects a callable that prompts an agent, or an eval-harness SampleRunner.',
                );
            }

            /** @var EvalEngine $engine */
            $engine = app(EvalEngine::class);

            $assertion = EvalAssertion::run(
                engine: $engine,
                dataset: $dataset,
                systemUnderTest: $subject,
                minMacroF1: $minMacroF1,
                minPassRate: $minPassRate,
                repetitions: $repetitions,
                budgetUsd: $budgetUsd,
            );

            Assert::assertTrue($assertion->passed, $assertion->message);

            return $this;
        });
    }

    padosoft_eval_harness_ai_bridge_register_pest_expectations();
}
