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



/**
 * EventLogService — generates chat logs for concluded events.
 *
 * Produces a ZIP archive containing:
 *   - chat-log.txt       (IRC-style plain text log, same format as admin logs)
 *   - chat-log.pdf       (PDF with images embedded inline)
 *   - attachments/       (image files posted during the event)
 */
final class EventLogService
{
    /**
     * Build the full event log ZIP.
     * Returns the path to the temp ZIP file, or null on failure.
     */
    public static function buildLogZip(array $event, string $channelName, string $channelSlug): ?string
    {
        if (!class_exists('ZipArchive')) {
            return null;
        }

        $rows = self::fetchLogRows($channelName, $event['started_at'] ?? null, $event['ended_at'] ?? null);
        $logTxt = self::formatLogText($rows, $channelName, $event);

        // Collect image attachments from the event.
        $images = self::collectImages($rows);

        $zipPath = tempnam(sys_get_temp_dir(), 'evtlog_');
        if ($zipPath === false) {
            return null;
        }
        // Ensure .zip extension.
        rename($zipPath, $zipPath .= '.zip');

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($zipPath);
            return null;
        }

        // Add the plain text log.
        $zip->addFromString('chat-log.txt', $logTxt);

        // Add image attachments to attachments/ directory.
        foreach ($images as $idx => $img) {
            $ext = pathinfo($img['path'], PATHINFO_EXTENSION) ?: 'webp';
            $zip->addFile($img['path'], 'attachments/img_' . str_pad((string) ($idx + 1), 3, '0', STR_PAD_LEFT) . '.' . $ext);
        }

        // Generate the PDF with inline images.
        $pdfPath = self::generatePdf($rows, $channelName, $event, $images);
        if ($pdfPath) {
            $zip->addFile($pdfPath, 'chat-log.pdf');
        }

        $zip->close();

        // Clean up temp PDF.
        if ($pdfPath) {
            @unlink($pdfPath);
        }

        return $zipPath;
    }

    /**
     * Email the event log ZIP to the founder.
     */
    public static function emailLog(array $event, array $founder, string $zipPath): bool
    {
        if (!Mailer::configured() || empty($founder['email'])) {
            return false;
        }

        $siteName = config_get('site_name', 'LVChat');
        $slug = preg_replace('/[^a-z0-9]+/i', '-', str_replace('#', '', $event['title']));
        $filename = 'event-' . $slug . '-log-' . gmdate('Y-m-d') . '.zip';

        $text = "The chat log for your event \"{$event['title']}\" is attached.\n\n"
            . "The archive contains:\n"
            . "  - chat-log.txt (plain text log)\n"
            . "  - chat-log.pdf (formatted log with images)\n"
            . "  - attachments/ (image files posted during the event)\n";

        $html = '<div style="font-family:sans-serif;max-width:600px;margin:0 auto">'
            . '<h2 style="color:#5865F2">Event Chat Log</h2>'
            . '<p>The chat log for your event <strong>' . h($event['title']) . '</strong> is attached.</p>'
            . '<p>The archive contains:</p>'
            . '<ul>'
            . '<li>chat-log.txt (plain text log)</li>'
            . '<li>chat-log.pdf (formatted log with images)</li>'
            . '<li>attachments/ (image files posted during the event)</li>'
            . '</ul>'
            . '</div>';

        $result = Mailer::sendWithAttachment(
            $founder['email'],
            "Event Log: {$event['title']}",
            $text,
            $html,
            $zipPath,
            $filename
        );
        // sendWithAttachment returns an array, not a bool — convert before the
        // : bool return type. Returning the raw array used to throw a TypeError
        // AFTER the mail was delivered, aborting the cron tick before the event
        // was marked ended — so the exact same email went out on every tick.
        return !empty($result['ok']);
    }

    /** Fetch chat log rows for the channel name, bounded by event time range. */
    private static function fetchLogRows(string $channelName, ?string $startedAt = null, ?string $endedAt = null): array
    {
        $sql = 'SELECT * FROM chat_logs WHERE channel_name = ?';
        $params = [$channelName];
        if ($startedAt) {
            $sql .= ' AND created_at >= ?';
            $params[] = $startedAt;
        }
        if ($endedAt) {
            $sql .= ' AND created_at <= ?';
            $params[] = $endedAt . ' 23:59:59';
        }
        $sql .= ' ORDER BY id ASC LIMIT 50000';
        return Database::all($sql, $params);
    }

    /** Format rows into IRC-style plain text (same format as admin logs). */
    private static function formatLogText(array $rows, string $channelName, array $event): string
    {
        $startDate = $event['started_at'] ? substr($event['started_at'], 0, 10) : gmdate('Y-m-d');
        $endDate = $event['ended_at'] ? substr($event['ended_at'], 0, 10) : $startDate;

        $start = date('g:i A', strtotime($startDate . ' 00:00:00'));
        $end = date('g:i A', strtotime($endDate . ' 23:59:00'));
        $lines = ['#' . $channelName . ' - ' . $startDate . ' ' . $start . ' - ' . $endDate . ' ' . $end . ' - ' . $event['title']];

        foreach ($rows as $r) {
            $time = date('g:i:s A', strtotime($r['created_at'] . ' UTC'));
            $user = (string) $r['username'] . ((int) ($r['guest'] ?? 0) === 1 ? ' (guest)' : '');
            $content = (string) $r['content'];
            switch ($r['kind']) {
                case 'message':
                    $lines[] = $time . ' - ' . $user . ' - ' . $content;
                    break;
                case 'action':
                    $lines[] = $time . ' - * ' . $user . ' ' . $content;
                    break;
                case 'image':
                    $lines[] = $time . ' - * ' . $user . ' posts an image: ' . $content;
                    break;
                case 'topic':
                    $lines[] = $time . ' - -Topic Changed to ' . $content . ' by ' . $user;
                    break;
                case 'ban':
                    $lines[] = $time . ' - -' . $content;
                    break;
                case 'join':
                    $lines[] = $time . ' - -' . $content;
                    break;
                case 'part':
                    $lines[] = $time . ' - -' . $content;
                    break;
                case 'kick':
                    $lines[] = $time . ' - -' . $content;
                    break;
                default:
                    $lines[] = $time . ' - -' . $content;
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /** Collect image files referenced in the chat log. */
    private static function collectImages(array $rows): array
    {
        $images = [];
        $seen = [];
        foreach ($rows as $r) {
            if ($r['kind'] !== 'image') {
                continue;
            }
            // Content contains the image URL or path.
            $content = (string) $r['content'];
            $path = self::resolveImagePath($content);
            if ($path && !isset($seen[$path])) {
                $seen[$path] = true;
                $images[] = ['path' => $path, 'content' => $content];
            }
        }
        return $images;
    }

    /** Resolve an image URL/path to an absolute filesystem path. */
    private static function resolveImagePath(string $content): ?string
    {
        // Content could be a URL like /uploads/xxx.webp or just a filename.
        if ($content === '') {
            return null;
        }
        // Strip any leading domain to get the path.
        $path = preg_replace('#^https?://[^/]+#', '', $content);
        if ($path === '' || $path[0] !== '/') {
            $path = '/uploads/' . $path;
        }
        $fullPath = ROOT . '/public' . $path;
        return is_file($fullPath) ? $fullPath : null;
    }

    /**
     * Generate a PDF with the chat log and inline images.
     * Returns the path to the temp PDF file, or null on failure.
     */
    private static function generatePdf(array $rows, string $channelName, array $event, array $images): ?string
    {
        if (!class_exists('TCPDF')) {
            return null;
        }

        try {
            $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
            $pdf->SetCreator('LVChat');
            $pdf->SetTitle('Event Log: ' . $event['title']);
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->SetMargins(15, 15, 15);
            $pdf->SetFont('helvetica', '', 9);
            $pdf->AddPage();

            // Title.
            $pdf->SetFont('helvetica', 'B', 14);
            $pdf->Cell(0, 8, '#' . $channelName . ' - ' . $event['title'], 0, 1);
            $pdf->SetFont('helvetica', '', 9);
            $pdf->Cell(0, 5, 'Event log generated ' . gmdate('Y-m-d H:i:s') . ' UTC', 0, 1);
            $pdf->Ln(4);

            // Build a map of image paths for inline rendering.
            $imageMap = [];
            foreach ($images as $img) {
                $imageMap[$img['content']] = $img['path'];
            }

            // Log lines.
            foreach ($rows as $r) {
                $time = date('g:i:s A', strtotime($r['created_at'] . ' UTC'));
                $user = (string) $r['username'];
                $content = (string) $r['content'];
                $kind = (string) $r['kind'];

                $line = '';
                switch ($kind) {
                    case 'message':
                        $line = $time . ' - ' . $user . ': ' . $content;
                        break;
                    case 'action':
                        $line = $time . ' - * ' . $user . ' ' . $content;
                        break;
                    case 'image':
                        $line = $time . ' - * ' . $user . ' posts an image:';
                        $pdf->SetFont('helvetica', '', 9);
                        $pdf->MultiCell(0, 4, $line, 0, 'L');
                        // Try to embed the image inline.
                        $imgPath = $imageMap[$content] ?? null;
                        if (!$imgPath) {
                            // Try resolving from content directly.
                            $imgPath = self::resolveImagePath($content);
                        }
                        if ($imgPath && is_file($imgPath)) {
                            $imgWidth = 80;
                            $imgHeight = 60;
                            // Try to get actual dimensions.
                            $info = @getimagesize($imgPath);
                            if ($info) {
                                $ratio = $info[0] / $info[1];
                                $imgHeight = $imgWidth / $ratio;
                                if ($imgHeight > 120) {
                                    $imgHeight = 120;
                                    $imgWidth = $imgHeight * $ratio;
                                }
                            }
                            $pdf->Image($imgPath, '', '', $imgWidth, $imgHeight, '', '', '', false, 300);
                            $pdf->Ln($imgHeight + 2);
                        }
                        continue 2;
                    default:
                        $line = $time . ' - -' . $content;
                }

                $pdf->SetFont('helvetica', '', 9);
                $pdf->MultiCell(0, 4, $line, 0, 'L');
            }

            $pdfPath = tempnam(sys_get_temp_dir(), 'evtlog_pdf_');
            if ($pdfPath === false) {
                return null;
            }
            $pdf->Output($pdfPath, 'F');
            return $pdfPath;
        } catch (\Throwable $e) {
            error_log('[EventLogService] PDF generation failed: ' . $e->getMessage());
            return null;
        }
    }
}
