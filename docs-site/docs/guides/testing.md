# Evals in your test suite

A golden dataset that only runs in a nightly job is a dataset nobody watches. Running it from Pest or PHPUnit puts the same measurement next to your unit tests, on the same red/green, in the loop where somebody is already looking.

## Pest

```php
it('holds its ground on support questions', function () {
    expect(fn (array $input) => Ai::agent(SupportAgent::class)->prompt($input['question']))
        ->toPassEval('support.agent', minMacroF1: 0.85);
});
```

The expectation registers itself when Pest is installed.

If it comes back as an unknown expectation, Composer loaded this package's
`files` entry before Pest's own — an order that is not specified between sibling
packages, and this one only *suggests* Pest so there is no dependency edge to
order them by. One line in `tests/Pest.php` settles it permanently:

```php
\Padosoft\EvalHarnessAiBridge\Testing\registerPestExpectations();
```

## PHPUnit

```php
use Padosoft\EvalHarnessAiBridge\Testing\AssertsEvals;

final class SupportAgentTest extends TestCase
{
    use AssertsEvals;

    public function test_the_support_agent_holds_its_ground(): void
    {
        $this->assertPassesEval(
            'support.agent',
            fn (array $input) => Ai::agent(SupportAgent::class)->prompt($input['question']),
            minMacroF1: 0.85,
        );
    }
}
```

Both surfaces sit on the same primitive, `EvalAssertion`, so they behave identically.

## An eval is not a unit test

It is **statistical**, it is **slow**, and it **costs money**. Three consequences shaped this API.

### It is a threshold, not an all-must-pass

```php
$this->assertPassesEval('support.agent', $agent, minMacroF1: 0.85, minPassRate: 0.9);
```

A suite that demands 100% on an LLM pipeline is a suite that goes red on noise, and a suite that goes red on noise gets muted within a fortnight. Pick the number you would actually block a release on.

`minPassRate` is a separate floor because the two say different things: macro-F1 is *how well* on average, pass rate is *how many* rows cleared the bar. A pipeline can hold its average while a fifth of its rows collapse.

### The failure names the rows

```
Dataset 'support.agent' failed: macro-F1 0.7143 is below the required 0.8500.
3 failing row(s), worst first:
  - refund-window (score 0.1000, pass rate 0%)
  - work-address (score 0.3000, pass rate 33%)
  - stock-check (score 0.6000, pass rate 67%)
Run `php artisan eval-harness:brief <report>` for the full diagnosis.
```

`macro-F1 0.71 < 0.85` tells nobody what to open.

### One run can feed several assertions

```php
$report = $this->evalEngine()->run('support.agent', new AgentSampleRunner($agent));

$this->assertEvalReportPasses($report, minMacroF1: 0.85);
$this->assertEvalReportPasses($report, minMacroF1: 0.5, minPassRate: 0.95);
```

Running an eval three times to check three thresholds is three bills.

## Repetitions and budgets, from the test

```php
$this->assertPassesEval(
    'support.agent',
    $agent,
    minMacroF1: 0.85,
    repetitions: 3,
    budgetUsd: 0.50,
);
```

**`repetitions`** matters more for an agent than for a RAG pipeline. An agent that calls the right tool two times in three is not an agent that scores 1.0, and a single execution cannot tell those apart — it just flips a build red on Tuesday and green on Wednesday with no change in between.

**`budgetUsd`** stops a runaway loop before the invoice does. A test suite that accidentally runs a thousand-row eval on every push is a discovery people make monthly.

## A halted run can never pass

If the budget stops the run, the assertion fails **whatever the numbers say**:

```
Dataset 'support.agent' failed: the run halted on its budget
(Spent $0.5100 of a $0.5000 budget after 12 rows).
```

A halted run is incomplete data, and the rows that never executed are disproportionately the ones that would have failed. Green has to mean *everything ran and everything passed*.

## Where to run it

An eval that hits a real provider does not belong in the suite that runs on every save. The usual arrangement:

- a **small, fast dataset** (a dozen rows, no repetitions) in the normal test suite, as a smoke test;
- the **full dataset** in a separate PHPUnit group or Pest `--group=eval`, run in CI on pull requests, with `--compare=baseline` doing the actual gating;
- `Ai::fake()` for everything else, because most of your tests are about your code and not about the model.
