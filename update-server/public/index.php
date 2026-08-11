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

require dirname(__DIR__) . '/src/bootstrap.php';

// CORS for API clients (Electron apps read /manifest.json + feeds from file://
// or loopback origins, which send an Origin header). Simple pass-through for
// GET; no credentials needed for the public feeds.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF');
    header('Vary: Origin');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
if (preg_match('#^/index\.php(/.*)?$#', $path, $m)) {
    $path = $m[1] === '' ? '/' : $m[1];
}
$path = rtrim($path, '/') ?: '/';

function is_admin(): bool
{
    return !empty($_SESSION['update_admin']);
}

function require_admin(): void
{
    if (!is_admin()) {
        redirect('/admin');
    }
}

function require_admin_post(): void
{
    require_admin();
    Csrf::verify();
}

function app_entry(): ?array
{
    global $method, $path;
    if ($method !== 'GET') {
        return null;
    }
    if (!preg_match('#^/(api/latest|downloads)/([A-Za-z_]+)$#', $path, $m)) {
        return null;
    }
    $kind = $m[1];
    $app = $m[2];
    if (!in_array($app, Manifest::APPS, true)) {
        json_out(['error' => 'Unknown app'], 404);
    }
    $entry = Manifest::app($app);
    if ($entry === null) {
        json_out(['error' => 'Unknown app'], 404);
    }
    if ($kind === 'api/latest') {
        json_out(['app' => $app, 'version' => $entry['version'] ?? '', 'notes' => $entry['notes'] ?? '', 'released_at' => $entry['released_at'] ?? '']);
    }
    $url = trim((string) ($entry['url'] ?? ''));
    if ($url === '') {
        json_out(['error' => 'No download configured'], 404);
    }
    redirect($url);
}

function platform_redirect(): void
{
    global $method, $path;
    if ($method !== 'GET' || !preg_match('#^/downloads/([A-Za-z_]+)/([A-Za-z_]+)$#', $path, $m)) {
        return;
    }
    $p = Manifest::platform($m[1], $m[2]);
    if ($p === null || trim((string) ($p['url'] ?? '')) === '') {
        http_response_code(404);
        echo 'Not found';
        exit;
    }
    redirect(trim((string) $p['url']));
}

function platform_latest(): void
{
    global $method, $path;
    if ($method !== 'GET' || !preg_match('#^/api/latest/([A-Za-z_]+)/([A-Za-z_]+)$#', $path, $m)) {
        return;
    }
    $p = Manifest::platform($m[1], $m[2]);
    if ($p === null) {
        json_out(['error' => 'Unknown app or platform'], 404);
    }
    json_out([
        'app' => $m[1],
        'platform' => $m[2],
        'url' => $p['url'] ?? '',
        'sha256' => $p['sha256'] ?? '',
        'size' => $p['size'] ?? null,
    ]);
}

function yml_feed(): void
{
    global $method, $path;
    if ($method !== 'GET' || !preg_match('#^/(desktop|messenger)/(latest(?:-mac|-linux)?\.yml)$#', $path, $m)) {
        return;
    }
    $app = $m[1];
    $file = $m[2];
    $kind = match ($file) {
        'latest.yml' => 'win',
        'latest-mac.yml' => 'mac',
        'latest-linux.yml' => 'linux',
        default => null,
    };
    $body = $kind !== null ? ElectronFeed::forPlatformGroup($app, $kind) : null;
    if ($body === null) {
        http_response_code(404);
        echo 'Not found';
        exit;
    }
    text_out($body, 'text/yaml; charset=utf-8');
}

// ── Public routes ───────────────────────────────────────────────────────────

if ($method === 'GET' && $path === '/') {
    $errors = Manifest::validate();
    render_view('index', ['errors' => $errors], 'layout');
}

if ($method === 'GET' && $path === '/manifest.json') {
    $data = Manifest::load();
    json_out($data);
}

if ($method === 'GET' && $path === '/health') {
    json_out(['ok' => true]);
}

platform_redirect();
platform_latest();
app_entry();
yml_feed();

// ── Admin ───────────────────────────────────────────────────────────────────

if ($path === '/admin') {
    if ($method === 'GET') {
        if (!admin_configured()) {
            render_view('admin/not-configured', [], 'layout');
        }
        if (!is_admin()) {
            render_view('admin/login', [], 'layout');
        }
        render_view('admin/dashboard', [
            'data' => Manifest::load(),
            'errors' => Manifest::validate(),
            'feeds' => ElectronFeed::all('desktop') + ElectronFeed::all('messenger'),
        ], 'layout');
    }
    if ($method === 'POST') {
        Csrf::verify();
        $pass = (string) ($_POST['password'] ?? '');
        if (admin_verify($pass)) {
            session_regenerate_id(true);
            $_SESSION['update_admin'] = true;
            flash('Signed in.');
            redirect('/admin');
        }
        flash('Incorrect password.');
        redirect('/admin');
    }
}

if ($path === '/admin/logout' && $method === 'POST') {
    require_admin_post();
    unset($_SESSION['update_admin']);
    flash('Signed out.');
    redirect('/admin');
}

if ($path === '/admin/save' && $method === 'POST') {
    require_admin_post();
    $data = Manifest::load();
    $data['apps'] = [];
    foreach (Manifest::APPS as $app) {
        $entry = [
            'version' => trim((string) ($_POST["{$app}_version"] ?? '')),
            'notes' => trim((string) ($_POST["{$app}_notes"] ?? '')),
            'released_at' => trim((string) ($_POST["{$app}_released_at"] ?? '')),
        ];
        if ($app === 'web') {
            $entry['url'] = trim((string) ($_POST['web_url'] ?? ''));
            $entry['sha256'] = trim((string) ($_POST['web_sha256'] ?? ''));
        } else {
            $entry['platforms'] = [];
            foreach (Manifest::PLATFORMS as $plat) {
                $url = trim((string) ($_POST["{$app}_{$plat}_url"] ?? ''));
                $entry['platforms'][$plat] = [
                    'url' => $url,
                    'sha256' => trim((string) ($_POST["{$app}_{$plat}_sha256"] ?? '')),
                    'sha512' => trim((string) ($_POST["{$app}_{$plat}_sha512"] ?? '')),
                    'size' => trim((string) ($_POST["{$app}_{$plat}_size"] ?? '')),
                ];
            }
        }
        $data['apps'][$app] = $entry;
    }
    Manifest::save($data);
    $errors = Manifest::validate();
    if ($errors !== []) {
        flash('Saved — but validation found issues: ' . implode('; ', $errors));
    } else {
        flash('Manifest saved.');
    }
    redirect('/admin');
}

if ($path === '/admin/fetch-hash' && $method === 'POST') {
    require_admin_post();
    $url = trim((string) ($_POST['url'] ?? ''));
    if (!preg_match('#^https?://#i', $url)) {
        json_out(['ok' => false, 'error' => 'URL must start with http(s)://'], 422);
    }
    $hash = http_hash($url);
    if ($hash === null) {
        json_out(['ok' => false, 'error' => 'Could not download the URL to compute hashes'], 422);
    }
    json_out(['ok' => true] + $hash);
}

if ($path === '/admin/check' && $method === 'POST') {
    require_admin_post();
    $data = Manifest::load();
    $results = [];
    foreach (Manifest::APPS as $app) {
        $entry = $data['apps'][$app] ?? [];
        if ($app === 'web') {
            $results[] = ['name' => $app, 'url' => trim((string) ($entry['url'] ?? '')), 'ok' => http_get(trim((string) ($entry['url'] ?? ''))) !== null];
            continue;
        }
        foreach (Manifest::PLATFORMS as $plat) {
            $p = $entry['platforms'][$plat] ?? [];
            $url = trim((string) ($p['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $results[] = ['name' => "$app/$plat", 'url' => $url, 'ok' => http_get($url) !== null];
        }
    }
    render_view('admin/check', ['results' => $results], 'layout');
}

http_response_code(404);
echo 'Not found';
