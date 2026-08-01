# Ticket Classifier

Laravel app that classifies support ticket text into a category using Groq's
chat completions API.

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

Classifies a piece of ticket text into one of: `billing`, `technical`,
`account`, `general`. If the model returns anything outside that set, the
response falls back to `general`.

**Request body**

| Field     | Type   | Required | Notes           |
|-----------|--------|----------|-----------------|
| `message` | string | yes      | Max 5000 chars. |

```bash
curl -X POST http://ticket-classifier.test/api/classify \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"message": "I was charged twice for my subscription this month."}'
```

**Success — `200 OK`**

```json
{
    "message": "I was charged twice for my subscription this month.",
    "category": "billing"
}
```

**Validation error — `422 Unprocessable Content`**

```json
{
    "message": "The message field is required.",
    "errors": {
        "message": ["The message field is required."]
    }
}
```

**Upstream failure — `500 Internal Server Error`**

Returned if the Groq API key is missing or the Groq request itself fails.
