# Round 2 Iteration Report — LVChat Full Monty

- Project: LVChat (`chat.lasvegasbestinternet.com`)
- Scope: FULL_MONTY (test → static audit → security/perf/UIUX scans → fix → re-test → PDF)
- Timestamp: 2026-08-11
- Local run: `bash bin/test.sh` + messenger `npm test` suites

## Test Results (final — all green)

| Suite | Result |
|-------|--------|
| `tests/smoke.php` | 670 passed, 0 failed |
| `tests/http_test.php` | 588 passed, 0 failed |
| `tests/ws_test.php` | 12 passed, 0 failed |
| `messenger-web` build-test | ALL TESTS PASSED |
| `desktop` e2e | ALL TESTS PASSED |
| `lvchat-messenger` (6 suites) | ALL TESTS PASSED (6/6) |

Total server assertions: **1270**. `startup-e2e.js` (the previously flaky suite) was
re-run 3x with both `STARTUP_SEED=auto` and `=lastused` — all green after the fix.

## Audit methodology

- **Test first**: full baseline of `bin/test.sh` + all client suites.
- **Static code audit**: manual review of the HIGH_RISK_FILES list from
  `references/lvchat-profile.md` (SQLi, XSS, CSRF, IDOR, auth bypass, hardcoded
  secrets, command injection, open redirects, session/cookie config, upload
  handling, SSRF, rate limiting) plus a grep/heuristic sweep.
- **LLM audit (Model A)**: `scripts/audit_worker.py` against 28 high-risk files.
  The local `qwen3.5:9b` was CPU-bound (timed out per file); `llama3.2:3b` was
  fast but produced near-100% false positives (every parameterized query flagged
  as SQLi). All LLM findings were manually verified against source and rejected.
- **Security / performance / UI-UX scans**: findings below.

## Findings

| ID | Severity | Track | File | Issue | Fix applied |
|----|----------|-------|------|-------|-------------|
| H-01 | High | UI/UX | `lvchat-messenger/src/main.js` | Close-to-tray race: `ready-to-show` re-showed a window the user had already closed to the tray (flaky `startup-e2e.js` failure, `STARTUP_SEED=auto`) | Per-window `userClosed` flag in the close handler + conditional `show()` |
| M-01 | Medium | Security | `src/bootstrap.php` | `display_errors` always on → verbose error disclosure (stack traces, paths, query details) in production | Gate on CLI/`LVC_DEBUG=1`; otherwise off + `log_errors`; test harness sets `LVC_DEBUG=1` |
| L-01 | Low | Security | `tests/http_test.php` | Embed mock server fixture (`router.php`) was never created by the test → 10 silent 502 failures on machines without the stale `/tmp` file | Test now generates the mock router itself (suite is self-contained) |

## What was verified clean (no fix needed)

- **SQLi**: every query uses bound parameters; the two string-built `IN (...)` clauses
  and the sound-dedupe SQL interpolate only DB-derived ints / `PDO::quote()`d values.
- **XSS**: all output goes through `h()`/JSON; theme CSS input strictly validated
  (allowlisted fonts, hex colors, local-path images, clamped ints).
- **CSRF**: every mutating endpoint calls `Csrf::verify()` (or bearer auth); public
  webhook/OpenClaw endpoints are token-authenticated.
- **Open redirects**: every `next` target passes through `safe_next()`.
- **Command injection**: all `CommandRunner` calls use `escapeshellarg`/int casts, admin-gated.
- **File upload**: `getimagesize` + MIME allowlist, random filenames, realpath containment.
- **SSRF**: embed proxy resolves and rejects loopback/private/link-local/CGNAT/multicast
  and unresolvable hosts (DNS-rebinding resistant).
- **IDOR**: support tickets gated by `canView` (owner or staff); admin by `Auth::requireAdmin`.
- **Session/cookies**: HttpOnly, Secure on HTTPS, SameSite, session ID rotation on login.
- **Performance**: history/search/DM queries LIMIT-bounded; presence throttle on the
  poll hot path; no PHP-level N+1 round-trips.
- **Native dialogs**: no `alert()`/`confirm()`/`prompt()` in app JS.

## Notes

- The working tree contained in-progress **licensing work** (`LicensingService`,
  `docs/protocol/licensing.md`, `module_recheck`, license settings/badges, fixture
  license server + tests) that landed mid-run. It is complete and fully exercised
  by the suites (`smoke.php` `== license keys ==`, `http_test.php`
  `== licensing client ==`); no defects found. Docs updated to match
  (`docs/modules.md` re-check bullet + testing matrix row).
- The embed-proxy failures (L-01) surfaced only because `/tmp/opencode` had been
  cleaned; the fix makes the suite deterministic on any machine.
- `public/assets/css/app.css` carries a pre-existing unrelated working-tree change
  from before this run; it was left untouched.
