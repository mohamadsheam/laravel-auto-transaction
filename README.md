# Laravel Auto Transaction

[![Latest Version on Packagist](https://img.shields.io/packagist/v/sheum/laravel-auto-transaction.svg?style=flat-square)](https://packagist.org/packages/sheum/laravel-auto-transaction) [![Total Downloads](https://img.shields.io/packagist/dt/sheum/laravel-auto-transaction.svg?style=flat-square)](https://packagist.org/packages/sheum/laravel-auto-transaction) [![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/mohamadsheam/laravel-auto-transaction/run-tests.yml?branch=main&label=tests)](https://github.com/mohamadsheam/laravel-auto-transaction/actions)

Automatic database transaction management for Laravel applications. No more manually calling `DB::beginTransaction()`, `DB::commit()`, and `DB::rollBack()`!

## Features

- 🛡️ **Middleware support** - Automatic transactions for entire routes
- 🔧 **Trait integration** - Easy to use in services and repositories
- 🎨 **Helper functions** - Simple wrapper functions
- 🔄 **Retry mechanism** - Handle deadlocks automatically
- 🎛️ **Multiple connections** - Support for different database connections

## Installation

```bash
composer require sheum/laravel-auto-transaction
```

The package will automatically register itself.

### Publish Configuration (Optional)

```bash
php artisan vendor:publish --tag=auto-transaction-config
```

## Usage

### Method 1: Using Trait with runInTransaction (Recommended for Services)

```php
use Sheum\AutoTransaction\Traits\HandlesTransactions;

class UserService
{
    use HandlesTransactions;
    
    public function createUser(array $data)
    {
        return $this->runInTransaction(function () use ($data) {
            $user = User::create($data);
            $user->profile()->create($data['profile']);
            
            // Automatically commits on success
            // Automatically rolls back on exception
            return $user;
        });
    }
    
    public function updateUserWithRetry(User $user, array $data)
    {
        return $this->runInTransaction(
            callback: fn() => $user->update($data),
            attempts: 3,
            connection: 'mysql'
        );
    }
}
```

### Method 2: Using Middleware (For Controllers)

Apply to specific routes:

```php
// In routes/web.php or routes/api.php
Route::middleware(['transaction'])->group(function () {
    Route::post('/orders', [OrderController::class, 'store']);
    Route::put('/orders/{order}', [OrderController::class, 'update']);
    Route::delete('/orders/{order}', [OrderController::class, 'destroy']);
});

// Or on a single route
Route::post('/users', [UserController::class, 'store'])
    ->middleware('transaction');

// With custom connection
Route::post('/reports', [ReportController::class, 'generate'])
    ->middleware('transaction:reporting_db');
```

Apply to controller methods:

```php
class OrderController extends Controller
{
    public function __construct()
    {
        $this->middleware('transaction')->only(['store', 'update', 'destroy']);
    }
    
    public function store(Request $request)
    {
        $order = Order::create($request->validated());
        // Automatically commits on successful response (2xx)
        // Automatically rolls back on error or exception
        return response()->json($order, 201);
    }
}
```

### Method 3: Using Helper Functions

```php
// Simple usage
$user = transactional(function () use ($data) {
    $user = User::create($data);
    $user->profile()->create($data['profile']);
    return $user;
});

// With retry attempts
$order = transactional(function () use ($orderData) {
    return Order::create($orderData);
}, attempts: 3);

// With custom connection
$report = transactional(function () use ($data) {
    return Report::create($data);
}, connection: 'reporting_db');

// Using auto_transaction helper
$result = auto_transaction(function () {
    // Your database operations
}, [
    'attempts' => 3,
    'connection' => 'mysql'
]);
```

### Method 4: Using Attributes (Advanced)

For advanced usage, you can use attributes with the trait:

```php
use Sheum\AutoTransaction\Attributes\Transactional;
use Sheum\AutoTransaction\Traits\HandlesTransactions;

class OrderService
{
    use HandlesTransactions;
    
    public function placeOrder(array $orderData)
    {
        return $this->executeWithTransactionIfNeeded('placeOrderWithTransaction', [$orderData]);
    }
    
    #[Transactional(attempts: 3)]
    protected function placeOrderWithTransaction(array $orderData)
    {
        $order = Order::create($orderData);
        
        foreach ($orderData['items'] as $item) {
            $order->items()->create($item);
        }
        
        return $order;
    }
}
```

## Configuration Options

### runInTransaction Parameters

```php
$this->runInTransaction(
    callback: fn() => /* your code */,
    connection: 'mysql',      // Database connection name (default: null)
    attempts: 3,              // Number of retry attempts (default: 1)
    throwOnFailure: true      // Throw exception on failure (default: true)
);
```

### Attribute Parameters

```php
#[Transactional(
    connection: 'mysql',      // Database connection name (default: null)
    attempts: 3,              // Number of retry attempts (default: 1)
    throwOnFailure: true      // Throw exception on failure (default: true)
)]
```

## Best Practices

1. **Use `runInTransaction()` for service methods** - Clean and explicit
2. **Use middleware for API endpoints** - Automatic transaction per request
3. **Use helper functions for quick operations** - Simple and straightforward
4. **Use traits in repositories** - Consistent transaction handling
5. **Handle nested transactions carefully** - Laravel uses savepoints
6. **Don't mix transaction methods** - Choose one approach per layer

## Error Handling

The package throws `TransactionException` on failure:

```php
use Sheum\AutoTransaction\Exceptions\TransactionException;

try {
    $user = $userService->createUser($data);
} catch (TransactionException $e) {
    Log::error('User creation failed: ' . $e->getMessage());
    return response()->json(['error' => 'Failed to create user'], 500);
}
```

## Testing

```bash
composer test
```

## Examples

### E-commerce Order Processing

```php
use Sheum\AutoTransaction\Traits\HandlesTransactions;

class OrderService
{
    use HandlesTransactions;
    
    public function completeOrder(Cart $cart, array $paymentData)
    {
        return $this->runInTransaction(function () use ($cart, $paymentData) {
            // Create order
            $order = Order::create([
                'user_id' => $cart->user_id,
                'total' => $cart->total,
            ]);
            
            // Transfer cart items to order
            foreach ($cart->items as $item) {
                $order->items()->create([
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                ]);
            }
            
            // Reduce inventory
            foreach ($cart->items as $item) {
                Product::find($item->product_id)
                    ->decrement('stock', $item->quantity);
            }
            
            // Process payment
            $payment = Payment::create([
                'order_id' => $order->id,
                'amount' => $cart->total,
                'method' => $paymentData['method'],
            ]);
            
            // Clear cart
            $cart->items()->delete();
            
            // All or nothing - automatic commit/rollback
            return $order;
        }, attempts: 3);
    }
}
```

### User Registration with Profile

```php
use Sheum\AutoTransaction\Traits\HandlesTransactions;

class UserService
{
    use HandlesTransactions;
    
    public function registerUser(array $data)
    {
        return $this->runInTransaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
            ]);
            
            $user->profile()->create([
                'bio' => $data['bio'],
                'avatar' => $data['avatar'],
            ]);
            
            $user->settings()->create([
                'notifications' => true,
                'newsletter' => $data['newsletter'] ?? false,
            ]);
            
            return $user;
        });
    }
}
```

### Banking Transfer Service

```php
use Sheum\AutoTransaction\Traits\HandlesTransactions;

class BankingService
{
    use HandlesTransactions;
    
    public function transfer(Account $from, Account $to, float $amount)
    {
        return $this->runInTransaction(
            callback: function () use ($from, $to, $amount) {
                if ($from->balance < $amount) {
                    throw new \Exception('Insufficient funds');
                }
                
                $from->decrement('balance', $amount);
                $to->increment('balance', $amount);
                
                return Transaction::create([
                    'from_account_id' => $from->id,
                    'to_account_id' => $to->id,
                    'amount' => $amount,
                ]);
            },
            attempts: 5,
            connection: 'banking'
        );
    }
}
```

## Additional Examples

Please see [Additional Examples](EXAMPLES.MD) for more examples.

## Requirements

- PHP 8.1 or higher
- Laravel 10.x or 11.x

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.

## Changelog

Please see [CHANGELOG](CHANGELOG) for more information on what has changed recently.

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

## Credits

- [MD Nazmul Hasan Sheum](https://github.com/mohamadsheam)

## Support

If you discover any issues, please email <nazmulhasansheum@gmail.com> or create an issue on GitHub.
