<?php

// The package's remaining defaults are merged only when AUTH_SIGN_IN=embedded.
// Its users, sessions, keys and tables remain separate from Vora's.
return [
    'prefix' => env('AUTH_MANAGER_EMBEDDED_PREFIX', 'auth'),
    'config' => [
        'bherila-auth' => [
            // Provider administration uses its own screens and administrator guard.
            'audit' => ['table' => 'audit_log', 'routes_enabled' => false, 'admin_ability' => null],
            // MariaDB FK constraint names are schema-wide even though indexes
            // are table-scoped. These logical names differ from Vora's tables.
            'two_factor' => ['table' => 'two_factor_attempts'],
        ],
    ],
];
