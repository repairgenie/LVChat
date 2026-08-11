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
 * Server-side embed proxy for the Channel URL pane. Many sites refuse to be
 * framed (X-Frame-Options / CSP frame-ancestors) or are plain http:// (mixed
 * content on an https chat). Fetching the page on the server and re-serving it
 * from this origin sidesteps both, so "most websites load with as little fuss
 * as possible". The proxied document is always shown in an opaque-origin
 * sandbox (no allow-same-origin) so its scripts can never touch the chat app.
 * Stylesheets and fonts referenced by the page are re-served through a sibling
 * resource proxy (/api/embed/res) with Access-Control-Allow-Origin: *, since
 * from the opaque origin those subresource fetches would be CORS-blocked.
 *
 * Access is gated to signed-in sessions and targets are validated (scheme,
 * standard ports, no private/loopback addresses, no globally banned domains),
 * so the endpoint cannot be used as an open proxy or for SSRF.
 */
final class EmbedService
{
    private const MAX_REDIRECTS = 5;
    private const CONNECT_TIMEOUT = 8;
    private const READ_TIMEOUT = 15;
    private const MAX_BYTES = 4 * 1024 * 1024;
    private const MAX_RESOURCE_BYTES = 16 * 1024 * 1024;

    /** Validate + fetch + rewrite a URL for embedding. Returns
     *  ['error' => string, 'status' => int] or
     *  ['body' => string, 'content_type' => string, 'is_html' => bool]. */
    public static function proxy(string $url): array
    {
        $url = trim($url);
        if (!preg_match('#^https?://#i', $url)) {
            return ['error' => 'Only http:// and https:// pages can be embedded.', 'status' => 400];
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            return ['error' => 'That URL cannot be embedded.', 'status' => 400];
        }
        $ban = UrlBanService::isBanned($host);
        if ($ban) {
            return ['error' => 'That domain is banned (' . ($ban['reason'] ?: 'no reason given') . ').', 'status' => 403];
        }
        if (self::isLocalTarget($host)) {
            return ['error' => 'That address cannot be embedded.', 'status' => 403];
        }

        $fetched = self::fetch($url);
        if ($fetched === null) {
            return ['error' => 'Could not load that page.', 'status' => 502];
        }
        return self::rewrite($fetched['body'], $fetched['headers'], $fetched['final_url']);
    }

    /** Validate + fetch + rewrite a resource (stylesheet, font, image) referenced
     *  by an embedded page. From the opaque-origin sandbox those subresource
     *  fetches are CORS-gated (origin 'null'), so we re-serve them from this
     *  origin with Access-Control-Allow-Origin: * and, for CSS, rewrite nested
     *  url()/@import references back through the proxy. Returns
     *  ['error' => string, 'status' => int] or
     *  ['body' => string, 'content_type' => string]. */
    public static function resource(string $url): array
    {
        $url = trim($url);
        if (!preg_match('#^https?://#i', $url)) {
            return ['error' => 'Only http:// and https:// resources can be loaded.', 'status' => 400];
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '') {
            return ['error' => 'That resource cannot be loaded.', 'status' => 400];
        }
        $ban = UrlBanService::isBanned($host);
        if ($ban) {
            return ['error' => 'That domain is banned (' . ($ban['reason'] ?: 'no reason given') . ').', 'status' => 403];
        }
        if (self::isLocalTarget($host)) {
            return ['error' => 'That address cannot be loaded.', 'status' => 403];
        }

        $fetched = self::fetch($url, self::MAX_RESOURCE_BYTES);
        if ($fetched === null) {
            return ['error' => 'Could not load that resource.', 'status' => 502];
        }
        [$contentType, $baseType] = self::contentTypeFrom($fetched['headers']);
        $body = $fetched['body'];
        if ($baseType === 'text/css' || str_ends_with($baseType, '+css')) {
            $body = self::rewriteCssUrls($body, $fetched['final_url']);
        }
        return ['body' => $body, 'content_type' => $contentType !== '' ? $contentType : 'application/octet-stream'];
    }

    /** Fetch a URL server-side with redirects, timeouts and a size cap. */
    private static function fetch(string $url, int $maxBytes = self::MAX_BYTES): ?array
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = @curl_init($url);
        if ($ch === false) {
            return null;
        }
        $body = '';
        $tooBig = false;
        $headers = [];
        $ok = curl_setopt_array($ch, [
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => self::MAX_REDIRECTS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::READ_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; LVChatEmbed/1.0)',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8'],
            CURLOPT_ENCODING => '',
            CURLOPT_WRITEFUNCTION => function ($ch, string $data) use (&$body, &$tooBig, $maxBytes): int {
                $len = strlen($data);
                if (strlen($body) + $len > $maxBytes) {
                    $tooBig = true;
                    return -1;
                }
                $body .= $data;
                return $len;
            },
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$headers): int {
                $headers[] = $line;
                return strlen($line);
            },
        ]);
        if (!$ok) {
            curl_close($ch);
            return null;
        }
        @curl_exec($ch);
        $code = (int) @curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $final = (string) @curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        if ($tooBig || $body === '' || $code < 200 || $code >= 400) {
            return null;
        }
        return ['body' => $body, 'headers' => $headers, 'final_url' => $final !== '' ? $final : $url];
    }

    /** Strip framing blocks and, for HTML, make relative resources resolve +
     *  reroute in-page link clicks back through the proxy. */
    private static function rewrite(string $body, array $headers, string $finalUrl): array
    {
        [$contentType, $baseType] = self::contentTypeFrom($headers);
        $isHtml = $baseType === 'text/html' || str_ends_with($baseType, '+html');

        if ($isHtml) {
            $body = self::rewriteStyles($body, $finalUrl);
            $body = self::injectHtml($body, $finalUrl);
        }

        return ['body' => $body, 'content_type' => $contentType !== '' ? $contentType : 'text/plain', 'is_html' => $isHtml];
    }

    /** @return array{0:string,1:string} [full Content-Type, base type without
     *  parameters, lowercased]. */
    private static function contentTypeFrom(array $headers): array
    {
        $contentType = '';
        foreach ($headers as $line) {
            if (preg_match('#^Content-Type:\s*(.*)$#i', trim($line), $m)) {
                $contentType = trim($m[1]);
                break;
            }
        }
        $baseType = strtolower(trim(strtok($contentType, ';') ?: 'text/plain'));
        return [$contentType, $baseType];
    }

    /** Prepend a <base> (relative URLs resolve to the target site), a click
     *  catcher that routes <a> navigation back through the proxy, and opaque
     *  origin resilience shims so heavy JS sites (SPAs, consent managers) don't
     *  crash in the sandboxed document. */
    private static function injectHtml(string $body, string $finalUrl): string
    {
        $baseTag = '<base href="' . htmlspecialchars($finalUrl, ENT_QUOTES, 'UTF-8') . '">';
        $script = '<script>/* lvc-embed */'
            . 'try{(function(){'
            // lvc-resilience: in an opaque-origin sandbox history/localStorage/
            // cookie access throws — shim them so embedded scripts keep running.
            . '/* lvc-resilience */'
            . 'function _memStore(){var m={},n=0;return{'
            . 'get length(){return n},'
            . 'key:function(i){var k=Object.keys(m);return i>=0&&i<k.length?k[i]:null},'
            . 'getItem:function(k){return Object.prototype.hasOwnProperty.call(m,k)?m[k]:null},'
            . 'setItem:function(k,v){if(!Object.prototype.hasOwnProperty.call(m,k))n++;m[k]=String(v)},'
            . 'removeItem:function(k){if(Object.prototype.hasOwnProperty.call(m,k)){delete m[k];n--}},'
            . 'clear:function(){m={};n=0}}}'
            . 'try{var _ls=_memStore();Object.defineProperty(window,"localStorage",{get:function(){return _ls},configurable:true})}catch(e){}'
            . 'try{var _ss=_memStore();Object.defineProperty(window,"sessionStorage",{get:function(){return _ss},configurable:true})}catch(e){}'
            . 'try{Object.defineProperty(document,"cookie",{get:function(){return ""},set:function(){},configurable:true})}catch(e){}'
            . 'try{var _rs=history.replaceState,_ps=history.pushState;'
            . 'function _hist(fn,state,title,url){'
            . 'if(url===undefined)return fn.call(history,state,title);'
            . 'try{return fn.call(history,state,title,url)}catch(e){'
            . 'try{var u=new URL(String(url),location.href);'
            . 'return fn.call(history,state,title,u.pathname+u.search+u.hash)}catch(e2){}}}'
            . 'history.replaceState=function(s,t,u){return _hist(_rs,s,t,u)};'
            . 'history.pushState=function(s,t,u){return _hist(_ps,s,t,u)};'
            . '}catch(e){}'
            // Click catcher: reroute in-pane link clicks back through the proxy.
            . 'var doc=new URL(document.baseURI);'
            . 'var proxy=location.href.split("?")[0];'
            . 'function strip(u){var s=String(u).split("#")[0];return s;}'
            . 'document.addEventListener("click",function(e){'
            . 'var a=e.target&&e.target.closest?e.target.closest("a[href]"):null;'
            . 'if(!a)return;var u;try{u=new URL(a.href,document.baseURI)}catch(err){return}'
            . 'if(u.protocol!=="http:"&&u.protocol!=="https:")return;'
            . 'if(u.pathname==="/api/embed"&&u.searchParams.get("url"))return;'
            . 'if(u.hash&&strip(u.href)===strip(doc.href))return;'
            . 'if(a.href===location.href)return;'
            . 'e.preventDefault();location.href=proxy+"?url="+encodeURIComponent(u.href);'
            . '},true)'
            . '})()}catch(e){}</script>';
        $inject = $baseTag . $script;

        // Remove any existing <base> so ours wins.
        $body = preg_replace('#<base\b[^>]*>#i', '', $body, 1) ?? $body;

        // Insert right after the opening <head> tag (or <html> if there is none).
        if (preg_match('#<head(\s[^>]*)?>#i', $body, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1] + strlen($m[0][0]);
            return substr($body, 0, $pos) . $inject . substr($body, $pos);
        }
        if (preg_match('#<html(\s[^>]*)?>#i', $body, $m, PREG_OFFSET_CAPTURE)) {
            $pos = $m[0][1] + strlen($m[0][0]);
            return substr($body, 0, $pos) . $inject . substr($body, $pos);
        }
        return $inject . $body;
    }

    /** Rewrite <link rel="stylesheet"> hrefs and inline <style> url() refs so
     *  stylesheets and fonts load through the resource proxy (from the opaque
     *  origin they would be CORS-blocked). Scripts, images and media are left
     *  alone — they load cross-origin without CORS. */
    private static function rewriteStyles(string $body, string $finalUrl): string
    {
        $body = preg_replace_callback('#<link\b[^>]*>#i', function (array $m) use ($finalUrl): string {
            $tag = $m[0];
            if (!preg_match('#\brel\s*=\s*(?:"[^"]*stylesheet[^"]*"|\'[^\']*stylesheet[^\']*\')#i', $tag)) {
                return $tag;
            }
            if (!preg_match('#\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^"\'\s>]+))#i', $tag, $mm)) {
                return $tag;
            }
            $href = $mm[1] !== '' ? $mm[1] : ($mm[2] !== '' ? $mm[2] : ($mm[3] ?? ''));
            $abs = self::resolveUrl($finalUrl, $href);
            if ($abs === null) {
                return $tag;
            }
            $proxied = self::proxyResUrl($abs);
            return (string) preg_replace('#\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^"\'\s>]+))#i', 'href="' . htmlspecialchars($proxied, ENT_QUOTES, 'UTF-8') . '"', $tag, 1);
        }, $body) ?? $body;

        $body = preg_replace_callback('#(<style\b[^>]*>)(.*?)(</style>)#is', function (array $m) use ($finalUrl): string {
            return $m[1] . self::rewriteCssUrls($m[2], $finalUrl) . $m[3];
        }, $body) ?? $body;

        return $body;
    }

    /** Rewrite url() and bare @import references in CSS to go through the
     *  resource proxy so nested fonts/backgrounds load despite the opaque-origin
     *  CORS gate. */
    private static function rewriteCssUrls(string $css, string $base): string
    {
        $css = preg_replace_callback(
            '/url\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^)"\'\s]+))\s*\)/i',
            function (array $m) use ($base): string {
                $ref = $m[1] !== '' ? $m[1] : ($m[2] !== '' ? $m[2] : ($m[3] ?? ''));
                $abs = self::resolveUrl($base, $ref);
                if ($abs === null) {
                    return $m[0];
                }
                return 'url(' . self::proxyResUrl($abs) . ')';
            },
            $css
        ) ?? $css;

        $css = preg_replace_callback(
            '/@import\s+(?:"([^";]+)"|\'([^\';]+)\')/i',
            function (array $m) use ($base): string {
                $ref = $m[1] !== '' ? $m[1] : ($m[2] ?? '');
                $abs = self::resolveUrl($base, $ref);
                if ($abs === null) {
                    return $m[0];
                }
                return '@import "' . self::proxyResUrl($abs) . '"';
            },
            $css
        ) ?? $css;

        return $css;
    }

    /** Build the /api/embed/res URL for an absolute resource URL. Server-relative
     *  so it works from the web app and both messengers (their iframe document
     *  lives on the chat server origin). */
    private static function proxyResUrl(string $absUrl): string
    {
        return '/api/embed/res?url=' . rawurlencode($absUrl);
    }

    /** Resolve a possibly-relative URL reference against a base URL. Returns null
     *  for empty / non-http(s) references so callers leave them untouched. */
    private static function resolveUrl(string $base, string $ref): ?string
    {
        $ref = trim($ref);
        if ($ref === '' || preg_match('~^(data:|about:|blob:|javascript:|mailto:|tel:|#)~i', $ref)) {
            return null;
        }
        if (preg_match('#^https?://#i', $ref)) {
            return $ref;
        }
        $b = parse_url($base);
        if ($b === false || empty($b['scheme']) || empty($b['host'])) {
            return null;
        }
        $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($ref, '//')) {
            return $b['scheme'] . ':' . $ref;
        }
        if (str_starts_with($ref, '/')) {
            return $origin . $ref;
        }
        if (str_starts_with($ref, '?') || str_starts_with($ref, '#')) {
            $dir = substr($b['path'] ?? '/', 0, (int) strrpos($b['path'] ?? '/', '/') + 1);
            return $origin . $dir . $ref;
        }
        $dir = substr($b['path'] ?? '/', 0, (int) strrpos($b['path'] ?? '/', '/') + 1);
        $joined = $origin . $dir . $ref;
        $parts = parse_url($joined);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        $segs = [];
        foreach (explode('/', $parts['path'] ?? '/') as $seg) {
            if ($seg === '..') {
                if ($segs !== []) {
                    array_pop($segs);
                }
            } elseif ($seg !== '.' && $seg !== '') {
                $segs[] = $seg;
            }
        }
        $path = '/' . implode('/', $segs);
        $query = !empty($parts['query']) ? '?' . $parts['query'] : '';
        $frag = !empty($parts['fragment']) ? '#' . $parts['fragment'] : '';
        return $origin . $path . $query . $frag;
    }

    /** Reject hosts that resolve to loopback / private / link-local addresses
     *  (SSRF guard) or that cannot be resolved at all. The LVC_EMBED_ALLOW_LOCAL
     *  env override (test harness only) lets loopback targets through so the
     *  proxy's success path can be exercised against a local mock server while
     *  private ranges stay blocked. */
    private static function isLocalTarget(string $host): bool
    {
        $h = strtolower(trim($host, "[]"));
        if ($h === 'localhost' || $h === 'localhost.' || str_ends_with($h, '.local') || str_ends_with($h, '.localhost') || str_ends_with($h, '.localdomain')) {
            return getenv('LVC_EMBED_ALLOW_LOCAL') !== '1';
        }
        if (str_starts_with($h, '127.') || $h === '::1') {
            return getenv('LVC_EMBED_ALLOW_LOCAL') !== '1';
        }
        $ips = [];
        if (filter_var($h, FILTER_VALIDATE_IP)) {
            $ips[] = $h;
        } else {
            $v4 = @gethostbynamel($host);
            if (is_array($v4)) {
                $ips = array_merge($ips, $v4);
            }
            $recs = @dns_get_record($host, DNS_AAAA);
            if (is_array($recs)) {
                foreach ($recs as $r) {
                    if (($r['type'] ?? '') === 'AAAA' && !empty($r['ipv6'])) {
                        $ips[] = $r['ipv6'];
                    }
                }
            }
        }
        if ($ips === []) {
            return true; // unresolvable — refuse rather than leak DNS-rebinding style fetches
        }
        foreach ($ips as $ip) {
            if (self::isNonRoutable($ip)) {
                return true;
            }
        }
        return false;
    }

    private static function isNonRoutable(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $p = array_map('intval', explode('.', $ip));
            if ($p[0] === 0 || $p[0] === 10 || $p[0] === 127) return true;
            if ($p[0] === 100 && $p[1] >= 64 && $p[1] <= 127) return true; // CGNAT
            if ($p[0] === 169 && $p[1] === 254) return true;
            if ($p[0] === 172 && $p[1] >= 16 && $p[1] <= 31) return true;
            if ($p[0] === 192 && $p[1] === 168) return true;
            if ($p[0] >= 224) return true; // multicast / reserved
            return false;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $v = strtolower($ip);
            if (str_contains($v, '%')) return true; // zone id
            if ($v === '::' || $v === '::1') return true;
            if (str_starts_with($v, 'fe80:')) return true;
            if (str_starts_with($v, 'fc') || str_starts_with($v, 'fd')) return true;
            return false;
        }
        return true;
    }
}
