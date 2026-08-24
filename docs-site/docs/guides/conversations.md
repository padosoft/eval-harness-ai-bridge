# Multi-turn conversations

The failure that costs the most in a real assistant is not a wrong first answer. It is the model that answers turn one perfectly and **forgets it by turn three**.

A single-turn dataset cannot express that. Every row is independent, so every row is turn one.

## The file

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

## One row per turn

Each turn becomes a dataset row, with id `<conversation>#<n>` and every preceding turn in `input.history`:

```php
[
    'id' => 'refund-then-address#3',
    'input' => [
        'question' => 'And can you send it to my work address instead?',
        'history' => [
            ['user' => 'I want to return the boots…', 'assistant' => 'asks for the order number'],
            ['user' => 'It is 44192.', 'assistant' => 'confirms the 30-day window'],
        ],
    ],
    'expected_output' => 'uses the address from the order, does not re-ask for the order number',
    'metadata' => [
        'conversation_id' => 'refund-then-address',
        'turn' => 3,
        'tags' => ['returns', 'context-retention'],
    ],
]
```

That shape is deliberate, and it earns three things:

**The report names the turn that broke**, not *"conversation 4 failed"*. A pass rate over turns tells you *where* an assistant loses the thread, and "turn 3 and later" is a finding that leads to a fix — usually a context window, a summarisation step, or a tool that is not being re-read.

**Every existing metric works unchanged.** A judge, an exact match, a trajectory assertion all take a row and an answer. A turn is a row.

**Turns are independently addressable by the regression gate.** `--compare=baseline` joins turn 3 across runs by content hash exactly like any other row, so *"turn 3 of the refund conversation regressed"* is a sentence CI can say.

## Your agent sees the history

```php
$eval->run('support.multi-turn', new AgentSampleRunner(
    fn (array $input) => Ai::agent(SupportAgent::class)
        ->withMessages($this->messagesFrom($input['history']))
        ->prompt($input['question']),
));
```

How you replay the history is yours — `laravel/ai` conversations, a message array, a summarised context. The dataset supplies it; the runner decides what to do with it.

## The history holds expected answers, not the model's

This is the decision that makes a conversation eval *measurable*.

If turn 3's input contained what the model actually said at turn 2, then every run would be evaluating a different conversation. A run where turn 2 went badly would feed turn 3 a broken premise, turn 3 would fail for reasons that have nothing to do with turn 3, and the two runs could not be compared to each other — which is the entire point of a regression gate.

So the history is the dataset's own `expect` values: the conversation as it *should* have gone. Turn 3 measures turn 3.

The cost is that a compounding failure looks like three independent ones rather than one cascade. That is the right trade for a gate: you want to know that turn 3 breaks *given a correct turn 2*, because that is the bug you can fix.

## Cohorts come for free

Conversation-level and turn-level `tags` are merged into the row's tags, so:

- `--compare` and the report break down by cohort automatically;
- a [briefing](https://doc.eval-harness.padosoft.com/guides/briefing) can say *"4 of 5 failures are tagged `context-retention`"*, which is a diagnosis rather than a list.

Extra `metadata` maps at either level are merged in too, turn-level winning.

## What is refused, and why

| Refused | Because |
|---|---|
| an empty `conversations` list | nothing to evaluate |
| a conversation with no string `id` | rows need stable, addressable ids |
| a turn with no `user` or no `expect` | a turn with no expectation cannot be scored |
| two conversations sharing an `id` | two rows would share a sample id and the report would aggregate them as one |

Every one raises `DatasetSchemaException` naming the conversation and the turn number.
