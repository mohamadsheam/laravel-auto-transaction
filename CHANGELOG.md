# Changelog

All notable changes to `laravel-auto-transaction` will be documented in this file.

## [1.0.0] - 2025-10-05

### Added

- Initial release
- Transactional attribute for automatic transaction handling
- HandlesTransactions trait for service classes
- TransactionMiddleware for controller actions
- Helper functions (transactional, auto_transaction)
- Support for multiple database connections
- Retry mechanism for handling deadlocks
- Comprehensive test suite
- Full documentation

### Features

- PHP 8.1+ attribute-based transactions
- Automatic commit on success
- Automatic rollback on exceptions
- Configurable retry attempts
- Multiple database connection support
- Middleware for HTTP requests
- Clean and simple API
