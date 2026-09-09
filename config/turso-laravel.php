<?php

// config for Mis3085/TursoLaravel
return [
    'client' => [
        'connect_timeout' => env('TURSO_CONNECT_TIMEOUT', 10),
        'timeout' => env('TURSO_REQUEST_TIMEOUT', 30),
    ],

    'sync_command' => [
        'node_path' => env('NODE_PATH'), // Full path to the node executable. E.g: /usr/bin/node
        'script_filename' => 'turso-sync.mjs',
        'script_path' => realpath(__DIR__.'/..'),
        'timeout' => 60,
    ],
];
