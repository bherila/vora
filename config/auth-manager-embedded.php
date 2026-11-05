<?php

// The package's remaining defaults are merged only when AUTH_SIGN_IN=embedded.
// Its users, sessions, keys and tables remain separate from Vora's.
return [
    'prefix' => env('AUTH_MANAGER_EMBEDDED_PREFIX', 'auth'),
    'config' => [
        'bherila-auth' => [
            // Provider administration uses its own screens and administrator guard.
            'audit' => ['routes_enabled' => false, 'admin_ability' => null],
        ],
    ],
];
