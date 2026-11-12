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
        'internal_signature_min_agent_version' => env('PRINT_HUB_AGENT_INTERNAL_SIGNATURE_MIN_VERSION', '0.3.12'),
        'authenticode_ca_file' => env('PRINT_HUB_AGENT_UPDATE_CA_FILE'),
        'internal_public_keys' => [
            'opsigo-release-2026-01' => env(
                'PRINT_HUB_AGENT_UPDATE_PUBLIC_KEY_2026_01',
                'MIIBojANBgkqhkiG9w0BAQEFAAOCAY8AMIIBigKCAYEAxXzHorjwDtJHg5tdpTnfY5CsJ+21rNxMkdso3ZBLQYdiUScDKgifF1ejgLAjtt5SLW8bh6NnbqQse0qHtYpqAFGYBwgzsoVEgAOI36i0PNm3AQvOpFQXD/lO7jEqSKM8mJwSyV7FkkOVG39lmdLcOS7mglte564u7amJ8pGgLfuJT2eLEwqxrIQKYDHxa5m9pYF365knWZhz1F4gbHv0apApP+uC50p/JD9bgjI9MhkflL817W4Zg1YVaVBxVw7EFk1B9EVwNXCgM4vSr+QfW/1ys/swuYJ7YNb1026/BsaUYtiPh+N06vasticQE+kzw4Y5A/+2+vEM1kmRtW3zKzQFqVs2GPNZepPFkAkde9Gqqd9viRUIrlE+TX6FWuFBbrTyXc/HSaugEKTFJLFB4LMU/q3qA87Oy3Y7kuZoNWnW3dYFz37EbknsnBv1Dshv5hxgVp3coK/xXzl8UBLTZs1wagc+5TCKCBp4uHHH3MpxYvfXrFss1+HArUY0c5eJAgMBAAE=',
            ),
        ],
    ],
    'default_print_settings' => [
        'engine' => 'pdfium',
        'copies' => 1,
        'duplex' => false,
        'tray' => null,
        'scale' => 'noscale',
    ],
];
