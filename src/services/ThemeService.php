<?php

declare(strict_types=1);

/**
 * Theming: preset library, per-user + server-wide themes, CSS emission.
 *
 * The whole UI is driven by CSS custom properties consumed by Tailwind as
 * `rgb(var(--c-x) / <alpha>)`. A theme is a preset color identity plus optional
 * overrides (accent, sidebar, font, chat background). Every rendered theme emits
 * BOTH palettes (`:root` = dark, `html.light` = light) so the existing instant
 * dark/light toggle keeps working by flipping a single class on <html>.
 *
 * Precedence (resolve()): personal theme (users.theme_json) -> server theme
 * (server_config `theme`) -> default preset. The admin kill-switch
 * (`theme_user_customization` = '0') freezes everyone on the server theme.
 *
 * A channel can also carry its own background image/colour (set by its owner);
 * while you view that channel it overrides the theme's chat background.
 */
final class ThemeService
{
    private const DEFAULT_PRESET = 'midnight';
    private const MAX_PRESETS = 75;

    /** System font stacks only (no CDN — PWA/offline safe). */
    public const FONTS = [
        'default' => '"gg sans", "Helvetica Neue", Helvetica, Arial, sans-serif',
        'sans'    => 'system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif',
        'serif'   => 'Georgia, "Times New Roman", serif',
        'mono'    => '"JetBrains Mono", "Fira Code", ui-monospace, "SFMono-Regular", Consolas, monospace',
        'rounded' => 'ui-rounded, "Segoe UI", "Trebuchet MS", "Varela Round", sans-serif',
    ];

    public const CHAT_BG_FITS = ['contain', 'cover', 'repeat'];

    /** Default overlay opacity (0–100) between chat text and a background image.
     *  Dark mode overlays black, light mode overlays white. */
    public const CHAT_BG_OVERLAY_DEFAULT = 55;

    private const CSS_VARS = [
        'blurple' => '--c-blurple', 'blurple_dark' => '--c-blurple-dark',
        'd_100' => '--c-d-100', 'd_200' => '--c-d-200', 'd_300' => '--c-d-300',
        'd_400' => '--c-d-400', 'd_500' => '--c-d-500', 'd_600' => '--c-d-600',
        'd_700' => '--c-d-700', 'd_750' => '--c-d-750', 'd_800' => '--c-d-800',
        'd_850' => '--c-d-850', 'd_900' => '--c-d-900', 'd_950' => '--c-d-950',
        'sidebar' => '--c-sidebar', 'sidebar_hover' => '--c-sidebar-hover',
    ];

    // Dark-mode surfaces: [lightness multiplier of the sidebar base, floor].
    // The text shades (d_400..d_100) are absolute so bright text stays readable
    // even on very dark sidebars.
    private const DARK_SURFACE = [
        'd_950' => [0.39, 0.04], 'd_900' => [0.72, 0.08], 'd_850' => [0.94, 0.10],
        'd_800' => [1.00, 0.12], 'd_750' => [1.17, 0.16], 'd_700' => [1.33, 0.19],
        'd_600' => [1.44, 0.22], 'd_500' => [1.78, 0.30],
    ];
    private const DARK_TEXT = ['d_400' => 0.52, 'd_300' => 0.74, 'd_200' => 0.88, 'd_100' => 0.96];
    private const DARK_SAT = [
        'd_950' => 1.0, 'd_900' => 1.0, 'd_850' => 1.0, 'd_800' => 1.0, 'd_750' => 1.0,
        'd_700' => 0.90, 'd_600' => 0.85, 'd_500' => 0.75, 'd_400' => 0.55,
        'd_300' => 0.45, 'd_200' => 0.40, 'd_100' => 0.35,
    ];

    private const LIGHT_TEXT = ['d_100' => 0.11, 'd_200' => 0.19, 'd_300' => 0.31, 'd_400' => 0.44];
    // Light-mode surfaces: how far to mix the sidebar colour toward white.
    private const LIGHT_SURFACE = [
        'd_500' => 0.70, 'd_600' => 0.75, 'd_700' => 0.84, 'd_750' => 0.92,
        'd_800' => 0.98, 'd_850' => 0.95, 'd_900' => 0.94, 'd_950' => 0.90,
    ];

    /** Curated anchor palettes; each yields a few hue-rotated variants (see presets()). */
    private static function anchors(): array
    {
        return [
            ['id' => 'midnight',     'name' => 'Midnight',     'sidebar' => '2b2d31', 'accent' => '5865f2'],
            ['id' => 'deepblurple',  'name' => 'Deep Blurple', 'sidebar' => '1f2335', 'accent' => '5865f2'],
            ['id' => 'nord',         'name' => 'Nord',         'sidebar' => '2e3440', 'accent' => '88c0d0'],
            ['id' => 'dracula',      'name' => 'Dracula',      'sidebar' => '282a36', 'accent' => 'bd93f9'],
            ['id' => 'solarized',    'name' => 'Solarized',    'sidebar' => '002b36', 'accent' => '268bd2'],
            ['id' => 'onedark',      'name' => 'One Dark',     'sidebar' => '282c34', 'accent' => '61afef'],
            ['id' => 'gruvbox',      'name' => 'Gruvbox',      'sidebar' => '282828', 'accent' => 'fabd2f'],
            ['id' => 'catppuccin',   'name' => 'Catppuccin',   'sidebar' => '1e1e2e', 'accent' => '89b4fa'],
            ['id' => 'tokyonight',   'name' => 'Tokyo Night',  'sidebar' => '1a1b26', 'accent' => '7aa2f7'],
            ['id' => 'cyberpunk',    'name' => 'Cyberpunk',    'sidebar' => '160b2b', 'accent' => 'f72585'],
            ['id' => 'forest',       'name' => 'Forest',       'sidebar' => '122017', 'accent' => '22c55e'],
            ['id' => 'ocean',        'name' => 'Ocean',        'sidebar' => '0f1b2a', 'accent' => '0ea5e9'],
            ['id' => 'sunset',       'name' => 'Sunset',       'sidebar' => '2a1610', 'accent' => 'f97316'],
            ['id' => 'rose',         'name' => 'Rose',         'sidebar' => '2a1220', 'accent' => 'f43f5e'],
            ['id' => 'mono',         'name' => 'Monochrome',   'sidebar' => '202225', 'accent' => '8a8f98'],
        ];
    }

    /** 30° hue bucket -> descriptive name, used to label generated variants. */
    private static function hueName(float $hue): string
    {
        $hue = fmod($hue, 360);
        if ($hue < 0) {
            $hue += 360;
        }
        $names = [
            0 => 'red', 30 => 'orange', 60 => 'yellow', 90 => 'lime', 120 => 'green',
            150 => 'teal', 180 => 'cyan', 210 => 'azure', 240 => 'blue', 270 => 'violet',
            300 => 'magenta', 330 => 'rose',
        ];
        $bucket = (int) floor(fmod($hue + 15, 360) / 30) * 30;
        return $names[$bucket] ?? 'color';
    }

    /**
     * The full preset library (up to 75 complementing combinations): every
     * curated anchor plus accent-hue and sidebar-hue rotations of it.
     *
     * @return array<int, array{id:string,name:string,sidebar:string,accent:string,swatch:array}>
     */
    public static function presets(): array
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $out = [];
        foreach (self::anchors() as $a) {
            $accentHue = self::hexToHsl($a['accent'])[0];
            $sidebarHue = self::hexToHsl($a['sidebar'])[0];
            $out[] = self::presetCard($a['id'], $a['name'], $a['sidebar'], $a['accent']);
            $out[] = self::presetCard(
                $a['id'] . '-' . self::hueName($accentHue + 60),
                $a['name'] . ' ' . ucfirst(self::hueName($accentHue + 60)),
                $a['sidebar'],
                self::rotateHex($a['accent'], 60)
            );
            $out[] = self::presetCard(
                $a['id'] . '-' . self::hueName($accentHue + 120),
                $a['name'] . ' ' . ucfirst(self::hueName($accentHue + 120)),
                $a['sidebar'],
                self::rotateHex($a['accent'], 120)
            );
            $out[] = self::presetCard(
                $a['id'] . '-side-' . self::hueName($sidebarHue + 45),
                $a['name'] . ' Side ' . ucfirst(self::hueName($sidebarHue + 45)),
                self::rotateHex($a['sidebar'], 45),
                $a['accent']
            );
            $out[] = self::presetCard(
                $a['id'] . '-side-' . self::hueName($sidebarHue - 45),
                $a['name'] . ' Side ' . ucfirst(self::hueName($sidebarHue - 45)),
                self::rotateHex($a['sidebar'], -45),
                $a['accent']
            );
        }
        $cached = array_slice($out, 0, self::MAX_PRESETS);
        return $cached;
    }

    /** One preset library entry (id/name/colours + a small swatch for pickers). */
    private static function presetCard(string $id, string $name, string $sidebar, string $accent): array
    {
        $palette = self::palette($sidebar, $accent);
        $hexOf = fn (string $triplet): string => '#' . self::tripletToHex($triplet);
        return [
            'id' => $id,
            'name' => $name,
            'sidebar' => '#' . strtolower($sidebar),
            'accent' => '#' . strtolower($accent),
            'swatch' => [
                'sidebar' => $hexOf($palette['dark']['sidebar']),
                'accent' => $hexOf($palette['dark']['blurple']),
                'bg' => $hexOf($palette['dark']['d_900']),
                'surface' => $hexOf($palette['dark']['d_800']),
                'text' => $hexOf($palette['dark']['d_300']),
                'light_bg' => $hexOf($palette['light']['d_900']),
            ],
        ];
    }

    public static function preset(string $id): ?array
    {
        $id = (string) $id;
        foreach (self::presets() as $p) {
            if ($p['id'] === $id) {
                return $p;
            }
        }
        return null;
    }

    /** Normalize a preset id, falling back to the default. */
    public static function presetId(mixed $id): string
    {
        $p = self::preset((string) $id);
        return $p ? $p['id'] : self::DEFAULT_PRESET;
    }

    /**
     * Normalize a stored theme array into a canonical shape, dropping any
     * invalid override values.
     *
     * @return array{preset:string,mode:string,overrides:array}
     */
    public static function normalize(array $theme): array
    {
        $overrides = is_array($theme['overrides'] ?? null) ? $theme['overrides'] : [];
        $o = [];
        if ($hex = self::hex((string) ($overrides['accent'] ?? ''))) {
            $o['accent'] = $hex;
        }
        if ($hex = self::hex((string) ($overrides['sidebar'] ?? ''))) {
            $o['sidebar'] = $hex;
        }
        $font = (string) ($overrides['font'] ?? '');
        if (isset(self::FONTS[$font])) {
            $o['font'] = $font;
        }
        if ($hex = self::hex((string) ($overrides['chat_bg_color'] ?? ''))) {
            $o['chat_bg_color'] = $hex;
        }
        if ($path = self::localPath((string) ($overrides['chat_bg_image'] ?? ''))) {
            $o['chat_bg_image'] = $path;
        }
        if (in_array($overrides['chat_bg_fit'] ?? '', self::CHAT_BG_FITS, true)) {
            $o['chat_bg_fit'] = $overrides['chat_bg_fit'];
        }
        $overlay = (int) ($overrides['chat_bg_overlay'] ?? -1);
        if ($overlay >= 0 && $overlay <= 100) {
            $o['chat_bg_overlay'] = $overlay;
        }
        $mode = (string) ($theme['mode'] ?? '');
        if (!in_array($mode, ['light', 'dark'], true)) {
            $mode = '';
        }
        return [
            'preset' => self::presetId($theme['preset'] ?? ''),
            'mode' => $mode,
            'overrides' => $o,
        ];
    }

    /** The global (server-wide) theme as a normalized array. */
    public static function globalTheme(): array
    {
        $raw = (string) (config_get('theme', '') ?? '');
        $data = $raw !== '' ? json_decode($raw, true) : [];
        return self::normalize(is_array($data) ? $data : []);
    }

    /** Whether users may customize their own theme (admin kill-switch). */
    public static function customizationEnabled(): bool
    {
        return config_get('theme_user_customization', '1') !== '0';
    }

    /**
     * The user's own stored theme (users.theme_json), normalized; an empty
     * preset means "no personal theme" so resolve() falls back to the server
     * theme. Empty overrides mean "use the preset's default values".
     *
     * @return array{preset:string,mode:string,overrides:array}
     */
    public static function userTheme(?array $user): array
    {
        if (!$user || empty($user['theme_json'])) {
            return ['preset' => '', 'mode' => '', 'overrides' => []];
        }
        $data = json_decode((string) $user['theme_json'], true);
        return self::normalize(is_array($data) ? $data : []);
    }

    /**
     * Resolve the effective theme for a viewer:
     *   user theme (if allowed + stored) > server theme > default preset.
     * The dark/light mode comes from the personal theme's mode, then the
     * account's saved quick-toggle mode (users.theme), then the server theme's
     * mode, then 'dark'. When customization is disabled the user's stored
     * choices are ignored entirely.
     *
     * @return array{preset:string,mode:string,overrides:array}
     */
    public static function resolve(?array $user): array
    {
        $custom = self::customizationEnabled();
        $theme = self::globalTheme();
        $hasPersonal = false;
        if ($custom && $user) {
            $personal = self::userTheme($user);
            if (!empty($personal['preset'])) {
                $theme = $personal;
                $hasPersonal = true;
            }
        }
        $theme['preset'] = self::presetId($theme['preset'] ?? '');

        $mode = $hasPersonal && in_array($theme['mode'], ['light', 'dark'], true) ? $theme['mode'] : '';
        if ($mode === '' && $custom && $user && in_array($user['theme'] ?? '', ['light', 'dark'], true)) {
            $mode = $user['theme'];
        }
        if ($mode === '') {
            $globalMode = self::globalTheme()['mode'];
            $mode = in_array($globalMode, ['light', 'dark'], true) ? $globalMode : 'dark';
        }
        $theme['mode'] = $mode === 'light' ? 'light' : 'dark';
        return $theme;
    }

    /**
     * Fully computed theme (both palettes, font, chat background) ready for CSS.
     *
     * @return array{id:string,name:string,mode:string,font:string,dark:array,light:array,chat_bg_color:string,chat_bg_image:string,chat_bg_fit:string}
     */
    public static function render(array $theme): array
    {
        $preset = self::preset(self::presetId($theme['preset'] ?? '')) ?? self::preset(self::DEFAULT_PRESET);
        $o = $theme['overrides'] ?? [];
        $sidebar = ltrim(self::hex((string) ($o['sidebar'] ?? '')) ?: $preset['sidebar'], '#');
        $accent = ltrim(self::hex((string) ($o['accent'] ?? '')) ?: $preset['accent'], '#');
        $palette = self::palette($sidebar, $accent);
        return [
            'id' => $preset['id'],
            'name' => $preset['name'],
            'mode' => ($theme['mode'] ?? '') === 'light' ? 'light' : 'dark',
            'font' => isset(self::FONTS[$o['font'] ?? '']) ? (string) $o['font'] : 'default',
            'dark' => $palette['dark'],
            'light' => $palette['light'],
            'chat_bg_color' => self::hex((string) ($o['chat_bg_color'] ?? '')) ?: '',
            'chat_bg_image' => self::localPath((string) ($o['chat_bg_image'] ?? '')) ?: '',
            'chat_bg_fit' => in_array($o['chat_bg_fit'] ?? '', self::CHAT_BG_FITS, true) ? (string) $o['chat_bg_fit'] : 'contain',
            'chat_bg_overlay' => isset($o['chat_bg_overlay']) && $o['chat_bg_overlay'] >= 0 && $o['chat_bg_overlay'] <= 100
                ? (int) $o['chat_bg_overlay']
                : self::CHAT_BG_OVERLAY_DEFAULT,
        ];
    }

    /** Convenience: resolved + rendered theme for a view. */
    public static function effectiveForView(?array $user): array
    {
        return self::render(self::resolve($user));
    }

    /** CSS variables + font + colour-scheme for a rendered theme. */
    public static function cssVars(array $r): string
    {
        $font = self::FONTS[$r['font']] ?? self::FONTS['default'];
        $out = ":root{--font-sans:{$font};";
        foreach (self::CSS_VARS as $key => $var) {
            $out .= "{$var}:" . ($r['dark'][$key] ?? '0 0 0') . ';';
        }
        $out .= "}html.light{";
        foreach (self::CSS_VARS as $key => $var) {
            $out .= "{$var}:" . ($r['light'][$key] ?? '0 0 0') . ';';
        }
        $out .= '}html{color-scheme:dark;}html.light{color-scheme:light;}';
        return $out;
    }

    /**
     * Chat message-area background for a rendered theme. When a channel carries
     * its own background (owner-set) it wins over the theme's.
     */
    public static function chatBgCss(array $r, ?array $channel = null): string
    {
        $color = $r['chat_bg_color'];
        $image = $r['chat_bg_image'];
        $fit = $r['chat_bg_fit'];
        $overlay = $r['chat_bg_overlay'] ?? self::CHAT_BG_OVERLAY_DEFAULT;
        if ($channel) {
            $hasChannelBg = false;
            if ($hex = self::hex((string) ($channel['bg_color'] ?? ''))) {
                $color = $hex;
                $hasChannelBg = true;
            }
            if ($path = self::localPath((string) ($channel['bg_image'] ?? ''))) {
                $image = $path;
                $hasChannelBg = true;
            }
            if ($hasChannelBg) {
                // A channel's own background defaults to "contain" (and its own
                // overlay opacity) regardless of the theme, stored per-channel.
                $fit = in_array($channel['bg_fit'] ?? '', self::CHAT_BG_FITS, true)
                    ? (string) $channel['bg_fit']
                    : 'contain';
                $overlay = isset($channel['bg_overlay']) && $channel['bg_overlay'] >= 0 && $channel['bg_overlay'] <= 100
                    ? (int) $channel['bg_overlay']
                    : self::CHAT_BG_OVERLAY_DEFAULT;
            }
        }
        $css = '#messages{';
        if ($color !== '') {
            $css .= "background-color:{$color};";
        }
        $css .= '}';
        if ($image !== '') {
            $size = $fit === 'repeat' ? 'auto' : $fit;
            $pos = $fit === 'repeat' ? '' : 'background-position:center;';
            $a = number_format(max(0, min(100, $overlay)) / 100, 2, '.', '');
            if ((int) $overlay > 0) {
                // A translucent layer between the text and the image (black in
                // dark mode, white in light mode) keeps text readable over busy
                // images; opacity is user/admins/channel-owner adjustable.
                $darkBg = "linear-gradient(rgba(0,0,0,{$a}),rgba(0,0,0,{$a})),url('{$image}')";
                $lightBg = "linear-gradient(rgba(255,255,255,{$a}),rgba(255,255,255,{$a})),url('{$image}')";
            } else {
                $darkBg = "url('{$image}')";
                $lightBg = "url('{$image}')";
            }
            $css .= "#messages.theme-bg-image{background-image:{$darkBg};";
            $css .= "background-size:{$size};{$pos}background-attachment:fixed;}";
            $css .= "html.light #messages.theme-bg-image{background-image:{$lightBg};}";
        }
        return $css;
    }

    /** Full CSS for an ad-hoc theme built from raw params (live-preview endpoint). */
    public static function cssFor(array $params): string
    {
        $theme = self::normalize([
            'preset' => (string) ($params['preset'] ?? ''),
            'mode' => (string) ($params['mode'] ?? ''),
            'overrides' => [
                'accent' => (string) ($params['accent'] ?? ''),
                'sidebar' => (string) ($params['sidebar'] ?? ''),
                'font' => (string) ($params['font'] ?? ''),
                'chat_bg_color' => (string) ($params['chat_bg_color'] ?? ''),
                'chat_bg_image' => (string) ($params['chat_bg_image'] ?? ''),
                'chat_bg_fit' => (string) ($params['chat_bg_fit'] ?? ''),
                'chat_bg_overlay' => (int) ($params['chat_bg_overlay'] ?? -1),
            ],
        ]);
        $rendered = self::render($theme);
        return self::cssVars($rendered) . "\n" . self::chatBgCss($rendered);
    }

    /** Build a full rendered theme from raw params (live preview helpers). */
    public static function preview(array $params): array
    {
        return self::render(self::normalize([
            'preset' => (string) ($params['preset'] ?? ''),
            'mode' => (string) ($params['mode'] ?? ''),
            'overrides' => [
                'accent' => (string) ($params['accent'] ?? ''),
                'sidebar' => (string) ($params['sidebar'] ?? ''),
                'font' => (string) ($params['font'] ?? ''),
                'chat_bg_color' => (string) ($params['chat_bg_color'] ?? ''),
                'chat_bg_image' => (string) ($params['chat_bg_image'] ?? ''),
                'chat_bg_fit' => (string) ($params['chat_bg_fit'] ?? ''),
                'chat_bg_overlay' => (int) ($params['chat_bg_overlay'] ?? -1),
            ],
        ]));
    }

    /**
     * Compute both palettes for a sidebar base + accent.
     * Values are "R G B" triplets (the format Tailwind's rgb(var(--x)/alpha) needs).
     *
     * @return array{dark:array<string,string>,light:array<string,string>}
     */
    private static function palette(string $sidebarHex, string $accentHex): array
    {
        [$sh, $ss, $sl] = self::hexToHsl($sidebarHex);
        [$ah, $as, $al] = self::hexToHsl($accentHex);

        $dark = [];
        foreach (self::DARK_SURFACE as $shade => [$mult, $floor]) {
            $l = max($floor, $sl * $mult);
            $s = min(0.55, $ss * (self::DARK_SAT[$shade] ?? 1.0));
            $dark[$shade] = self::rgbToTriplet(self::hslToRgb($sh, $s, min(0.96, $l)));
        }
        foreach (self::DARK_TEXT as $shade => $l) {
            $s = min(0.55, $ss * (self::DARK_SAT[$shade] ?? 0.5));
            $dark[$shade] = self::rgbToTriplet(self::hslToRgb($sh, $s, $l));
        }
        $dark['blurple'] = self::rgbToTriplet(self::hslToRgb($ah, $as, $al));
        $dark['blurple_dark'] = self::rgbToTriplet(self::hslToRgb($ah, $as, max(0.06, $al * 0.82)));
        $dark['sidebar'] = self::rgbToTriplet(self::hslToRgb($sh, $ss, $sl));
        $dark['sidebar_hover'] = self::rgbToTriplet(self::hslToRgb($sh, $ss, min(0.55, $sl + 0.05)));

        $light = [];
        foreach (self::LIGHT_TEXT as $shade => $l) {
            $light[$shade] = self::rgbToTriplet(self::hslToRgb($sh, 0.07, $l));
        }
        foreach (self::LIGHT_SURFACE as $shade => $t) {
            $light[$shade] = self::rgbToTriplet(self::mixHex($sidebarHex, 'ffffff', $t));
        }
        $light['blurple'] = self::rgbToTriplet(self::hslToRgb($ah, $as, max(0.08, $al * 0.88)));
        $light['blurple_dark'] = self::rgbToTriplet(self::hslToRgb($ah, $as, max(0.06, $al * 0.76)));
        $light['sidebar'] = self::rgbToTriplet(self::mixHex($sidebarHex, 'ffffff', 0.92));
        $light['sidebar_hover'] = self::rgbToTriplet(self::mixHex($sidebarHex, 'ffffff', 0.86));

        return ['dark' => $dark, 'light' => $light];
    }

    /** Validate/normalize a hex colour. Returns '#rrggbb' or ''. */
    public static function hex(mixed $v): string
    {
        $s = strtolower(trim((string) $v));
        $s = ltrim($s, '#');
        if (preg_match('/^[0-9a-f]{3}$/', $s)) {
            $s = $s[0] . $s[0] . $s[1] . $s[1] . $s[2] . $s[2];
        }
        if (!preg_match('/^[0-9a-f]{6}$/', $s)) {
            return '';
        }
        return '#' . $s;
    }

    /** Validate a local asset path for chat backgrounds. Returns the path or ''. */
    public static function localPath(mixed $v): string
    {
        $s = trim((string) $v);
        if ($s === '' || str_starts_with($s, '//') || str_contains($s, '..') || str_contains($s, '\\')) {
            return '';
        }
        if (!preg_match('#^/[a-zA-Z0-9_./\-\s]+$#', $s)) {
            return '';
        }
        return $s;
    }

    public static function hexToRgb(string $hex): array
    {
        $hex = ltrim(self::hex($hex) ?: $hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    private static function hexToHsl(string $hex): array
    {
        [$r, $g, $b] = self::hexToRgb($hex);
        $r /= 255;
        $g /= 255;
        $b /= 255;
        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;
        $d = $max - $min;
        if ($d < 0.0001) {
            return [0.0, 0.0, $l];
        }
        $s = $d / (1 - abs(2 * $l - 1));
        if ($max === $r) {
            $h = fmod(($g - $b) / $d, 6);
        } elseif ($max === $g) {
            $h = ($b - $r) / $d + 2;
        } else {
            $h = ($r - $g) / $d + 4;
        }
        $h *= 60;
        if ($h < 0) {
            $h += 360;
        }
        return [$h, $s, $l];
    }

    private static function hslToRgb(float $h, float $s, float $l): array
    {
        $h = fmod($h, 360);
        if ($h < 0) {
            $h += 360;
        }
        $c = (1 - abs(2 * $l - 1)) * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $l - $c / 2;
        if ($h < 60) {
            [$r, $g, $b] = [$c, $x, 0];
        } elseif ($h < 120) {
            [$r, $g, $b] = [$x, $c, 0];
        } elseif ($h < 180) {
            [$r, $g, $b] = [0, $c, $x];
        } elseif ($h < 240) {
            [$r, $g, $b] = [0, $x, $c];
        } elseif ($h < 300) {
            [$r, $g, $b] = [$x, 0, $c];
        } else {
            [$r, $g, $b] = [$c, 0, $x];
        }
        return [
            (int) round(($r + $m) * 255),
            (int) round(($g + $m) * 255),
            (int) round(($b + $m) * 255),
        ];
    }

    private static function rgbToTriplet(array $rgb): string
    {
        return (int) max(0, min(255, $rgb[0])) . ' ' . (int) max(0, min(255, $rgb[1])) . ' ' . (int) max(0, min(255, $rgb[2]));
    }

    private static function tripletToHex(string $triplet): string
    {
        $parts = array_map('intval', preg_split('/\s+/', trim($triplet)));
        $f = fn ($v): string => str_pad(dechex(max(0, min(255, $v))), 2, '0', STR_PAD_LEFT);
        return $f($parts[0] ?? 0) . $f($parts[1] ?? 0) . $f($parts[2] ?? 0);
    }

    /** Linear mix of two hex colours (t=0 -> $a, t=1 -> $b). */
    private static function mixHex(string $a, string $b, float $t): array
    {
        [$ar, $ag, $ab] = self::hexToRgb($a);
        [$br, $bg, $bb] = self::hexToRgb($b);
        return [
            (int) round($ar + ($br - $ar) * $t),
            (int) round($ag + ($bg - $ag) * $t),
            (int) round($ab + ($bb - $ab) * $t),
        ];
    }

    /** Rotate a hex colour's hue (keeps saturation/lightness). */
    private static function rotateHex(string $hex, float $degrees): string
    {
        [$h, $s, $l] = self::hexToHsl($hex);
        $rgb = self::hslToRgb($h + $degrees, $s, $l);
        $f = fn ($v): string => str_pad(dechex(max(0, min(255, $v))), 2, '0', STR_PAD_LEFT);
        return $f($rgb[0]) . $f($rgb[1]) . $f($rgb[2]);
    }

    /** Store the global theme (normalized) into server_config. */
    public static function saveGlobal(array $theme): void
    {
        config_set('theme', json_encode(self::normalize($theme)));
    }

    /** Save a user's personal theme (always a valid preset + overrides). The
     *  saved mode is mirrored to users.theme so the quick header toggle stays
     *  in sync with the profile theme editor. */
    public static function saveUser(array $user, array $theme): void
    {
        $t = self::normalize($theme);
        Database::query(
            'UPDATE users SET theme_json = ?, theme = ? WHERE id = ?',
            [json_encode($t), $t['mode'] === '' ? '' : $t['mode'], (int) $user['id']]
        );
    }

    /** Clear a user's personal theme so they fall back to the server theme. */
    public static function clearUser(array $user): void
    {
        Database::query('UPDATE users SET theme_json = NULL, theme = "" WHERE id = ?', [(int) $user['id']]);
    }

    /** The visible theme-color for the PWA manifest (server theme's dark surface). */
    public static function manifestColors(): array
    {
        $r = self::render(self::globalTheme());
        $hexOf = fn (string $triplet): string => '#' . self::tripletToHex($triplet);
        return [
            'theme_color' => $hexOf($r['dark']['d_900']),
            'background_color' => $hexOf($r['dark']['d_800']),
        ];
    }
}
