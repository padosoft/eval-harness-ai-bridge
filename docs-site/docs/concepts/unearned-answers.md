# What a text metric cannot see

Every metric that scores a **string** shares one blind spot: it cannot distinguish an answer that was *derived* from an answer that was *guessed*.

For a RAG pipeline that mostly does not matter — the pipeline retrieves, then generates, and a wrong retrieval usually produces a visibly wrong answer.

For an **agent** it matters enormously, because an agent that skips its tools still produces fluent, plausible, frequently-correct text.

## Four failures, none of them visible in the text

### The unearned answer

> *"Your order ships Tuesday."*

Correct. Scores 1.0 on exact-match, on ROUGE, on embeddings, on an LLM judge. The order lookup was never called.

The model inferred a plausible ship date from the question's shape and its training. It will be right often — orders do ship on Tuesdays — and the day it is wrong, it will be wrong with total confidence, to a customer, about their money.

**Catches it:** `tool-called`.

### The confidently wrong lookup

The agent *did* call `lookup_order` — with id `44192` when the customer asked about `44129`. It then reported that order's status, accurately, about the wrong order.

Every text metric sees a well-formed answer citing real data.

**Catches it:** `tool-called-with`.

### The action taken before the check

The agent charged the card, then checked stock. Both tools were called; both succeeded; the answer is correct. The customer has been charged for something that is not in the warehouse.

**Catches it:** `tool-call-order`.

### The claim of an action that has not happened

> *"I have submitted that refund for you."*

The refund is sitting in an approval queue. The text is what a successful refund would look like, so a judge grading against *"confirms the refund"* scores it well.

This is not a quality bug. It is a customer being told something untrue about their money by a system, which in most jurisdictions is a compliance matter.

**Catches it:** `no-pending-approvals`, and `approval-gated` for the inverse — an action that went through *without* the approval it required.

## Why the text keeps looking fine

None of these are a model failing to write well. They are a model **acting** badly and then describing the action correctly-in-form.

Language models are very good at producing the text that would accompany a correct process. That is close to what they are for. So the closer a model gets to fluent, the *less* the text tells you about whether the process happened.

This is the part that surprises people: **as your agent gets better, text metrics get less informative about it**, not more.

## What the trajectory adds

It is the difference between grading an exam and watching somebody take it. Two students hand in the same right answer; one derived it, one remembered a number that happened to match. On paper they are identical.

The trajectory is the working.

```yaml
metadata:
  trajectory:
    tools: [lookup_order]
    forbidden: [charge_card]
    arguments:
      lookup_order: { id: 44192 }
    order: [check_stock, charge_card]
    max_steps: 6
    approvals: [refund_order]
```

## And it is cheap

Text metrics that could catch some of this — an LLM judge with a rubric about tool use — cost a provider call per row and are themselves non-deterministic. Trajectory assertions are **structural**: they read a DTO, they cost nothing, they return the same answer twice, and they can run on every row of every build.

Use both. A judge tells you whether the answer was good; the trajectory tells you whether it was earned. An agent that fails the second while passing the first is the most dangerous shape a system can be in, because it looks healthy right up until it isn't.
