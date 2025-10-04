# Real-World Usage Examples

## Example 1: User Registration Service

```php
<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Sheum\AutoTransaction\Traits\HandlesTransactions;

class UserRegistrationService
{
    use HandlesTransactions;

    public function register(array $data): User
    {
        return $this->runInTransaction(function () use ($data) {
            // Create user
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
            ]);

            // Create user profile
            $user->profile()->create([
                'bio' => $data['bio'] ?? null,
                'phone' => $data['phone'] ?? null,
                'address' => $data['address'] ?? null,
            ]);

            // Create default settings
            $user->settings()->create([
                'theme' => 'light',
                'notifications_enabled' => true,
                'newsletter_subscribed' => $data['newsletter'] ?? false,
            ]);

            // Assign default role
            $user->roles()->attach($data['role_id'] ?? 3); // Default user role

            // All operations commit together or rollback on any failure
            return $user;
        }, attempts: 2);
    }
}
```

## Example 2: E-commerce Order Processing

```php
<?php

namespace App\Services;

use App\Models\{Order, Cart, Product, Payment};
use Sheum\AutoTransaction\Traits\HandlesTransactions;

class OrderService
{
    use HandlesTransactions;

    public function createOrder(Cart $cart, array $paymentDetails): Order
    {
        return $this->runInTransaction(function () use ($cart, $paymentDetails) {
            // Create the order
            $order = Order::create([
                'user_id' => $cart->user_id,
                'subtotal' => $cart->subtotal,
                'tax' => $cart->tax,
                'total' => $cart->total,
                'status' => 'pending',
            ]);

            // Transfer cart items to order items
            foreach ($cart->items as $cartItem) {
                $order->items()->create([
                    'product_id' => $cartItem->product_id,
                    'quantity' => $cartItem->quantity,
                    'price' => $cartItem->price,
                    'total' => $cartItem->quantity * $cartItem->price,
                ]);

                // Reduce product stock
                $product = Product::findOrFail($cartItem->product_id);
                
                if ($product->stock < $cartItem->quantity) {
                    throw new \Exception("Insufficient stock for {$product->name}");
                }
                
                $product->decrement('stock', $cartItem->quantity);
            }

            // Process payment
            $payment = Payment::create([
                'order_id' => $order->id,
                'amount' => $order->total,
                'method' => $paymentDetails['method'],
                'status' => 'pending',
            ]);

            // Clear the cart
            $cart->items()->delete();

            // Update order status
            $order->update(['status' => 'confirmed']);

            return $order->load('items', 'payment');
        }, attempts: 3);
    }

    public function cancelOrder(Order $order): Order
    {
        return $this->runInTransaction(function () use ($order) {
            // Restore product stock
            foreach ($order->items as $item) {
                Product::find($item->product_id)
                    ->increment('stock', $item->quantity);
            }

            // Refund payment
            if ($order->payment) {
                $order->payment->update(['status' => 'refunded']);
            }

            // Update order status
            $order->update(['status' => 'cancelled']);

            return $order;
        });
    }
}
```

## Example 3: Banking Transfer Service

```php
<?php

namespace App\Services;

use App\Models\{Account, Transaction};
use Sheum\AutoTransaction\Traits\HandlesTransactions;

class BankingService
{
    use HandlesTransactions;

    public function transfer(Account $fromAccount, Account $toAccount, float $amount): Transaction
    {
        return $this->runInTransaction(
            callback: function () use ($fromAccount, $toAccount, $amount) {
                // Check sufficient balance
                if ($fromAccount->balance < $amount) {
                    throw new \Exception('Insufficient funds');
                }

                // Deduct from sender
                $fromAccount->decrement('balance', $amount);

                // Add to receiver
                $toAccount->increment('balance', $amount);

                // Record transaction
                $transaction = Transaction::create([
                    'from_account_id' => $fromAccount->id,
                    'to_account_id' => $toAccount->id,
                    'amount' => $amount,
                    'type' => 'transfer',
                    'status' => 'completed',
                ]);

                // Log the transaction
                $fromAccount->transactions()->create([
                    'transaction_id' => $transaction->id,
                    'type' => 'debit',
                    'amount' => $amount,
                ]);

                $toAccount->transactions()->create([
                    'transaction_id' => $transaction->id,
                    'type' => 'credit',
                    'amount' => $amount,
                ]);

                return $transaction;
            },
            attempts: 5,
            connection: 'banking'
        );
    }
}
```

## Example 4: API Controller with Middleware

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;

class OrderController extends Controller
{
    public function __construct(private OrderService $orderService)
    {
        // Apply transaction middleware to write operations
        $this->middleware('transaction')->except(['index', 'show']);
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $cart = $request->user()->cart;
        
        // This entire method runs in a transaction (via middleware)
        // Commits on 2xx response, rolls back on exception
        $order = $this->orderService->createOrder(
            $cart,
            $request->input('payment')
        );

        return response()->json([
            'message' => 'Order created successfully',
            'data' => $order,
        ], 201);
    }

    public function cancel(int $orderId): JsonResponse
    {
        $order = Order::findOrFail($orderId);
        
        // Automatic transaction via middleware
        $order = $this->orderService->cancelOrder($order);

        return response()->json([
            'message' => 'Order cancelled successfully',
            'data' => $order,
        ]);
    }
}
```

## Example 5: Routes with Transaction Middleware

```php
<?php

// routes/api.php

use App\Http\Controllers\Api\{OrderController, UserController, ProductController};
use Illuminate\Support\Facades\Route;

// Apply transaction middleware to entire resource
Route::middleware(['auth:api', 'transaction'])->group(function () {
    // All write operations will be transactional
    Route::apiResource('orders', OrderController::class);
    Route::apiResource('products', ProductController::class);
});

// Apply to specific routes
Route::middleware('auth:api')->group(function () {
    Route::post('users', [UserController::class, 'store'])
        ->middleware('transaction');
    
    Route::put('users/{user}', [UserController::class, 'update'])
        ->middleware('transaction');
});

// Use custom database connection
Route::post('reports/generate', [ReportController::class, 'generate'])
    ->middleware('transaction:reporting_db');
```

## Example 6: Using Helper Functions

```php
<?php

namespace App\Services;

use App\Models\{Post, Tag, Category};

class PostService
{
    public function createPost(array $data): Post
    {
        // Using transactional helper
        return transactional(function () use ($data) {
            $post = Post::create([
                'title' => $data['title'],
                'content' => $data['content'],
                'user_id' => $data['user_id'],
            ]);

            // Attach tags
            if (isset($data['tags'])) {
                $post->tags()->attach($data['tags']);
            }

            // Set category
            if (isset($data['category_id'])) {
                $post->category_id = $data['category_id'];
                $post->save();
            }

            return $post;
        }, attempts: 2);
    }

    public function bulkUpdatePosts(array $postIds, array $updates): int
    {
        // Using auto_transaction helper with options
        return auto_transaction(function () use ($postIds, $updates) {
            return Post::whereIn('id', $postIds)->update($updates);
        }, [
            'attempts' => 3,
            'connection' => null,
        ]);
    }
}
```

## Example 7: Complex Multi-Step Process

```php
<?php

namespace App\Services;

use App\Models\{Subscription, Invoice, Payment, User, Plan};
use Sheum\AutoTransaction\Traits\HandlesTransactions;

class SubscriptionService
{
    use HandlesTransactions;

    public function subscribe(User $user, string $planId): Subscription
    {
        return $this->runInTransaction(function () use ($user, $planId) {
            // Check if user already has active subscription
            if ($user->subscriptions()->active()->exists()) {
                throw new \Exception('User already has an active subscription');
            }

            // Get plan details
            $plan = Plan::findOrFail($planId);

            // Create subscription
            $subscription = $user->subscriptions()->create([
                'plan_id' => $plan->id,
                'starts_at' => now(),
                'ends_at' => now()->addMonth(),
                'status' => 'active',
            ]);

            // Create invoice
            $invoice = Invoice::create([
                'user_id' => $user->id,
                'subscription_id' => $subscription->id,
                'amount' => $plan->price,
                'due_date' => now(),
                'status' => 'pending',
            ]);

            // Process payment
            $payment = Payment::create([
                'invoice_id' => $invoice->id,
                'amount' => $plan->price,
                'method' => 'credit_card',
                'status' => 'completed',
            ]);

            // Update invoice
            $invoice->update([
                'status' => 'paid',
                'paid_at' => now(),
            ]);

            // Grant access to premium features
            $user->update(['is_premium' => true]);

            // Record activity
            $user->activities()->create([
                'type' => 'subscription_created',
                'description' => "Subscribed to {$plan->name}",
            ]);

            return $subscription->load('plan', 'invoice.payment');
        }, attempts: 3);
    }
}
```

## Example 8: Nested Transactions (Using Savepoints)

```php
<?php

namespace App\Services;

use App\Models\{Company, Department, Employee};
use Sheum\AutoTransaction\Traits\HandlesTransactions;

class CompanySetupService
{
    use HandlesTransactions;

    public function setupCompany(array $data): Company
    {
        return $this->runInTransaction(function () use ($data) {
            // Outer transaction
            $company = Company::create([
                'name' => $data['name'],
                'email' => $data['email'],
            ]);

            // Create departments (nested transaction via savepoint)
            foreach ($data['departments'] as $deptData) {
                $this->createDepartment($company, $deptData);
            }

            return $company->load('departments.employees');
        });
    }

    private function createDepartment(Company $company, array $data): Department
    {
        return $this->runInTransaction(function () use ($company, $data) {
            $department = $company->departments()->create([
                'name' => $data['name'],
                'budget' => $data['budget'],
            ]);

            // Create employees
            if (isset($data['employees'])) {
                foreach ($data['employees'] as $empData) {
                    $department->employees()->create($empData);
                }
            }

            return $department;
        });
    }
}
```

## Example 9: Error Handling

```php
<?php

namespace App\Services;

use App\Models\Order;
use Sheum\AutoTransaction\Exceptions\TransactionException;
use Sheum\AutoTransaction\Traits\HandlesTransactions;
use Illuminate\Support\Facades\Log;

class OrderProcessingService
{
    use HandlesTransactions;

    public function processOrder(array $orderData): ?Order
    {
        try {
            return $this->runInTransaction(
                callback: fn() => Order::create($orderData),
                attempts: 3,
                throwOnFailure: true
            );
        } catch (TransactionException $e) {
            // Log the error
            Log::error('Order processing failed', [
                'data' => $orderData,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Notify admin
            $this->notifyAdminOfFailure($e);

            // Return null or rethrow based on your needs
            return null;
        }
    }

    private function notifyAdminOfFailure(\Exception $e): void
    {
        // Send notification logic
    }
}
```

## Example 10: Repository Pattern

```php
<?php

namespace App\Repositories;

use App\Models\Product;
use Sheum\AutoTransaction\Traits\HandlesTransactions;

class ProductRepository
{
    use HandlesTransactions;

    public function create(array $data): Product
    {
        return $this->runInTransaction(function () use ($data) {
            $product = Product::create($data);

            // Sync categories
            if (isset($data['categories'])) {
                $product->categories()->sync($data['categories']);
            }

            // Create inventory record
            $product->inventory()->create([
                'quantity' => $data['stock'] ?? 0,
                'warehouse_id' => $data['warehouse_id'] ?? 1,
            ]);

            // Create price history
            $product->priceHistory()->create([
                'price' => $data['price'],
                'effective_date' => now(),
            ]);

            return $product;
        });
    }

    public function update(Product $product, array $data): Product
    {
        return $this->runInTransaction(function () use ($product, $data) {
            $product->update($data);

            // Update relationships if provided
            if (isset($data['categories'])) {
                $product->categories()->sync($data['categories']);
            }

            // Update price history if price changed
            if (isset($data['price']) && $data['price'] != $product->price) {
                $product->priceHistory()->create([
                    'price' => $data['price'],
                    'effective_date' => now(),
                ]);
            }

            return $product->fresh();
        });
    }

    public function delete(Product $product): bool
    {
        return $this->runInTransaction(function () use ($product) {
            // Soft delete related records
            $product->inventory()->delete();
            $product->priceHistory()->delete();
            $product->categories()->detach();

            return $product->delete();
        });
    }
}
```

## Example 11: Multiple Database Connections

```php
<?php

namespace App\Services;

use App\Models\User;
use Sheum\AutoTransaction\Traits\HandlesTransactions;

class AnalyticsService
{
    use HandlesTransactions;

    public function trackUserAction(User $user, array $actionData): void
    {
        // Save to main database
        $this->runInTransaction(function () use ($user, $actionData) {
            $user->actions()->create($actionData);
        });

        // Save to analytics database (separate connection)
        $this->runInTransaction(
            callback: function () use ($user, $actionData) {
                \DB::connection('analytics')->table('user_events')->insert([
                    'user_id' => $user->id,
                    'action' => $actionData['action'],
                    'metadata' => json_encode($actionData['metadata'] ?? []),
                    'created_at' => now(),
                ]);
            },
            connection: 'analytics'
        );
    }
}
```
