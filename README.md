# Ticket Classifier

Laravel API that classifies a support ticket's title and description into a
category, using Groq's chat completions API.

**Live demo:** _add deployed URL here_

## Requirements

- PHP 8.3+
- Composer
- MySQL

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

## Testing

```bash
php artisan test
```

HTTP calls to Groq are faked in tests (`Http::fake()`) — no real API key or
network access is needed to run the suite.

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

## Postman collection

A ready-made collection lives in [`postman/`](postman/):

- `Ticket-Classifier.postman_collection.json` — health check, one request per
  category (bug/feature-request/documentation/other), a validation-error
  case, and a rate-limit probe, each with `pm.test()` assertions.
- `Ticket-Classifier.postman_environment.json` — sets `baseUrl` (defaults to
  `http://ticket-classifier.test`; change it to `http://127.0.0.1:8000` if
  you're using `php artisan serve`).

**Import into Postman:** File → Import → select both JSON files, pick the
"Ticket Classifier Local" environment in the top-right dropdown, then run
requests individually or hit "Run collection".

These requests hit a real running server and a real Groq API — no faking —
so the app must be up with a valid `GROK_API_KEY` in `.env`.

**Rate-limit folder:** run the "Rate Limiting" folder by itself via
Collection Runner with **6 iterations** and **0ms delay**. The test script
expects requests 1–5 to return `200` and request 6 to return `429`.

**Run headless with Newman:**

```bash
npm install -g newman
newman run postman/Ticket-Classifier.postman_collection.json \
  -e postman/Ticket-Classifier.postman_environment.json
```
