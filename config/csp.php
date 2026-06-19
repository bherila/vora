<?php

use App\Csp\CloudflareCspPreset;
use Spatie\Csp\Nonce\RandomString;

return [

    /*
     * Presets put here are ENFORCED via the `Content-Security-Policy` header.
     * Left empty for now: media uploads/playback (R2 + HLS) were being blocked,
     * so the policy is shipped report-only first (see `report_only_presets`).
     * Once reports come back clean, move CloudflareCspPreset here to enforce.
     */
    'presets' => [],

    /**
     * Register additional global enforced CSP directives here.
     */
    'directives' => [],

    /*
     * Presets put here are sent as `Content-Security-Policy-Report-Only`: the
     * browser reports violations but does not block anything. This lets us
     * validate the media-origin allowlist in production without breaking uploads.
     */
    'report_only_presets' => [
        CloudflareCspPreset::class,
    ],

    /**
     * Register additional global report-only CSP directives here.
     */
    'report_only_directives' => [],

    /*
     * All violations against a policy will be reported to this url.
     */
    'report_uri' => env('CSP_REPORT_URI', ''),

    /*
     * The name of the reporting endpoint that violations should be sent to.
     */
    'report_to' => env('CSP_REPORT_TO', ''),

    /*
     * Reporting endpoints sent in the `Reporting-Endpoints` HTTP header.
     */
    'reporting_endpoints' => [],

    /*
     * Headers will only be added if this setting is set to true.
     */
    'enabled' => env('CSP_ENABLED', true),

    /**
     * Headers will be added when Vite is hot reloading.
     */
    'enabled_while_hot_reloading' => env('CSP_ENABLED_WHILE_HOT_RELOADING', false),

    /*
     * The class responsible for generating the nonces used in inline tags and headers.
     */
    'nonce_generator' => RandomString::class,

    /*
     * Set false to disable automatic nonce generation and handling.
     */
    'nonce_enabled' => env('CSP_NONCE_ENABLED', true),
];
