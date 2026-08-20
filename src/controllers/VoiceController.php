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
 * Voice API — proxies audio to/from the local STT and TTS sidecars.
 *
 * Endpoints:
 *   POST /api/voice/transcribe  — upload audio, get text back
 *   POST /api/voice/speak       — send text, get WAV audio back
 *   GET  /api/voice/config      — feature flags for the UI
 */
final class VoiceController
{
    private const STT_TIMEOUT = 30;
    private const TTS_TIMEOUT = 15;

    private static function requireUser(): array
    {
        $u = Auth::user();
        if (!$u) {
            json_out(['error' => 'Not authenticated.'], 401);
        }
        return $u;
    }

    private static function requireCsrf(): void
    {
        if (Csrf::bearerAuthorized()) {
            return;
        }
        $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
        if (!is_string($sent) || !hash_equals(Csrf::token(), $sent)) {
            json_out(['error' => 'CSRF token mismatch.'], 419);
        }
    }

    /** GET /api/voice/config — returns feature flags for client UIs. */
    public static function config(): void
    {
        self::requireUser();
        json_out([
            'stt_enabled'  => config_get('voice_stt_enabled', '0') === '1',
            'tts_enabled'  => config_get('voice_tts_enabled', '0') === '1',
            'force_local'  => config_get('voice_force_local', '0') === '1',
        ]);
    }

    /** POST /api/voice/transcribe — upload audio, get text back. */
    public static function transcribe(): void
    {
        $user = self::requireUser();
        self::requireCsrf();

        if (config_get('voice_stt_enabled', '0') !== '1') {
            json_out(['error' => 'Speech-to-text is not enabled.'], 403);
        }

        if (class_exists('SaaSService') && !SaaSService::feature($user, 'voice_stt')) {
            json_out(['error' => 'Speech-to-text is not available on your plan.'], 403);
        }

        $sidecarUrl = rtrim((string) config_get('voice_stt_sidecar_url', 'http://127.0.0.1:8787'), '/') . '/transcribe';

        // Accept the audio upload from the browser
        $audioData = null;
        $audioType = 'audio/webm';
        if (!empty($_FILES['audio'])) {
            $audioData = file_get_contents($_FILES['audio']['tmp_name']);
            $audioType = $_FILES['audio']['type'] ?? 'audio/webm';
        } elseif (isset($_POST['audio_data'])) {
            // Base64-encoded audio (used by some clients)
            $audioData = base64_decode($_POST['audio_data'], true);
            $audioType = $_POST['audio_type'] ?? 'audio/webm';
        }

        if ($audioData === false || strlen($audioData) < 100) {
            json_out(['error' => 'No audio data received.'], 400);
        }

        // Proxy to the STT sidecar
        $boundary = 'LVChat' . bin2hex(random_bytes(16));
        $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"audio\"; filename=\"audio.webm\"\r\n"
            . "Content-Type: {$audioType}\r\n\r\n"
            . $audioData . "\r\n"
            . "--{$boundary}--\r\n";

        $ch = curl_init($sidecarUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::STT_TIMEOUT,
            CURLOPT_HTTPHEADER     => [
                "Content-Type: multipart/form-data; boundary={$boundary}",
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $error) {
            json_out(['error' => 'Could not reach the speech-to-text service.'], 503);
        }
        if ($httpCode === 503) {
            json_out(['error' => 'Speech-to-text service is starting up. Please try again.'], 503);
        }
        if ($httpCode !== 200) {
            json_out(['error' => 'Speech-to-text service returned an error.'], 502);
        }

        $data = json_decode($response, true);
        if (!is_array($data) || !isset($data['text'])) {
            json_out(['error' => 'Invalid response from speech-to-text service.'], 502);
        }

        json_out(['text' => $data['text']]);
    }

    /** POST /api/voice/speak — send text, get WAV audio back. */
    public static function speak(): void
    {
        $user = self::requireUser();
        self::requireCsrf();

        if (config_get('voice_tts_enabled', '0') !== '1') {
            json_out(['error' => 'Text-to-speech is not enabled.'], 403);
        }

        if (class_exists('SaaSService') && !SaaSService::feature($user, 'voice_tts')) {
            json_out(['error' => 'Text-to-speech is not available on your plan.'], 403);
        }

        $text = trim((string) ($_POST['text'] ?? ''));
        if ($text === '') {
            json_out(['error' => 'No text provided.'], 400);
        }
        // Limit text length
        $text = mb_substr($text, 0, 5000);

        $sidecarUrl = rtrim((string) config_get('voice_tts_sidecar_url', 'http://127.0.0.1:8788'), '/') . '/synthesize';

        // Proxy to the TTS sidecar
        $payload = json_encode(['text' => $text], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($sidecarUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TTS_TIMEOUT,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($payload),
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $error) {
            json_out(['error' => 'Could not reach the text-to-speech service.'], 503);
        }
        if ($httpCode === 503) {
            json_out(['error' => 'Text-to-speech service is starting up.'], 503);
        }
        if ($httpCode !== 200) {
            json_out(['error' => 'Text-to-speech service returned an error.'], 502);
        }

        // Stream the WAV audio back
        header('Content-Type: audio/wav');
        header('Content-Length: ' . strlen($response));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        echo $response;
        exit;
    }
}
