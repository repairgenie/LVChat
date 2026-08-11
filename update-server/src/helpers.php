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

function text_out(string $body, string $contentType = 'text/plain; charset=utf-8'): never
{
    header('Content-Type: ' . $contentType);
    echo $body;
    exit;
}

function render_view(string $name, array $vars = [], ?string $layout = 'layout'): never
{
    extract($vars, EXTR_SKIP);
    $view = UPDATE_ROOT . '/views/' . $name . '.php';
    if ($layout) {
        ob_start();
        require $view;
        $content = ob_get_clean();
        require UPDATE_ROOT . '/views/' . $layout . '.php';
    } else {
        require $view;
    }
    exit;
}

/** The public base URL (config override, else derived from the request). */
function public_base(): string
{
    $configured = rtrim(config_value('base_url'), '/');
    if ($configured !== '') {
        return $configured;
    }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('/index.php', '', $_SERVER['SCRIPT_NAME'] ?? ''), '/');
    return $scheme . '://' . $host . $dir;
}

/** A valid admin password is configured (plaintext or hash). */
function admin_configured(): bool
{
    return config_value('admin_pass') !== '' || config_value('admin_pass_hash') !== '';
}

function admin_verify(string $password): bool
{
    if ($password === '') {
        return false;
    }
    $hash = config_value('admin_pass_hash');
    if ($hash !== '' && password_verify($password, $hash)) {
        return true;
    }
    $plain = config_value('admin_pass');
    return $plain !== '' && hash_equals($plain, $password);
}

/** Best-effort read of a remote URL body with a timeout (curl or streams). */
function http_get(string $url, int $timeout = 30): ?string
{
    if ($url === '') {
        return null;
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'LVChat-UpdateServer/1.0',
        ]);
        $body = curl_exec($ch);
        $ok = is_string($body) && curl_errno($ch) === 0;
        curl_close($ch);
        return $ok ? $body : null;
    }
    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'follow_location' => 1, 'user_agent' => 'LVChat-UpdateServer/1.0']]);
    $body = @file_get_contents($url, false, $ctx);
    return is_string($body) ? $body : null;
}

/** Streaming hash of a remote file: returns [sha256, sha512(b64), size] or null. */
function http_hash(string $url, int $timeout = 120): ?array
{
    if ($url === '' || !function_exists('curl_init')) {
        return null;
    }
    $ch = curl_init($url);
    $sha256 = hash_init('sha256');
    $sha512 = hash_init('sha512');
    $size = 0;
    $failed = false;
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_USERAGENT => 'LVChat-UpdateServer/1.0',
        CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$sha256, &$sha512, &$size, &$failed) {
            $size += strlen($data);
            hash_update($sha256, $data);
            hash_update($sha512, $data);
            return strlen($data);
        },
    ]);
    curl_exec($ch);
    if (curl_errno($ch) !== 0) {
        $failed = true;
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($failed || $status < 200 || $status >= 300) {
        return null;
    }
    return [
        'sha256' => hash_final($sha256),
        'sha512' => base64_encode(hash_final($sha512, true)),
        'size' => $size,
    ];
}
