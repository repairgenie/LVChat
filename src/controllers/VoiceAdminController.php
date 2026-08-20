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

    /** POST /admin/voice/setup-stream — streaming setup (create venv + install deps). */
    public static function setupStream(): void
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
        if (!in_array($which, ['stt', 'tts', 'all'], true)) {
            json_out(['error' => 'Invalid sidecar.'], 400);
        }

        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!CommandRunner::available()) {
            echo "[Shell execution is disabled — cannot run setup.]\n";
            flush();
            exit;
        }
        echo "(via " . CommandRunner::backend() . ")\n\n";
        flush();

        // Detect OS and package manager once
        $osInfo = self::detectOs();
        echo "OS: {$osInfo['name']} | Package manager: {$osInfo['pm']}\n\n";

        $targets = $which === 'all' ? ['stt', 'tts'] : [$which];
        foreach ($targets as $w) {
            $dir = ROOT . '/sidecar/' . $w;
            $name = $w === 'stt' ? 'STT (speech-to-text)' : 'TTS (text-to-speech)';
            echo "━━━ Setting up {$name} sidecar ━━━\n\n";

            if (!is_dir($dir)) {
                echo "[error] Directory {$dir} not found. Did you upload the sidecar files?\n\n";
                continue;
            }

            $reqFile = $dir . '/requirements.txt';
            $venvDir = $dir . '/venv';
            $python = $venvDir . '/bin/python';

            if (!file_exists($python)) {
                echo "→ Creating virtualenv...\n";
                flush();

                // Try creating the venv
                $cmd = "python3 -m venv " . escapeshellarg($venvDir) . " 2>&1";
                [$code, $output] = CommandRunner::run($cmd, 60);
                echo $output;

                if ($code !== 0) {
                    // Detect the specific failure
                    $needsVenvPkg = (strpos($output, 'ensurepip is not available') !== false)
                        || (strpos($output, 'No module named venv') !== false)
                        || (strpos($output, 'python3-venv') !== false);

                    if ($needsVenvPkg) {
                        echo "\n⚠ python3-venv package is required but not installed.\n\n";

                        $pyVer = self::pythonVersion();
                        $installCmd = self::venvInstallCommand($pyVer);

                        if ($installCmd !== null) {
                            echo "→ Attempting to install automatically...\n";
                            echo "  Running: sudo {$installCmd}\n\n";
                            flush();

                            $sudoCmd = "echo '{$_SERVER['USER']}' | sudo -S {$installCmd} 2>&1";
                            [$iCode, $iOutput] = CommandRunner::run($sudoCmd, 120);
                            echo $iOutput;

                            if ($iCode !== 0) {
                                echo "\n⚠ Automatic install failed (exit {$iCode}).\n";
                                echo "  You may need to install it manually:\n\n";
                                echo "  sudo {$installCmd}\n\n";
                                echo "  Or install the full venv package for your Python version:\n";
                                echo "  sudo apt install python{$pyVer}-venv\n\n";
                                continue;
                            }

                            echo "✓ Package installed. Retrying virtualenv creation...\n\n";
                            flush();

                            // Retry venv creation
                            [$code2, $output2] = CommandRunner::run("python3 -m venv " . escapeshellarg($venvDir) . " 2>&1", 60);
                            echo $output2;
                            if ($code2 !== 0) {
                                echo "\n[error] Virtualenv creation still failed after installing python3-venv.\n";
                                echo "  Try creating it manually: python3 -m venv {$venvDir}\n\n";
                                continue;
                            }
                            echo "✓ Virtualenv created.\n\n";
                        } else {
                            echo "  Could not determine the install command for your system.\n";
                            echo "  Please install the venv package manually:\n\n";
                            if ($osInfo['family'] === 'debian' || $osInfo['family'] === 'ubuntu') {
                                echo "  sudo apt install python{$pyVer}-venv\n";
                            } elseif ($osInfo['family'] === 'fedora') {
                                echo "  sudo dnf install python3-virtualenv\n";
                            } elseif ($osInfo['family'] === 'arch') {
                                echo "  sudo pacman -S python-virtualenv\n";
                            } elseif ($osInfo['family'] === 'alpine') {
                                echo "  apk add python3\n";
                            } else {
                                echo "  Consult your distribution's package manager for python3-venv.\n";
                            }
                            echo "\n  Then re-run Setup.\n\n";
                            continue;
                        }
                    } else {
                        echo "\n[error] Failed to create virtualenv (exit {$code}).\n";
                        echo "  Is python3 installed? Check: python3 --version\n\n";
                        continue;
                    }
                } else {
                    echo "✓ Virtualenv created.\n\n";
                }
            } else {
                echo "✓ Virtualenv already exists.\n\n";
            }

            if (file_exists($reqFile)) {
                echo "→ Installing dependencies from requirements.txt...\n";
                flush();
                $pip = $venvDir . '/bin/pip';
                [$pCode, $pOutput] = CommandRunner::run(
                    escapeshellarg($pip) . " install --upgrade pip -q 2>&1 && "
                    . escapeshellarg($pip) . " install -r " . escapeshellarg($reqFile) . " 2>&1",
                    300
                );
                echo $pOutput;
                if ($pCode !== 0) {
                    echo "\n[warning] pip install exited with code {$pCode} — some packages may have failed.\n\n";
                } else {
                    echo "\n✓ Dependencies installed.\n\n";
                }
            } else {
                echo "✓ No requirements.txt found — skipping dependency install.\n\n";
            }
        }

        echo "━━━ Setup complete ━━━\n";
        echo "You can now start the sidecar(s) with the Start button.\n";
        log_audit('voice_setup', '', 'streamed for: ' . implode(',', $targets));
        exit;
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
            // Check if the venv exists — if not, the sidecar needs setup
            $venvDir = ROOT . '/sidecar/' . $which . '/venv';
            $needsSetup = !is_dir($venvDir);
            return ['running' => false, 'url' => $baseUrl, 'error' => $error ?: null, 'needs_setup' => $needsSetup];
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

    /** Detect the OS family and package manager. */
    private static function detectOs(): array
    {
        $result = ['name' => PHP_OS_FAMILY, 'family' => strtolower(PHP_OS_FAMILY), 'pm' => 'unknown'];

        if (PHP_OS_FAMILY === 'Linux') {
            $release = @file_get_contents('/etc/os-release');
            if ($release !== false) {
                $parsed = [];
                foreach (explode("\n", $release) as $line) {
                    if (str_contains($line, '=')) {
                        [$k, $v] = explode('=', $line, 2);
                        $parsed[trim($k)] = trim($v, '"');
                    }
                }
                $result['name'] = $parsed['PRETTY_NAME'] ?? $parsed['NAME'] ?? 'Linux';

                $id = strtolower($parsed['ID'] ?? '');
                $idLike = strtolower($parsed['ID_LIKE'] ?? '');

                if (in_array($id, ['ubuntu', 'debian', 'linuxmint', 'pop', 'zorin', 'elementary', 'kali', 'raspbian'], true)
                    || str_contains($idLike, 'debian')
                    || str_contains($idLike, 'ubuntu')) {
                    $result['family'] = 'debian';
                    $result['pm'] = 'apt';
                } elseif (in_array($id, ['fedora', 'rhel', 'centos', 'rocky', 'alma', 'ol', 'amazon', 'nobara'], true)
                    || str_contains($idLike, 'rhel')
                    || str_contains($idLike, 'fedora')) {
                    $result['family'] = 'fedora';
                    $result['pm'] = 'dnf';
                    // CentOS 7 / RHEL 7 still uses yum
                    if (file_exists('/usr/bin/yum') && !file_exists('/usr/bin/dnf')) {
                        $result['pm'] = 'yum';
                    }
                } elseif ($id === 'arch' || $id === 'manjaro' || str_contains($idLike, 'arch')) {
                    $result['family'] = 'arch';
                    $result['pm'] = 'pacman';
                } elseif ($id === 'alpine') {
                    $result['family'] = 'alpine';
                    $result['pm'] = 'apk';
                } elseif ($id === 'opensuse-leap' || $id === 'opensuse-tumbleweed' || $id === 'sles') {
                    $result['family'] = 'suse';
                    $result['pm'] = 'zypper';
                }
            }
        } elseif (PHP_OS_FAMILY === 'Darwin') {
            $result['family'] = 'macos';
            $result['pm'] = file_exists('/opt/homebrew/bin/brew') || file_exists('/usr/local/bin/brew') ? 'brew' : 'unknown';
        }

        return $result;
    }

    /** Get the Python version string (e.g. "3.12"). */
    private static function pythonVersion(): string
    {
        [$code, $output] = CommandRunner::run('python3 -c "import sys; print(f\'{sys.version_info.major}.{sys.version_info.minor}\')" 2>&1', 5);
        $ver = trim($output);
        return preg_match('/^\d+\.\d+$/', $ver) ? $ver : '';
    }

    /**
     * Determine the install command for python3-venv based on the OS.
     * Returns null if we can't determine the command.
     */
    private static function venvInstallCommand(string $pyVer): ?string
    {
        $os = self::detectOs();
        $family = $os['family'];
        $pm = $os['pm'];

        if ($family === 'debian' || $family === 'ubuntu') {
            // Try the version-specific package first, then the unversioned one
            return "apt install -y python{$pyVer}-venv || apt install -y python3-venv";
        }
        if ($family === 'fedora') {
            return "dnf install -y python3-virtualenv";
        }
        if ($family === 'arch') {
            return "pacman -S --noconfirm python-virtualenv";
        }
        if ($family === 'alpine') {
            return "apk add python3";
        }
        if ($family === 'suse') {
            return "zypper install -y python3-virtualenv";
        }
        if ($family === 'macos') {
            // macOS usually has venv built-in, but if not, python3 comes from Xcode or Homebrew
            return null;
        }

        return null;
    }
}
