# Trajectories

A trajectory is **how** an answer was produced: which tools were called, with what arguments, in what order, in how many steps, and whether anybody approved the actions that needed approving.

`padosoft/eval-harness` scores one. This package fills it from a `laravel/ai` response.

## The translation

```php
use Padosoft\EvalHarnessAiBridge\Trajectories\AgentResponseTrajectory;

$trajectory = AgentResponseTrajectory::fromResponse($response);
```

| `laravel/ai` | `Trajectory` |
|---|---|
| `$response->toolCalls` | `toolCalls[]` — name and arguments |
| `$response->toolResults` | the `result` / `error` on the **matching** call |
| `$response->steps` | `steps` |
| last `Step::$finishReason` | `finishReason` |
| `$response->pendingApprovals` | `pendingApprovals` |
| approved tool results | `approvals[]` |
| usage, provider, model | `metadata` |

Most of the time you never call it directly — `AgentSampleRunner` does it for you:

```php
use Padosoft\EvalHarnessAiBridge\Runners\AgentSampleRunner;

$eval->run('support.agent', new AgentSampleRunner(
    fn (array $input) => Ai::agent(SupportAgent::class)->prompt($input['question']),
));
```

The callable receives the row's `input` array and, if you type a second parameter, the `SampleInvocation`. Return whatever `laravel/ai` gave back; the runner takes `->text` for the text metrics and records the trajectory for the trajectory metrics.

## Four details that are not obvious

Each has a test, because each is a bug somebody would otherwise ship.

**Results join calls by id, never by position.** Parallel tools return out of order, and a call still awaiting approval has no result at all. Matching by index silently attaches one call's outcome to another's — a failure that looks like a passing eval.

**A denied call is recorded as failed, with no result.** The tool never ran. An assertion asking *"did it look the order up?"* must not be satisfied by a rejection.

**A run stopped on an approval reports `pending_approval`, not `stop`.** Text that says *"I have submitted that refund"* while an approval is pending reads as success and is not. This is the difference between a compliance finding and a UX detail.

**Usage travels in `metadata.usage`, in the shape eval-harness's cost ledger reads.** Agent spend then appears next to judge spend in the run's cost report instead of being quietly treated as free.

## What you can assert

Expectations live in the row's `metadata.trajectory`:

```yaml
- id: refund-request
  input: { question: 'Refund order 44192 please.' }
  expected_output: 'confirms the refund is submitted for approval'
  metadata:
    trajectory:
      tools: [lookup_order, refund_order]        # tool-called
      forbidden: [charge_card]                    # tool-not-called
      arguments:
        lookup_order: { id: 44192 }               # tool-called-with
      order: [lookup_order, refund_order]         # tool-call-order
      max_steps: 6                                # steps-below
      approvals: [refund_order]                   # approval-gated
```

| Metric | Asks |
|---|---|
| `tool-called` | did it use the tools this row required? |
| `tool-not-called` | did it stay away from the ones it must not use here? |
| `tool-called-with` | did it look up the **right** record? |
| `tool-call-order` | did it check stock *before* charging, not after? |
| `steps-below` | did it stay inside its step budget? |
| `no-pending-approvals` | did it finish, or did it stop? |
| `approval-gated` | did the actions that needed approval get it? |

Two matching rules worth knowing, both from eval-harness:

- **Arguments match as a subset.** A runtime that adds a trace id has still made the call.
- **Order matches as a subsequence.** A new tool appearing *between* two expected ones must not fail an eval that was about the two.

## Using the adapter on its own

Already recording trajectories from somewhere else? Skip the runner:

```php
use Padosoft\EvalHarness\Trajectory\TrajectoryRecorder;
use Padosoft\EvalHarnessAiBridge\Trajectories\AgentResponseTrajectory;

app(TrajectoryRecorder::class)->record(
    $sample->id,
    AgentResponseTrajectory::fromResponse($response),
);
```

## What happens without a recorder

`AgentSampleRunner` resolves the recorder from the container and degrades quietly if there is none — a missing recorder costs you the trajectory metrics, not the run. That keeps the runner usable in a plain unit test with no application booted.

A missing *trajectory* is different: eval-harness raises a `MetricException` on a trajectory metric with nothing to score, captured as a failure. Scoring 0 would blame the agent for the harness's wiring; scoring 1 would let a dataset go green because nobody plugged the recorder in.
