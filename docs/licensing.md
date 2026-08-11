# LVChat Licensing

This document is the authoritative statement of how LVChat and its modules are
licensed. It complements `docs/modules.md` (the module system) and
`CONTRIBUTING.md` (how to contribute).

## Core application

The LVChat core — `src/`, `views/`, `public/`, `bin/`, `desktop/`,
`messenger-web/`, `lvchat-messenger/`, and the WebRTC module — is licensed under
the **GNU Affero General Public License, version 3 only** (SPDX:
`AGPL-3.0-only`). The full license text is in `LICENSE`.

A one-line summary for non-lawyers:

- You may **use**, **copy**, **modify**, and **redistribute** LVChat, including
  charging for copies and offering paid support.
- Any **modified** version you distribute must also be AGPL-3.0-only, and you
  must make your modifications available under the same terms.
- **Network use clause (§13):** because LVChat is a *server application*, if you
  run a modified version as a network service, you must offer the users of that
  service the corresponding source of your modified version. Hosting LVChat
  unmodified is fine; hiding modified code behind a hosted service is not.

This is what keeps contributions and improvements open: anyone who builds on
the core — whether they distribute it or only run it as a service — must share
the source of their changes with the people who use it.

## Modules

Modules are separate products that plug into the core through the documented
extension surfaces (manifest, routes, commands, hooks, assets, views — see
`docs/modules.md`). How a module may be licensed depends on how it is shipped:

### Modules shipped in this repository

Any module whose code lives in this repository is part of the LVChat project
and is licensed **AGPL-3.0-only**, exactly like the core. The WebRTC module
(`modules/webrtc/`) is the current example; it carries its own `LICENSE` file
and is declared `"license": "AGPL-3.0-only"` in its `module.json`.

### Modules distributed separately

A module distributed independently of the core — for example a paid plugin sold
with a license key — is an **independent work** and may be licensed under the
module author's own terms, including a **proprietary/commercial license**.
LVChat's own paid modules work this way, and third parties are welcome to do
the same.

For a proprietary module to stay independent of the AGPL core, it must meet
**all** of these criteria:

1. **No copied core source.** The module must not copy, adapt, or translate code
   from the core (that code is AGPL and would infect the module).
2. **Public API only.** The module talks to the core only through the documented
   interfaces (`ModuleLoader`, `Router`, `CommandRegistry`, `Database`,
   `config_get`/`config_set`, the manifest hooks). It must not reach into core
   internals in a way that effectively re-implements them.
3. **Separate distribution and copyright.** The module is distributed on its
   own, under its own copyright notice, by its own author.

If a module copies core code or is so tightly bound to it that it becomes a
derivative work, the AGPL applies to it and it must be released AGPL-3.0-only.

### The paid license-key system is unrelated to open-source licensing

The `"license": true` manifest flag and the license-key validation in
`ModuleLoader::isLicensed()` control **activation and distribution** of paid
modules. It is a business mechanism and does not change the copyright license of
the module's code. A paid module can be proprietary (closed source) or can be
open source — the key system gates access to features, not the legal license.

## Third-party code

Bundled third-party libraries keep their own licenses (for example the bundled
`livekit-client.umd.js` is Apache-2.0 and the tiptap editor is MIT). See
`THIRD_PARTY_NOTICES.md`. Bundling or aggregating third-party code does not
change its license, and combining Apache-2.0 or MIT code with AGPL code is
permitted.

## Contributions

By contributing to LVChat you agree that your contribution is made under the
project's license (AGPL-3.0-only for the core, or the module's own license for a
module). Contributions are accepted under the **Developer Certificate of Origin**
(DCO): every commit must carry a `Signed-off-by` line. See `CONTRIBUTING.md`.

Because the core is licensed AGPL-3.0-**only**, contributions to the core are
locked to that version; LVChat cannot adopt a future GPL/AGPL version without a
relicensing agreement from contributors.
