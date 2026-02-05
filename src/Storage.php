<?php namespace Nabeghe\Cronark;

/**
 * File-based Storage Implementation
 *
 * Stores worker state in serialized files.
 * Each worker has its own storage file.
 */
class Storage implements StorageInterface
{
    /**
     * Base storage directory path
     */
    protected string $storagePath;

    /**
     * Constructor
     *
     * @param string|null $storagePath Custom storage path (defaults to system temp directory)
     */
    public function __construct(?string $storagePath = null)
    {
        $this->storagePath = $storagePath ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cronark';

        // Ensure storage directory exists
        if (!is_dir($this->storagePath)) {
            @mkdir($this->storagePath, 0755, true);
        }
    }

    /**
     * Generate the file path for a worker's storage
     *
     * @param string|null $worker Worker name
     * @return string Full file path
     */
    protected function getFilePath(?string $worker = null): string
    {
        $worker = $worker ?? 'global';
        return $this->storagePath . DIRECTORY_SEPARATOR . "worker_{$worker}.dat";
    }

    public function get(string $key, ?string $worker = null): mixed
    {
        $file = $this->getFilePath($worker);

        if (!file_exists($file)) {
            return null;
        }

        $data = @file_get_contents($file);
        if ($data === false) {
            return null;
        }

        $array = @unserialize($data);
        if (!is_array($array)) {
            return null;
        }

        return $array[$key] ?? null;
    }

    public function set(string $key, mixed $value, ?string $worker = null): bool
    {
        $file = $this->getFilePath($worker);
        $array = [];

        // Load existing data
        if (file_exists($file)) {
            $existing = @file_get_contents($file);
            $array = @unserialize($existing);

            if (!is_array($array)) {
                $array = [];
            }
        }

        // Update value
        if ($value === null) {
            unset($array[$key]);
        } else {
            $array[$key] = $value;
        }

        // Save atomically with file locking
        $data = serialize($array);
        return @file_put_contents($file, $data, LOCK_EX) !== false;
    }

    /**
     * Clear all storage for a worker
     *
     * @param string|null $worker Worker name
     * @return bool True on success
     */
    public function clear(?string $worker = null): bool
    {
        $file = $this->getFilePath($worker);

        if (file_exists($file)) {
            return @unlink($file);
        }

        return true;
    }
}
