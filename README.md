<h1 align="center">eval-harness-ai-bridge</h1>

<p align="center">
  <strong>Evaluate a <code>laravel/ai</code> agent on <em>what it did</em>, not just what it said.</strong><br>
  The bridge between <a href="https://github.com/padosoft/eval-harness">padosoft/eval-harness</a> and the official Laravel AI SDK.
</p>

<p align="center">
  <a href="https://packagist.org/packages/padosoft/eval-harness-ai-bridge"><img alt="Packagist" src="https://img.shields.io/packagist/v/padosoft/eval-harness-ai-bridge.svg?style=flat-square"></a>
  <a href="https://github.com/padosoft/eval-harness-ai-bridge/actions"><img alt="CI" src="https://img.shields.io/github/actions/workflow/status/padosoft/eval-harness-ai-bridge/ci.yml?branch=main&style=flat-square"></a>
  <img alt="PHP" src="https://img.shields.io/badge/php-%3E%3D8.3-777bb3?style=flat-square">
  <img alt="Laravel" src="https://img.shields.io/badge/laravel-12%20%7C%2013-ff2d20?style=flat-square">
  <img alt="License" src="https://img.shields.io/badge/license-Apache--2.0-blue?style=flat-square">
</p>

---

## The bug you cannot see

Your support agent replies:

> *"Your order ships Tuesday."*

It is correct. Every metric you have scores it **1.0** — exact match, ROUGE, embeddings, even an LLM judge grading it against the golden answer.

It never called the order lookup.

It guessed, from the shape of the question and whatever was in its context, and it happened to land. It will land often enough to look healthy, and the day it does not, it will be wrong with total confidence — to a customer, about their order.

**No amount of scoring the text will ever tell you this.** The final answer is precisely the part a broken agent can still get right by accident.

## What this package does

`padosoft/eval-harness` can already score *how* an answer was produced: `tool-called`, `tool-called-with`, `tool-call-order`, `steps-below`, `no-pending-approvals`, `approval-gated`. It scores them against a plain **`Trajectory`** DTO that knows nothing about any SDK.

This package is the twelve lines that fill that DTO from a `laravel/ai` response — plus the two other things you need before an agent eval is real: **multi-turn conversations**, and an assertion you can put in your **test suite**.

```php
use Padosoft\EvalHarnessAiBridge\Runners\AgentSampleRunner;

$eval->dataset('support.agent')
    ->loadFromYaml(database_path('evals/support.yaml'))
    ->withMetrics(['llm-as-judge', 'tool-called', 'no-pending-approvals'])
    ->register();

$report = $eval->run('support.agent', new AgentSampleRunner(
    fn (array $input) => Ai::agent(SupportAgent::class)->prompt($input['question']),
));
```

That is the whole integration. The runner calls your agent, returns the text for the text metrics, and records the trajectory for the trajectory metrics.

```yaml
- id: ship-date
  input: { question: 'When does order 44192 ship?' }
  expected_output: 'Tuesday'
  metadata:
    trajectory:
      tools: [lookup_order]          # it must actually look it up
      arguments:
        lookup_order: { id: 44192 }  # and look up the right order
```

The guess now scores **0.0** on `tool-called`, and your build goes red on the bug that was invisible yesterday.

---

## Why the DTO is not the SDK's response object

This is the design decision the whole package exists to protect.

The obvious implementation is to have the harness accept a `Laravel\Ai\Responses\AgentResponse` directly. It is less code, and it is the shape competing tools ship. It also means **your eval suite is only as portable as your SDK choice**: move to a custom orchestrator, to MCP, to `laravel-flow` saga steps, or simply to the next major version of the SDK, and every agent assertion you wrote has to be rewritten.

So the harness scores a DTO, and the translation lives here — in **one file** whose blast radius an architecture test keeps honest:

```php
public function test_the_harness_itself_never_references_the_ai_sdk(): void
public function test_only_the_adapter_and_the_runner_know_about_laravel_ai(): void
```

A boundary that is only described in a README erodes. These fail when it does.

---

## What gets translated

| `laravel/ai` | `Trajectory` |
|---|---|
| `$response->toolCalls` | `toolCalls[]` — name and arguments |
| `$response->toolResults` | the `result` / `error` on the **matching** call |
| `$response->steps` | `steps` |
| last `Step::$finishReason` | `finishReason` |
| `$response->pendingApprovals` | `pendingApprovals` |
| approved tool results | `approvals[]` |
| usage, provider, model | `metadata` |

Four details that are not obvious, and each has a test:

- **Results join calls by id, never by position.** Parallel tools return out of order, and a pending call has no result at all. Matching by index silently attaches one call's outcome to another's.
- **A denied call is recorded as failed, with no result.** The tool never ran. *"Did it look the order up?"* must not be satisfied by a rejection.
- **A run stopped on an approval reports `pending_approval`, not `stop`.** Text that says *"I have submitted that"* while an approval is pending reads as success and is not.
- **Usage travels in the metadata, in the shape eval-harness's cost ledger reads** — so agent spend appears next to judge spend instead of being quietly treated as free.

---

## Multi-turn conversations

The failure that costs the most in a real assistant is not a wrong first answer. It is the model that answers turn one perfectly and **forgets it by turn three**. A single-turn dataset cannot express that.

```yaml
name: support.multi-turn
conversations:
  - id: refund-then-address
    tags: [returns]
    turns:
      - user: 'I want to return the boots I bought last week.'
        expect: 'asks for the order number'
      - user: 'It is 44192.'
        expect: 'confirms the 30-day window'
      - user: 'And can you send it to my work address instead?'
        expect: 'uses the address from the order, does not re-ask for the order number'
        tags: [context-retention]
```

```php
use Padosoft\EvalHarnessAiBridge\Datasets\ConversationDataset;

$eval->dataset('support.multi-turn')
    ->withSamples(ConversationDataset::fromFile(database_path('evals/support-conversations.yaml')))
    ->withMetrics(['llm-as-judge'])
    ->register();
```

**Each turn becomes its own row**, carrying every turn before it as `input.history`. That shape earns three things:

- the report names **the turn that broke**, not "conversation 4 failed" — a pass rate over turns tells you *where* an assistant loses the thread, which is the number that leads to a fix;
- **every existing metric works unchanged**, because a turn is a row;
- turns are independently addressable, so the regression gate joins **turn 3** across runs by content hash like any other row.

The history holds the *dataset's* expected answers, never the model's own previous output. An eval whose turn-3 input depends on what the model said at turn 2 measures something different on every run and cannot be compared to itself.

---

## Evals in your test suite

A golden dataset that only runs in a nightly job is a dataset nobody watches. Put it on the same red/green as everything else:

**Pest**

```php
it('holds its ground on support questions', function () {
    expect(fn (array $input) => Ai::agent(SupportAgent::class)->prompt($input['question']))
        ->toPassEval('support.agent', minMacroF1: 0.85);
});
```

The expectation registers itself when Pest is installed. If it ever comes back as
an unknown expectation, Composer loaded this package's `files` entry before
Pest's own — an order that is not specified between sibling packages, and this
one only *suggests* Pest so there is no dependency edge to order them by. One
line in `tests/Pest.php` settles it:

```php
\Padosoft\EvalHarnessAiBridge\Testing\registerPestExpectations();
```

**PHPUnit**

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

An eval is not a unit test: it is statistical, slow, and it costs money. So this is deliberately a **threshold** assertion — a suite that demands 100% on an LLM pipeline is a suite that gets muted within a fortnight — and a failure names the rows:

```
Dataset 'support.agent' failed: macro-F1 0.7143 is below the required 0.8500.
3 failing row(s), worst first:
  - refund-window (score 0.1000, pass rate 0%)
  - work-address (score 0.3000, pass rate 33%)
  - stock-check (score 0.6000, pass rate 67%)
Run `php artisan eval-harness:brief <report>` for the full diagnosis.
```

`macro-F1 0.71 < 0.85` tells nobody what to open.

Three more things the assertion does:

- **`--repetitions` and `--budget-usd` are available from the test.** An LLM pipeline that answers correctly two times in three is not a pipeline that scores 1.0, and one execution cannot tell those apart.
- **A run halted on its budget can never pass**, whatever the numbers say. The rows that never executed are disproportionately the ones that would have failed.
- **One run can feed several assertions** (`assertEvalReportPasses`). Running an eval three times to check three thresholds is three bills.

---

## Installation

```bash
composer require --dev padosoft/eval-harness-ai-bridge
```

Requires PHP 8.3+, Laravel 12 or 13, `padosoft/eval-harness` ^1.6, and `laravel/ai`.

There is **no service provider and nothing to configure**. A package whose job is to connect two other packages should not become a third thing to configure: everything here is a class you construct where you use it. (The one caveat is the Pest expectation's autoload order, above.)

---

## API

| Class | What it is for |
|---|---|
| `Trajectories\AgentResponseTrajectory::fromResponse()` | translate any `laravel/ai` response into a `Trajectory` |
| `Runners\AgentSampleRunner` | run an agent as the system-under-test and record its trajectory |
| `Datasets\ConversationDataset::fromFile()` | multi-turn conversations as dataset rows |
| `Testing\AssertsEvals` | `assertPassesEval()` / `assertEvalReportPasses()` for PHPUnit |
| `Testing\EvalAssertion` | the run-and-judge primitive both surfaces sit on |
| `expect(...)->toPassEval()` | the Pest expectation |
| `Testing\registerPestExpectations()` | registers it; idempotent, and a no-op without Pest |

Already recording trajectories yourself? Use the adapter alone:

```php
$trajectory = AgentResponseTrajectory::fromResponse($response);
app(TrajectoryRecorder::class)->record($sample->id, $trajectory);
```

---

## Part of the Padosoft AI suite

| Package | What it does |
|---|---|
| **[padosoft/eval-harness](https://github.com/padosoft/eval-harness)** (+ `-admin`) | Golden datasets, RAG metrics, repeated sampling with real statistics, baselines and per-row regression gates, run briefings, cost budgets, adversarial testing |
| [padosoft/laravel-evidence-risk-review](https://github.com/padosoft/laravel-evidence-risk-review) | Evidence tiers and risk sweeps — also a **zero-token** eval metric |
| [padosoft/laravel-pii-redactor](https://github.com/padosoft/laravel-pii-redactor) | Field-level PII detection and masking inside the app boundary |
| [padosoft/laravel-flow](https://github.com/padosoft/laravel-flow) (+ `-ai`, `-admin`) | Saga engine with approval gates and replay for AI workflows |
| [padosoft/laravel-ai-finops](https://github.com/padosoft/laravel-ai-finops) | Budgets, quotas and chargeback for AI spend |
| [padosoft/laravel-iam-agents](https://github.com/padosoft/laravel-iam-agents) | Delegated access: an agent acts for a user without ever holding their token |

---

## Contributing

Read `CONTRIBUTING.md` and `docs/LESSON.md` before opening a PR. Every change ships with `composer validate --strict`, Pint, PHPStan level 6 and PHPUnit green.

## License

Apache-2.0. See [LICENSE](LICENSE).
