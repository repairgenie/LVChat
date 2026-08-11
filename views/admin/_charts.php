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


// SVG chart helpers for the admin dashboard. Zero dependencies, ships offline:
// no CDN, no JS, no build step. Series are ['label' => string, 'value' => int].
// The palette and axis colours match the Discord-style theme.

if (!function_exists('chart_palette')) {
    function chart_palette(): array
    {
        return ['#5865f2', '#f59e0b', '#10b981', '#ef4444', '#06b6d4', '#a855f7', '#ec4899', '#84cc16', '#f97316', '#14b8a6'];
    }

    function chart_empty(string $message = 'No data in this range yet'): string
    {
        return '<div class="flex items-center justify-center h-40 rounded-md border border-dashed border-discord-700 text-sm text-discord-500 px-4 text-center">' . h($message) . '</div>';
    }

    function chart_day_label(string $day): string
    {
        $ts = strtotime($day);
        if ($ts === false) {
            return h($day);
        }
        return gmdate('Y', $ts) !== gmdate('Y') ? gmdate('Y-m-d', $ts) : gmdate('M j', $ts);
    }

    /** Line/area chart for time series (handles long ranges by downsampling labels). */
    function chart_line(array $series, array $opts = []): string
    {
        $count = count($series);
        if ($count === 0) {
            return chart_empty($opts['empty'] ?? 'No data in this range yet');
        }
        $color = $opts['color'] ?? '#5865f2';
        $width = (int) ($opts['width'] ?? 560);
        $height = (int) ($opts['height'] ?? 170);
        $fill = (bool) ($opts['fill'] ?? true);
        $padL = 36;
        $padR = 8;
        $padT = 8;
        $padB = 20;
        $innerW = $width - $padL - $padR;
        $innerH = $height - $padT - $padB;
        $max = max(1, max(array_column($series, 'value')));
        $stepX = $count > 1 ? $innerW / ($count - 1) : 0;
        $points = [];
        $circles = '';
        foreach ($series as $i => $s) {
            $x = $padL + ($count > 1 ? $i * $stepX : $innerW / 2);
            $y = $padT + $innerH - (($s['value'] / $max) * $innerH);
            $points[] = round($x, 1) . ',' . round($y, 1);
            if ($count <= 120) {
                $circles .= '<circle cx="' . round($x, 1) . '" cy="' . round($y, 1) . '" r="3" fill="' . $color . '"><title>' . h($s['label']) . ': ' . (int) $s['value'] . '</title></circle>';
            }
        }
        $grid = '';
        for ($g = 0; $g <= 4; $g++) {
            $y = $padT + $innerH - ($g / 4) * $innerH;
            $val = (int) round($max * ($g / 4));
            $grid .= '<line x1="' . $padL . '" y1="' . round($y, 1) . '" x2="' . ($width - $padR) . '" y2="' . round($y, 1) . '" stroke="#3b3f52" stroke-width="1" />';
            $grid .= '<text x="' . ($padL - 6) . '" y="' . round($y + 3, 1) . '" text-anchor="end" font-size="9" fill="#8e8fa8">' . $val . '</text>';
        }
        $xLabels = '';
        $every = max(1, (int) ceil($count / 9));
        foreach ($series as $i => $s) {
            if ($i % $every !== 0 && $i !== $count - 1) {
                continue;
            }
            $x = $padL + ($count > 1 ? $i * $stepX : $innerW / 2);
            $xLabels .= '<text x="' . round($x, 1) . '" y="' . ($height - 6) . '" text-anchor="middle" font-size="9" fill="#8e8fa8">' . chart_day_label((string) $s['label']) . '</text>';
        }
        $area = '';
        if ($fill) {
            $firstX = (float) explode(',', $points[0])[0];
            $lastX = (float) explode(',', $points[$count - 1])[0];
            $bottom = $padT + $innerH;
            $area = '<polygon points="' . $firstX . ',' . $bottom . ' ' . implode(' ', $points) . ' ' . $lastX . ',' . $bottom . '" fill="' . $color . '" opacity="0.12" />';
        }
        $polyline = '<polyline fill="none" stroke="' . $color . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" points="' . implode(' ', $points) . '" />';
        return '<svg viewBox="0 0 ' . $width . ' ' . $height . '" width="100%" height="auto" role="img" aria-label="line chart">' . $grid . $area . $polyline . $circles . $xLabels . '</svg>';
    }

    /** Vertical bars for fixed-size buckets (hours of day, weekdays). */
    function chart_vbars(array $series, array $opts = []): string
    {
        $count = count($series);
        if ($count === 0) {
            return chart_empty($opts['empty'] ?? 'No data in this range yet');
        }
        $color = $opts['color'] ?? '#5865f2';
        $width = (int) ($opts['width'] ?? 560);
        $height = (int) ($opts['height'] ?? 170);
        $padL = 34;
        $padR = 6;
        $padT = 8;
        $padB = 20;
        $innerW = $width - $padL - $padR;
        $innerH = $height - $padT - $padB;
        $max = max(1, max(array_column($series, 'value')));
        $slot = $innerW / $count;
        $barW = max(3, min(22, $slot * 0.72));
        $grid = '';
        for ($g = 0; $g <= 4; $g++) {
            $y = $padT + $innerH - ($g / 4) * $innerH;
            $grid .= '<line x1="' . $padL . '" y1="' . round($y, 1) . '" x2="' . ($width - $padR) . '" y2="' . round($y, 1) . '" stroke="#3b3f52" stroke-width="1" />';
            $val = (int) round($max * ($g / 4));
            $grid .= '<text x="' . ($padL - 6) . '" y="' . round($y + 3, 1) . '" text-anchor="end" font-size="9" fill="#8e8fa8">' . $val . '</text>';
        }
        $bars = '';
        $labels = '';
        $every = max(1, (int) ceil($count / 13));
        foreach ($series as $i => $s) {
            $x = $padL + $i * $slot + ($slot - $barW) / 2;
            $h = max(1, ($s['value'] / $max) * $innerH);
            $y = $padT + $innerH - $h;
            $bars .= '<rect x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . round($barW, 1) . '" height="' . round($h, 1) . '" rx="3" fill="' . $color . '" opacity="' . ($s['value'] > 0 ? 1 : 0.25) . '"><title>' . h($s['label']) . ': ' . (int) $s['value'] . '</title></rect>';
            if ($i % $every === 0 || $i === $count - 1) {
                $cx = $padL + $i * $slot + $slot / 2;
                $labels .= '<text x="' . round($cx, 1) . '" y="' . ($height - 6) . '" text-anchor="middle" font-size="9" fill="#8e8fa8">' . h($s['label']) . '</text>';
            }
        }
        return '<svg viewBox="0 0 ' . $width . ' ' . $height . '" width="100%" height="auto" role="img" aria-label="bar chart">' . $grid . $bars . $labels . '</svg>';
    }

    /** Horizontal ranking bars (top users, channels, filter triggers...). */
    function chart_hbars(array $series, array $opts = []): string
    {
        $count = count($series);
        if ($count === 0) {
            return chart_empty($opts['empty'] ?? 'No data in this range yet');
        }
        $color = $opts['color'] ?? '#5865f2';
        $width = (int) ($opts['width'] ?? 560);
        $rowH = (int) ($opts['rowH'] ?? 26);
        $padL = (int) ($opts['padL'] ?? 150);
        $height = $count * $rowH + 8;
        $max = max(1, max(array_column($series, 'value')));
        $barMax = $width - $padL - 52;
        $out = '';
        foreach ($series as $i => $s) {
            $y = 6 + $i * $rowH;
            $val = (int) $s['value'];
            $barW = $max > 0 ? max(2, ($val / $max) * $barMax) : 0;
            $label = mb_strimwidth((string) $s['label'], 0, 21, '…');
            $out .= '<text x="0" y="' . ($y + 12) . '" font-size="11" fill="#b5bac1">' . h($label) . '</text>';
            $out .= '<rect x="' . $padL . '" y="' . ($y + 2) . '" width="' . round($barW, 1) . '" height="' . ($rowH - 8) . '" rx="4" fill="' . $color . '" opacity="0.9"><title>' . h($s['label']) . ': ' . $val . '</title></rect>';
            $out .= '<text x="' . ($padL + $barW + 6) . '" y="' . ($y + 12) . '" font-size="10" fill="#8e8fa8">' . $val . '</text>';
        }
        return '<svg viewBox="0 0 ' . $width . ' ' . $height . '" width="100%" height="auto" role="img" aria-label="horizontal bar chart">' . $out . '</svg>';
    }

    /** Donut breakdown with a legend (moderation mix, ban types, reports...). */
    function chart_donut(array $series, array $opts = []): string
    {
        $count = count($series);
        if ($count === 0) {
            return chart_empty($opts['empty'] ?? 'No data in this range yet');
        }
        $colors = $opts['colors'] ?? chart_palette();
        $total = (int) array_sum(array_column($series, 'value'));
        if ($total <= 0) {
            return chart_empty($opts['empty'] ?? 'No data in this range yet');
        }
        $c = 2 * pi() * 60;
        $slices = '';
        $legend = '';
        $cumulative = 0.0;
        foreach ($series as $i => $s) {
            $val = (int) $s['value'];
            $frac = $val / $total;
            $dash = $frac * $c;
            $color = $colors[$i % count($colors)];
            $slices .= '<circle cx="84" cy="84" r="60" fill="none" stroke="' . $color . '" stroke-width="26"
                stroke-dasharray="' . round($dash, 2) . ' ' . $c . '" stroke-dashoffset="' . round($c - $cumulative, 2) . '"
                transform="rotate(-90 84 84)"><title>' . h($s['label']) . ': ' . $val . ' (' . round($frac * 100) . '%)</title></circle>';
            $cumulative += $dash;
            $pct = round($frac * 100);
            $legend .= '<div class="flex items-center gap-2 text-sm"><span class="w-2.5 h-2.5 rounded-sm shrink-0" style="background:' . $color . '"></span><span class="text-discord-300">' . h($s['label']) . '</span><span class="text-discord-500 ml-auto">' . $val . ' · ' . $pct . '%</span></div>';
        }
        return '<div class="flex items-center gap-6 flex-wrap">'
            . '<svg viewBox="0 0 168 168" width="132" height="132" role="img" aria-label="donut chart">' . $slices
            . '<text x="84" y="81" text-anchor="middle" font-size="22" font-weight="600" fill="#fff">' . $total . '</text>'
            . '<text x="84" y="96" text-anchor="middle" font-size="9" fill="#8e8fa8">total</text></svg>'
            . '<div class="flex-1 min-w-[160px] space-y-1.5">' . $legend . '</div></div>';
    }
}
