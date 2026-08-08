# Ticket Classifier

Laravel API that classifies a support ticket's title and description into a
category, using Groq's chat completions API. Also includes a small RAG
(retrieval-augmented generation) document Q&A system built on Postgres +
pgvector.

**Status:** local development only — no hosted deployment yet. The RAG
feature depends on a locally running Ollama server, which most PaaS
platforms don't support out of the box; going live would mean either
swapping to a cloud embedding provider or self-hosting Ollama on a VPS.
Neither is planned right now.

## Requirements

- PHP 8.3+
- Composer
- MySQL (ticket classifier)
- PostgreSQL 18+ with the [pgvector](https://github.com/pgvector/pgvector)
  extension (RAG document Q&A)
- [Ollama](https://ollama.com) running locally with the `nomic-embed-text`
  model pulled (`ollama pull nomic-embed-text`) — used for free, local
  embeddings, no API key required

## Local development quickstart

Everything this app needs must be running **before** you use it:

1. MySQL and your web server (e.g. via Laragon) — for `/api/classify`
2. PostgreSQL 18 with pgvector — for `/api/ask`
3. Ollama (`ollama serve`, usually already running as a background service)

Then verify all of it in one shot:

```bash
php artisan app:health-check
```

This checks the `pdo_mysql`/`pdo_pgsql` PHP extensions, both DB connections,
the pgvector extension, `GROK_API_KEY`, and whether Ollama is reachable —
and exits non-zero if anything's missing, so it's safe to script.

**One gotcha this won't catch:** `app:health-check` runs as PHP CLI, which
loads extensions fresh every invocation. If you edit `php.ini` (e.g. to
enable `pdo_pgsql`) while your web server (Apache/Nginx/Laragon) is already
running, the CLI checks will pass but the actual web-facing app will still
fail with `could not find driver` until you **restart the web server** —
it's holding onto the PHP process from before your `php.ini` edit. Restart
Laragon (or your web server) any time you change `php.ini`, then re-test
through the browser/domain, not just via `artisan`.

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Configure `.env`:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ticket_classifier
DB_USERNAME=root
DB_PASSWORD=

GROK_API_KEY=your-groq-api-key
GROQ_BASE_URI=https://api.groq.com/openai/v1
GROQ_MODEL=llama-3.3-70b-versatile
GROQ_TIMEOUT=10
```

`GROK_API_KEY` must be a **Groq Cloud** key (`gsk_...`), not an xAI Grok key —
requests go to Groq's OpenAI-compatible endpoint.

```bash
php artisan migrate
```

### RAG (Postgres + pgvector) setup

The RAG feature uses a **separate** Postgres connection (`pgsql_rag`), kept
independent of the MySQL connection above so the two features can't
interfere with each other.

1. Create the database and enable the extension:

   ```sql
   CREATE DATABASE ticket_classifier_rag;
   \c ticket_classifier_rag
   CREATE EXTENSION vector;
   ```

2. Configure `.env`:

   ```
   RAG_DB_HOST=127.0.0.1
   RAG_DB_PORT=5432
   RAG_DB_DATABASE=ticket_classifier_rag
   RAG_DB_USERNAME=postgres
   RAG_DB_PASSWORD=your-postgres-password

   OLLAMA_BASE_URI=http://localhost:11434
   OLLAMA_EMBED_MODEL=nomic-embed-text
   ```

3. Run the RAG-specific migration (it lives in its own subfolder and its
   own connection, so it's never picked up by a plain `php artisan migrate`
   against MySQL, and vice versa):

   ```bash
   php artisan migrate --database=pgsql_rag --path=database/migrations/pgsql_rag
   ```

4. Ingest a document (`.txt`, `.md`, or `.pdf`):

   ```bash
   php artisan rag:ingest path/to/document.pdf
   # optional: --max-chars=800 --overlap=100 to tune chunk size
   ```

   This extracts text, splits it into overlapping chunks, embeds each chunk
   via Ollama, and stores `{ source, chunk_index, content, embedding }` rows
   in `document_chunks`.

### Frontend

A small Vue 3 SPA (`resources/js/`) provides a UI for both endpoints —
Classify and Ask tabs, served from `GET /`.

```bash
npm install
npm run dev    # Vite dev server with HMR
npm run build  # production build into public/build
```

## Testing

```bash
php artisan test
```

HTTP calls to Groq and Ollama are faked in tests (`Http::fake()`) — no real
API key or network access is needed to run the suite. The RAG tests do write
to the real `pgsql_rag` Postgres connection (there's no faking a database),
but wrap each test in a rolled-back transaction, so nothing is left behind —
you do need a working Postgres + pgvector setup (see below) to run those
tests.

Frontend component tests (Vitest + Vue Test Utils) cover both forms' success,
validation-error, and service-error states, with `classifyTicket`/`askQuestion`
mocked — no backend needs to be running:

```bash
npm test
```

## API

### `POST /api/classify`

Classifies a ticket into one of: `bug`, `feature-request`, `documentation`,
`other`. If the model returns anything outside that set, the response falls
back to `other`.

Rate limited to **5 requests/minute** per client.

**Request body**

| Field         | Type   | Required | Notes            |
|---------------|--------|----------|------------------|
| `title`       | string | yes      | Max 255 chars.   |
| `description` | string | yes      | Max 5000 chars.  |

```bash
curl -X POST http://ticket-classifier.test/api/classify \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "App crashes on login",
    "description": "Tapping Sign in closes the app immediately on iOS 17."
  }'
```

**Success — `200 OK`**

```json
{
    "category": "bug",
    "confidence": 0.97,
    "reasoning": "Describes a crash, a concrete broken behavior."
}
```

**Validation error — `422 Unprocessable Content`**

```json
{
    "message": "The title field is required. (and 1 more error)",
    "errors": {
        "title": ["The title field is required."],
        "description": ["The description field is required."]
    }
}
```

**Rate limited — `429 Too Many Requests`**

Returned after 5 requests within a minute.

**Groq service errors**

| Status | Cause                                    |
|--------|-------------------------------------------|
| `500`  | `GROK_API_KEY` is missing/not configured.  |
| `502`  | Groq returned a non-2xx response.          |
| `504`  | Request to Groq timed out.                 |

Every request (success or failure) is logged with the ticket title and
response time in milliseconds; failures also log the exception type and
message.

<!-- Add a screenshot of a real request/response here, e.g. from Postman or curl. -->

### `POST /api/ask`

Answers a question using retrieval-augmented generation: embeds the
question (Ollama), finds the most similar chunks in `document_chunks`
(Postgres cosine distance), and asks Groq to answer using only that
context. If nothing has been ingested, or the context doesn't cover the
question, it says so instead of guessing.

**Request body**

| Field      | Type   | Required | Notes            |
|------------|--------|----------|------------------|
| `question` | string | yes      | Max 2000 chars.  |

```bash
curl -X POST http://ticket-classifier.test/api/ask \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"question": "What status code do I get if the Groq request times out?"}'
```

**Success — `200 OK`**

```json
{
    "answer": "504",
    "sources": [
        { "source": "sample-doc.txt", "chunk_index": 4, "distance": 0.2703 },
        { "source": "sample-doc.txt", "chunk_index": 2, "distance": 0.3564 }
    ]
}
```

`sources` is ordered nearest-first; `distance` is cosine distance (lower is
more similar). When no documents are indexed, `answer` is a fixed
"I don't have enough information to answer that." message and `sources` is
empty — Groq is never called in that case.

**Validation error — `422 Unprocessable Content`** — same shape as
`/api/classify`'s, with a `question` field error.

**Service errors**

| Status | Cause                                          |
|--------|-------------------------------------------------|
| `502`  | Ollama embedding request failed.                |
| `502`  | Groq returned a non-2xx response.                |

## Postman collection

A ready-made collection lives in [`postman/`](postman/):

- `Ticket-Classifier.postman_collection.json` — four folders:
  - **Classify**: one request per category (bug/feature-request/documentation/other)
    plus a validation-error case, each with `pm.test()` assertions.
  - **Ask (RAG)**: content-aware retrieval check, an off-topic question that
    should be declined rather than hallucinated, a structural-only check
    that works regardless of what's ingested, and a validation-error case.
  - **Rate Limiting**: a `/api/classify` probe meant for Collection Runner.
  - **Health Check**: `GET /up`.
- `Ticket-Classifier.postman_environment.json` — sets `baseUrl` (defaults to
  `http://ticket-classifier.test`; change it to `http://127.0.0.1:8000` if
  you're using `php artisan serve`).

**Import into Postman:** File → Import → select both JSON files, pick the
"Ticket Classifier Local" environment in the top-right dropdown, then run
requests individually or hit "Run collection".

These requests hit a real running server and real Groq/Ollama services — no
faking — so the app must be up with a valid `GROK_API_KEY` in `.env` and
Ollama running locally.

**Before running the "Ask (RAG)" folder's content-specific requests**,
ingest the bundled fixture so results are reproducible:

```bash
php artisan rag:ingest storage/app/rag-samples/ticket-classifier-api-doc.txt --max-chars=300 --overlap=50
```

The "Structural Check" and "Validation Error" requests in that folder work
regardless of what's been ingested.

**Rate-limit folder:** run the "Rate Limiting" folder by itself via
Collection Runner with **6 iterations** and **0ms delay**. The test script
expects requests 1–5 to return `200` and request 6 to return `429`.

**Run headless with Newman:**

```bash
npm install -g newman
newman run postman/Ticket-Classifier.postman_collection.json \
  -e postman/Ticket-Classifier.postman_environment.json
```
