<?php

namespace Sheum\AutoTransaction\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Sheum\AutoTransaction\Attributes\Transactional;
use Sheum\AutoTransaction\Traits\HandlesTransactions;
use Sheum\AutoTransaction\Exceptions\TransactionException;

class TransactionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Create test tables
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });

        Schema::create('profiles', function ($table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('bio')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('profiles');
        Schema::dropIfExists('users');

        parent::tearDown();
    }

    /** @test */
    public function it_commits_transaction_on_success()
    {
        $service = new class {
            use HandlesTransactions;

            #[Transactional]
            public function createUser()
            {
                DB::table('users')->insert([
                    'name' => 'sheum',
                    'email' => 'sheum@example.com',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return true;
            }

            public function execute()
            {
                return $this->executeWithTransactionIfNeeded('createUser');
            }
        };

        $service->execute();

        $this->assertDatabaseHas('users', [
            'name' => 'sheum',
            'email' => 'sheum@example.com',
        ]);
    }

    /** @test */
    public function it_rolls_back_transaction_on_exception()
    {
        $service = new class {
            use HandlesTransactions;

            #[Transactional]
            public function createUserWithError()
            {
                DB::table('users')->insert([
                    'name' => 'Sheum 2',
                    'email' => 'sheum2@example.com',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                throw new \Exception('Something went wrong');
            }

            public function execute()
            {
                return $this->executeWithTransactionIfNeeded('createUserWithError');
            }
        };

        try {
            $service->execute();
        } catch (\Exception $e) {
            // Expected exception
        }

        $this->assertDatabaseMissing('users', [
            'name' => 'Sheum 2',
            'email' => 'sheum2@example.com',
        ]);
    }

    /** @test */
    public function it_handles_multiple_operations_in_transaction()
    {
        $service = new class {
            use HandlesTransactions;

            #[Transactional]
            public function createUserWithProfile()
            {
                $userId = DB::table('users')->insertGetId([
                    'name' => 'Alice Smith',
                    'email' => 'alice@example.com',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('profiles')->insert([
                    'user_id' => $userId,
                    'bio' => 'Software Developer',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $userId;
            }

            public function execute()
            {
                return $this->executeWithTransactionIfNeeded('createUserWithProfile');
            }
        };

        $userId = $service->execute();

        $this->assertDatabaseHas('users', [
            'name' => 'Alice Smith',
        ]);

        $this->assertDatabaseHas('profiles', [
            'user_id' => $userId,
            'bio' => 'Software Developer',
        ]);
    }

    /** @test */
    public function it_rolls_back_all_operations_on_failure()
    {
        $service = new class {
            use HandlesTransactions;

            #[Transactional]
            public function createUserWithProfileAndFail()
            {
                $userId = DB::table('users')->insertGetId([
                    'name' => 'Bob Johnson',
                    'email' => 'bob@example.com',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('profiles')->insert([
                    'user_id' => $userId,
                    'bio' => 'Designer',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // This should rollback everything
                throw new \Exception('Profile creation failed');
            }

            public function execute()
            {
                return $this->executeWithTransactionIfNeeded('createUserWithProfileAndFail');
            }
        };

        try {
            $service->execute();
        } catch (\Exception $e) {
            // Expected
        }

        // Both insertions should be rolled back
        $this->assertDatabaseMissing('users', [
            'name' => 'Bob Johnson',
        ]);

        $this->assertDatabaseMissing('profiles', [
            'bio' => 'Designer',
        ]);
    }

    /** @test */
    public function helper_function_transactional_works()
    {
        transactional(function () {
            DB::table('users')->insert([
                'name' => 'Helper User',
                'email' => 'helper@example.com',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertDatabaseHas('users', [
            'name' => 'Helper User',
            'email' => 'helper@example.com',
        ]);
    }

    /** @test */
    public function helper_function_rolls_back_on_exception()
    {
        try {
            transactional(function () {
                DB::table('users')->insert([
                    'name' => 'Failed User',
                    'email' => 'failed@example.com',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                throw new \Exception('Transaction failed');
            });
        } catch (\Exception $e) {
            // Expected
        }

        $this->assertDatabaseMissing('users', [
            'name' => 'Failed User',
        ]);
    }

    /** @test */
    public function auto_transaction_helper_works()
    {
        auto_transaction(function () {
            DB::table('users')->insert([
                'name' => 'Auto User',
                'email' => 'auto@example.com',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertDatabaseHas('users', [
            'name' => 'Auto User',
        ]);
    }

    /** @test */
    public function it_respects_custom_attempts_configuration()
    {
        $service = new class {
            use HandlesTransactions;
            public int $attemptCount = 0;

            #[Transactional(attempts: 3)]
            public function createUserWithRetry()
            {
                $this->attemptCount++;

                DB::table('users')->insert([
                    'name' => 'Retry User',
                    'email' => 'retry@example.com',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return $this->attemptCount;
            }

            public function execute()
            {
                return $this->executeWithTransactionIfNeeded('createUserWithRetry');
            }
        };

        $attempts = $service->execute();

        $this->assertEquals(1, $attempts);
        $this->assertDatabaseHas('users', [
            'name' => 'Retry User',
        ]);
    }

    /** @test */
    public function it_throws_transaction_exception_on_failure()
    {
        $service = new class {
            use HandlesTransactions;

            #[Transactional(throwOnFailure: true)]
            public function failingMethod()
            {
                throw new \Exception('Original exception');
            }

            public function execute()
            {
                return $this->executeWithTransactionIfNeeded('failingMethod');
            }
        };

        $this->expectException(TransactionException::class);
        $this->expectExceptionMessage('Transaction failed: Original exception');

        $service->execute();
    }

    /** @test */
    public function it_can_use_run_in_transaction_manually()
    {
        $service = new class {
            use HandlesTransactions;

            public function manualTransaction()
            {
                return $this->runInTransaction(function () {
                    DB::table('users')->insert([
                        'name' => 'Manual User',
                        'email' => 'manual@example.com',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    return 'success';
                });
            }
        };

        $result = $service->manualTransaction();

        $this->assertEquals('success', $result);
        $this->assertDatabaseHas('users', [
            'name' => 'Manual User',
        ]);
    }

    /** @test */
    public function it_returns_null_when_throw_on_failure_is_false()
    {
        $service = new class {
            use HandlesTransactions;

            public function failSilently()
            {
                return $this->runInTransaction(
                    callback: function () {
                        throw new \Exception('Silent fail');
                    },
                    connection: null,
                    attempts: 1,
                    throwOnFailure: false
                );
            }
        };

        $result = $service->failSilently();

        $this->assertNull($result);
    }

    /** @test */
    public function it_handles_nested_transactions_with_savepoints()
    {
        $service = new class {
            use HandlesTransactions;

            #[Transactional]
            public function outerTransaction()
            {
                $userId = DB::table('users')->insertGetId([
                    'name' => 'Nested User',
                    'email' => 'nested@example.com',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Inner transaction (will use savepoint)
                $this->innerTransaction($userId);

                return $userId;
            }

            #[Transactional]
            public function innerTransaction($userId)
            {
                DB::table('profiles')->insert([
                    'user_id' => $userId,
                    'bio' => 'Nested profile',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            public function execute()
            {
                return $this->executeWithTransactionIfNeeded('outerTransaction');
            }
        };

        $userId = $service->execute();

        $this->assertDatabaseHas('users', [
            'name' => 'Nested User',
        ]);

        $this->assertDatabaseHas('profiles', [
            'user_id' => $userId,
            'bio' => 'Nested profile',
        ]);
    }

    /** @test */
    public function helper_function_accepts_attempts_parameter()
    {
        $result = transactional(function () {
            DB::table('users')->insert([
                'name' => 'Attempt User',
                'email' => 'attempt@example.com',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return 'completed';
        }, attempts: 3);

        $this->assertEquals('completed', $result);
        $this->assertDatabaseHas('users', [
            'name' => 'Attempt User',
        ]);
    }
}
