<?php

declare(strict_types=1);

/**
 * Generates the electron-updater generic-provider feeds (latest*.yml) from the
 * manifest, so data/releases.json stays the single source of truth.
 *
 * The generic provider base URL points at the feed's directory on this server
 * (e.g. https://updates.example.com/desktop). Artifacts may live anywhere —
 * each file entry carries the full artifact URL, which electron-updater
 * downloads directly (absolute URLs are honoured over relative joins).
 */
final class ElectronFeed
{
    public static function isKnownApp(string $app): bool
    {
        return in_array($app, ['desktop', 'messenger'], true);
    }

    /** Which yml file each feed answers for. */
    public static function feedFiles(string $app): array
    {
        return [
            'win' => "latest.yml",
            'mac' => "latest-mac.yml",
            'linux' => "latest-linux.yml",
        ];
    }

    /** All the yml files an app exposes, keyed by file name -> content. */
    public static function all(string $app): array
    {
        $out = [];
        foreach (self::feedFiles($app) as $kind => $file) {
            $content = self::forPlatformGroup($app, $kind);
            if ($content !== null) {
                $out[$file] = $content;
            }
        }
        return $out;
    }

    public static function forPlatformGroup(string $app, string $kind): ?string
    {
        if (!self::isKnownApp($app)) {
            return null;
        }
        $entry = Manifest::app($app);
        if ($entry === null || trim((string) ($entry['version'] ?? '')) === '') {
            return null;
        }
        $version = trim((string) $entry['version']);
        $releaseDate = self::isoDate((string) ($entry['released_at'] ?? ''));
        $platforms = $entry['platforms'] ?? [];

        if ($kind === 'win') {
            $files = self::filesOf($platforms, ['win']);
        } elseif ($kind === 'mac') {
            $files = self::filesOf($platforms, ['mac']);
        } else {
            $files = self::filesOf($platforms, ['linux_appimage', 'linux_deb', 'linux_rpm']);
        }
        if ($files === []) {
            return null;
        }

        $path = $files[0]['url'];
        $sha512 = $files[0]['sha512'];

        $lines = [];
        $lines[] = 'version: ' . $version;
        $lines[] = 'files:';
        foreach ($files as $f) {
            $lines[] = '  - url: ' . $f['url'];
            $lines[] = '    sha512: ' . ($f['sha512'] !== '' ? $f['sha512'] : 'null');
            $lines[] = '    size: ' . ($f['size'] !== null ? (string) (int) $f['size'] : '0');
        }
        $lines[] = 'path: ' . $path;
        $lines[] = 'sha512: ' . ($sha512 !== '' ? $sha512 : 'null');
        $lines[] = 'releaseDate: ' . $releaseDate;
        return implode("\n", $lines) . "\n";
    }

    /** @return list<array{url:string,sha512:string,size:?int}> */
    private static function filesOf(array $platforms, array $wanted): array
    {
        $files = [];
        foreach ($wanted as $plat) {
            $p = $platforms[$plat] ?? null;
            if (is_array($p) && trim((string) ($p['url'] ?? '')) !== '') {
                $files[] = [
                    'url' => trim((string) $p['url']),
                    'sha512' => trim((string) ($p['sha512'] ?? '')),
                    'size' => isset($p['size']) && $p['size'] !== '' && $p['size'] !== null ? (int) $p['size'] : null,
                ];
            }
        }
        return $files;
    }

    private static function isoDate(string $value): string
    {
        $ts = $value !== '' ? strtotime($value) : time();
        return $ts ? gmdate('Y-m-d\TH:i:s\Z', $ts) : gmdate('Y-m-d\TH:i:s\Z');
    }
}
