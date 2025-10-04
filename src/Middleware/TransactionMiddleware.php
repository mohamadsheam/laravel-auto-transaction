<?php

namespace Sheum\AutoTransaction\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class TransactionMiddleware
{
    /**
     * Handle an incoming request with automatic transaction management
     * The middleware automatically:
     * 1. Begins transaction before controller method
     * 2. Commits if response is successful (2xx status)
     * 3. Rolls back on exception or error response
     * @param string|null $connection Optional database connection name
     */
    public function handle(Request $request, Closure $next, ?string $connection = null): Response
    {
        // Only apply transactions to state-changing requests (POST, PUT, PATCH, DELETE)
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $db = $connection ? DB::connection($connection) : DB::connection();

        try {
            $db->beginTransaction();

            $response = $next($request);

            // Check if response indicates success
            if ($this->shouldCommit($response)) {
                $db->commit();
            } else {
                $db->rollBack();
            }

            return $response;
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Determine if the transaction should be committed based on response
     */
    protected function shouldCommit(Response $response): bool
    {
        $statusCode = $response->getStatusCode();

        // Commit on successful status codes (2xx)
        return $statusCode >= 200 && $statusCode < 300;
    }
}
