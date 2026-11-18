<?php

/*
|--------------------------------------------------------------------------
| Optional AI integration
|--------------------------------------------------------------------------
|
| The only AI feature in OpenQDA is optional text embeddings for
| autocoding. It is OFF unless AI_ENABLED=true is set; with the flag unset
| no AI route does anything and no AI controls are shown.
|
| Prerequisite: embeddings are computed by Ollama (https://ollama.com), the
| only supported provider. Install it, then pull the model once:
|
|   ollama pull nomic-embed-text
|
| To switch the feature on, paste this into your .env file
| (see docs/plugins/autocoding.md):
|
|   AI_ENABLED=true
|   AI_EMBEDDING_PROVIDER=ollama
|   AI_EMBEDDING_MODEL=nomic-embed-text
|   OLLAMA_BASE_URL=http://host.docker.internal:11434
|
| Each project additionally has to be opted in by its owner before any
| project text is sent to the embedding service.
|
*/

$nullableInt = static function ($value): ?int {
    return ($value === null || $value === '') ? null : (int) $value;
};

return [

    'enabled' => (bool) env('AI_ENABLED', false),

    // Local-first: while true (default) only an Ollama server on your own
    // machine or network is used. An Ollama endpoint elsewhere counts as a
    // third-party service and is refused until an admin sets AI_LOCAL_ONLY=false.
    'local_only' => (bool) env('AI_LOCAL_ONLY', true),

    // Text embeddings for autocoding
    'embedding' => [
        'provider' => env('AI_EMBEDDING_PROVIDER', 'ollama'),
        // nomic-embed-text (≈ 270 MB); all-minilm is even lighter (≈ 45 MB)
        'model' => env('AI_EMBEDDING_MODEL', 'nomic-embed-text'),
        // optional Matryoshka truncation, e.g. 256 or 512 (only for models that support it)
        'dimensions' => $nullableInt(env('AI_EMBEDDING_DIMENSIONS')),
        'batch_size' => (int) env('AI_EMBEDDING_BATCH_SIZE', 64),
    ],

    'providers' => [
        'ollama' => [
            'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
            'cloud' => (bool) env('OLLAMA_IS_CLOUD', false),
        ],
    ],

    'timeout' => (int) env('AI_TIMEOUT', 120),

    // Project text is only ever sent over HTTPS, except to loopback / private
    // network hosts (e.g. a local Ollama). Set to true only for testing.
    'allow_insecure_http' => (bool) env('AI_ALLOW_INSECURE_HTTP', false),

    // Pseudonymise outgoing text: "cloud" (default) = only for third-party
    // endpoints, "always" = also for local ones, "off" = never.
    // Masks e-mail addresses, URLs, phone numbers, IBANs, card numbers, IP
    // addresses and the project's own list of terms (names, places ...).
    'redaction' => env('AI_REDACTION', 'cloud'),

    /*
    | Whether a provider counts as local is decided from its endpoint: this
    | machine, a private network address or a single-label/internal host
    | name. A provider's `cloud` flag can only make it stricter. Hosts listed
    | here (comma-separated) are treated as your own infrastructure too, e.g.
    | an embedding server on an institutional domain.
    */
    'local_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('AI_LOCAL_HOSTS', ''))))),

    /*
    | Bump to ask project owners to confirm their consent again, e.g. after
    | the data protection terms changed.
    */
    'consent_policy_version' => (int) env('AI_CONSENT_POLICY_VERSION', 1),

    'cache' => [
        // embeddings are encrypted at rest with the application key
        'encrypt' => (bool) env('AI_CACHE_ENCRYPT', true),
        // cached embeddings older than this are pruned daily (php artisan ai:prune)
        'ttl_days' => (int) env('AI_CACHE_TTL_DAYS', 90),
    ],

    'limits' => [
        'requests_per_minute' => (int) env('AI_RATE_LIMIT', 60),
        'max_texts_per_request' => 256,
        'max_chars_per_text' => 8000,
    ],

    'autocode' => [
        // upper bound for the source texts sent to the browser in one request
        'max_corpus_bytes' => (int) env('AI_AUTOCODE_MAX_CORPUS_BYTES', 50 * 1024 * 1024),
        'max_sources' => 500,
        // maximum number of suggestions accepted in one bulk request
        'max_accept' => 2000,
    ],
];
