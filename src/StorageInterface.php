<?php namespace Nabeghe\Cronark;

/**
 * Storage Interface
 *
 * Defines the contract for persistent storage of worker state,
 * including PIDs, job indices, and job hashes.
 */
interface StorageInterface
{
    /**
     * Retrieve a value from storage
     *
     * @param string $key The key to retrieve
     * @param string|null $worker Worker name (null for global)
     * @return mixed The stored value or null if not found
     */
    public function get(string $key, ?string $worker = null): mixed;

    /**
     * Store a value
     *
     * @param string $key The key to store
     * @param mixed $value The value to store
     * @param string|null $worker Worker name (null for global)
     * @return bool True on success, false on failure
     */
    public function set(string $key, mixed $value, ?string $worker = null): bool;
}
