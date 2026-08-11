# Third-Party Notices

LVChat bundles or depends on the following third-party software. Each keeps its
own copyright and license, which are reproduced or linked below. Combining this
software with LVChat (AGPL-3.0-only) is permitted under the respective licenses.

## PHP runtime dependencies (Composer)

### workerman/workerman — MIT License
Realtime WebSocket gateway. See `vendor/workerman/workerman/LICENSE`.

## Bundled JavaScript/CSS (first-party build outputs include third-party code)

### tiptap (and @tiptap/* extensions, @tiptap/pm) — MIT License
Bundled into `public/assets/vendor/tiptap/tiptap-bundle.js` at build time.
License: MIT — https://github.com/ueberdosis/tiptap/blob/main/LICENSE.md

### livekit-client — Apache License 2.0
Bundled verbatim as `modules/webrtc/assets/vendor/livekit-client.umd.js`.
License: Apache-2.0 — https://github.com/livekit/client-sdk-js/blob/main/LICENSE

### Tailwind CSS — MIT License
Build-time dependency used to generate `public/assets/css/app.css`.
License: MIT — https://github.com/tailwindlabs/tailwindcss/blob/master/LICENSE

### esbuild — MIT License
Build-time bundler for the tiptap entry. License: MIT —
https://github.com/evanw/esbuild/blob/master/LICENSE.md

## LiveKit server (not bundled, required at runtime by the WebRTC module)

The WebRTC module connects to a self-hosted LiveKit SFU, which is a separate
program distributed under the Apache License 2.0. LVChat does not redistribute
LiveKit.

---

If any third-party component above is missing its attribution or license,
please report it so the notice can be corrected.
