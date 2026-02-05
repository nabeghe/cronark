# Cronark

**A lightweight, cron-based background job scheduler and worker manager for PHP.**

Cronark provides a simple yet powerful solution for running background jobs in PHP applications.
Unlike traditional queue systems that require external services (Redis, RabbitMQ, Beanstalkd),
Cronark works with just **cron** and **minimal storage**; making it perfect for shared hosting,
small to medium projects, or anywhere you want to avoid infrastructure complexity.

---

## 🎯 Why Cronark?

### The Problem with Traditional Queue Systems

Most PHP queue solutions come with significant challenges:

- **❌ Infrastructure Overhead**: Require Redis, RabbitMQ, or other external services
- **❌ Shared Hosting**: Not available on most shared hosting environments
- **❌ Complexity**: Learning curve, configuration, and maintenance burden
- **❌ Resource Heavy**: Memory consumption and process management complexity
- **❌ Overkill**: Too much for simple background job needs

### The Cronark Solution

Cronark takes a different approach:

- **✅ Zero Dependencies**: Just PHP 8.1+ and cron (available everywhere)
- **✅ Shared Hosting Friendly**: Works on any hosting with cron access
- **✅ Simple Setup**: Define jobs, register with cron, done
- **✅ Lightweight**: Minimal resource footprint
- **✅ Process-Safe**: Prevents duplicate worker execution automatically
- **✅ Flexible Storage**: File-based by default, easily customizable to database or Redis

---

## 📦 Installation

Install via Composer:

```bash
composer require nabeghe/cronark
```

Requirements:
- PHP 8.1 or higher
- Cron access (available on virtually all hosting providers)

## 🚀 Quick Start

### 1. Create a Job

```php
<?php

use Nabeghe\Cronark\Cronark;
use Nabeghe\Cronark\Job;

class SendEmailsJob implements Job
{
    public function __construct(private Cronark $cronark)
    {
    }

    public function handle(): void
    {
        // Your job logic here
        $this->cronark->print('Sending emails...');
        
        // Send pending emails
        // ...
        
        $this->cronark->print('Emails sent successfully!');
    }
}
```

### 2. Register the Job

Create a worker file (e.g., worker.php):

```php
<?php

require 'vendor/autoload.php';

use Nabeghe\Cronark\Cronark;

$cronark = new Cronark();

// Register jobs for the 'email' worker
$cronark->addJob(SendEmailsJob::class, 'email');
$cronark->addJob(ProcessNewsletterJob::class, 'email');

// Start the worker
$cronark->start('email');
```

### 3. Setup Cron

Add to your crontab:

```bash
# Run email worker every minute
* * * * * php /path/to/worker.php
```

That's it! Your jobs will now run continuously in the background.

## 🎨 Features

Multiple Workers
Run different workers for different job types:

```php
$cronark = new Cronark();

// Email worker - runs every minute
$cronark->addJob(SendEmailsJob::class, 'email');
$cronark->addJob(ProcessBouncesJob::class, 'email');

// Data processing worker - runs every 5 minutes
$cronark->addJob(ImportDataJob::class, 'data');
$cronark->addJob(GenerateReportsJob::class, 'data');

// Cleanup worker - runs hourly
$cronark->addJob(CleanupTempFilesJob::class, 'cleanup');
$cronark->addJob(PurgeOldLogsJob::class, 'cleanup');

// Start specific worker
$worker = $argv ?? 'email';[1]
$cronark->start($worker);
```

### Process Management

Cronark automatically prevents duplicate workers:

```php
// If worker is already running, this will abort
$cronark->start('email');

// Check if worker is active
if ($cronark->isActive('email', $pid)) {
    echo "Worker is running with PID: $pid";
}

// Gracefully stop a worker
$cronark->kill('email');

// Stop all workers
$cronark->killAll();
```

### Job Ordering

Control job execution order:

```php
// Add to end (default)
$cronark->addJob(JobA::class, 'worker');
$cronark->addJob(JobB::class, 'worker');

// Insert at specific position
$cronark->addJob(UrgentJob::class, 'worker', 0); // First

// Jobs execute: UrgentJob -> JobA -> JobB -> repeat
```

### Custom Storage

Implement your own storage backend (Database, Redis, Memcached, etc.):

```php
use Nabeghe\Cronark\StorageInterface;

class DatabaseStorage implements StorageInterface
{
    public function get(string $key, ?string $worker = null): mixed
    {
        // Fetch from database
        return DB::table('cronark_storage')
            ->where('worker', $worker)
            ->where('key', $key)
            ->value('value');
    }

    public function set(string $key, mixed $value, ?string $worker = null): bool
    {
        // Save to database
        return DB::table('cronark_storage')->updateOrInsert(
            ['worker' => $worker, 'key' => $key],
            ['value' => serialize($value)]
        );
    }
}

$cronark = new Cronark(new DatabaseStorage());
```

#### Built-in Storage:

- File-based (default): Zero setup, works everywhere
- Custom: Implement StorageInterface for database, Redis, etc.

#### Lifecycle Hooks

Customize worker behavior:

```php
class CustomCronark extends Cronark
{
    protected function onStarted(string $worker): void
    {
        // Log worker start
        Log::info("Worker {$worker} started");
    }

    protected function onStopped(string $worker): void
    {
        // Log worker stop
        Log::info("Worker {$worker} stopped");
    }

    protected function onError(Throwable $e, string $worker): void
    {
        // Custom error handling
        Log::error("Worker {$worker} error: " . $e->getMessage());
        parent::onError($e, $worker);
    }

    protected function onJobCreating(): void
    {
        // Before each job instantiation
        DB::reconnect(); // Reconnect to database
    }
}
```

### Performance Tuning

Control CPU usage by adding delay between job executions:

```php
// No delay (default) - maximum speed
$cronark = new Cronark();
$cronark->start('worker');

// Balanced - 50ms delay (recommended for shared hosting)
$cronark = new Cronark();
$cronark->setDelay(50000); // microseconds
$cronark->start('worker');

// Using seconds (convenience method)
$cronark = new Cronark();
$cronark->setDelaySeconds(0.1); // 100ms
$cronark->start('worker');

// Method chaining
$cronark = new Cronark();
$cronark->setDelay(50000)
    ->addJob(EmailJob::class)
    ->start('worker');
```

#### When to use delay:

* ✅ Shared hosting with CPU limits
* ✅ Light/fast jobs (< 10ms execution time)
* ✅ Rate limiting external API calls
* ✅ Reducing database connection pressure

#### When NOT to use delay:

* ❌ Heavy/slow jobs (they already have natural delay)
* ❌ Real-time processing requirements
* ❌ Time-sensitive operations

#### Recommended values:

* 0 – No delay (default, maximum speed)
* 10000 – 10ms (~100 jobs/sec, near real-time)
* 50000 – 50ms (~20 jobs/sec, balanced)
* 100000 – 100ms (~10 jobs/sec, CPU friendly)

## 📖 How It Works

### Architecture

- Cron Trigger: Cron executes worker script every minute (or your interval)
- Duplicate Prevention: Worker checks if it's already running (via PID + script path)
- Infinite Loop: If not running, worker enters infinite loop
- Job Execution: Executes jobs sequentially in a circular fashion
- Graceful Shutdown: Monitors PID to allow graceful termination

### Process Flow

```
Cron executes worker.php
    ↓
Check if worker already active?
    ↓ No
Register PID and start infinite loop
    ↓
Execute Job 1 → Job 2 → Job 3 → Job 1 → ...
    ↓
Check PID still valid after each job
    ↓
Continue until killed or error
```
### Job Wrapping

Jobs execute in a circular fashion:

```php
$cronark->addJob(JobA::class, 'worker');
$cronark->addJob(JobB::class, 'worker');
$cronark->addJob(JobC::class, 'worker');

// Execution order: A → B → C → A → B → C → A → ...
```
This ensures all jobs get executed repeatedly without any sitting idle.

### 🔧 Advanced Usage

Jobs can throw exceptions; they won't stop the worker:

### Error Handling

```php
class RiskyJob implements Job
{
    public function handle(): void
    {
        try {
            // Risky operation
            $this->processData();
        } catch (Exception $e) {
            // Handle error
            Log::error($e->getMessage());
            throw $e; // Worker will catch and continue
        }
    }
}
```

### Worker Isolation

Each worker maintains its own state:

```php
// worker-email.php
$cronark = new Cronark();
$cronark->addJob(SendEmailsJob::class, 'email');
$cronark->start('email');

// worker-data.php
$cronark = new Cronark();
$cronark->addJob(ProcessDataJob::class, 'data');
$cronark->start('data');

// Both can run simultaneously without conflict
```

### Cron Schedule Examples

```bash
# Every minute
* * * * * php /path/to/worker-email.php

# Every 5 minutes
*/5 * * * * php /path/to/worker-data.php

# Every hour
0 * * * * php /path/to/worker-cleanup.php

# Every day at 2 AM
0 2 * * * php /path/to/worker-reports.php

# Multiple workers
* * * * * php /path/to/worker.php email
*/5 * * * * php /path/to/worker.php data
0 * * * * php /path/to/worker.php cleanup
```

### Deployment on Shared Hosting

Most shared hosting providers (cPanel, Plesk) offer cron job access:

#### cPanel:

1. Go to "Cron Jobs"
2. Add: * * * * * php /home/username/public_html/worker.php

#### Plesk:

1. Go to "Scheduled Tasks"
2. Add command: php /var/www/vhosts/domain.com/worker.php
3. Set schedule: Every minute

## 🧪 Testing

```bash
# Install dev dependencies
composer install

# Run all tests
composer test

# Run specific test suite
composer test:unit
composer test:integration

# Generate coverage report
composer test:coverage
```

Test Coverage: 67 tests with full coverage of all core functionality.

## 🛡️ Reliability

### Duplicate Prevention

Cronark uses PID + Script Path verification to prevent duplicate workers:

```php
// If worker already running
if ($cronark->isActive('email')) {
    echo "Worker already running, aborting";
    return; // Cron job exits
}

// Otherwise, start worker
$cronark->start('email');
```

### Crash Recovery

If worker crashes, cron will restart it on next trigger:

```
Worker crashes at 10:05:23
    ↓
Cron triggers at 10:06:00
    ↓
Detects worker not running (PID check fails)
    ↓
Starts new worker automatically
```

### Atomic Storage

File operations use LOCK_EX for atomic writes:

```php
// Prevents race conditions between workers
file_put_contents($file, $data, LOCK_EX);
```

## 🤝 Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

```bash
# Clone repository
git clone https://github.com/nabeghe/cronark.git
cd cronark

# Install dependencies
composer install

# Run tests
composer test
```

## 💡 Use Cases

Perfect for:

* Shared Hosting Projects: No Redis/RabbitMQ required
* Small to Medium Apps: When full queue infrastructure is overkill
* Email Processing: Send newsletters, notifications
* Data Import/Export: Process CSV files, API sync
* Report Generation: Periodic reports, analytics
* Cleanup Tasks: Temp file cleanup, log rotation
* Social Media Posting: Schedule posts, fetch feeds
* Database Maintenance: Backups, optimization
* Any Recurring Task: If it needs to run regularly, Cronark can handle it

## 📚 Documentation

### Core Classes

* `Cronark`: Main scheduler class
* `Job`: Interface for all jobs
* `Process`: Cross-platform process utilities
* `Storage`: File-based storage implementation
* `StorageInterface`: Storage contract for custom backends

### Key Methods

```php
// Worker management
$cronark->registerWorker(string $worker): void
$cronark->start(string $worker): void
$cronark->isActive(string $worker, ?int &$pid = null): bool
$cronark->kill(string $worker, ?int &$pid = null): bool
$cronark->killAll(): void

// Job management
$cronark->addJob(string $job, string $worker = 'main', int $position = -1): void
$cronark->getJobsCount(?string $worker = null): int
$cronark->hasAnyJob(?string $worker = null): bool

// State management
$cronark->getPid(string $worker): ?int
$cronark->setPid(?int $pid, string $worker): bool
$cronark->getCurrentWorker(): ?string
```

## ⚠️ Important Notes

1. **Execution Time**: Workers run indefinitely; ensure your hosting allows long-running processes
2. **Memory**: Monitor memory usage if jobs process large datasets
3. **Error Handling**: Always implement proper error handling in jobs
4. **Logging**: Use the `print()` method or implement custom logging
5. **Database Connections**: Reconnect to database in `onJobCreating()` hook to avoid timeout issues

## 🌟 Show Your Support

If you find Cronark useful, please:

* ⭐ Star the repository
* 🐛 Report bugs
* 💡 Suggest features
* 📖 Improve documentation
* 🔀 Submit pull requests

## 📖 License

Licensed under the MIT license, see [LICENSE.md](LICENSE.md) for details.

---

Made with ❤️ by (Nabeghe)[https://github.com/nabeghe]

Cronark - Simple, Reliable, Zero-Dependency Background Jobs for PHP
