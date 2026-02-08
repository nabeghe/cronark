<?php namespace Nabeghe\Cronark;

use Exception;
use Throwable;

/**
 * Cronark - Background Job Scheduler
 *
 * A lightweight cron-like job scheduler that manages background workers.
 * Each worker runs in an infinite loop, executing registered jobs sequentially.
 * Prevents duplicate worker processes using PID tracking and script path validation.
 *
 * @package Nabeghe\Cronark
 */
class Cronark
{
    /**
     * Registered workers and their jobs
     * Format: ['worker_name' => ['JobClass1', 'JobClass2', ...]]
     *
     * @var array<string, array<string>>
     */
    protected array $workers = [];

    /**
     * Currently executing worker name
     *
     * @var string|null
     */
    protected ?string $currentWorker = null;

    /**
     * Currently executing job class name
     *
     * @var string|null
     */
    protected ?string $currentJob = null;

    /**
     * Storage implementation for persisting worker state
     *
     * @var StorageInterface
     */
    protected StorageInterface $storage;

    /**
     * Delay in microseconds between job executions
     *
     * Default is 0 (no delay) for maximum performance.
     * Set a delay to reduce CPU usage:
     * - 10000 (10ms) for near real-time processing
     * - 50000 (50ms) for balanced performance
     * - 100000 (100ms) for light jobs on shared hosting
     *
     * @var int
     */
    protected int $delay = 100000;

    /**
     * Constructor
     *
     * @param  StorageInterface|null  $storage  Custom storage implementation
     */
    public function __construct(?StorageInterface $storage = null)
    {
        $this->storage = $storage ?? new Storage();
    }

    /**
     * Register a worker (creates empty job list if not exists)
     *
     * @param  string  $worker  Worker name
     * @return void
     */
    public function registerWorker(string $worker): void
    {
        $this->workers[$worker] ??= [];
    }

    /**
     * Get the currently executing worker name
     *
     * @return string|null Worker name or null if not executing
     */
    public function getCurrentWorker(): ?string
    {
        return $this->currentWorker;
    }

    /**
     * Add a job to a worker's queue
     *
     * @param  string  $job  Fully qualified job class name
     * @param  string  $worker  Worker name (default: 'main')
     * @param  int  $position  Position to insert (-1 for end, >= 0 for specific position)
     * @return void
     */
    public function addJob(string $job, string $worker = 'main', int $position = -1): void
    {
        $this->registerWorker($worker);

        if ($position < 0) {
            $this->workers[$worker][] = $job;
        } else {
            array_splice($this->workers[$worker], $position, 0, [$job]);
        }
    }

    /**
     * Get a job by index
     *
     * @param  int  $position  Job index
     * @param  string|null  $worker  Worker name (null = use current worker)
     * @return string|null Job class name or null if not found
     */
    public function getJob(int $position, ?string $worker = null): ?string
    {
        $worker = $worker ?? $this->getCurrentWorker() ?? 'main';
        return $this->workers[$worker][$position] ?? null;
    }

    /**
     * Check if a specific job exists at position
     *
     * @param  int  $position  Job index
     * @param  string  $worker  Worker name
     * @return bool True if job exists
     */
    public function hasJob(int $position, string $worker = 'main'): bool
    {
        return isset($this->workers[$worker][$position]);
    }

    /**
     * Check if worker has any jobs
     *
     * @param  string|null  $worker  Worker name (null = check all workers)
     * @return bool True if jobs exist
     */
    public function hasAnyJob(?string $worker = null): bool
    {
        if (is_null($worker)) {
            foreach ($this->workers as $jobs) {
                if (!empty($jobs)) {
                    return true;
                }
            }
            return false;
        }

        return isset($this->workers[$worker]) && !empty($this->workers[$worker]);
    }

    /**
     * Get total job count
     *
     * @param  string|null  $worker  Worker name (null = count all workers)
     * @return int Number of jobs
     */
    public function getJobsCount(?string $worker = null): int
    {
        if (is_null($worker)) {
            $count = 0;
            foreach ($this->workers as $jobs) {
                $count += count($jobs);
            }
            return $count;
        }

        return isset($this->workers[$worker]) ? count($this->workers[$worker]) : 0;
    }

    /**
     * Generate hash of jobs for change detection
     *
     * @param  string|null  $worker  Worker name (null = hash all workers)
     * @return string MD5 hash of serialized jobs
     */
    public function getJobsHash(?string $worker = null): string
    {
        if (is_null($worker)) {
            return md5(serialize($this->workers));
        }

        return md5(serialize($this->workers[$worker] ?? []));
    }

    /**
     * Get saved jobs hash from storage
     *
     * @param  string|null  $worker  Worker name
     * @return string|null Saved hash or null
     */
    public function getSavedJobsHash(?string $worker = null): ?string
    {
        $hash = $this->storage->get('jobs_hash', $worker);
        return is_string($hash) ? $hash : null;
    }

    /**
     * Save jobs hash to storage
     *
     * @param  string|null  $hash  Hash to save
     * @param  string|null  $worker  Worker name
     * @return bool True on success
     */
    public function saveJobsHash(?string $hash, ?string $worker = null): bool
    {
        return $this->storage->set('jobs_hash', $hash, $worker);
    }

    /**
     * Check if jobs have changed since last execution
     *
     * @param  string|null  $worker  Worker name
     * @return bool True if jobs changed
     */
    public function hasJobsHashChanged(?string $worker = null): bool
    {
        return $this->getJobsHash($worker) !== $this->getSavedJobsHash($worker);
    }

    /**
     * Get current job index from storage
     *
     * @param  string  $worker  Worker name
     * @return int|null Current index or null if invalid
     */
    public function getCurrentJobIndex(string $worker = 'main'): ?int
    {
        if (!$this->hasAnyJob($worker) || $this->hasJobsHashChanged($worker)) {
            return null;
        }

        $index = $this->storage->get('current_job_index', $worker);

        if (!is_int($index) || !$this->hasJob($index, $worker)) {
            return null;
        }

        return $index;
    }

    /**
     * Save current job index to storage
     *
     * @param  int|null  $index  Job index
     * @param  string  $worker  Worker name
     * @return bool True on success
     */
    public function setCurrentJobIndex(?int $index, string $worker = 'main'): bool
    {
        return $this->storage->set('current_job_index', $index, $worker);
    }

    /**
     * Get worker's PID from storage
     *
     * @param  string  $worker  Worker name
     * @return int|null PID or null
     */
    public function getPid(string $worker = 'main'): ?int
    {
        $pid = $this->storage->get('pid', $worker);

        if (is_int($pid) || is_numeric($pid)) {
            return (int) $pid;
        }

        return null;
    }

    /**
     * Save worker's PID to storage
     *
     * @param  int|null  $pid  Process ID
     * @param  string  $worker  Worker name
     * @return bool True on success
     */
    public function setPid(?int $pid, string $worker = 'main'): bool
    {
        return $this->storage->set('pid', $pid, $worker);
    }

    /**
     * Set delay between job executions
     *
     * Use this to reduce CPU usage on shared hosting or when running light jobs.
     * The delay is applied after each job execution in the infinite loop.
     *
     * @param  int  $microseconds  Delay in microseconds (1 second = 1,000,000 microseconds)
     *                             Recommended values:
     *                             - 0: No delay (maximum speed, default)
     *                             - 10000: 10ms (near real-time, ~100 jobs/sec)
     *                             - 50000: 50ms (balanced, ~20 jobs/sec)
     *                             - 100000: 100ms (CPU friendly, ~10 jobs/sec)
     * @return self
     */
    public function setDelay(int $microseconds): self
    {
        $this->delay = max(0, $microseconds);
        return $this;
    }

    /**
     * Get current delay between job executions
     *
     * @return int Delay in microseconds
     */
    public function getDelay(): int
    {
        return $this->delay;
    }

    /**
     * Set delay in seconds (convenience method)
     *
     * This is a convenience wrapper for setDelay() that accepts seconds instead of microseconds.
     *
     * @param  float  $seconds  Delay in seconds (e.g., 0.1 for 100ms, 0.5 for 500ms)
     * @return self
     */
    public function setDelaySeconds(float $seconds): self
    {
        return $this->setDelay((int) ($seconds * 1000000));
    }

    /**
     * Check if worker is currently active
     *
     * Validates that PID exists and matches the same script path
     *
     * @param  string  $worker  Worker name
     * @param  int|null  $pid  Output parameter for found PID
     * @return bool True if worker is active
     */
    public function isActive(string $worker = 'main', ?int &$pid = null): bool
    {
        $pid = $this->getPid($worker);

        if (!$pid || !Process::exists($pid)) {
            return false;
        }

        // Verify it's running the same script
        $currentScriptPath = Process::getScriptPath(Process::id());
        $targetScriptPath = Process::getScriptPath($pid);

        // If we can't determine paths, assume it's active (safer)
        if (is_null($currentScriptPath) || is_null($targetScriptPath)) {
            return true;
        }

        return $currentScriptPath === $targetScriptPath;
    }

    /**
     * Terminate a worker process
     *
     * @param  string  $worker  Worker name
     * @param  int|null  $pid  Output parameter for killed PID
     * @return bool True if process was killed or PID was cleared
     */
    public function kill(string $worker = 'main', ?int &$pid = null): bool
    {
        $pid = $this->getPid($worker);

        if (!$pid) {
            return false;
        }

        $killed = Process::kill($pid);

        // Clear PID regardless of kill success
        // This handles cases where process doesn't exist anymore
        if ($killed || !Process::exists($pid)) {
            $this->setPid(null, $worker);
            return true;
        }

        return false;
    }

    /**
     * Terminate all registered workers
     *
     * @return void
     */
    public function killAll(): void
    {
        foreach (array_keys($this->workers) as $worker) {
            $this->kill($worker);
        }
    }

    /**
     * Get and increment to next job index
     *
     * @param  string  $worker  Worker name
     * @return int|null Next job index or null if no jobs
     */
    public function nextJobIndex(string $worker = 'main'): ?int
    {
        if (!$this->hasAnyJob($worker) || $this->hasJobsHashChanged($worker)) {
            return null;
        }

        $currentJobIndex = $this->getCurrentJobIndex($worker);

        if (is_null($currentJobIndex)) {
            $currentJobIndex = -1;
        }

        $nextJobIndex = $currentJobIndex + 1;

        // Wrap around if we've reached the end
        if (!$this->hasJob($nextJobIndex, $worker)) {
            $nextJobIndex = 0;
        }

        $this->setCurrentJobIndex($nextJobIndex, $worker);

        return $nextJobIndex;
    }

    /**
     * Get next job class name
     *
     * @param  string  $worker  Worker name
     * @return string|null Job class name or null
     */
    public function nextJob(string $worker = 'main'): ?string
    {
        $index = $this->nextJobIndex($worker);

        if (is_null($index)) {
            return null;
        }

        return $this->getJob($index, $worker);
    }

    /**
     * Start the worker's infinite job loop
     *
     * This is the main entry point called by cron.
     * It prevents duplicate processes and runs jobs in an infinite loop.
     *
     * @param  string  $worker  Worker name
     * @return void
     */
    public function start(string $worker): void
    {
        try {
            if (!$this->canStart($worker)) {
                $this->print("Worker '$worker' cannot start (not registered or no jobs)", $worker);
                return;
            }

            $this->currentWorker = $worker;
            $this->print('Started', $worker);
            $this->onStarted($worker);

            $jobsCount = $this->getJobsCount($worker);
            $this->print("Jobs count: $jobsCount", $worker);

            // Save current jobs hash
            $newJobsHash = $this->getJobsHash($worker);
            $this->print("New jobs hash: $newJobsHash", $worker);
            $this->saveJobsHash($newJobsHash, $worker);
            $this->print("Saved jobs hash: ".$this->getSavedJobsHash($worker), $worker);

            if (!$this->hasAnyJob($worker)) {
                $this->print("No jobs found", $worker);
                return;
            }

            // Check if already running
            if ($this->isActive($worker, $pid)) {
                $this->print("Already running under PID $pid, aborting", $worker);
                return;
            }

            // Register current PID
            if (!$this->setPid(Process::id(), $worker)) {
                $this->print("Failed to save PID", $worker);
                $this->onError(new Exception("Can't save PID for worker $worker"), $worker);
                return;
            }

            // Reset job index
            if (!$this->setCurrentJobIndex(null, $worker)) {
                $this->print("Failed to reset job index", $worker);
                return;
            }

            // Remove execution time limits
            set_time_limit(0);
            ini_set('max_execution_time', '0');

            // Get first job
            $jobIndex = $this->nextJobIndex($worker);
            $jobClass = !is_null($jobIndex) ? $this->getJob($jobIndex, $worker) : null;
            if (!is_null($jobClass)) {
                $this->print("Starting with job index: $jobIndex, class: $jobClass", $worker);
            }

            $isFirst = true;

            // Infinite loop
            while ($this->canLoop($jobClass, $jobIndex, $worker, $isFirst)) {
                if ($isFirst) {
                    $this->print('Entering infinite loop', $worker);
                }

                // Execute job
                $this->handle($jobClass, $jobIndex, $worker, $isFirst);

                // Check if PID still matches (allows graceful shutdown)
                if ($this->canCheckPid($jobClass, $jobIndex, $worker, $isFirst)) {
                    $savedPid = $this->getPid($worker);
                    $currentPid = Process::id();

                    if (!$savedPid || $savedPid !== $currentPid) {
                        $this->print("PID mismatch (saved: $savedPid, current: $currentPid), stopping", $worker);
                        break;
                    }
                }

                // Move to next job
                $jobIndex = $this->nextJobIndex($worker);
                $jobClass = $this->getJob($jobIndex, $worker);
                $this->print("Next job index: $jobIndex, class: $jobClass", $worker);

                if (!$this->canResume($jobClass, $jobIndex, $worker)) {
                    $this->print("Cannot resume loop", $worker);
                    break;
                }

                $this->onResume($jobClass, $jobIndex, $worker, $isFirst);
                $isFirst = false;

                // Apply delay if set
                if ($this->delay > 0) {
                    usleep($this->delay);
                }
            }
        } catch (Throwable $e) {
            $this->onError($e, $worker);
        } finally {
            $this->currentWorker = null;
            $this->print('Stopped', $worker);
            $this->onStopped($worker);
            $this->print('------------- END -------------', $worker);
        }
    }

    /**
     * Execute a single job
     *
     * @param  string|null  $jobClass  Job class name
     * @param  int|null  $index  Job index
     * @param  string  $worker  Worker name
     * @param  bool  $isFirst  Whether this is the first execution
     * @return bool True if job executed successfully
     */
    protected function handle(?string $jobClass, ?int $index, string $worker = 'main', bool $isFirst = true): bool
    {
        try {
            if (!$this->canHandle($jobClass, $index, $worker)) {
                $this->print("Cannot handle job (validation failed)", $worker);
                return false;
            }

            $this->print('Fetching job...', $worker);

            if (!$jobClass || !class_exists($jobClass)) {
                $this->print("Job class not found: $jobClass", $worker);
                return false;
            }

            $this->currentJob = $jobClass;
            $this->print('Job initializing...', $worker);
            $this->onJobCreating();

            $job = new $jobClass($this);

            $this->print('Job handling...', $worker);
            $job();
            $this->print('Job completed', $worker);

            return true;

        } catch (Throwable $e) {
            $this->onError($e, $worker);
            return false;
        } finally {
            $this->currentJob = null;
        }
    }

    /**
     * Check if worker can start
     *
     * @param  string  $worker  Worker name
     * @return bool True if worker can start
     */
    protected function canStart(string $worker = 'main'): bool
    {
        return isset($this->workers[$worker]);
    }

    /**
     * Check if job can be handled
     *
     * @param  string|null  $jobClass  Job class name
     * @param  int|null  $jobIndex  Job index
     * @param  string  $worker  Worker name
     * @return bool True if job can be handled
     */
    protected function canHandle(?string $jobClass, ?int $jobIndex, string $worker = 'main'): bool
    {
        return !is_null($jobClass) &&
            !is_null($jobIndex) &&
            $this->hasJob($jobIndex, $worker);
    }

    /**
     * Check if loop should continue
     *
     * @param  string|null  $jobClass  Job class name
     * @param  int|null  $jobIndex  Job index
     * @param  string  $worker  Worker name
     * @param  bool  $isFirst  Whether this is the first iteration
     * @return bool True if loop should continue
     */
    protected function canLoop(?string $jobClass, ?int $jobIndex, string $worker, bool $isFirst = true): bool
    {
        return !is_null($jobClass) &&
            !is_null($jobIndex) &&
            $this->hasJob($jobIndex, $worker);
    }

    /**
     * Check if PID should be verified
     *
     * Override to disable PID checking or implement custom logic
     *
     * @param  string|null  $jobClass  Job class name
     * @param  int|null  $jobIndex  Job index
     * @param  string  $worker  Worker name
     * @param  bool  $isFirst  Whether this is the first iteration
     * @return bool True if PID should be checked
     */
    protected function canCheckPid(?string $jobClass, ?int $jobIndex, string $worker = 'main', bool $isFirst = true): bool
    {
        return true;
    }

    /**
     * Check if loop can resume to next job
     *
     * Override to implement custom stopping logic
     *
     * @param  string|null  $jobClass  Job class name
     * @param  int|null  $jobIndex  Job index
     * @param  string  $worker  Worker name
     * @return bool True if loop can continue
     */
    protected function canResume(?string $jobClass, ?int $jobIndex, string $worker): bool
    {
        return true;
    }

    /**
     * Hook: Called before job instance is created
     *
     * @return void
     */
    protected function onJobCreating(): void
    {
        // Override in subclass
    }

    /**
     * Hook: Called when worker starts
     *
     * @param  string  $worker  Worker name
     * @return void
     */
    protected function onStarted(string $worker): void
    {
        // Override in subclass
    }

    /**
     * Hook: Called before each loop iteration (after first)
     *
     * @param  string|null  $jobClass  Job class name
     * @param  int|null  $jobIndex  Job index
     * @param  string  $worker  Worker name
     * @param  bool  $isFirst  Whether this is the first iteration
     * @return void
     */
    protected function onResume(?string $jobClass, ?int $jobIndex, string $worker, bool $isFirst): void
    {
        // Override in subclass
    }

    /**
     * Hook: Called when worker stops
     *
     * @param  string  $worker  Worker name
     * @return void
     */
    protected function onStopped(string $worker): void
    {
        // Override in subclass
    }

    /**
     * Hook: Called when an error occurs
     *
     * @param  Throwable  $e  The exception
     * @param  string  $worker  Worker name
     * @return void
     */
    protected function onError(Throwable $e, string $worker): void
    {
        $this->print(
            "⛔ Error: {$e->getMessage()}\n   File: {$e->getFile()}\n   Line: {$e->getLine()}",
            $worker
        );
    }

    /**
     * Print message to console (CLI only)
     *
     * @param  string  $message  Message to print
     * @param  string|null  $worker  Worker name
     * @return void
     */
    public function print(string $message, ?string $worker = null): void
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }

        $output = "> Cronark";

        $worker = $worker ?? $this->currentWorker;
        if (!is_null($worker)) {
            $output .= "::$worker";
        }

        if (!is_null($this->currentJob)) {
            $output .= ".".basename(str_replace('\\', '/', $this->currentJob));
        }

        $output .= ":\n  $message".PHP_EOL;
        echo $output;
    }
}
