<?php

/**
 * Certificate configuration
 *
 * Purpose:
 * - Centralise settings used during PDF certificate issuance and digital signing.
 * - Values are primarily driven by environment variables to ease deployment across environments.
 *
 * Notes:
 * - Do not hard-code secrets. Keep PEM paths and passwords in the environment (.env).
 * - 'issuer_*' appear in the PDF metadata and visible certificate fields.
 * - 'cert_pem' and 'key_pem' should point to readable PEM files for TCPDF signing.
 * - 'verify_base' may be deprecated by route() usage, but is kept for future external/public verification endpoints.
 */

return [
    // Displayed on the PDF (issuer / signature information)
    'issuer_name'  => env('CERT_ISSUER_NAME', 'TRF9 (infra@trf9.jus.br)'),
    'issuer_email' => env('CERT_ISSUER_EMAIL', 'infra@trf9.jus.br'),

    // Base URL for public verification (kept for forward compatibility / external validators)
    'verify_base'  => env('VERIFY_BASE', 'https://certificados.trf9.jus.br'),

    // Certificate & private key for digital signature (TCPDF)
    // Recommendation: separate PEM files (cert and key). Password may be set if the key is encrypted.
    'cert_pem'     => env('CERT_PEM', base_path('certs/cert.pem')),
    'key_pem'      => env('CERT_KEY', base_path('certs/key.pem')),
    'key_pass'     => env('CERT_PASS', ''),

    // Generated filename prefix (used when streaming the PDF)
    'file_prefix'  => env('CERT_FILE_PREFIX', 'certificate'),
];
