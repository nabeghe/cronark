<?php namespace Nabeghe\Cronark\Tests\Fixtures;

use Nabeghe\Cronark\Cronark;

/**
 * Test Job for Unit Testing
 *
 * This job tracks execution count and logs for testing purposes
 */
class TestJob
{
    /**
     * Counter for total number of executions
     */
    public static int $executionCount = 0;

    /**
     * Detailed execution log with timestamps
     */
    public static array $executionLog = [];

    public function __construct(protected Cronark $cronark)
    {
    }

    public function __invoke()
    {
        self::$executionCount++;
        self::$executionLog[] = [
            'time' => microtime(true),
            'worker' => $this->cronark->getCurrentWorker(),
            'job' => static::class
        ];
    }

    /**
     * Reset all static counters and logs
     *
     * This should be called in setUp() of tests
     */
    public static function reset(): void
    {
        self::$executionCount = 0;
        self::$executionLog = [];
    }
}
