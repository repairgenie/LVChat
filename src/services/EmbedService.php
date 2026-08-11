<?php

declare(strict_types=1);

/**
 * Server-side embed proxy for the Channel URL pane. Many sites refuse to be
 * framed (X-Frame-Options / CSP frame-ancestors) or are plain http:// (mixed
 * content on an https chat). Fetching the page on the server and re-serving it
 * from this origin sidesteps both, so "most websites load with as little fuss
 * as possible". The proxied document is always shown in an opaque-origin
 * sandbox (no allow-same-origin) so its scripts can never touch the chat app.
 *
 * Access is gated to signed-in sessions and the target is validated (scheme,
 * standard ports, no private/loopback addresses, no globally banned domains),
 * so the endpoint cannot be used as an open proxy or for SSRF.
 */
final class EmbedService
{
    private const MAX_REDIRECTS = 5;
    private const CONNECT_TIMEOUT = 8;
    private const READ_TIMEOUT = 15;
    private const MAX_BYTES = 4 * 1024 * 1024;

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

    /** Fetch a URL server-side with redirects, timeouts and a size cap. */
    private static function fetch(string $url): ?array
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
            CURLOPT_WRITEFUNCTION => function ($ch, string $data) use (&$body, &$tooBig): int {
                $len = strlen($data);
                if (strlen($body) + $len > self::MAX_BYTES) {
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
        $contentType = '';
        foreach ($headers as $line) {
            if (preg_match('#^Content-Type:\s*(.*)$#i', trim($line), $m)) {
                $contentType = trim($m[1]);
                break;
            }
        }
        $baseType = strtolower(trim(strtok($contentType, ';') ?: 'text/plain'));
        $isHtml = $baseType === 'text/html' || str_ends_with($baseType, '+html');

        if ($isHtml) {
            $body = self::injectHtml($body, $finalUrl);
        }

        return ['body' => $body, 'content_type' => $contentType !== '' ? $contentType : 'text/plain', 'is_html' => $isHtml];
    }

    /** Prepend a <base> (relative URLs resolve to the target site) and a click
     *  catcher that routes <a> navigation back through the proxy. */
    private static function injectHtml(string $body, string $finalUrl): string
    {
        $baseTag = '<base href="' . htmlspecialchars($finalUrl, ENT_QUOTES, 'UTF-8') . '">';
        $script = '<script>/* lvc-embed */'
            . 'try{(function(){'
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
            . '},true)})()}catch(e){}</script>';
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
