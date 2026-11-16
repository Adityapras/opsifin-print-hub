<?php

return [
    'min_agent_version' => env('PRINT_HUB_MIN_AGENT_VERSION', '0.1.0'),
    'claim_interval_s' => (int) env('PRINT_HUB_CLAIM_INTERVAL', 5),
    'heartbeat_interval_s' => (int) env('PRINT_HUB_HEARTBEAT_INTERVAL', 30),
    'lease_seconds' => (int) env('PRINT_HUB_LEASE_SECONDS', 120),
    'max_print_attempts' => 3,
    'max_pdf_bytes' => (int) env('PRINT_HUB_MAX_PDF_BYTES', 20 * 1024 * 1024),
    'callback_expiry_hours' => 24,
    'callback_retry_delays' => [30, 120, 600, 1800, 3600],
    'print_retry_delays' => [30, 120, 600],
    'printer_status_interval_s' => 60,
    'printer_status_retry_delays' => [30, 120, 600, 1800],
    'permanent_print_errors' => [
        'PDF_HASH_MISMATCH',
        'PDF_INVALID',
        'PRINTER_NOT_FOUND',
    ],
    'agent_log_level' => env('PRINT_HUB_AGENT_LOG_LEVEL', 'info'),
    'agent_updates' => [
        'max_package_bytes' => (int) env('PRINT_HUB_AGENT_UPDATE_MAX_BYTES', 512 * 1024 * 1024),
        'download_url_ttl_minutes' => (int) env('PRINT_HUB_AGENT_UPDATE_URL_TTL_MINUTES', 1440),
        'stalled_after_minutes' => (int) env('PRINT_HUB_AGENT_UPDATE_STALLED_MINUTES', 120),
    ],
    'default_print_settings' => [
        'engine' => 'pdfium',
        'copies' => 1,
        'duplex' => false,
        'tray' => null,
        'scale' => 'noscale',
    ],
];
