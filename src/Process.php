<?php namespace Nabeghe\Cronark;

/**
 * Process Utility Class
 *
 * Provides cross-platform utilities for managing system processes,
 * including PID validation, process termination, and script path detection.
 */
class Process
{
    /**
     * Get the current process ID
     *
     * @return int Current PID
     */
    public static function id(): int
    {
        return getmypid();
    }

    /**
     * Check if a process exists
     *
     * @param int $pid Process ID to check
     * @return bool True if process exists
     */
    public static function exists(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        $os = strtoupper(substr(PHP_OS, 0, 3));

        if ($os === 'WIN') {
            $output = shell_exec("tasklist /FI \"PID eq $pid\" /NH 2>nul");
            return $output && str_contains($output, (string) $pid);
        }

        // Unix-like systems (Linux, macOS)
        return @posix_kill($pid, 0);
    }

    /**
     * Terminate a process
     *
     * @param int $pid Process ID to kill
     * @param int $signal Signal to send (default: SIGTERM on Unix, forceful on Windows)
     * @return bool True if process was terminated
     */
    public static function kill(int $pid, int $signal = 15): bool
    {
        if ($pid <= 0 || !self::exists($pid)) {
            return false;
        }

        $os = strtoupper(substr(PHP_OS, 0, 3));

        if ($os === 'WIN') {
            $output = shell_exec("taskkill /PID $pid /F 2>&1");
            return $output && (str_contains($output, 'SUCCESS') || !self::exists($pid));
        }

        // Unix-like systems
        return @posix_kill($pid, $signal) && !self::exists($pid);
    }

    /**
     * Get the script path being executed by a process
     *
     * @param int $pid Process ID
     * @return string|null Script path or null if not found
     */
    public static function getScriptPath(int $pid): ?string
    {
        if ($pid <= 0 || !self::exists($pid)) {
            return null;
        }

        $os = strtoupper(substr(PHP_OS, 0, 3));

        if ($os === 'WIN') {
            $command = "wmic process where ProcessId=$pid get CommandLine 2>&1";
            $output = shell_exec($command);

            if (!empty($output)) {
                $full_command_line = trim($output);

                // Parse Windows command line
                if (preg_match('/^"(.+?)"\s*"(.+?)"/', $full_command_line, $script_matches)) {
                    return $script_matches[2];
                }

                $parts = explode(' ', $full_command_line, 3);
                if (isset($parts[1])) {
                    return trim($parts[1], '"');
                }
            }

            return null;
        }

        if ($os === 'LIN' || $os === 'DAR') { // Linux or macOS
            $cmdline_path = "/proc/$pid/cmdline";

            if (file_exists($cmdline_path)) {
                $cmdline_content = @file_get_contents($cmdline_path);

                if ($cmdline_content !== false) {
                    $args = explode("\0", $cmdline_content);
                    $args = array_filter($args);

                    if (isset($args[1])) {
                        $script_path = $args[1];

                        // Try to resolve absolute path
                        if (realpath($script_path) !== false) {
                            return realpath($script_path);
                        }

                        // Try to resolve relative to process working directory
                        $cwd_path = "/proc/$pid/cwd";
                        if (is_link($cwd_path) && $cwd = @readlink($cwd_path)) {
                            $resolved_path = realpath($cwd . '/' . $script_path);
                            return $resolved_path ?: $script_path;
                        }

                        return $script_path;
                    }
                }
            }
        }

        return null;
    }
}
