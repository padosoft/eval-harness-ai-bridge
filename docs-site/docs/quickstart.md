# Quickstart

The whole loop, from an untested agent to a red build on the bug you could not see.

## 1. An agent

```php
namespace App\Ai;

use Laravel\Ai\Attributes\Tool;

final class SupportAgent
{
    public function instructions(): string
    {
        return 'You answer customer questions about orders. Always look the order up.';
    }

    #[Tool('Look up an order by id.')]
    public function lookup_order(int $id): array
    {
        return Order::findOrFail($id)->only(['status', 'ships_on']);
    }
}
```

## 2. A dataset

`database/evals/support.yaml`:

```yaml
name: support.agent
samples:
  - id: ship-date
    input:
      question: 'When does order 44192 ship?'
    expected_output: 'Tuesday'
    metadata:
      tags: [orders]
      trajectory:
        tools: [lookup_order]
        arguments:
          lookup_order: { id: 44192 }

  - id: refund-window
    input:
      question: 'How long do I have to return the boots?'
    expected_output: '30 days from delivery'
    metadata:
      tags: [returns]
```

`metadata.trajectory` is what turns *"the answer was right"* into *"the answer was **earned**"*. See [Trajectories](/guides/trajectories) for every expectation available.

## 3. Register and run

```php
use Padosoft\EvalHarnessAiBridge\Runners\AgentSampleRunner;

$eval->dataset('support.agent')
    ->loadFromYaml(database_path('evals/support.yaml'))
    ->withMetrics(['llm-as-judge', 'tool-called', 'tool-called-with', 'no-pending-approvals'])
    ->register();

$report = $eval->run('support.agent', new AgentSampleRunner(
    fn (array $input) => Ai::agent(SupportAgent::class)->prompt($input['question']),
));
```

Or from the CLI, once the dataset is registered in a service provider:

```bash
php artisan eval-harness:run support.agent --repetitions=3 --budget-usd=1.00
```

`--repetitions=3` matters more for an agent than for a RAG pipeline: an agent that calls the right tool two times in three is not an agent that scores 1.0, and one execution cannot tell those apart. `--budget-usd` stops a runaway loop before the invoice does.

## 4. Put it in the test suite

```php
it('holds its ground on support questions', function () {
    expect(fn (array $input) => Ai::agent(SupportAgent::class)->prompt($input['question']))
        ->toPassEval('support.agent', minMacroF1: 0.85);
});
```

A failure names the rows:

```
Dataset 'support.agent' failed: macro-F1 0.7143 is below the required 0.8500.
3 failing row(s), worst first:
  - refund-window (score 0.1000, pass rate 0%)
  …
Run `php artisan eval-harness:brief <report>` for the full diagnosis.
```

See [Evals in your test suite](/guides/testing).

## 5. Gate the pull request

```bash
php artisan eval-harness:baseline support.agent           # promote today's run

php artisan eval-harness:run support.agent \
    --repetitions=3 --compare=baseline --max-regressions=0 --confident-only
```

`--confident-only` counts only the drops the run could actually detect — the difference between a gate people trust and a gate people learn to re-run.

## 6. When it breaks

```bash
php artisan eval-harness:brief report.json \
    --dataset=database/evals/support.yaml --format=github
```

The briefing carries the tool calls, so a failing row reads *"it answered without calling `lookup_order`"* rather than *"score 0.2"*.
