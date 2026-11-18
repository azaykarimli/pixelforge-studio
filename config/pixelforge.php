<?php

return [

    /*
    |--------------------------------------------------------------------------
    | API Credentials
    |--------------------------------------------------------------------------
    |
    | Use one API key per environment. Each key has its own webhook URL,
    | signing secret and usage report, so an environment only receives
    | its own events and reconciles its own costs against Pixelforge.
    |
    */

    'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),

    'api_key' => env('PIXELFORGE_API_KEY'),

    'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | Signatures carry a timestamp. Requests signed longer ago than this
    | many seconds are rejected, so a captured webhook can't be replayed.
    |
    */

    'webhook_tolerance' => 300,

    /*
    |--------------------------------------------------------------------------
    | HTTP Timeouts
    |--------------------------------------------------------------------------
    |
    | Seconds to wait when connecting to Pixelforge and for its response.
    |
    */

    'connect_timeout' => 5,

    'timeout' => 15,

    /*
    |--------------------------------------------------------------------------
    | Limits And Retries
    |--------------------------------------------------------------------------
    |
    | Pixelforge runs at most this many generations at once per account, so
    | we hold the rest back in our own queue instead of collecting 429s.
    | Transient failures (overloads, timeouts) are retried a few times,
    | and a request that never gets a slot is given up on eventually.
    |
    */

    'max_concurrent_generations' => 3,

    'max_submission_attempts' => 4,

    'submission_deadline_minutes' => 15,

    /*
    |--------------------------------------------------------------------------
    | Polling Fallback
    |--------------------------------------------------------------------------
    |
    | Webhook delivery isn't guaranteed. A generation that has been quiet
    | for this many seconds is fetched from Pixelforge by the scheduler.
    |
    */

    'stale_after_seconds' => 45,

];
