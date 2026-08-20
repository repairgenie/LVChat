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
 * Admin controller for Voice & Speech (STT / TTS sidecars).
 */
final class VoiceAdminController
{
    private static function requireAdmin(): array
    {
        Auth::requireAdmin();
        $u = Auth::user();
        if (!$u) {
            json_out(['error' => 'Not authenticated.'], 401);
        }
        return $u;
    }

    private static function requireCsrf(): void
    {
        if (Csrf::bearerAuthorized()) {
            return;
        }
        $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
        if (!is_string($sent) || !hash_equals(Csrf::token(), $sent)) {
            json_out(['error' => 'CSRF token mismatch.'], 419);
        }
    }

    /** GET /admin/voice — render the Voice & Speech admin page. */
    public static function admin(): void
    {
        $admin = self::requireAdmin();
        $keys = [
            'voice_stt_enabled', 'voice_tts_enabled', 'voice_force_local',
            'voice_stt_sidecar_url', 'voice_tts_sidecar_url',
        ];
        $settings = [];
        foreach ($keys as $k) {
            $settings[$k] = (string) config_get($k, '');
        }
        render_view('admin/voice', ['admin' => $admin, 'settings' => $settings]);
    }

    /** GET /admin/voice/status — JSON status of both sidecars. */
    public static function status(): void
    {
        self::requireAdmin();
        json_out([
            'ok'   => true,
            'stt'  => self::sidecarStatus('stt'),
            'tts'  => self::sidecarStatus('tts'),
        ]);
    }

    /** POST /admin/voice/save — save voice feature settings. */
    public static function save(): void
    {
        self::requireAdmin();
        self::requireCsrf();
        config_set('voice_stt_enabled', ($_POST['voice_stt_enabled'] ?? '0') === '1' ? '1' : '0');
        config_set('voice_tts_enabled', ($_POST['voice_tts_enabled'] ?? '0') === '1' ? '1' : '0');
        config_set('voice_force_local', ($_POST['voice_force_local'] ?? '0') === '1' ? '1' : '0');
        config_set('voice_stt_sidecar_url', rtrim(trim((string) ($_POST['voice_stt_sidecar_url'] ?? 'http://127.0.0.1:8787')), '/'));
        config_set('voice_tts_sidecar_url', rtrim(trim((string) ($_POST['voice_tts_sidecar_url'] ?? 'http://127.0.0.1:8788')), '/'));
        log_audit('voice_settings_save');
        flash('Voice settings saved.');
        redirect('/admin/voice');
    }

    /** POST /admin/voice/control — start or stop a sidecar. */
    public static function control(): void
    {
        self::requireAdmin();
        self::requireCsrf();
        $which = (string) ($_POST['which'] ?? '');
        $action = (string) ($_POST['action'] ?? '');
        if (!in_array($which, ['stt', 'tts', 'all'], true)) {
            json_out(['error' => 'Invalid sidecar.'], 400);
        }
        if (!in_array($action, ['start', 'stop'], true)) {
            json_out(['error' => 'Invalid action.'], 400);
        }

        $targets = $which === 'all' ? ['stt', 'tts'] : [$which];
        $results = [];
        foreach ($targets as $w) {
            $results[$w] = $action === 'start'
                ? self::startSidecar($w)
                : self::stopSidecar($w);
        }
        json_out(['ok' => true, 'results' => $results]);
    }

    /** POST /admin/voice/start-stream — streaming start output. */
    public static function startStream(): void
    {
        self::requireAdmin();
        if (!Csrf::bearerAuthorized()) {
            $sent = $_POST['csrf'] ?? ($_GET['csrf'] ?? '');
            if (!is_string($sent) || !hash_equals(Csrf::token(), $sent)) {
                http_response_code(419);
                exit('CSRF token mismatch');
            }
        }
        $which = (string) ($_POST['which'] ?? ($_GET['which'] ?? ''));
        if (!in_array($which, ['stt', 'tts'], true)) {
            json_out(['error' => 'Invalid sidecar.'], 400);
        }

        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $name = $which === 'stt' ? 'STT (speech-to-text)' : 'TTS (text-to-speech)';
        echo "Starting {$name} sidecar...\n";
        if (!CommandRunner::available()) {
            echo "\n[Shell execution is disabled — cannot start sidecars.]\n";
            flush();
            exit;
        }
        echo "(via " . CommandRunner::backend() . ")\n\n";
        flush();

        $dir = ROOT . '/sidecar/' . $which;
        $startScript = $dir . '/start.sh';

        if (!file_exists($startScript)) {
            echo "[error] {$startScript} not found. Run the sidecar setup first.\n";
            flush();
            exit;
        }

        // Run start.sh which creates venv if needed, then launches uvicorn.
        // Use nohup so the sidecar survives after the streaming response ends.
        $port = $which === 'stt'
            ? (int) (config_get('voice_stt_sidecar_url', 'http://127.0.0.1:8787') !== '' ? preg_replace('#.*:(\d+)$#', '$1', config_get('voice_stt_sidecar_url', 'http://127.0.0.1:8787')) : '8787')
            : (int) (config_get('voice_tts_sidecar_url', 'http://127.0.0.1:8788') !== '' ? preg_replace('#.*:(\d+)$#', '$1', config_get('voice_tts_sidecar_url', 'http://127.0.0.1:8788')) : '8788');

        $env = $which === 'stt'
            ? "STT_PORT={$port} STT_MODEL=" . escapeshellarg(config_get('voice_stt_model', 'small'))
            : "TTS_PORT={$port} TTS_VOICE=" . escapeshellarg(config_get('voice_tts_voice', 'en_US-lessac-medium'));

        $cmd = "cd " . escapeshellarg($dir) . " && {$env} bash " . escapeshellarg($startScript) . " 2>&1";
        $code = CommandRunner::stream($cmd, 120);
        log_audit("voice_{$which}_start", '', 'streamed, exit=' . $code);
        exit;
    }

    /** Check if a sidecar process is running by probing its /health endpoint. */
    private static function sidecarStatus(string $which): array
    {
        $urlKey = $which === 'stt' ? 'voice_stt_sidecar_url' : 'voice_tts_sidecar_url';
        $defaultUrl = $which === 'stt' ? 'http://127.0.0.1:8787' : 'http://127.0.0.1:8788';
        $baseUrl = rtrim((string) config_get($urlKey, $defaultUrl), '/');
        $healthUrl = $baseUrl . '/health';

        $ch = curl_init($healthUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $error || $httpCode !== 200) {
            return ['running' => false, 'url' => $baseUrl, 'error' => $error ?: null];
        }

        $data = json_decode($response, true);
        return [
            'running' => true,
            'url'     => $baseUrl,
            'model'   => $data['model'] ?? $data['voice'] ?? null,
            'device'  => $data['device'] ?? null,
        ];
    }

    /** Start a sidecar process in the background. */
    private static function startSidecar(string $which): array
    {
        $status = self::sidecarStatus($which);
        if ($status['running']) {
            return ['ok' => true, 'already_running' => true];
        }

        $dir = ROOT . '/sidecar/' . $which;
        $startScript = $dir . '/start.sh';
        if (!file_exists($startScript)) {
            return ['ok' => false, 'error' => "start.sh not found in sidecar/{$which}/"];
        }

        $urlKey = $which === 'stt' ? 'voice_stt_sidecar_url' : 'voice_tts_sidecar_url';
        $defaultUrl = $which === 'stt' ? 'http://127.0.0.1:8787' : 'http://127.0.0.1:8788';
        $baseUrl = rtrim((string) config_get($urlKey, $defaultUrl), '/');
        $port = (int) preg_replace('#.*:(\d+)$#', '$1', $baseUrl);
        if ($port <= 0 || $port > 65535) {
            $port = $which === 'stt' ? 8787 : 8788;
        }

        // Start in background with nohup, redirect output to a log file
        $logFile = ROOT . "/data/voice-{$which}.log";
        $env = $which === 'stt'
            ? "STT_PORT={$port}"
            : "TTS_PORT={$port}";
        $cmd = "cd " . escapeshellarg($dir) . " && {$env} nohup bash start.sh > " . escapeshellarg($logFile) . " 2>&1 &";
        exec($cmd);

        // Give it a moment, then check if it came up
        sleep(2);
        $newStatus = self::sidecarStatus($which);
        return ['ok' => $newStatus['running'], 'status' => $newStatus];
    }

    /** Stop a sidecar process by finding and killing it. */
    private static function stopSidecar(string $which): array
    {
        $status = self::sidecarStatus($which);
        if (!$status['running']) {
            return ['ok' => true, 'already_stopped' => true];
        }

        $baseUrl = $status['url'] ?? '';
        $port = (int) preg_replace('#.*:(\d+)$#', '$1', $baseUrl);
        if ($port <= 0) {
            return ['ok' => false, 'error' => 'Could not determine port'];
        }

        // Find PIDs listening on this port and kill them
        $pids = self::pidsOnPort($port);
        $killed = 0;
        foreach ($pids as $pid) {
            if ($pid > 1) {
                posix_kill((int) $pid, SIGTERM);
                $killed++;
            }
        }
        // Give processes time to exit, then force-kill survivors
        usleep(500000);
        foreach ($pids as $pid) {
            if ($pid > 1 && file_exists("/proc/{$pid}")) {
                posix_kill((int) $pid, SIGKILL);
            }
        }

        return ['ok' => true, 'killed' => $killed];
    }

    /** Find PIDs bound to a given TCP port (Linux /proc/net/tcp). */
    private static function pidsOnPort(int $port): array
    {
        $hexPort = sprintf('%04X', $port);
        $pids = [];
        foreach (['tcp', 'tcp6'] as $proto) {
            $path = "/proc/net/{$proto}";
            if (!is_readable($path)) {
                continue;
            }
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!$lines) {
                continue;
            }
            foreach ($lines as $line) {
                if (stripos($line, ":{$hexPort}") === false) {
                    continue;
                }
                $parts = preg_split('/\s+/', $line);
                // Local_address field has the port in the last 4 hex chars after ':'
                if (!preg_match('#:[0-9A-F]{4}$#i', $parts[1] ?? '', $m)) {
                    continue;
                }
                if (strtolower($m[0]) !== ":{$hexPort}") {
                    continue;
                }
                $inode = $parts[9] ?? '';
                if ($inode === '' || $inode === '0') {
                    continue;
                }
                // Match inode to PID via /proc/*/fd/* → socket:[inode]
                foreach (glob('/proc/[0-9]*/fd/*') as $fd) {
                    $link = @readlink($fd);
                    if ($link === "socket:[{$inode}]") {
                        preg_match('#/proc/(\d+)/#', $fd, $pm);
                        if (!empty($pm[1])) {
                            $pids[] = (int) $pm[1];
                        }
                    }
                }
            }
        }
        return array_unique($pids);
    }
}
