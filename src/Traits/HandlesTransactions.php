<?php

namespace Sheum\AutoTransaction\Traits;

use Closure;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Throwable;
use Sheum\AutoTransaction\Attributes\Transactional;
use Sheum\AutoTransaction\Exceptions\TransactionException;

trait HandlesTransactions
{
    /**
     * Run a callback within a database transaction
     */
    protected function runInTransaction(
        Closure $callback,
        ?string $connection = null,
        int $attempts = 1,
        bool $throwOnFailure = true
    ) {
        $db = $connection ? DB::connection($connection) : DB::connection();

        try {
            return $db->transaction($callback, $attempts);
        } catch (Throwable $e) {
            if ($throwOnFailure) {
                throw new TransactionException(
                    "Transaction failed: {$e->getMessage()}",
                    $e->getCode(),
                    $e
                );
            }
            return null;
        }
    }

    /**
     * Execute a method with automatic transaction handling if it has the Transactional attribute
     */
    protected function executeWithTransactionIfNeeded(string $method, array $arguments = [])
    {
        if (!method_exists($this, $method)) {
            throw new \BadMethodCallException("Method {$method} does not exist.");
        }

        $reflection = new ReflectionMethod($this, $method);
        $attributes = $reflection->getAttributes(Transactional::class);

        if (empty($attributes)) {
            // No transaction attribute, execute normally
            return $this->$method(...$arguments);
        }

        /** @var Transactional $config */
        $config = $attributes[0]->newInstance();

        return $this->runInTransaction(
            fn() => $this->$method(...$arguments),
            $config->connection,
            $config->attempts,
            $config->throwOnFailure
        );
    }
}
