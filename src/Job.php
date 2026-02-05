<?php namespace Nabeghe\Cronark;

/**
 * Job Interface
 *
 * All background jobs must implement this interface.
 * Jobs are executed by workers in an infinite loop managed by Cronark.
 */
interface Job
{
    /**
     * Constructor receives the Cronark instance
     *
     * @param Cronark $cronark The Cronark scheduler instance
     */
    public function __construct(Cronark $cronark);

    /**
     * Handle the job execution
     *
     * This method contains the actual job logic that will be executed
     * repeatedly by the worker in the background.
     *
     * @return void
     */
    public function handle(): void;
}
