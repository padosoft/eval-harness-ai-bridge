# Why the DTO is not the SDK

This is the design decision the whole package exists to protect, and it is worth being explicit about because the alternative is genuinely less code.

## The obvious implementation

Have the eval harness accept a `Laravel\Ai\Responses\AgentResponse` directly:

```php
// Not what this ecosystem does.
$eval->assertToolCalled($response, 'lookup_order');
```

It is shorter. It needs no adapter, no DTO, no bridge package. It is also the shape competing tools ship, and it is a reasonable thing to build.

## What it costs

Your eval suite becomes **exactly as portable as your SDK choice**.

Move to a custom orchestrator, to an MCP tool server, to `laravel-flow` saga steps, to a different SDK, or simply to the next major version of the one you are on — and every agent assertion in every dataset has to be rewritten, because the assertions were written against a response object that no longer exists.

That is a bad trade for a *test suite* specifically. Test suites are supposed to be the thing that survives a refactor and tells you whether it worked. An eval suite that has to be rewritten alongside the runtime it evaluates cannot answer the one question you needed it for: *did the migration change the behaviour?*

## What this ecosystem does instead

`padosoft/eval-harness` scores a plain `Trajectory` DTO:

```php
new Trajectory(
    toolCalls: [new ToolCall('lookup_order', ['id' => 44192], result: 'shipped')],
    steps: 3,
    finishReason: 'stop',
    pendingApprovals: 0,
    approvals: ['refund_order'],
);
```

No SDK types, no framework coupling, nothing that knows how the answer was produced beyond what happened. Fill it from `laravel/ai`, from a hand-rolled tool loop, from an MCP transcript, from a recorded JSON file, from `laravel-flow` steps.

The dataset stays the same across all of them:

```yaml
metadata:
  trajectory:
    tools: [lookup_order]
    order: [lookup_order, refund_order]
```

**The eval outlives the runtime.** That is the property being bought, and it is worth one adapter file.

## The blast radius is two files, and it is tested

```php
public function test_the_harness_itself_never_references_the_ai_sdk(): void
public function test_only_the_adapter_and_the_runner_know_about_laravel_ai(): void
```

A boundary that is only described in a README erodes on the third Tuesday somebody is in a hurry. These architecture tests fail when it does — including if `eval-harness` itself ever grows a dependency on an agent SDK, at which point this package would have lost its reason to exist.

## The same reasoning, elsewhere in the suite

It is the pattern this whole ecosystem is built on, not a one-off:

- `laravel-evidence-risk-review` ships an eval metric that implements the harness's `Metric` interface, with the harness as a `require-dev` — the specialised package learns about the generic one, never the reverse.
- `laravel-flow-ai` runs agents without the runtime learning about IAM; delegated identity arrives through a capability.
- The harness's cost seam is an **event** (`EvalRunCosted`), so a FinOps package can attribute eval spend without either package depending on the other.

The rule underneath all of them: **the generic package must not learn about the specific one.** When two packages need to meet, the meeting happens in a third that either of them can be swapped out from under.
