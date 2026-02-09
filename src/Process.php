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
     * Checks if a process exists by its PID (Cross-platform)
     *
     * Uses posix_kill with signal 0 on Unix systems (doesn't actually send
     * a signal, just checks if process exists) and tasklist on Windows.
     *
     * @param  int  $pid  The process ID to check
     * @return bool Returns true if the process exists and is running, false otherwise
     */
    public static function exists(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        $isWindows = stripos(php_uname('s'), 'win') > -1;

        if ($isWindows) {
            exec("tasklist /FI \"PID eq $pid\" /NH 2>NUL", $output);

            foreach ($output as $line) {
                if (str_contains($line, (string) $pid)) {
                    return true;
                }
            }

            return false;
        } else {
            if (function_exists('posix_kill')) {
                return posix_kill($pid, 0);
            } else {
                // Fallback: kill -0 checks existence without killing
                exec("kill -0 $pid 2>/dev/null", $output, $returnCode);
                return ($returnCode === 0);
            }
        }
    }

    /**
     * Kills a process by its PID (Cross-platform: Windows, Linux, macOS)
     *
     * Uses SIGKILL (signal 9) on Unix systems and taskkill /F on Windows
     * to forcefully terminate the process. This signal cannot be ignored.
     *
     * @param  int  $pid  The process ID to kill
     * @return bool Returns true if the process was successfully killed, false otherwise
     */
    public static function kill(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        $isWindows = stripos(php_uname('s'), 'win') > -1;

        if ($isWindows) {
            // /F = Force kill (SIGKILL equivalent)
            // /T = Kill process tree (all children)
            exec("taskkill /F /T /PID $pid 2>NUL", $output, $returnCode);

            // Return code 0 or 128 = success (128 = already dead)
            $success = in_array($returnCode, [0, 128]);
        } else {
            if (function_exists('posix_kill')) {
                $success = posix_kill($pid, 9); // 9 = SIGKILL
            } else {
                // Fallback to shell command
                exec("kill -9 $pid 2>/dev/null", $output, $returnCode);
                $success = ($returnCode === 0);
            }
        }

        // Wait a moment for OS to clean up
        usleep(50000); // 50ms

        return $success && !self::exists($pid);
    }

    /**
     * Get the script path being executed by a process
     *
     * @param  int  $pid  Process ID
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
                            $resolved_path = realpath($cwd.'/'.$script_path);
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
