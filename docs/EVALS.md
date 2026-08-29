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
call, not classifier quality. Tomorrow's harness should grade those
against the `notes` field's stated alternatives as partial credit, not
binary pass/fail.

### Drift tracking

The value of a golden set isn't the first run — it's the twentieth, run
after a system prompt tweak, a model change, or three months of nothing
happening at all. Unit tests can't catch this because they never touch the
real model. Running this same fixture on a schedule (or before merging a
prompt change) and diffing category-accuracy over time is the only thing
in this repo that would catch quality silently degrading.

### A real constraint discovered while building this

Attempting even a small, manual spot-check of 5 golden examples against
the live endpoint today hit two independent rate limits back to back: the
API's own `throttle:5,1` (by design — see the main README), and, once past
that, Groq's free-tier account quota, exhausted from this session's
cumulative real API calls across all three projects in this program. The
second one produced the same `502` a genuine Groq outage would — from the
outside, "quota exhausted" and "Groq is down" are indistinguishable.

This isn't a flaw in the golden set, but it's a real design constraint for
tomorrow's harness: running all 30 examples straight through will hit the
app's own rate limit well before finishing (30 requests at 5/minute is a
minimum of ~6 minutes even with zero Groq latency), and needs to
distinguish "we got throttled, retry with backoff" from "the classifier
actually got it wrong" — collapsing those into the same failure would make
eval results meaningless on a quota-constrained free tier.
