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

The one exception is the Pest expectation, registered through a Composer `files`
autoload entry — Pest has no service provider to hook.

That is best-effort rather than a guarantee, and it is worth being precise about
why: the order of `files` entries **across sibling packages is not specified**,
and this package only *suggests* Pest, so there is no dependency edge to order
them by. In the load order where this file runs before Pest's own function file,
`expect()` does not exist yet, and Composer will not re-run the file.

So the registration is a named, idempotent function you can call yourself. If
`toPassEval` ever comes back as an unknown expectation, one line in
`tests/Pest.php` settles it:

```php
\Padosoft\EvalHarnessAiBridge\Testing\registerPestExpectations();
```

Calling it twice is safe; calling it without Pest is a no-op returning `false`.
Without Pest installed nothing runs at all, and the `AssertsEvals` trait is the
surface that works everywhere.

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
