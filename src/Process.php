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
                return @posix_kill($pid, 0);
            }

            $openBasedir = ini_get('open_basedir');
            $procAllowed = empty($openBasedir) || str_contains($openBasedir, '/proc');

            if ($procAllowed && is_dir("/proc/$pid")) {
                return true;
            }

            if (function_exists('exec')) {
                $output = [];
                $return = 0;
                @exec("kill -0 ".$pid." 2>/dev/null", $output, $return);
                return $return === 0;
            }

            return false;
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

        if (!self::exists($pid)) {
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
            $success = false;

            if (function_exists('posix_kill')) {
                $success = posix_kill($pid, 9);
            } elseif (function_exists('exec')) {
                $output = [];
                $returnCode = 0;

                exec("kill -9 ".$pid." 2>&1", $output, $returnCode);

                $success = ($returnCode === 0);
            } else {
                return false;
            }
        }

        // Wait a moment for OS to clean up
        usleep(100000); // 50ms

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

            // --- 1. Try /proc (only if allowed) ---
            $cmdlinePath = "/proc/$pid/cmdline";

            $openBasedir = ini_get('open_basedir');
            $procAllowed = empty($openBasedir) || str_contains($openBasedir, '/proc');

            if ($procAllowed && is_readable($cmdlinePath)) {
                $args = array_values(array_filter(explode("\0", @file_get_contents($cmdlinePath))));

                foreach ($args as $arg) {
                    if (!$arg || $arg[0] === '-') {
                        continue;
                    }

                    if (substr($arg, -4) !== '.php') {
                        continue;
                    }

                    if (is_file($arg)) {
                        return realpath($arg);
                    }

                    $cwdPath = "/proc/$pid/cwd";
                    if (is_link($cwdPath)) {
                        $cwd = @readlink($cwdPath);
                        if ($cwd && is_file($cwd.'/'.$arg)) {
                            return realpath($cwd.'/'.$arg);
                        }
                    }
                }
            }

            // --- 2. Fallback (works on shared hosting / cPanel) ---
            if (!empty($_SERVER['SCRIPT_FILENAME'])) {
                return realpath($_SERVER['SCRIPT_FILENAME']);
            }

            if (!empty($_SERVER['PHP_SELF'])) {
                return realpath($_SERVER['DOCUMENT_ROOT'].$_SERVER['PHP_SELF']);
            }

            // --- 3. Last resort (CLI safe) ---
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $trace) {
                if (!empty($trace['file'])) {
                    return realpath($trace['file']);
                }
            }

        }

        return null;
    }
}
