# Lessons

Decisions worth remembering, and the reasoning that produced them.

## 2026-08-24 — Building the bridge

- **The generic package must not learn about the specific one.** The shortest
  implementation has eval-harness accept an `AgentResponse`. It also makes every
  eval suite exactly as portable as its SDK choice — and a test suite that has to
  be rewritten alongside the runtime it tests cannot answer "did the migration
  change the behaviour?", which is the one question it existed for.
- **A boundary described only in a README erodes.** Two architecture tests keep
  it: one fails if eval-harness ever references `Laravel\Ai`, one fails if any
  file outside the adapter and the runner does.
- **Join tool results to calls by id, never by index.** Parallel tools return out
  of order and a pending call has no result at all. Position matching silently
  attaches one call's outcome to another's, and the eval still passes.
- **A denied call is not a quiet success.** "Did it look the order up?" must not
  be satisfied by a rejection, so a denied result becomes `error`, not `result`.
- **`pending_approval` is not `stop`.** Text that says "I have submitted that
  refund" while an approval is queued reads as success and is not. Reporting the
  SDK's finish reason unchanged would have lost exactly that case.
- **Conversation history must hold the dataset's expected answers, not the
  model's.** Feeding turn 3 what the model actually said at turn 2 means every
  run evaluates a different conversation, and two runs cannot be compared —
  which is the whole point of a regression gate. The cost is that a cascade looks
  like three independent failures; that is the right trade, because "turn 3
  breaks given a correct turn 2" is the fixable statement.
- **One row per turn, not one row per conversation.** It makes the failing *turn*
  addressable — by the report, by the briefing's cohorts, and by the regression
  gate's content hash — and every existing metric keeps working, because a turn
  is a row.
- **An eval assertion has to be a threshold.** A suite demanding 100% from an LLM
  pipeline is a suite that gets muted within a fortnight.
- **A halted run can never pass an assertion.** The rows that never executed are
  disproportionately the ones that would have failed.
- **Name the rows in the failure message.** "macro-F1 0.71 < 0.85" tells nobody
  what to open.
- **Do not ship a service provider with nothing in it.** A package whose job is
  to connect two other packages should not become a third thing to configure. The
  one exception is the Pest expectation, autoloaded through a `files` entry
  because Pest has no provider to hook and a registration line nobody remembers
  is how a nice API becomes an unused one.
- **PHPStan's `trait.unused` on a public-API trait is solved by analysing the
  tests**, not by an ignore: the tests are where a consumer-facing trait is
  legitimately used.
- **`src/Testing/pest.php` is excluded from PHPStan at config level, with the
  reason written down.** Pest binds `$this` inside `extend()` closures at
  runtime; typing it would mean depending on Pest, which this package
  deliberately does not.
- **Check the pending approval before the recorded finish reason.** The shape an
  agent actually produces at an approval gate is *both*: steps whose last reason
  is `tool_calls`, and a pending approval. Reading the step reason first reported
  such a run as finished — defeating the exact case the branch was written for.
  The original test only covered the no-steps path, which is why it passed.
- **"Registered automatically" was a claim, not a fact.** Composer's `files`
  order across sibling packages is unspecified, and a `suggest` creates no
  dependency edge to order by, so the Pest expectation can lose the race and
  never register — with no second chance, since Composer will not re-run the
  file. Making the registration a named idempotent function costs nothing and
  gives the host a one-line fix; claiming automation that the loader does not
  guarantee costs somebody an afternoon.
