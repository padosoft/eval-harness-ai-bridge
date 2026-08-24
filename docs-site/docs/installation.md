# Installation

```bash
composer require --dev padosoft/eval-harness-ai-bridge
```

| Requirement | Version |
|---|---|
| PHP | 8.3+ |
| Laravel | 12 or 13 |
| `padosoft/eval-harness` | ^1.6 |
| `laravel/ai` | ^0.11 |

`--dev` is the usual choice: evals run in CI and in your test suite, not in the request path. Install it in `require` only if you promote production interactions or run evals from a scheduled job inside the same deployment.

## There is nothing to configure

No service provider, no config file, no published assets.

A package whose job is to connect two other packages should not become a third thing to configure. Everything here is a class you construct where you use it:

```php
use Padosoft\EvalHarnessAiBridge\Datasets\ConversationDataset;
use Padosoft\EvalHarnessAiBridge\Runners\AgentSampleRunner;
use Padosoft\EvalHarnessAiBridge\Trajectories\AgentResponseTrajectory;
```

The one exception is the Pest expectation, which is auto-registered through a Composer `files` autoload entry — Pest has no service provider to hook, and asking every host to remember a registration line in `Pest.php` is how a nice API becomes an unused one. Without Pest installed, that file defines a function that never runs.

## Configuration lives in eval-harness

Judges, embeddings, report disks, cost rates and budgets are all `eval-harness` concerns. See its [configuration guide](https://doc.eval-harness.padosoft.com/configuration).

## Verifying the install

```php
use Padosoft\EvalHarnessAiBridge\Trajectories\AgentResponseTrajectory;

$trajectory = AgentResponseTrajectory::fromResponse(
    Ai::agent(SupportAgent::class)->prompt('When does order 44192 ship?')
);

dump($trajectory->toolNames());   // ['lookup_order']
dump($trajectory->stepCount());   // 2
```

If `toolNames()` comes back empty on an agent you know uses tools, the agent returned before the tool loop ran — that is a real finding, not an install problem.
