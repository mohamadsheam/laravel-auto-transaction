<?php

use Illuminate\Support\Facades\DB;

if (!function_exists('transactional')) {
    /**
     * Execute a callback within a database transaction
     *
     * @param  callable  $callback
     * @param  int  $attempts
     * @param  string|null  $connection
     * @return mixed
     */
    function transactional(callable $callback, int $attempts = 1, ?string $connection = null)
    {
        $db = $connection ? DB::connection($connection) : DB::connection();
        return $db->transaction($callback, $attempts);
    }
}

if (!function_exists('auto_transaction')) {
    /**
     * Create a transactional wrapper around a callback
     *
     * @param  callable  $callback
     * @param  array  $options
     * @return mixed
     */
    function auto_transaction(callable $callback, array $options = [])
    {
        $attempts = $options['attempts'] ?? 1;
        $connection = $options['connection'] ?? null;

        return transactional($callback, $attempts, $connection);
    }
}
