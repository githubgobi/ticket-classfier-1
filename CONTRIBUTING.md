# Contributing

This started as a personal learning project (part of a series building
small AI-integrated systems in Laravel), but issues and PRs are welcome.

## Setup

See [README.md](README.md) for full setup — you'll need MySQL, PostgreSQL +
pgvector, and Ollama running locally to exercise every feature, though the
test suite itself doesn't require a real Groq/Ollama connection (everything
is faked via `Http::fake()`).

## Before submitting a PR

```bash
php artisan test   # backend — must pass
npm test           # frontend (Vitest) — must pass
```

- Match the existing code style (no comments explaining *what* code does —
  only *why*, when it's non-obvious).
- Add or update tests for any behavior change. This project favors real
  regression tests over trusting manual verification.
- Keep PRs focused — one change per PR is easier to review than a bundle.

## Reporting issues

Open a GitHub issue with:
- What you expected vs. what happened
- Steps to reproduce
- Relevant error messages/logs (redact any API keys first)
