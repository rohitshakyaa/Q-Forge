<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'python' => [
        'base_url' => env('PYTHON_SERVICE_URL', 'http://qforge_python:8000'),

        // Where the `shared` disk is mounted *inside the Python container*. Paths
        // sent to /extract are this root joined with document_uploads.stored_path.
        'shared_root' => env('PYTHON_SHARED_ROOT', '/shared-storage/shared'),

        // Extraction OCRs every scanned page; it is not a fast request.
        'extract_timeout' => (int) env('PYTHON_EXTRACT_TIMEOUT', 600),

        // AI generation calls a local LLM (CPU-only), so give it generous headroom.
        'generate_timeout' => (int) env('PYTHON_GENERATE_TIMEOUT', 300),

        // M6 (RAG): embedding is a single forward pass per text — fast, but batches
        // of many texts (re-index) can add up on CPU.
        'embed_timeout' => (int) env('PYTHON_EMBED_TIMEOUT', 120),
    ],

    // M6 (RAG): the vector index. Laravel is the only service that talks to it
    // (REST); MySQL stays the source of truth — Qdrant is rebuildable via
    // `artisan qforge:rag:reindex`. See docs/RAG-GUIDE.md.
    'qdrant' => [
        // Master switch for everything RAG. Off in phpunit.xml so the wider test
        // suite never touches embeddings; RAG tests turn it on and fake the HTTP.
        'enabled' => (bool) env('RAG_ENABLED', true),

        'base_url' => env('QDRANT_URL', 'http://qforge_qdrant:6333'),
        'timeout' => (int) env('QDRANT_TIMEOUT', 30),

        // Stored alongside every vector so a model swap makes stale vectors
        // detectable (vectors from different models are incomparable).
        'embedding_model' => env('RAG_EMBEDDING_MODEL', 'nomic-embed-text'),
        'vector_size' => (int) env('RAG_VECTOR_SIZE', 768),

        // Cosine similarity at/above this counts as a near-duplicate (Phase 1).
        // Empirical, model-dependent — tune, don't trust.
        'duplicate_threshold' => (float) env('RAG_DUPLICATE_THRESHOLD', 0.90),

        // Phase 2 — budget-based hybrid grounding. Full material at/under the
        // budget is sent whole; over it, the top-k semantically-closest chunks
        // are retrieved instead. ~4 chars/token, so 6000 ≈ 1500 tokens.
        'grounding_budget_chars' => (int) env('RAG_GROUNDING_BUDGET_CHARS', 6000),
        'grounding_top_k' => (int) env('RAG_GROUNDING_TOP_K', 6),

        // Phase 3 — unit suggestions in the review queue. A unit is only
        // suggested when its best content chunk scores at least this against
        // the candidate. Lower than the duplicate threshold on purpose:
        // "about the same topic" is a much looser bar than "the same question".
        'unit_suggestion_min_score' => (float) env('RAG_UNIT_SUGGESTION_MIN_SCORE', 0.50),
    ],

];
