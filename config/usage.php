<?php

return [
    // Roll out migrations before enabling ingestion. This never changes billing.
    'enabled' => env('USAGE_ENABLED', false),
    'history_days' => (int) env('USAGE_HISTORY_DAYS', 400),
    'access_days' => (int) env('USAGE_ACCESS_DAYS', 90),
    'identity_days' => (int) env('USAGE_IDENTITY_DAYS', 400),
    'query_days' => 93,
    'online_ttl' => 120,
    'max_report_rows' => 2000,
];
