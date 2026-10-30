<?php

declare(strict_types=1);

return [

    /*
    | Every table this package owns is prefixed, so two consumers cannot collide
    | and a consumer can rename them without forking the package.
    */
    'table_prefix' => 'mailroom_',

    /*
    | Typing: how much body text goes to the gateway, and where the gateway is.
    | The harness's decide gateway answers on the tailnet without auth.
    */
    'typing' => [
        'text_limit' => (int) env('MAILROOM_TEXT_LIMIT', 2500),
        'gateway_url' => env('MAILROOM_GATEWAY_URL', 'http://100.89.95.9:8020'),
        'gateway_model' => env('MAILROOM_GATEWAY_MODEL', 'default'),
        'gateway_timeout' => (int) env('MAILROOM_GATEWAY_TIMEOUT', 30),
        // Minutes of gateway failure before the unreachable hook fires once.
        'unreachable_after' => (int) env('MAILROOM_GATEWAY_UNREACHABLE_AFTER', 30),
    ],

    /*
    | Watching: the scheduler runs mailroom:watch every N seconds; an expired
    | cursor resyncs this many days.
    */
    'watch' => [
        'interval_seconds' => (int) env('MAILROOM_WATCH_INTERVAL', 10),
        'resync_days' => (int) env('MAILROOM_RESYNC_DAYS', 7),
    ],

    /*
    | Credentials: the file resolver reads <dir>/<identity>.json holding
    | {client_id, client_secret, refresh_token}. On the hub this directory is
    | a read-only tmpfs mount fed by systemd-creds; never commit it.
    */
    'credentials' => [
        'directory' => env('MAILROOM_CREDENTIALS_DIR', storage_path('mailroom/credentials')),
    ],

    /*
    | Webhooks: deliveries retry from 1 minute, doubling, for up to 24 hours,
    | then are marked dead. Receivers reject signatures older than 5 minutes.
    */
    'webhooks' => [
        'first_retry_seconds' => 60,
        'give_up_after_hours' => 24,
        'timeout' => 10,
    ],

    /*
    | asPdf: Browsershot with puppeteer's pinned Chrome-for-Testing. Do not
    | point chrome_path at a distro chromium (SIGTRAPs in containers).
    */
    'pdf' => [
        'chrome_path' => env('MAILROOM_CHROME_PATH'),
        'node_binary' => env('MAILROOM_NODE_BINARY'),
        'npm_binary' => env('MAILROOM_NPM_BINARY'),
        'node_modules_path' => env('MAILROOM_NODE_MODULES_PATH'),
        'no_sandbox' => (bool) env('MAILROOM_CHROME_NO_SANDBOX', true),
        'timeout' => (int) env('MAILROOM_PDF_TIMEOUT', 60),
        'chromium_arguments' => array_filter(explode(',', (string) env('MAILROOM_CHROMIUM_ARGS', 'disable-gpu'))),
    ],

];
