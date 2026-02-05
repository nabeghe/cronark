<?php namespace Nabeghe\Cronark\Tests\Integration;

use Nabeghe\Cronark\Cronark;
use Nabeghe\Cronark\Process;
use Nabeghe\Cronark\Storage;
use Nabeghe\Cronark\Tests\Fixtures\FailingJob;
use Nabeghe\Cronark\Tests\Fixtures\SlowJob;
use Nabeghe\Cronark\Tests\Fixtures\TestJob;
use Exception;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Integration Tests for Cronark
 *
 * These tests verify the complete workflow of Cronark with actual job execution
 */
class CronarkIntegrationTest extends TestCase
{
    private TestableCronark $cronark;
    private string $testStoragePath;

    protected function setUp(): void
    {
        // Create isolated storage for testing
        $this->testStoragePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cronark_integration_' . uniqid();

        // Ensure directory exists
        if (!is_dir($this->testStoragePath)) {
            mkdir($this->testStoragePath, 0755, true);
        }

        $storage = new Storage($this->testStoragePath);

        // Use TestableCronark which allows loop control
        $this->cronark = new TestableCronark($storage);

        // Reset job counters
        TestJob::reset();
        SlowJob::reset();
        FailingJob::reset();
    }

    protected function tearDown(): void
    {
        // Clean up storage files after each test
        try {
            if (is_dir($this->testStoragePath)) {
                $this->removeDirectory($this->testStoragePath);
            }
        } catch (Throwable $e) {
            // Ignore cleanup errors in tests
            echo "Warning: Failed to cleanup test directory: {$e->getMessage()}\n";
        }
    }

    /**
     * Recursively remove directory and its contents
     *
     * @param string $dir Directory path
     * @return void
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $item;

            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }

    #[Test]
    #[TestDox('Worker executes jobs in sequence')]
    public function it_executes_jobs_in_sequence(): void
    {
        // Add 3 jobs
        $this->cronark->addJob(TestJob::class, 'test_worker');
        $this->cronark->addJob(TestJob::class, 'test_worker');
        $this->cronark->addJob(TestJob::class, 'test_worker');

        // Limit to 3 executions (otherwise infinite loop)
        $this->cronark->setMaxIterations(3);
        $this->cronark->start('test_worker');

        // Should execute exactly 3 times
        $this->assertEquals(3, TestJob::$executionCount);

        // Verify logs are recorded correctly
        $this->assertCount(3, TestJob::$executionLog);
        $this->assertEquals('test_worker', TestJob::$executionLog[0]['worker']);
    }

    #[Test]
    #[TestDox('Worker wraps around jobs')]
    public function it_wraps_around_jobs(): void
    {
        // Only 2 jobs available
        $this->cronark->addJob(TestJob::class, 'test_worker');
        $this->cronark->addJob(TestJob::class, 'test_worker');

        // But execute 5 times (more than job count)
        $this->cronark->setMaxIterations(5);
        $this->cronark->start('test_worker');

        // Should execute 5 times (circular)
        $this->assertEquals(5, TestJob::$executionCount);
    }

    #[Test]
    #[TestDox('Worker handles job failures gracefully')]
    public function it_handles_job_failures_gracefully(): void
    {
        // Mix of successful and failing jobs
        $this->cronark->addJob(TestJob::class, 'test_worker');
        $this->cronark->addJob(FailingJob::class, 'test_worker'); // This will fail
        $this->cronark->addJob(TestJob::class, 'test_worker');

        $this->cronark->setMaxIterations(3);
        $this->cronark->start('test_worker');

        // Should execute 2 TestJobs (FailingJob doesn't increment counter)
        $this->assertEquals(2, TestJob::$executionCount);

        // Should have 1 recorded error
        $this->assertCount(1, $this->cronark->getErrors());
        $this->assertStringContainsString('Job failed intentionally', $this->cronark->getErrors()[0]->getMessage());
    }

    #[Test]
    #[TestDox('Worker prevents duplicate execution')]
    public function it_prevents_duplicate_execution(): void
    {
        $this->cronark->addJob(TestJob::class, 'test_worker');

        // Simulate worker already running
        $this->cronark->setPid(Process::id(), 'test_worker');

        $this->cronark->setMaxIterations(3);
        $this->cronark->start('test_worker');

        // Should not execute because already active
        $this->assertEquals(0, TestJob::$executionCount);
    }

    #[Test]
    #[TestDox('Worker stops when PID changes')]
    public function it_stops_when_pid_changes(): void
    {
        $this->cronark->addJob(TestJob::class, 'test_worker');

        // Change PID after 2 iterations
        $this->cronark->setChangePidAfterIterations(2);
        $this->cronark->setMaxIterations(10);
        $this->cronark->start('test_worker');

        // Debug: see actual count
        echo "\nActual execution count: " . TestJob::$executionCount . "\n";

        // Should only execute 2 times then stop
        $this->assertEquals(2, TestJob::$executionCount);
    }

    #[Test]
    #[TestDox('Worker resets index when jobs change')]
    public function it_resets_index_when_jobs_change(): void
    {
        // Add initial job
        $this->cronark->addJob(TestJob::class, 'test_worker');

        // Save hash
        $hash = $this->cronark->getJobsHash('test_worker');
        $this->cronark->saveJobsHash($hash, 'test_worker');

        // Set a high index value
        $this->cronark->setCurrentJobIndex(5, 'test_worker');

        // Add new job (jobs changed)
        $this->cronark->addJob(TestJob::class, 'test_worker');

        // Index should reset to null because jobs changed
        $this->assertNull($this->cronark->getCurrentJobIndex('test_worker'));
    }

    #[Test]
    #[TestDox('Multiple workers can run independently')]
    public function it_runs_multiple_workers_independently(): void
    {
        // Two different workers with different jobs
        $this->cronark->addJob(TestJob::class, 'worker1');
        $this->cronark->addJob(TestJob::class, 'worker2');

        // Execute worker1
        $this->cronark->setMaxIterations(2);
        $this->cronark->start('worker1');
        $count1 = TestJob::$executionCount;

        // Reset counters for worker2
        TestJob::reset();

        // Execute worker2
        $this->cronark->setMaxIterations(2);
        $this->cronark->start('worker2');
        $count2 = TestJob::$executionCount;

        // Each worker should execute 2 times
        $this->assertEquals(2, $count1);
        $this->assertEquals(2, $count2);
    }

    #[Test]
    #[TestDox('Worker does not start without jobs')]
    public function it_does_not_start_without_jobs(): void
    {
        // Only register worker without jobs
        $this->cronark->registerWorker('empty_worker');

        $this->cronark->setMaxIterations(3);
        $this->cronark->start('empty_worker');

        // No jobs should be executed
        $this->assertEquals(0, TestJob::$executionCount);
    }

    #[Test]
    #[TestDox('Worker does not start for unregistered worker')]
    public function it_does_not_start_unregistered_worker(): void
    {
        $this->cronark->setMaxIterations(3);
        $this->cronark->start('non_existent_worker');

        // No jobs should be executed
        $this->assertEquals(0, TestJob::$executionCount);
    }

    #[Test]
    #[TestDox('killAll() terminates all workers')]
    public function it_kills_all_workers(): void
    {
        // Simulate 3 active workers
        $this->cronark->registerWorker('worker1');
        $this->cronark->registerWorker('worker2');
        $this->cronark->registerWorker('worker3');

        $this->cronark->setPid(999991, 'worker1');
        $this->cronark->setPid(999992, 'worker2');
        $this->cronark->setPid(999993, 'worker3');

        // Kill all
        $this->cronark->killAll();

        // All PIDs should be null
        $this->assertNull($this->cronark->getPid('worker1'));
        $this->assertNull($this->cronark->getPid('worker2'));
        $this->assertNull($this->cronark->getPid('worker3'));
    }

    #[Test]
    #[TestDox('Worker handles slow jobs correctly')]
    public function it_handles_slow_jobs_correctly(): void
    {
        $this->cronark->addJob(SlowJob::class, 'test_worker');
        $this->cronark->addJob(SlowJob::class, 'test_worker');

        $startTime = microtime(true);

        $this->cronark->setMaxIterations(2);
        $this->cronark->start('test_worker');

        $duration = microtime(true) - $startTime;

        // Should take at least 0.15 seconds (2 jobs × 0.1 second with tolerance)
        $this->assertGreaterThanOrEqual(0.15, $duration);
        $this->assertEquals(2, SlowJob::$executionCount);
    }

    #[Test]
    #[TestDox('Worker executes mixed fast and slow jobs')]
    public function it_executes_mixed_speed_jobs(): void
    {
        $this->cronark->addJob(TestJob::class, 'test_worker');
        $this->cronark->addJob(SlowJob::class, 'test_worker');
        $this->cronark->addJob(TestJob::class, 'test_worker');

        $startTime = microtime(true);

        $this->cronark->setMaxIterations(3);
        $this->cronark->start('test_worker');

        $duration = microtime(true) - $startTime;

        // Should take at least 0.08 seconds (for SlowJob with tolerance)
        $this->assertGreaterThanOrEqual(0.08, $duration);

        // Total 3 jobs executed
        $this->assertEquals(2, TestJob::$executionCount);
        $this->assertEquals(1, SlowJob::$executionCount);
    }

    #[Test]
    #[TestDox('Worker can handle timeout scenarios')]
    public function it_can_handle_timeout_scenarios(): void
    {
        $this->cronark->addJob(SlowJob::class, 'test_worker');

        // Test that worker can execute slow jobs without timeout
        $this->cronark->setMaxIterations(1);

        $startTime = microtime(true);
        $this->cronark->start('test_worker');
        $duration = microtime(true) - $startTime;

        $this->assertLessThan(1, $duration); // Should be less than 1 second
        $this->assertEquals(1, SlowJob::$executionCount);
    }

    #[Test]
    #[TestDox('Worker preserves job order in execution')]
    public function it_preserves_job_order(): void
    {
        $this->cronark->addJob(TestJob::class, 'test_worker');
        $this->cronark->addJob(TestJob::class, 'test_worker');
        $this->cronark->addJob(TestJob::class, 'test_worker');

        $this->cronark->setMaxIterations(3);
        $this->cronark->start('test_worker');

        // Check execution order in log
        $this->assertCount(3, TestJob::$executionLog);

        // Execution time should be in ascending order
        $this->assertLessThan(
            TestJob::$executionLog[1]['time'],
            TestJob::$executionLog[0]['time']
        );
        $this->assertLessThan(
            TestJob::$executionLog[2]['time'],
            TestJob::$executionLog[1]['time']
        );
    }

    #[Test]
    #[TestDox('Worker continues after job failure')]
    public function it_continues_after_job_failure(): void
    {
        $this->cronark->addJob(TestJob::class, 'test_worker');
        $this->cronark->addJob(FailingJob::class, 'test_worker');
        $this->cronark->addJob(TestJob::class, 'test_worker');
        $this->cronark->addJob(TestJob::class, 'test_worker');

        $this->cronark->setMaxIterations(4);
        $this->cronark->start('test_worker');

        // Should successfully execute 3 TestJobs
        $this->assertEquals(3, TestJob::$executionCount);

        // And have 1 error
        $this->assertCount(1, $this->cronark->getErrors());
    }
}

/**
 * Testable Cronark Class
 *
 * Extended Cronark class for testing purposes that allows controlling the infinite loop
 */
class TestableCronark extends Cronark
{
    /**
     * Maximum number of iterations the worker can execute
     */
    private int $maxIterations = -1;

    /**
     * Current iteration count (total job attempts, not just successful)
     */
    private int $currentIteration = 0;

    /**
     * Change PID after this many iterations (for testing)
     */
    private int $changePidAfterIterations = -1;

    /**
     * List of errors that occurred
     */
    private array $errors = [];

    /**
     * Set maximum number of iterations
     */
    public function setMaxIterations(int $max): void
    {
        $this->maxIterations = $max;
        $this->currentIteration = 0;
    }

    /**
     * Set when to change PID (simulates process change)
     */
    public function setChangePidAfterIterations(int $iterations): void
    {
        $this->changePidAfterIterations = $iterations;
    }

    /**
     * Get list of errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Override canCheckPid to change PID at the right time
     */
    protected function canCheckPid(?string $jobClass, ?int $jobIndex, string $worker = 'main', bool $isFirst = true): bool
    {
        // If we need to change PID after certain iterations
        if ($this->changePidAfterIterations > 0 &&
            $this->currentIteration >= $this->changePidAfterIterations) {
            // Change PID to fake value before the check
            $this->setPid(999999, $worker);
        }

        return parent::canCheckPid($jobClass, $jobIndex, $worker, $isFirst);
    }

    /**
     * Override handle to count ALL job attempts (successful or failed)
     */
    protected function handle(?string $jobClass, ?int $index, string $worker = 'main', bool $isFirst = true): bool
    {
        // Count BEFORE executing (counts all attempts)
        $this->currentIteration++;

        // Execute the job
        return parent::handle($jobClass, $index, $worker, $isFirst);
    }

    /**
     * Override canLoop to limit iteration count
     */
    protected function canLoop(?string $jobClass, ?int $jobIndex, string $worker, bool $isFirst = true): bool
    {
        // Stop loop if max iterations reached
        if ($this->maxIterations > 0 && $this->currentIteration >= $this->maxIterations) {
            return false;
        }

        return parent::canLoop($jobClass, $jobIndex, $worker, $isFirst);
    }

    /**
     * Override onError to collect errors
     */
    protected function onError(Throwable $e, string $worker): void
    {
        $this->errors[] = $e;
        parent::onError($e, $worker);
    }
}


