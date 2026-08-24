# Contributing

Thanks for helping.

## Before you open a PR

Every change ships with all four gates green:

```bash
composer validate --strict
vendor/bin/pint
vendor/bin/phpstan analyse
vendor/bin/phpunit
```

CI runs the same four across PHP 8.3/8.4/8.5 and Laravel 12/13.

## The invariant this package exists to keep

`padosoft/eval-harness` must never learn about `laravel/ai`, and only
`src/Trajectories/AgentResponseTrajectory.php` and
`src/Runners/AgentSampleRunner.php` may reference the SDK.

`tests/Architecture/BoundaryTest.php` enforces both. If a change needs a third
SDK-aware file, that is a design conversation before it is a test-list edit —
the point of the boundary is that a future runtime swap has a known blast
radius.

## Tests

Every `laravel/ai` response in the suite is constructed by hand. The package is
about *translating* a response, so a test that needed a provider would be
testing the provider — and it would be slow, non-deterministic, and billable.

Write the test as a sentence about behaviour (`test_results_are_joined_by_call_id_not_by_position`),
and where a case exists because it would otherwise be a real bug, say so in a
docblock. The failure modes here are subtle; a reader six months out deserves
the reason, not just the assertion.

## Docs

`README.md` is the pitch; `docs-site/docs/` is the manual. A behaviour change
updates both. `docs/LESSON.md` records decisions and the reasoning behind them —
append to it when a change involved a trade-off worth remembering.
