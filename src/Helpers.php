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
        return '/app?channel=general';
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

/** Number of people currently connected (registered users + guests, last 30s). */
function online_count(): int
{
    $users = (int) Database::scalar("SELECT COUNT(*) FROM users WHERE away IS NULL AND last_seen >= datetime('now', '-30 seconds')");
    $guests = (int) Database::scalar("SELECT COUNT(*) FROM guests WHERE last_seen >= datetime('now', '-30 seconds')");
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
    if (($m['kind'] ?? '') === 'ai_response' || (int) ($m['bot'] ?? 0) === 1) {
        return chat_markup_ai((string) $m['content']);
    }
    return chat_markup_rich((string) $m['content']);
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

/** Avatar <img> with the initial-letter fallback baked in for a given user row. */
function avatar_img(array $user, string $classes = 'w-10 h-10 rounded-full'): string
{
    $initial = h(strtoupper(mb_substr($user['username'] ?? '?', 0, 1)));
    if (!empty($user['avatar'])) {
        return '<img src="' . h(url((string) $user['avatar'])) . '" alt="' . h($user['username']) . '" loading="lazy" class="' . h($classes) . ' object-cover">';
    }
    return '<div class="' . h($classes) . ' bg-discord-500 flex items-center justify-center text-sm font-bold text-white border border-discord-600 shrink-0">' . $initial . '</div>';
}
