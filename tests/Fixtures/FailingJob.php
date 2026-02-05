<?php namespace Nabeghe\Cronark\Tests\Fixtures;

use Nabeghe\Cronark\Cronark;
use RuntimeException;

/**
 * Failing Job for Testing Error Handling
 *
 * This job always throws an exception to test error handling
 */
class FailingJob
{
    /**
     * Counter for number of failures
     */
    public static int $failureCount = 0;

    public function __construct(protected Cronark $cronark)
    {
    }

    public function __invoke()
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
