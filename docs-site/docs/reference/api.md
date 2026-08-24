# API reference

## `Trajectories\AgentResponseTrajectory`

```php
public static function fromResponse(TextResponse $response): Trajectory
```

Translates any `laravel/ai` response (`AgentResponse`, `TextResponse`, `StructuredAgentResponse`, …) into an eval-harness `Trajectory`.

Accepts `TextResponse` rather than `AgentResponse` deliberately: every response in the SDK that can carry tool calls extends it, so a structured or streamed response works without a second adapter.

## `Runners\AgentSampleRunner`

```php
public function __construct(
    callable $agent,                              // fn (array $input, SampleInvocation $sample): TextResponse
    ?TrajectoryRecorder $trajectories = null,     // resolved from the container when omitted
)

public function run(SampleInvocation $sample): string
```

Implements eval-harness's `SampleRunner`, so it works with every batch mode including queue-backed parallel runs.

- returns `$response->text` as the answer scored by the text metrics;
- records the translated trajectory for the trajectory metrics;
- raises `EvalRunException` naming the row when the callable returns something that is not a `laravel/ai` response — a wiring mistake stringified silently would become a dataset of empty answers scored 0.0, blaming the pipeline for the harness's own wiring;
- degrades without a recorder: a missing recorder costs the trajectory metrics, not the run.

## `Datasets\ConversationDataset`

```php
public static function fromFile(string $path): array    // list<DatasetSample>
public static function fromString(string $yaml): array
public static function fromArray(array $decoded): array
```

Turns a conversation YAML into one dataset row per turn. See [Multi-turn conversations](/guides/conversations).

Row ids are `<conversation-id>#<turn-number>`; `input.history` carries every preceding turn as `['user' => …, 'assistant' => …]`; `metadata` carries `conversation_id`, `turn`, merged `tags`, and any extra metadata from either level.

Raises `DatasetSchemaException` — naming the conversation and the turn — for an empty list, a missing id, a missing `user` or `expect`, or a duplicate conversation id.

## `Testing\AssertsEvals`

```php
protected function assertPassesEval(
    string $dataset,
    callable|SampleRunner $systemUnderTest,
    float $minMacroF1 = 0.8,
    ?float $minPassRate = null,
    ?int $repetitions = null,
    ?float $budgetUsd = null,
): EvalReport

protected function assertEvalReportPasses(
    EvalReport $report,
    float $minMacroF1 = 0.8,
    ?float $minPassRate = null,
): EvalReport
```

Both return the report, so a test can assert further on it. A plain callable is wrapped in an `AgentSampleRunner`; a `SampleRunner` is used as given.

## `Testing\EvalAssertion`

```php
public static function run(EvalEngine $engine, string $dataset, callable|SampleRunner $sut, ...): self
public static function judge(EvalReport $report, float $minMacroF1, ?float $minPassRate = null): self

public readonly EvalReport $report;
public readonly bool $passed;
public readonly string $message;
```

The primitive under both testing surfaces. Use it directly when you want the verdict without an assertion — in a custom command, a scheduled job, or a health check.

A report that was **halted on its budget** never passes, whatever its numbers say.

## `expect(...)->toPassEval()`

```php
expect($agent)->toPassEval(
    string $dataset,
    float $minMacroF1 = 0.8,
    ?float $minPassRate = null,
    ?int $repetitions = null,
    ?float $budgetUsd = null,
);
```

Registered automatically when Pest is installed, via a Composer `files` autoload entry. The subject must be a callable or a `SampleRunner`.
