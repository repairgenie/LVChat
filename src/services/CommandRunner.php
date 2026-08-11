<?php

/**
 * LVChat — Discord-style web chat (PHP + SQLite)
 *
 * Copyright (C) LVChat contributors
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, version 3 of the License.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * SPDX-License-Identifier: AGPL-3.0-only
 */



declare(strict_types=1);

/**
 * Runs server-side commands from the web with graceful degradation.
 *
 * Control panels often disable `proc_open` (and sometimes `popen`/`exec`) in
 * PHP for security. This runner picks the best available backend — full
 * streaming via proc_open, line streaming via popen, blocking via exec — and
 * returns a clear error instead of a fatal when no shell function exists.
 */
final class CommandRunner
{
    /** True when at least one shell-execution function is available. */
    public static function available(): bool
    {
        return function_exists('proc_open')
            || function_exists('popen')
            || function_exists('exec');
    }

    /** Which backend is in use (for diagnostics / error messages). */
    public static function backend(): string
    {
        if (function_exists('proc_open')) {
            return 'proc_open';
        }
        if (function_exists('popen')) {
            return 'popen';
        }
        if (function_exists('exec')) {
            return 'exec';
        }
        return 'none';
    }

    private static function unavailable(): string
    {
        return 'Shell execution is disabled on this server (proc_open/popen/exec are all '
            . 'off in php.ini). Enable one of them, or run the command over SSH.';
    }

    /**
     * Run a command and stream its output to the browser until it exits or
     * $timeout seconds elapse. Returns the exit code (1 on startup failure).
     * Caller must set streaming headers and clear output buffering first.
     */
    public static function stream(string $cmd, int $timeout): int
    {
        if (function_exists('proc_open')) {
            return self::streamProcOpen($cmd, $timeout);
        }
        if (function_exists('popen')) {
            return self::streamPopen($cmd, $timeout);
        }
        if (function_exists('exec')) {
            return self::streamExec($cmd);
        }
        echo "\n[" . self::unavailable() . "]\n";
        flush();
        return 1;
    }

    /** Run a command to completion and return [exitCode, output]. Never throws. */
    public static function run(string $cmd, int $timeout): array
    {
        if (function_exists('proc_open')) {
            return self::runProcOpen($cmd, $timeout);
        }
        if (function_exists('popen')) {
            return self::runPopen($cmd);
        }
        if (function_exists('exec')) {
            return self::runExec($cmd);
        }
        return [1, self::unavailable()];
    }

    // ── proc_open (full streaming, stderr separated) ────────────────────────

    private static function streamProcOpen(string $cmd, int $timeout): int
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes, ROOT);
        if (!is_resource($proc)) {
            echo "\n[command failed to start]\n";
            flush();
            return 1;
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $start = time();
        while (true) {
            $status = proc_get_status($proc);
            foreach ([1, 2] as $p) {
                $chunk = stream_get_contents($pipes[$p]);
                if ($chunk !== false && $chunk !== '') {
                    echo $chunk;
                    flush();
                }
            }
            if (!$status['running']) {
                break;
            }
            if (time() - $start > $timeout) {
                proc_terminate($proc);
                echo "\n[command timed out after {$timeout}s]\n";
                flush();
                break;
            }
            if (connection_aborted()) {
                proc_terminate($proc);
                break;
            }
            usleep(100000);
        }
        foreach ([1, 2] as $p) {
            $tail = stream_get_contents($pipes[$p]);
            if ($tail !== false && $tail !== '') {
                echo $tail;
            }
            fclose($pipes[$p]);
        }
        $code = proc_close($proc);
        echo "\n\n[exit code: $code]\n";
        flush();
        return $code;
    }

    private static function runProcOpen(string $cmd, int $timeout): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes, ROOT);
        if (!is_resource($proc)) {
            return [1, 'Could not start the command.'];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $start = time();
        while (true) {
            $status = proc_get_status($proc);
            foreach ([1, 2] as $p) {
                $chunk = stream_get_contents($pipes[$p]);
                if ($chunk !== false && $chunk !== '') {
                    $out .= $chunk;
                }
            }
            if (!$status['running']) {
                break;
            }
            if (time() - $start > $timeout) {
                proc_terminate($proc);
                $out .= "\n[command timed out after {$timeout}s]";
                break;
            }
            usleep(100000);
        }
        foreach ([1, 2] as $p) {
            $tail = stream_get_contents($pipes[$p]);
            if ($tail !== false && $tail !== '') {
                $out .= $tail;
            }
            fclose($pipes[$p]);
        }
        $code = proc_close($proc);
        return [$code, trim($out)];
    }

    // ── popen (line streaming, stderr merged via 2>&1) ──────────────────────

    private static function streamPopen(string $cmd, int $timeout): int
    {
        $fp = @popen($cmd . ' 2>&1', 'r');
        if (!is_resource($fp)) {
            echo "\n[command failed to start]\n";
            flush();
            return 1;
        }
        stream_set_blocking($fp, false);
        $start = time();
        while (true) {
            $chunk = stream_get_contents($fp);
            if ($chunk !== false && $chunk !== '') {
                echo $chunk;
                flush();
            }
            if (feof($fp)) {
                break;
            }
            if (time() - $start > $timeout) {
                echo "\n[command timed out after {$timeout}s]\n";
                flush();
                pclose($fp);
                return 124;
            }
            if (connection_aborted()) {
                pclose($fp);
                return 1;
            }
            usleep(100000);
        }
        $code = pclose($fp);
        echo "\n\n[exit code: $code]\n";
        flush();
        return $code;
    }

    private static function runPopen(string $cmd): array
    {
        $fp = @popen($cmd . ' 2>&1', 'r');
        if (!is_resource($fp)) {
            return [1, 'Could not start the command.'];
        }
        $out = stream_get_contents($fp);
        $code = pclose($fp);
        return [$code, trim((string) $out)];
    }

    // ── exec (blocking, no incremental streaming) ───────────────────────────

    private static function streamExec(string $cmd): int
    {
        echo "(incremental streaming unavailable via exec — running to completion...)\n";
        flush();
        $output = [];
        $code = 0;
        exec($cmd . ' 2>&1', $output, $code);
        echo implode("\n", $output) . "\n\n[exit code: $code]\n";
        flush();
        return $code;
    }

    private static function runExec(string $cmd): array
    {
        $output = [];
        $code = 0;
        exec($cmd . ' 2>&1', $output, $code);
        return [$code, trim(implode("\n", $output))];
    }
}
