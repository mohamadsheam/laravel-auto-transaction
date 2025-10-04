<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Database Connection
    |--------------------------------------------------------------------------
    |
    | The default database connection to use for transactions.
    | Set to null to use the application's default connection.
    |
    */
    'connection' => env('AUTO_TRANSACTION_CONNECTION', null),

    /*
    |--------------------------------------------------------------------------
    | Default Transaction Attempts
    |--------------------------------------------------------------------------
    |
    | The number of times to attempt a transaction before giving up.
    | Useful for handling deadlocks.
    |
    */
    'attempts' => env('AUTO_TRANSACTION_ATTEMPTS', 1),

    /*
    |--------------------------------------------------------------------------
    | Throw On Failure
    |--------------------------------------------------------------------------
    |
    | Whether to throw an exception when a transaction fails.
    | If false, the transaction will return null on failure.
    |
    */
    'throw_on_failure' => env('AUTO_TRANSACTION_THROW', true),

    /*
    |--------------------------------------------------------------------------
    | Auto-Transaction Middleware
    |--------------------------------------------------------------------------
    |
    | Apply transaction middleware automatically to specific routes.
    | You can specify route patterns or controller actions.
    |
    */
    'auto_apply_routes' => [
        // 'api/*',
        // 'admin/*',
    ],
];
