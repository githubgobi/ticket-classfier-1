# Evals

This project has two kinds of automated checks, and they answer different
questions:

| | Unit/feature tests (`tests/Unit`, `tests/Feature`) | Evals (this doc) |
|---|---|---|
| Question answered | Did the *code* run correctly? | Was the AI's *judgment* good? |
| Nature | Deterministic — pass/fail | Subjective — graded against a rubric |
| Groq calls | Always faked (`Http::fake()`) | Real calls, real model |
| Catches | Broken validation, wrong status codes, malformed responses | Wrong category, bad reasoning, silent quality drift |

The existing test suite is thorough (32 tests) but every single one of them
fakes Groq's response. That's correct for testing *our* code — we don't
want tests that flake because an LLM call was slow or a free-tier quota
ran out — but it means the suite has **zero signal** about whether the
classifier actually classifies tickets well. A refactor that swapped in a
worse prompt, or a Groq model upgrade that changed behavior, would sail
through every existing test untouched. That gap is what evals close.

## Golden set

[`tests/golden/tickets.json`](../tests/golden/tickets.json) is a versioned,
human-labeled fixture: 30 tickets, each with a title/description (the real
input) and an `expected_category` (the label a human — not the classifier
— assigned by hand). It's checked into git specifically so "correct" is a
reviewable diff over time, not something that lives only in someone's head.

**Composition:**
- 23 "clear" examples, roughly evenly spread across all four categories
  (`bug`, `feature-request`, `documentation`, `other`), to establish a
  baseline the classifier should get right essentially every time.
- 3 `ambiguous` examples where a reasonable person could defensibly pick
  two different categories — each has a `notes` field explaining the
  reasoning and acknowledging the alternative. These exist because a
  golden set that only contains easy cases doesn't tell you anything a
  demo doesn't already show.
- 1 `multi-issue` example bundling a bug, a possibly-missing feature, and
  a feature request in one ticket — tests whether the classifier (or the
  grading rubric) has a sane tie-breaking rule, since real support queues
  are full of these.
- 3 `vague` examples (as short as a two-word title and one-line body) —
  tests behavior on minimal input, including one that's pure positive
  feedback with no actionable category at all.

### Rubric grading, not just exact-match

Straight `actual === expected` accuracy is the right metric for the 23
clear examples, but scoring the `ambiguous` and `multi-issue` cases that
way would be misleading — gold-026, for instance, could reasonably land on
either `documentation` or `bug`, and a harness that marks the "wrong" one
as a flat failure is measuring disagreement with one person's judgment
call, not classifier quality. The harness below reports accuracy broken
down by `case_type` for exactly this reason — a drop in the `clear` bucket
means the classifier got worse; a drop in `ambiguous` might just mean it
made a different defensible call. It does not yet do automatic partial
credit against the `notes` field's stated alternatives — that's still a
manual read of the mismatch list, not something scored automatically.

## The harness

```bash
php artisan eval:run
# tune pacing/retries if needed:
php artisan eval:run --delay=2 --retries=3
```

Calls `TicketClassifierService` **directly, in-process** — not over HTTP.
That's a deliberate choice: the point of an eval is to grade the model's
judgment, not to re-exercise the HTTP/validation/rate-limiting layer the
regular test suite already covers. It does mean the API's own `throttle:5,1`
doesn't apply here; the only real constraint left is Groq's own API-level
limits, handled with a configurable delay between calls and retry-with-
backoff on failure. Requests that still fail after all retries are
recorded as **errored**, not **wrong** — a 92% pass rate with 3 real
mismatches means something different than 92% with 1 mismatch and 2
requests that never got a response, and collapsing those would make the
number meaningless.

Each run prints an accuracy summary, a per-case-type breakdown, a
confusion matrix, and the specific mismatches — then writes the full
per-example detail to `storage/app/eval-results/<timestamp>.json`
(ephemeral, gitignored) and appends one summary line to
[`docs/eval-history.jsonl`](eval-history.jsonl) (committed) — that
append-only log is the actual drift-tracking mechanism: run this after any
prompt or model change, and a real regression shows up as a lower number
in that file, not just a feeling that something seems off.

## First real run

```
Accuracy: 28/30 (93.3%)

By case type:
  clear        21/23
  ambiguous    3/3
  multi-issue  1/1
  vague        3/3
```

The two mismatches were both in the "clear" `other` category, and reading
the model's actual reasoning made this more interesting than "the model
was wrong":

- **"How do I cancel my subscription?"** → classified `documentation`
  ("missing instructions on how to cancel"), gold label `other`. That's a
  defensible read — if the docs genuinely don't explain cancellation, this
  *is* a documentation gap, not just a general question.
- **"Can I get an invoice with our VAT number?"** → classified
  `feature-request` ("requests a new invoice feature"), gold label
  `other`. Also defensible — adding a field to invoices is arguably a
  feature ask.

Every one of the 7 examples I deliberately designed to be hard
(`ambiguous`, `multi-issue`, `vague`) was classified correctly. The two
actual misses were on examples I'd labeled "clear" — which says as much
about where my own gold labels were underspecified as it does about the
classifier. That's the eval discipline working as intended: it surfaced a
real disagreement to look at, instead of a green checkmark that hides it.

### A real constraint discovered while building this

The first attempt at even a small manual spot-check produced a 502 on
every single request, which looked exactly like the account's Groq free
tier quota being exhausted from this session's cumulative usage across all
three projects in this program — a very plausible explanation given how
many real calls had been made. It wasn't that. Checking the raw error
directly against Groq's API (bypassing this app's own error handling)
showed `model_not_found`: the `llama-3.3-70b-versatile` model this project
had used from day one is gone from Groq's current model catalog entirely,
not rate-limited, not down — deprecated. Every real classification since
whenever that happened had been silently failing into the graceful 502
path built for exactly this kind of failure, which is precisely why it
took an eval run (not the faked test suite) to notice.

Fixed by switching to `openai/gpt-oss-20b` (verified against Groq's API
directly before changing the default), which is what produced the 93.3%
number above. The bug hunt is arguably a better argument for running real
evals than the accuracy number is: a fully-mocked test suite had no way to
ever catch a third-party model being retired out from under it.
