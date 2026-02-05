<?php namespace Nabeghe\Cronark\Tests\Fixtures;

use Nabeghe\Cronark\Cronark;
use Nabeghe\Cronark\Job;
use RuntimeException;

/**
 * Failing Job for Testing Error Handling
 *
 * This job always throws an exception to test error handling
 */
class FailingJob implements Job
{
    protected Cronark $cronark;

    /**
     * Counter for number of failures
     */
    public static int $failureCount = 0;

    public function __construct(Cronark $cronark)
    {
        $this->cronark = $cronark;
    }

    public function handle(): void
    {
        self::$failureCount++;
        throw new RuntimeException('Job failed intentionally');
    }

    /**
     * Reset failure counter
     */
    public static function reset(): void
    {
        self::$failureCount = 0;
    }
}
