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

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Output a nonce attribute for inline <script> tags: nonce="..." */
function nonce_attr(): string
{
    return 'nonce="' . h(CSP_NONCE) . '"';
}

/**
 * Generate a random password that meets the complexity policy:
 * at least 8 chars with uppercase, lowercase, and a digit.
 * Used for admin-created accounts and password resets.
 */
function generate_password(int $length = 16): string
{
    $upper = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $lower = 'abcdefghijklmnopqrstuvwxyz';
    $digits = '0123456789';
    $all = $upper . $lower . $digits;
    // Ensure at least one from each required class.
    $pw = $upper[random_int(0, strlen($upper) - 1)]
        . $lower[random_int(0, strlen($lower) - 1)]
        . $digits[random_int(0, strlen($digits) - 1)];
    for ($i = 3; $i < $length; $i++) {
        $pw .= $all[random_int(0, strlen($all) - 1)];
    }
    // Shuffle to avoid predictable positions.
    $arr = str_split($pw);
    for ($i = count($arr) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);
        [$arr[$i], $arr[$j]] = [$arr[$j], $arr[$i]];
    }
    return implode('', $arr);
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

/** Only allow same-origin, absolute-relative redirect targets. Blocks open
 *  redirects (CWE-601): external URLs, protocol-relative "//host" and
 *  backslash variants are all rejected and fall back to the chat home. */
function safe_next(string $path): string
{
    $path = trim($path);
    if ($path === '' || $path[0] !== '/' || str_starts_with($path, '//') || str_contains($path, '\\') || preg_match('/[\x00-\x1F\x7F]/', $path)) {
        return '/app';
    }
    return $path;
}

function url(string $path): string
{
    return $path;
}

function base_url(): string
    {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $scheme = $https ? 'https' : 'http';
        $host = trusted_host();
        $dir = rtrim(str_replace('/index.php', '', $_SERVER['SCRIPT_NAME'] ?? ''), '/');
        return $scheme . '://' . $host . $dir;
    }

/** Resolve the canonical host for this request WITHOUT trusting the
 *  client-supplied Host header blindly:
 *   1. APP_URL (env, e.g. https://chat.example.com) wins when set.
 *   2. TRUSTED_HOSTS (env, comma-separated hostnames) — only those exact
 *      hosts are accepted from the Host header.
 *   3. Otherwise fall back to SERVER_NAME (server-configured) and reject the
 *      Host header entirely. This prevents host-header poisoning of emailed
 *      password-reset / magic-link / invite URLs.
 *  The returned value never contains scheme, path, or control characters. */
function trusted_host(): string
{
    $appUrl = getenv('APP_URL');
    if ($appUrl !== false && $appUrl !== '') {
        $parsed = parse_url((string) $appUrl);
        if (isset($parsed['host']) && preg_match('/^[a-zA-Z0-9._\-\[\]:]+$/', $parsed['host'])) {
            return $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
        }
    }
    $given = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $trusted = getenv('TRUSTED_HOSTS');
    if ($trusted !== false && $trusted !== '') {
        // Normalize allowlist entries: lowercase, host-only (strip any port).
        $allow = [];
        foreach (explode(',', (string) $trusted) as $entry) {
            $clean = strtolower((string) parse_url('http://' . trim($entry), PHP_URL_HOST));
            if ($clean !== '' && $clean !== false) {
                $allow[] = $clean;
            }
        }
        // Normalize the claimed Host the same way, then REBUILD the returned
        // value from parsed components — never echo the raw header (userinfo /
        // path / fragment smuggling: `Host: evil:x@good.com`, `good.com/evil`).
        $parsed = parse_url($given !== '' ? 'http://' . $given : '');
        $hostOnly = $parsed !== false ? strtolower((string) ($parsed['host'] ?? '')) : '';
        if ($hostOnly !== '' && in_array($hostOnly, $allow, true)) {
            $host = (string) $parsed['host'];
            return $host . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
        }
        // Host header not allowlisted — refuse to build links from it.
        return (string) ($_SERVER['SERVER_NAME'] ?? 'localhost');
    }
    // No TRUSTED_HOSTS configured: never use the client Host header.
    $server = (string) ($_SERVER['SERVER_NAME'] ?? 'localhost');
    if (preg_match('/^[a-zA-Z0-9._\-\[\]:]+$/', $server)) {
        return $server;
    }
    return 'localhost';
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

/** Best-effort real client IP, respecting reverse proxies while filtering spoofed values.
 *  Priority: Cloudflare header -> X-Real-IP (nginx) -> first entry of X-Forwarded-For -> REMOTE_ADDR.
 *  Proxy headers are only trusted when TRUSTED_PROXY is enabled (set in .env
 *  when behind a TLS-terminating reverse proxy). Without it, REMOTE_ADDR is
 *  always used, preventing IP spoofing via forged headers. */
function client_ip(): ?string
{
    $trustedProxy = getenv('TRUSTED_PROXY');
    $hasTrustedProxy = $trustedProxy !== false && $trustedProxy !== '' && $trustedProxy !== '0';
    if ($hasTrustedProxy) {
        $candidates = [];
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $candidates[] = $_SERVER['HTTP_CF_CONNECTING_IP'];
        }
        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $candidates[] = $_SERVER['HTTP_X_REAL_IP'];
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            // Left-most entry is the original client; the rest are added by each proxy.
            $candidates[] = trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        }
        foreach ($candidates as $ip) {
            $ip = trim((string) $ip);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
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
    oper_log($action, $target, $detail);
    Database::query(
        'INSERT INTO audit_log (actor_id, action, target, detail) VALUES (?, ?, ?, ?)',
        [Auth::id(), $action, $target, $detail]
    );
}

/**
 * Mirror an audited event into #oper-log when the actor is a server admin or
 * an o:line holder. Individual channel operators (who hold @ in a channel but
 * are neither admins nor o:line users) are deliberately excluded.
 */
function oper_log(string $action, ?string $target = null, ?string $detail = null): void
{
    $actor = Auth::user();
    if (!$actor || Auth::isGuest($actor)) {
        return;
    }
    $isAdmin = ($actor['role'] ?? '') === 'admin';
    $hasOline = !$isAdmin && (bool) Database::scalar(
        'SELECT 1 FROM opers WHERE username = ? COLLATE NOCASE',
        [$actor['username']]
    );
    if (!$isAdmin && !$hasOline) {
        return;
    }
    $ch = Database::row('SELECT * FROM channels WHERE slug = ? COLLATE NOCASE', ['oper-log']);
    if (!$ch) {
        return;
    }
    // Lazily auto-join qualifying admins/opers so the log channel stays current.
    Database::query(
        'INSERT OR IGNORE INTO channel_members (channel_id, user_id, level) VALUES (?, ?, "normal")',
        [$ch['id'], $actor['id']]
    );
    $line = $actor['username'];
    $operClass = Auth::operSessionClass();
    if ($operClass) {
        $line .= ' (' . $operClass . ')';
    }
    $line .= ': ' . $action;
    if ($target !== null && $target !== '') {
        $line .= ' ' . $target;
    }
    if ($detail !== null && $detail !== '') {
        $line .= ' — ' . $detail;
    }
    $id = MessageService::system((int) $ch['id'], 'notice', $line);
    Realtime::message($ch['slug'], [
        'id' => $id,
        'kind' => 'notice',
        'content' => $line,
        'channel' => $ch['slug'],
        'sender_id' => null,
        'username' => null,
        'guest' => 0,
    ]);
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

/** Max failed login attempts per IP inside the login window (e.g. 10 in 10min). */
function login_attempt_max(): int
{
    return 10;
}

/** Prune the login-attempt log and return how many failures this IP has now. */
function login_attempt_count(): int
{
    Database::query('DELETE FROM login_attempts WHERE attempted_at < datetime("now", "-10 minutes")');
    $ip = client_ip();
    return (int) Database::scalar('SELECT COUNT(*) FROM login_attempts WHERE ip = ?', [$ip ?: '']);
}

/** Record a failed login attempt for this IP. */
function login_attempt_record(): void
{
    Database::query('INSERT INTO login_attempts (ip) VALUES (?)', [client_ip() ?: '']);
}

/** Clear failed-attempt history for this IP after a successful login. */
function login_attempt_clear(): void
{
    Database::query('DELETE FROM login_attempts WHERE ip = ?', [client_ip() ?: '']);
}

/**
 * Global per-IP rate limiter. Returns true if the request is allowed, false if
 * rate-limited. Uses the existing rate_limits table with a "global:" bucket prefix.
 * $max is the max requests per $windowSeconds.
 */
function global_rate_limit(int $max = 60, int $windowSeconds = 60): bool
{
    $ip = client_ip() ?: 'unknown';
    $bucket = 'global:' . $ip;
    $now = time();
    $windowStart = $now - $windowSeconds;

    // Clean old entries periodically (not every request).
    if (mt_rand(1, 20) === 1) {
        Database::query('DELETE FROM rate_limits WHERE window_start < ?', [$windowStart]);
    }

    $row = Database::row('SELECT hits, window_start FROM rate_limits WHERE bucket = ?', [$bucket]);
    if ($row === null) {
        Database::query(
            'INSERT INTO rate_limits (bucket, hits, window_start) VALUES (?, 1, ?)',
            [$bucket, $now]
        );
        return true;
    }
    if ((int) $row['window_start'] < $windowStart) {
        // Window expired — reset.
        Database::query(
            'UPDATE rate_limits SET hits = 1, window_start = ? WHERE bucket = ?',
            [$now, $bucket]
        );
        return true;
    }
    if ((int) $row['hits'] >= $max) {
        return false;
    }
    Database::query('UPDATE rate_limits SET hits = hits + 1 WHERE bucket = ?', [$bucket]);
    return true;
}

/** Max new accounts per IP inside the registration window (0 = unlimited). */
function registration_rate_limit(): int
{
    return (int) (config_get('registration_rate_limit', '20') ?? 20);
}

/** Prune the registration log and return how many this IP has in the window. */
function registration_attempt_count(): int
{
    Database::query('DELETE FROM registration_attempts WHERE attempted_at < datetime("now", "-10 minutes")');
    $ip = client_ip();
    return (int) Database::scalar('SELECT COUNT(*) FROM registration_attempts WHERE ip = ?', [$ip ?: '']);
}

/** Record a new account created from this IP. */
function registration_attempt_record(): void
{
    Database::query('INSERT INTO registration_attempts (ip) VALUES (?)', [client_ip() ?: '']);
}

/** URL of the configured site logo, or null when none is set. */
function site_logo(): ?string
{
    $url = trim((string) (config_get('logo_url', '') ?? ''));
    return $url !== '' ? $url : null;
}

/** Seconds after which a user is considered offline (matches presence_throttle). */
function presence_window(): int
{
    return max(5, (int) (config_get('presence_throttle', '30') ?? 30));
}

/** Number of people currently connected (registered users + guests, last presence_window). */
function online_count(): int
{
    $w = presence_window();
    $users = (int) Database::scalar("SELECT COUNT(*) FROM users WHERE away IS NULL AND last_seen >= datetime('now', '-' || ? || ' seconds')", [$w]);
    $guests = (int) Database::scalar("SELECT COUNT(*) FROM guests WHERE last_seen >= datetime('now', '-' || ? || ' seconds')", [$w]);
    return $users + $guests;
}

/** Opportunistically record the all-time concurrent peak (runs off the presence write). */
function record_peak(): void
{
    $n = online_count();
    $peak = (int) (config_get('peak_online', '0') ?? 0);
    if ($n > $peak) {
        config_set('peak_online', (string) $n);
    }
}

/** Render the site logo image, or the default blurple "#" mark when none is set. */
function logo_mark(string $size = 'w-12 h-12 rounded-2xl text-2xl'): string
{
    $url = site_logo();
    if ($url !== null) {
        return '<img src="' . h($url) . '" alt="" class="' . h($size) . ' object-contain">';
    }
    return '<div class="' . h($size) . ' bg-blurple flex items-center justify-center font-bold text-white">#</div>';
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

/**
 * Map every channel name to its slug, cached once per request, so #channel
 * references in messages can be rendered as clickable links.
 * @return array<string,string>
 */
function channel_slug_map(): array
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (Database::all('SELECT name, slug FROM channels') as $r) {
            $map[(string) $r['name']] = (string) $r['slug'];
        }
    }
    return $map;
}

/**
 * Turn existing channel references ("#name" / "&name") into links. Input must
 * already be HTML-escaped; & channels arrive as "&amp;name" so both forms are
 * handled. Only channels that actually exist are linked.
 */
function chat_linkify_channels(string $escaped): string
{
    $map = channel_slug_map();
    if ($map === []) {
        return $escaped;
    }
    return preg_replace_callback(
        '/(^|[\s(>])#([A-Za-z0-9_\-\[\]{}^`|\\\\]+)|(^|[\s(>])(&amp;)([A-Za-z0-9_\-\[\]{}^`|\\\\]+)/',
        function ($m) use ($map) {
            if (!empty($m[4])) {
                $name = '&' . $m[5];
                if (!isset($map[$name])) {
                    return $m[0];
                }
                return $m[3] . '<a class="text-sky-400 hover:underline" href="/c/' . rawurlencode($map[$name]) . '">&amp;' . $m[5] . '</a>';
            }
            $name = '#' . $m[2];
            if (!isset($map[$name])) {
                return $m[0];
            }
            return $m[1] . '<a class="text-sky-400 hover:underline" href="/c/' . rawurlencode($map[$name]) . '">#' . $m[2] . '</a>';
        },
        $escaped
    );
}

/** Very light client-side safe rendering of IRC-style formatting for chat lines. */
function chat_markup(string $text): string
{
    $text = h($text);
    $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
    $text = preg_replace('/`(.+?)`/', '<code class="bg-discord-750 px-1 rounded">$1</code>', $text);
    $text = preg_replace('/@([A-Za-z0-9_\-\[\]\\`^{}|]+)/', '<span class="mention text-blurple font-semibold">@$1</span>', $text);
    $text = chat_linkify_channels($text);
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

/** Inline formatting pass for rich messages: bold, italic, strike, code, mentions, links. */
function chat_markup_inline(string $text): string
{
    $text = h($text);
    // Order matters: bold/italic/code first so their markers aren't linkified.
    $text = preg_replace('/`([^`\n]+)`/', '<code class="bg-discord-750 px-1 rounded">$1</code>', $text);
    $text = preg_replace('/\*\*([^*\n]+)\*\*/', '<strong>$1</strong>', $text);
    $text = preg_replace('/\*([^*\n]+)\*/', '<em>$1</em>', $text);
    $text = preg_replace('/~~([^~\n]+)~~/', '<s>$1</s>', $text);
    $text = preg_replace('/@([A-Za-z0-9_\-\[\]\\`^{}|]+)/', '<span class="mention text-blurple font-semibold">@$1</span>', $text);
    $text = chat_linkify_channels($text);
    $text = preg_replace_callback(
        '#(https?://[^\s<]+)#i',
        fn ($m) => '<a class="text-sky-400 hover:underline" target="_blank" rel="noopener" href="' . h($m[1]) . '">' . h($m[1]) . '</a>',
        $text
    );
    return $text;
}

/**
 * Full rich-text renderer for message bodies. Supports multi-line content:
 * fenced code blocks, blockquotes, unordered/ordered lists, and inline
 * formatting. All input is HTML-escaped; only the tokenizer tags we emit are
 * trusted. Fenced blocks are extracted first so their inner newlines survive.
 */
function chat_markup_rich(string $text): string
{
    $text = (string) $text;
    $blocks = [];
    $text = preg_replace_callback('/```([a-z0-9]*)\s*\n(.*?)```/s', function ($m) use (&$blocks) {
        $i = count($blocks);
        $lang = $m[1] !== '' ? '<span class="text-[10px] text-discord-400 font-mono">' . h($m[1]) . '</span>' : '';
        $code = rtrim($m[2], "\n");
        $blocks[$i] = '<div class="code-block bg-discord-900/70 border border-discord-700 rounded-lg p-3 my-1.5 text-[13px] leading-relaxed overflow-x-auto">' . $lang
            . '<pre class="whitespace-pre-wrap font-mono text-discord-200"><code>' . h($code) . '</code></pre></div>';
        return "\x02BLK$i\x03";
    }, $text);

    $html = '';
    $listBuf = [];
    $listType = null;
    $quoteBuf = [];
    $flushList = function () use (&$html, &$listBuf, &$listType): void {
        if (!$listBuf) {
            return;
        }
        $tag = $listType === 'ol' ? 'ol' : 'ul';
        $listClass = $listType === 'ol' ? 'list-decimal' : 'list-disc';
        $html .= '<' . $tag . ' class="' . $listClass . ' list-outside pl-5 my-1.5 space-y-0.5">';
        foreach ($listBuf as $item) {
            $html .= '<li class="leading-relaxed">' . chat_markup_inline($item) . '</li>';
        }
        $html .= '</' . $tag . '>';
        $listBuf = [];
        $listType = null;
    };
    $flushQuote = function () use (&$html, &$quoteBuf): void {
        if (!$quoteBuf) {
            return;
        }
        $html .= '<blockquote class="border-l-2 border-blurple/50 pl-2 my-1.5 text-discord-300">'
            . implode('<br>', array_map('chat_markup_inline', $quoteBuf)) . '</blockquote>';
        $quoteBuf = [];
    };

    foreach (explode("\n", $text) as $line) {
        // Blockquote: lines beginning with ">" (raw, pre-escaping).
        if (preg_match('/^&gt; ?(.*)$/', $line, $m) || preg_match('/^> ?(.*)$/', $line, $m)) {
            $flushList();
            $quoteBuf[] = $m[1];
            continue;
        }
        if (preg_match('/^[-*] (.*)$/', $line, $m)) {
            $flushQuote();
            if ($listType !== 'ul') {
                $flushList();
                $listType = 'ul';
            }
            $listBuf[] = $m[1];
            continue;
        }
        if (preg_match('/^(\d+)\. (.*)$/', $line, $m)) {
            $flushQuote();
            if ($listType !== 'ol') {
                $flushList();
                $listType = 'ol';
            }
            $listBuf[] = $m[2];
            continue;
        }
        $flushList();
        $flushQuote();
        if (trim($line) === '') {
            continue;
        }
        // A standalone code-block placeholder is emitted as-is (no wrapping div).
        if (preg_match('/^\x02BLK\d+\x03$/', $line)) {
            $html .= $line;
            continue;
        }
        $html .= '<div class="leading-relaxed">' . chat_markup_inline($line) . '</div>';
    }
    $flushList();
    $flushQuote();

    foreach ($blocks as $i => $b) {
        $html = str_replace("\x02BLK$i\x03", $b, $html);
    }

    return $html;
}

/**
 * Return an inline SVG icon for a file based on its MIME type.
 * All icons are 24×24 with currentColor stroke so they inherit text color.
 */
function file_icon_svg(string $mime): string
{
    // PDF
    if ($mime === 'application/pdf') {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0 text-red-400"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><line x1="10" y1="9" x2="8" y2="9"/></svg>';
    }
    // Archives (zip, rar, 7z, tar, gz)
    if (in_array($mime, ['application/zip', 'application/x-rar-compressed', 'application/x-rar', 'application/x-7z-compressed', 'application/x-tar', 'application/gzip', 'application/x-bzip2'], true)) {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0 text-amber-400"><path d="M21 8v13H3V3h13z"/><path d="M14 2v6h6"/><rect x="8" y="12" width="2" height="2" fill="currentColor"/><rect x="12" y="12" width="2" height="2" fill="currentColor"/><rect x="8" y="16" width="2" height="2" fill="currentColor"/><rect x="10" y="14" width="2" height="2" fill="currentColor"/></svg>';
    }
    // Video
    if (str_starts_with($mime, 'video/')) {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0 text-purple-400"><path d="m22 8-6 4 6 4V8Z"/><rect x="2" y="6" width="14" height="12" rx="2" ry="2"/></svg>';
    }
    // Audio
    if (str_starts_with($mime, 'audio/')) {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0 text-green-400"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>';
    }
    // Text / code
    if (str_starts_with($mime, 'text/')) {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0 text-sky-400"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><line x1="10" y1="9" x2="8" y2="9"/></svg>';
    }
    // Images
    if (str_starts_with($mime, 'image/')) {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0 text-pink-400"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>';
    }
    // Documents (doc, docx, odt, etc.)
    if (str_contains($mime, 'word') || str_contains($mime, 'document') || in_array($mime, ['application/vnd.oasis.opendocument.text'], true)) {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0 text-blue-400"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/></svg>';
    }
    // Spreadsheets
    if (str_contains($mime, 'sheet') || str_contains($mime, 'excel') || in_array($mime, ['application/vnd.oasis.opendocument.spreadsheet'], true)) {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0 text-emerald-400"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/><line x1="12" y1="9" x2="12" y2="21"/></svg>';
    }
    // Default file icon
    return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="shrink-0 text-discord-400"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/></svg>';
}

/**
 * Format a byte count into a human-readable size string.
 */
function human_file_size(int $bytes): string
{
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024) . ' KB';
    }
    return $bytes . ' B';
}

/**
 * Render a file attachment card for kind === 'file' messages.
 *
 * Content format: line 1 = URL, line 2 = original filename, line 3 = optional caption.
 */
function file_card_html(array $m): string
{
    $lines = explode("\n", (string) $m['content'], 3);
    $url = trim($lines[0] ?? '');
    $filename = trim($lines[1] ?? 'file');
    $caption = trim($lines[2] ?? '');
    if ($url === '') {
        return '';
    }
    // Guess MIME from extension for icon.
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $extMimeMap = [
        'pdf' => 'application/pdf',
        'zip' => 'application/zip', 'rar' => 'application/x-rar', '7z' => 'application/x-7z-compressed',
        'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg',
        'mp4' => 'video/mp4', 'webm' => 'video/webm',
        'txt' => 'text/plain', 'md' => 'text/plain',
        'js' => 'text/javascript', 'ts' => 'text/typescript',
        'py' => 'text/x-python', 'rb' => 'text/x-ruby',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
        'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];
    $mime = $extMimeMap[$ext] ?? 'application/octet-stream';
    $icon = file_icon_svg($mime);
    $size = (int) ($m['file_size'] ?? 0);
    $sizeStr = $size > 0 ? human_file_size($size) : '';
    $html = '<div class="file-card flex items-center gap-3 p-3 rounded-lg border border-discord-700 bg-discord-850 my-1 max-w-md">'
        . $icon
        . '<div class="min-w-0 flex-1">'
        . '<div class="text-sm text-discord-200 truncate">' . h($filename) . '</div>'
        . '<div class="text-[11px] text-discord-500">' . h(strtoupper($ext)) . ($sizeStr !== '' ? ' · ' . $sizeStr : '') . '</div>'
        . '</div>'
        . '<a href="' . h(url($url)) . '" download="' . h($filename) . '" class="shrink-0 text-xs text-blurple hover:text-blurple-300 font-semibold px-2 py-1 rounded hover:bg-blurple/10 transition-colors">Download</a>'
        . '</div>';
    if ($caption !== '') {
        $html .= '<div class="mt-1">' . chat_markup_rich($caption) . '</div>';
    }
    return $html;
}

/**
 * Render a message's content. For kind 'image', the first line is the upload
 * path rendered as a clickable thumbnail (opened in the lightbox), and any
 * following lines are the caption as normal text.
 */
function chat_content_html(array $m): string
{
    if (($m['kind'] ?? '') === 'gif') {
        $lines = explode("\n", (string) $m['content'], 2);
        $url = trim($lines[0] ?? '');
        $caption = trim($lines[1] ?? '');
        $html = '';
        if ($url !== '') {
            $html .= '<a href="' . h($url) . '" class="inline-block mt-1" target="_blank" rel="noopener">'
                . '<img src="' . h($url) . '" alt="' . h($caption !== '' ? $caption : 'GIF') . '" loading="lazy" class="max-h-72 max-w-full rounded-lg border border-discord-700 object-contain hover:opacity-90 transition-opacity">'
                . '</a>';
        }
        if ($caption !== '') {
            $html .= '<div class="mt-1">' . chat_markup_rich($caption) . '</div>';
        }
        return $html;
    }
    if (($m['kind'] ?? '') === 'image') {
        $lines = explode("\n", (string) $m['content'], 2);
        $path = trim($lines[0] ?? '');
        $caption = trim($lines[1] ?? '');
        $html = '';
        if ($path !== '') {
            $html .= '<a href="' . h(url($path)) . '" class="inline-block mt-1" data-lightbox="' . h(url($path)) . '">'
                . '<img src="' . h(url($path)) . '" alt="' . h($caption) . '" loading="lazy" class="max-h-72 max-w-full rounded-lg border border-discord-700 object-contain hover:opacity-90 transition-opacity">'
                . '</a>';
        }
        if ($caption !== '') {
            $html .= '<div class="mt-1">' . chat_markup_rich($caption) . '</div>';
        }
        return $html;
    }
    if (($m['kind'] ?? '') === 'file') {
        return file_card_html($m);
    }
    if (($m['kind'] ?? '') === 'ai_response' || (int) ($m['bot'] ?? 0) === 1) {
        return chat_markup_ai((string) $m['content']);
    }
    $html = chat_markup_rich((string) $m['content']);
    // Append link preview card if available.
    if (!empty($m['preview']) && is_array($m['preview']) && !empty($m['preview']['title'])) {
        $previewUrl = LinkPreviewService::extractFirstUrl((string) $m['content']);
        if ($previewUrl !== null) {
            $html .= link_preview_html($m['preview'], $previewUrl);
        }
    }
    return $html;
}

/**
 * Render a Facebook/Slack-style link preview card.
 * @param array{title:?string, description:?string, image_url:?string, site_name:?string, nsfw:bool} $preview
 * @param string $url The original URL from the message.
 */
function link_preview_html(array $preview, string $url): string
{
    $title = h((string) ($preview['title'] ?? ''));
    $desc = h((string) ($preview['description'] ?? ''));
    $siteName = h((string) ($preview['site_name'] ?? ''));
    $imageUrl = h((string) ($preview['image_url'] ?? ''));
    $nsfw = !empty($preview['nsfw']);
    $nsfwClass = $nsfw ? ' link-preview-nsfw' : '';

    $html = '<div class="link-preview-wrap relative">';
    $html .= '<a href="' . h($url) . '" target="_blank" rel="noopener" '
        . 'class="link-preview-card block mt-2 border border-discord-600/60 rounded-lg overflow-hidden hover:border-discord-400/80 transition-colors max-w-full' . $nsfwClass . '">';

    if ($imageUrl !== '') {
        $html .= '<div class="link-preview-image aspect-video w-full bg-discord-800 overflow-hidden">';
        $html .= '<img src="' . $imageUrl . '" alt="" loading="lazy" class="w-full h-full object-cover">';
        $html .= '</div>';
    }

    $html .= '<div class="link-preview-text px-4 py-3">';
    if ($siteName !== '') {
        $html .= '<div class="text-[11px] text-discord-400 uppercase tracking-wide leading-tight">' . $siteName . '</div>';
    }
    if ($title !== '') {
        $html .= '<div class="text-[13px] font-semibold text-discord-100 mt-1 leading-snug line-clamp-2">' . $title . '</div>';
    }
    if ($desc !== '') {
        $html .= '<div class="text-[12px] text-discord-400 mt-1 leading-snug line-clamp-2">' . $desc . '</div>';
    }
    $html .= '</div>';
    $html .= '</a>';

    if ($nsfw) {
        $html .= '<div class="link-preview-nsfw-overlay flex items-center justify-center absolute inset-0 bg-black/60 rounded-lg">';
        $html .= '<span class="text-sm font-medium text-white/80">NSFW — Click to view</span>';
        $html .= '</div>';
    }

    $html .= '</div>';
    return $html;
}

function chat_markup_ai(string $text): string
{
    $text = (string) $text;
    $thinkingBlocks = [];
    $text = preg_replace_callback('/:::thinking\n([\s\S]*?):::/', function ($m) use (&$thinkingBlocks) {
        $i = count($thinkingBlocks);
        $thinkingBlocks[] = h(trim($m[1]));
        return "\x01THINK$i\x02";
    }, $text);
    $toolBlocks = [];
    $text = preg_replace_callback('/:::tool\n([\s\S]*?):::/', function ($m) use (&$toolBlocks) {
        $i = count($toolBlocks);
        $toolBlocks[] = trim($m[1]);
        return "\x01TOOL$i\x02";
    }, $text);
    $html = chat_markup_rich($text);
    foreach ($thinkingBlocks as $i => $content) {
        $block = '<details class="ai-thinking my-1.5 rounded-lg border border-discord-700/50 bg-discord-900/40">'
            . '<summary class="px-3 py-1.5 text-xs text-discord-400 cursor-pointer select-none hover:text-discord-300">Thinking...</summary>'
            . '<div class="px-3 py-2 text-sm text-discord-400 italic whitespace-pre-wrap">' . $content . '</div></details>';
        $html = str_replace("\x01THINK$i\x02", $block, $html);
    }
    foreach ($toolBlocks as $i => $content) {
        $lines = explode("\n", $content);
        $name = h(array_shift($lines) ?: 'Tool');
        $output = h(implode("\n", $lines));
        $block = '<details class="ai-tool-card my-1.5 rounded-lg border border-discord-700 bg-discord-900/60">'
            . '<summary class="px-3 py-1.5 text-xs font-mono text-sky-400 cursor-pointer select-none hover:text-sky-300">🔧 ' . $name . '</summary>'
            . '<pre class="px-3 py-2 text-xs font-mono text-discord-300 whitespace-pre-wrap overflow-x-auto">' . $output . '</pre></details>';
        $html = str_replace("\x01TOOL$i\x02", $block, $html);
    }
    return $html;
}

/** Public URL of a user's stored avatar, or null. */
function avatar_url(?string $avatar): ?string
{
    if (!$avatar) {
        return null;
    }
    return url($avatar);
}

/* Deterministic gradient tile for users without an uploaded avatar —
   Discord-style colourful initial tile, hashed from the username so every
   client (web, JS mirror in app.js, messenger) shows the same colours. */
function avatar_gradient(string $name): string
{
    $palettes = [
        ['#f59e0b', '#ef4444'], ['#ec4899', '#8b5cf6'], ['#3b82f6', '#06b6d4'],
        ['#10b981', '#84cc16'], ['#f97316', '#f43f5e'], ['#6366f1', '#a855f7'],
        ['#14b8a6', '#3b82f6'], ['#eab308', '#f97316'], ['#ef4444', '#f97316'],
        ['#8b5cf6', '#ec4899'],
    ];
    $hash = array_sum(array_map('ord', str_split(strtolower((string) $name))));
    [$c1, $c2] = $palettes[$hash % count($palettes)];
    return "background:linear-gradient(135deg,{$c1},{$c2})";
}

/** Avatar <img> with the initial-letter fallback baked in for a given user row. */
function avatar_img(array $user, string $classes = 'w-10 h-10 rounded-full'): string
{
    $initial = h(strtoupper(mb_substr($user['username'] ?? '?', 0, 1)));
    if (!empty($user['avatar'])) {
        return '<img src="' . h(url((string) $user['avatar'])) . '" alt="' . h($user['username']) . '" loading="lazy" class="' . h($classes) . ' object-cover">';
    }
    return '<div style="' . avatar_gradient((string) ($user['username'] ?? '?')) . '" class="' . h($classes) . ' flex items-center justify-center text-sm font-bold text-white border border-black/20 shrink-0">' . $initial . '</div>';
}
