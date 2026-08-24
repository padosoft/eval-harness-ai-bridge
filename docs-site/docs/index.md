# eval-harness-ai-bridge

Your support agent replies:

> *"Your order ships Tuesday."*

It is correct. Every metric you have scores it **1.0** — exact match, ROUGE, embeddings, even an LLM judge grading it against the golden answer.

It never called the order lookup.

It guessed, from the shape of the question and whatever was in its context, and it happened to land. It will land often enough to look healthy, and the day it does not, it will be wrong with total confidence — to a customer, about their order.

**No amount of scoring the text will ever tell you this.** The final answer is precisely the part a broken agent can still get right by accident.

## What this package is

[`padosoft/eval-harness`](https://github.com/padosoft/eval-harness) can already score *how* an answer was produced — `tool-called`, `tool-called-with`, `tool-call-order`, `steps-below`, `no-pending-approvals`, `approval-gated` — against a plain `Trajectory` DTO that knows nothing about any agent SDK.

This package fills that DTO from a `laravel/ai` response, and adds the two other things an agent eval needs before it is real:

| | |
|---|---|
| **[Trajectories](/guides/trajectories)** | translate an `AgentResponse` into something scoreable |
| **[Conversations](/guides/conversations)** | multi-turn datasets, one row per turn |
| **[Testing](/guides/testing)** | `toPassEval()` in Pest, `assertPassesEval()` in PHPUnit |

## Thirty seconds

```bash
composer require --dev padosoft/eval-harness-ai-bridge
```

```php
use Padosoft\EvalHarnessAiBridge\Runners\AgentSampleRunner;

$eval->dataset('support.agent')
    ->loadFromYaml(database_path('evals/support.yaml'))
    ->withMetrics(['llm-as-judge', 'tool-called'])
    ->register();

$report = $eval->run('support.agent', new AgentSampleRunner(
    fn (array $input) => Ai::agent(SupportAgent::class)->prompt($input['question']),
));
```

```yaml
- id: ship-date
  input: { question: 'When does order 44192 ship?' }
  expected_output: 'Tuesday'
  metadata:
    trajectory:
      tools: [lookup_order]
      arguments:
        lookup_order: { id: 44192 }
```

The guess now scores **0.0**, and your build goes red on the bug that was invisible yesterday.

There is no service provider and nothing to configure — see [Installation](/installation).

## Where to go next

- [Quickstart](/quickstart) — the full loop, end to end
- [Why the DTO is not the SDK](/concepts/sdk-boundary) — the design decision this package exists to protect
- [What a text metric cannot see](/concepts/unearned-answers) — the failure modes, named
