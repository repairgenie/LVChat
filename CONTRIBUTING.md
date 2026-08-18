# Contributing to LVChat

Thanks for helping with LVChat. This project accepts contributions under the
**Developer Certificate of Origin** (DCO), so no separate contributor license
agreement is needed.

## License

LVChat is licensed under the **GNU Affero General Public License, version 3
only** (AGPL-3.0-only). By contributing, your work becomes part of an AGPL
project and is distributed under that license. Modules in this repository are
AGPL-3.0-only like the core; see `docs/licensing.md`.

## Developer Certificate of Origin (DCO)

Every commit must be signed off to certify that you are entitled to contribute
the code and that you agree to the DCO terms:

> Developer Certificate of Origin
> Version 1.1
>
> By making a contribution to this project, I certify that:
>
> (a) The contribution was created in whole or in part by me and I have
>     the right to submit it under the open source license indicated in
>     the file; or
>
> (b) The contribution is based upon previous work that, to the best of my
>     knowledge, is covered under an appropriate open source license and I
>     have the right under that license to submit that work with
>     modifications, whether created in whole or in part by me, under the
>     same open source license (unless I am permitted to submit under a
>     different license); or
>
> (c) The contribution was provided directly to me by some other person who
>     certified (a), (b) or (c) and I have not modified it.
>
> (d) I understand and agree that this project and the contribution are
>     public and that a record of the contribution (including all personal
>     information I submit with it, including my sign-off) is maintained
>     indefinitely and may be redistributed consistent with this project or
>     the open source license(s) involved.

To sign off a commit, add a line at the end of the commit message:

```
Signed-off-by: Your Name <you@example.com>
```

You can automate this with `git commit -s`. If you already made commits without
a sign-off, amend or rebase to add it before opening a pull request.

## Before you submit

- Run the existing tests: `bin/test.sh`
- Lint changed PHP files: `php -l <file>` for each one you touched
- If you changed views or assets, rebuild the committed stylesheet and bundles
  (only this machine needs Node): `npm run build`
- Update `docs/` when you change behavior that is documented there
- If you change the **module system** (`src/ModuleLoader.php`, module fixtures,
  or a shipped module), extend the module tests: the suites boot against
  `tests/fixtures/modules/` via the `CHAT_MODULES` env var, with loader-level
  assertions in `tests/smoke.php` and HTTP assertions in `tests/http_test.php`
  (see "Testing the module system" in `docs/modules.md`).

### Test layers

| Layer | Command | Covers |
|---|---|---|
| Service/command | `php tests/smoke.php` | slash commands + services against a scratch DB |
| HTTP e2e | `php tests/http_test.php` | the full HTTP API incl. admin, messenger, licensing, and **all `== webrtc * ==` sections** |
| Gateway | `php tests/ws_test.php` | the Workerman WebSocket daemon (auth, fan-out, realtime authz) |
| All three | `bin/test.sh` | runs the layers above in order |

The WebRTC voice module's assertions live in `tests/http_test.php` under the
`== webrtc … ==` banners (module gating, JWT join/leave, capacity, 1:1 + group
calls, host moderation + waiting room, egress recording, rate limits, events).
The fixture is a **symlink to the shipped `modules/webrtc` code**
(`tests/http_test.php` re-points it into the throwaway staging dir), so the
suite always exercises the real module.

The two Electron clients ship their own end-to-end suites (`npm test` in
`desktop/` and `lvchat-messenger/`; messenger-web has `node tests/build-test.js`
after `node build.js`). The voice UI assertions there are **reachable-UI only**
— the in-call pane's moderation/record buttons need a live LiveKit connection,
which the mock servers don't provide — so they cover the waiting-room lobby,
admit/deny handoff, and full-state labels, while the server-side contract is
fully covered by `tests/http_test.php`.

## Reporting issues

Include the LVChat version, PHP version, the steps to reproduce, and any
relevant log output. Do not include secrets or personal data.
