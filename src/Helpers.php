<?php

declare(strict_types=1);

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function url(string $path): string
{
    return $path;
}

function base_url(): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('/index.php', '', $_SERVER['SCRIPT_NAME'] ?? ''), '/');
    return $scheme . '://' . $host . $dir;
}

function canonical_channel_url(string $slug): string
{
    return url('/c/' . rawurlencode($slug));
}

function flash(?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['flash'] = $message;
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function json_out(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function client_ip(): ?string
{
    return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : null;
}

/** Parse an IRC-style duration like 30m, 2h, 1d, 1w into seconds ('' = permanent). */
function parse_duration(?string $s): ?int
{
    if ($s === null || trim($s) === '' || in_array(strtolower(trim($s)), ['0', 'permanent', 'perm', 'forever', '-'])) {
        return null;
    }
    if (preg_match_all('/(\d+)\s*(s|sec|m|min|h|hour|d|day|w|week|mo|month|y|year)?\b/i', $s, $m)) {
        $total = 0;
        $mult = [
            's' => 1, 'sec' => 1,
            'm' => 60, 'min' => 60,
            'h' => 3600, 'hour' => 3600,
            'd' => 86400, 'day' => 86400,
            'w' => 604800, 'week' => 604800,
            'mo' => 2592000, 'month' => 2592000,
            'y' => 31536000, 'year' => 31536000,
        ];
        foreach ($m[1] as $i => $num) {
            $unit = strtolower($m[2][$i] ?? 'm');
            $total += (int) $num * ($mult[$unit] ?? 60);
        }
        return $total > 0 ? $total : null;
    }
    if (is_numeric($s)) {
        return (int) $s * 60; // bare number = minutes, IRC style
    }
    return null;
}

function relative_time(?string $datetime): string
{
    if (!$datetime) {
        return '';
    }
    $ts = strtotime($datetime . ' UTC');
    $diff = time() - $ts;
    if ($diff < 5) {
        return 'now';
    }
    if ($diff < 60) {
        return $diff . 's ago';
    }
    if ($diff < 3600) {
        return floor($diff / 60) . 'm ago';
    }
    if ($diff < 86400) {
        return floor($diff / 3600) . 'h ago';
    }
    return date('M j', $ts);
}

function level_weight(string $level): int
{
    return [
        'normal' => 0,
        'voice' => 1,
        'halfop' => 2,
        'op' => 3,
        'admin' => 4,
        'founder' => 5,
    ][$level] ?? 0;
}

function level_symbol(string $level): string
{
    return [
        'normal' => '',
        'voice' => '+',
        'halfop' => '%',
        'op' => '@',
        'admin' => '&',
        'founder' => '~',
    ][$level] ?? '';
}

function level_color(string $level): string
{
    return [
        'normal' => 'text-discord-300',
        'voice' => 'text-green-400',
        'halfop' => 'text-cyan-400',
        'op' => 'text-orange-400',
        'admin' => 'text-red-400',
        'founder' => 'text-amber-400',
    ][$level] ?? 'text-discord-300';
}

function log_audit(string $action, ?string $target = null, ?string $detail = null): void
{
    Database::query(
        'INSERT INTO audit_log (actor_id, action, target, detail) VALUES (?, ?, ?, ?)',
        [Auth::id(), $action, $target, $detail]
    );
}

function config_get(string $key, ?string $default = null): ?string
{
    $v = Database::scalar('SELECT value FROM server_config WHERE key = ?', [$key]);
    return $v === false ? $default : $v;
}

function config_set(string $key, string $value): void
{
    Database::query('INSERT OR REPLACE INTO server_config (key, value) VALUES (?, ?)', [$key, $value]);
}

function render_view(string $name, array $vars = [], ?string $layout = 'layout'): never
{
    extract($vars, EXTR_SKIP);
    $view = ROOT . '/views/' . $name . '.php';
    if ($layout) {
        ob_start();
        require $view;
        $content = ob_get_clean();
        require ROOT . '/views/' . $layout . '.php';
    } else {
        require $view;
    }
    exit;
}

/** Very light client-side safe rendering of IRC-style formatting for chat lines. */
function chat_markup(string $text): string
{
    $text = h($text);
    $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
    $text = preg_replace('/`(.+?)`/', '<code class="bg-discord-750 px-1 rounded">$1</code>', $text);
    $text = preg_replace('/@([A-Za-z0-9_\-\[\]\\`^{}|]+)/', '<span class="mention text-blurple font-semibold">@$1</span>', $text);
    $text = preg_replace_callback(
        '#(https?://[^\s<]+)#i',
        fn ($m) => '<a class="text-sky-400 hover:underline" target="_blank" rel="noopener" href="' . h($m[1]) . '">' . h($m[1]) . '</a>',
        $text
    );
    return $text;
}

function chat_markup_plain(string $text): string
{
    return nl2br(chat_markup($text));
}
