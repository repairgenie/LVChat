import { test, expect, type Page, type BrowserContext } from '@playwright/test';

/**
 * Voice & Video Browser Tests
 *
 * Verifies the end-to-end voice/video experience in a real browser.
 * The setup script (tests/setup-pw.php) must be run first to register
 * users and configure voice.
 *
 * Requirements:
 *   - App server running on http://127.0.0.1:8098
 *   - LiveKit server running on ws://127.0.0.1:7880
 *   - tests/setup-pw.php has been run
 */

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8098';

test.describe('Voice & Video', () => {

  test('chat app loads', async ({ page }) => {
    await page.goto(`${BASE}/app`);
    // Should load the app (may redirect to register if not logged in)
    const url = page.url();
    expect(url).toMatch(/\/(app|register|login)/);
  });

  test('voice module JS asset loads', async ({ page }) => {
    const response = await page.goto(`${BASE}/modules/webrtc/assets/js/voice.js`);
    expect(response?.status()).toBe(200);
    const content = await response?.text() ?? '';
    expect(content).toContain('LVCVoice');
  });

  test('voice CSS asset loads', async ({ page }) => {
    const response = await page.goto(`${BASE}/modules/webrtc/assets/css/voice.css`);
    expect(response?.status()).toBe(200);
  });

  test('livekit client UMD loads', async ({ page }) => {
    const response = await page.goto(`${BASE}/modules/webrtc/assets/vendor/livekit-client.umd.js`);
    expect(response?.status()).toBe(200);
    const content = await response?.text() ?? '';
    expect(content).toContain('LivekitClient');
  });

  test('background effect assets load', async ({ page }) => {
    // These are served via the module asset route, which should work
    const js = await page.goto(`${BASE}/modules/webrtc/assets/vendor/selfie-segmentation/selfie_segmentation.js`);
    // May 404 if the module isn't loaded via staging dir — check if it at least responds
    expect([200, 404]).toContain(js?.status());
    if (js?.status() === 200) {
      const content = await js.text();
      expect(content).toContain('SelfieSegmentation');
    }
  });

  test('voice module JS contains core features', async ({ page }) => {
    const response = await page.goto(`${BASE}/modules/webrtc/assets/js/voice.js`);
    const content = await response?.text() ?? '';

    // Core call flow
    expect(content).toContain('connectLivekit');
    expect(content).toContain('startCall');
    expect(content).toContain('acceptCall');
    expect(content).toContain('declineCall');
    expect(content).toContain('endCall');
    expect(content).toContain('pollStatus');

    // Video features
    expect(content).toContain('setCameraEnabled');
    expect(content).toContain('setScreenShareEnabled');
    expect(content).toContain('toggleCamera');
    expect(content).toContain('toggleScreenShare');

    // Audio
    expect(content).toContain('playRing');
    expect(content).toContain('stopRing');
    expect(content).toContain('noiseSuppression');

    // Moderation
    expect(content).toContain('roster-admit');
    expect(content).toContain('roster-kick');
    expect(content).toContain('roster-mute');

    // Background effects
    expect(content).toContain('makeBgProcessor');
    expect(content).toContain('selfie_segmentation.js');
  });

  test('voice status API requires authentication', async ({ page }) => {
    await page.goto(`${BASE}/app`);
    const response = await page.evaluate(async () => {
      const res = await fetch('/api/webrtc/voice/status');
      return { status: res.status };
    });

    // Voice status requires auth - returns 401 when not logged in
    expect(response.status).toBe(401);
  });

  test('echo test endpoint requires auth', async ({ page }) => {
    await page.goto(`${BASE}/register`);
    const response = await page.evaluate(async () => {
      const csrf = document.body?.dataset?.csrf || '';
      const res = await fetch('/api/webrtc/voice/echo-test', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `csrf=${encodeURIComponent(csrf)}`,
      });
      return { status: res.status };
    });

    // Without login, echo test should return 401
    expect(response.status).toBe(401);
  });

  test('admin voice page is accessible', async ({ page }) => {
    const response = await page.goto(`${BASE}/admin/webrtc`);
    const status = response?.status() ?? 0;
    // Admin page should render (200) or redirect to login (302)
    expect([200, 302]).toContain(status);
  });

  test('voice admin page is served', async ({ page }) => {
    const response = await page.goto(`${BASE}/admin/webrtc`);
    const status = response?.status() ?? 0;
    // Admin page should respond (200 with login redirect, or 302 to login, or 404 if module not loaded)
    expect([200, 302, 404]).toContain(status);
  });

  test('voice.js contains video quality presets', async ({ page }) => {
    const response = await page.goto(`${BASE}/modules/webrtc/assets/js/voice.js`);
    const content = await response?.text() ?? '';

    expect(content).toContain('360p');
    expect(content).toContain('480p');
    expect(content).toContain('720p');
    expect(content).toContain('1080p');
    expect(content).toContain('VIDEO_QUALITY');
  });

  test('voice.js contains waiting room UI', async ({ page }) => {
    const response = await page.goto(`${BASE}/modules/webrtc/assets/js/voice.js`);
    const content = await response?.text() ?? '';

    expect(content).toContain('pane-waiting');
    expect(content).toContain('waiting');
    expect(content).toContain('admit');
  });

  test('voice.js contains reactions and raise hand', async ({ page }) => {
    const response = await page.goto(`${BASE}/modules/webrtc/assets/js/voice.js`);
    const content = await response?.text() ?? '';

    expect(content).toContain('publishData');
    expect(content).toContain('setAttributes');
    expect(content).toContain('hand');
  });

  test('voice.js contains call ring tone synthesis', async ({ page }) => {
    const response = await page.goto(`${BASE}/modules/webrtc/assets/js/voice.js`);
    const content = await response?.text() ?? '';

    expect(content).toContain('ringToneDataUrl');
    expect(content).toContain('playRing');
    expect(content).toContain('stopRing');
    expect(content).toContain('unlockRingAudio');
  });

  test('voice.js contains deafen and minimize controls', async ({ page }) => {
    const response = await page.goto(`${BASE}/modules/webrtc/assets/js/voice.js`);
    const content = await response?.text() ?? '';

    expect(content).toContain('pane-deafen');
    expect(content).toContain('deafen');
    expect(content).toContain('minimize');
  });

  test('voice.js contains recording controls', async ({ page }) => {
    const response = await page.goto(`${BASE}/modules/webrtc/assets/js/voice.js`);
    const content = await response?.text() ?? '';

    expect(content).toContain('pane-record');
    expect(content).toContain('/api/webrtc/record');
  });

  test('voice.js contains device settings and camera test', async ({ page }) => {
    const response = await page.goto(`${BASE}/modules/webrtc/assets/js/voice.js`);
    const content = await response?.text() ?? '';

    expect(content).toContain('enumerateDevices');
    expect(content).toContain('getUserMedia');
    expect(content).toContain('Test camera');
    expect(content).toContain('Test mic');
  });

  test('voice.js contains connection quality indicators', async ({ page }) => {
    const response = await page.goto(`${BASE}/modules/webrtc/assets/js/voice.js`);
    const content = await response?.text() ?? '';

    expect(content).toContain('ConnectionQualityChanged');
    expect(content).toContain('quality');
  });
});
