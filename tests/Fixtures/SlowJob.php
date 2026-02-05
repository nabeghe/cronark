<?php namespace Nabeghe\Cronark\Tests\Fixtures;

use Nabeghe\Cronark\Cronark;
use Nabeghe\Cronark\Job;

/**
 * Slow Job for Testing Performance
 *
 * This job takes time to execute (0.1 seconds)
 */
class SlowJob implements Job
{
    protected Cronark $cronark;

    /**
     * Counter for number of executions
     */
    public static int $executionCount = 0;

    /**
     * Total duration spent executing
     */
    public static float $totalDuration = 0.0;

    public function __construct(Cronark $cronark)
    {
        $this->cronark = $cronark;
    }

    public function handle(): void
    {
        $startTime = microtime(true);

        // Sleep for 0.1 seconds
        usleep(100000);

        $duration = microtime(true) - $startTime;

        self::$executionCount++;
        self::$totalDuration += $duration;
    }

    /**
     * Reset counters
     */
    public static function reset(): void
    {
        self::$executionCount = 0;
        self::$totalDuration = 0.0;
    }
}
