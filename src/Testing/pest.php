<?php

declare(strict_types=1);

namespace Padosoft\EvalHarnessAiBridge\Testing;

use InvalidArgumentException;
use Padosoft\EvalHarness\Contracts\SampleRunner;
use Padosoft\EvalHarness\EvalEngine;
use PHPUnit\Framework\Assert;

/**
 * Pest surface: `expect($agent)->toPassEval('support.agent')`.
 *
 * ## Why this is a function you can call, not only an autoload side effect
 *
 * Composer runs this file as a `files` autoload entry, and the common case is
 * that Pest's own function file has already run by then — so the call at the
 * bottom registers the expectation and a host does nothing.
 *
 * But the order of `files` entries across sibling packages is **not
 * guaranteed**, and this package only *suggests* Pest rather than depending on
 * it, so there is no dependency edge to order them by. In the load order where
 * this file runs first, `expect()` does not exist yet and there is no second
 * chance: Composer will not re-run the file.
 *
 * Rather than pretend that cannot happen, the registration is a named,
 * idempotent function. If `expect(...)->toPassEval()` ever comes back as an
 * unknown expectation, one line in `tests/Pest.php` fixes it for good:
 *
 * ```php
 * \Padosoft\EvalHarnessAiBridge\Testing\registerPestExpectations();
 * ```
 *
 * Calling it twice is safe, and calling it without Pest installed is a no-op —
 * the {@see AssertsEvals} trait is the surface that works everywhere.
 */
if (! function_exists(__NAMESPACE__.'\registerPestExpectations')) {
    /**
     * Register the `toPassEval` expectation with Pest.
     *
     * @return bool whether the expectation is registered — false when Pest is
     *              absent or has not booted yet, which is not an error
     */
    function registerPestExpectations(): bool
    {
        static $registered = false;

        if ($registered) {
            return true;
        }

        if (! function_exists('expect')) {
            return false;
        }

        $expectation = expect();

        if (! method_exists($expectation, 'extend')) {
            return false;
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

        $registered = true;

        return true;
    }

    // Best effort at autoload time; the documented one-liner covers the load
    // order where Pest has not defined expect() yet.
    registerPestExpectations();
}
