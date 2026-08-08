async function apiFetch(path, options = {}) {
    const response = await fetch(path, {
        ...options,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            ...options.headers,
        },
    });

    let body = null;
    try {
        body = await response.json();
    } catch {
        // No JSON body (e.g. an unexpected non-JSON error page).
    }

    if (!response.ok) {
        const error = new Error(body?.message ?? body?.error ?? `Request failed with status ${response.status}`);
        error.status = response.status;
        error.body = body;
        throw error;
    }

    return body;
}

export function classifyTicket({ title, description }) {
    return apiFetch('/api/classify', {
        method: 'POST',
        body: JSON.stringify({ title, description }),
    });
}

export function askQuestion({ question }) {
    return apiFetch('/api/ask', {
        method: 'POST',
        body: JSON.stringify({ question }),
    });
}
