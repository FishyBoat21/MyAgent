# Implementation Plan — `--bridge` command channel over a redirected stdin file

**Revision:** 1 (2026-11-01)
**Target repo:** `D:\Project\MyAgent` (PHP 8.4.22 NTS on Windows, Composer autoloading, no third-party packages)
**Status:** **Implemented** (2026-11-01) — see §9 for the record, the verification log and the one
correction to §3.2/M3 that the fix itself revealed.
**Baseline:** `6382970` (`docs(media): document image input and record the implementation`), working tree clean,
`composer test` green.
**Severity:** low for production supervisors (they spawn with pipes), medium for humans and for anyone
scripting a bridge session from a file. Found while verifying the image release, reproduced on the
pre-media baseline, recorded in the knowledge graph as `myagent-finding-bridge-file-stdin`.

---

## 1. Overview & Scope

### 1.1 The defect in one paragraph

`php agent.php --bridge < commands.jsonl` **silently ignores the command channel**: the process prints
`ready` and immediately `bye{reason:"idle-exit",code:0}` without reading a single command, exiting `0`
as if the supervisor had closed the channel deliberately. Nothing on STDERR, no `warning` event, no
non-zero exit code — a supervisor script sees success and an idle session.

The cause is **not** in `Bridge`. It is `Terminal::detect()`, which runs at startup and shells out to
`mode con` to discover the console width; that child inherits the parent's stdin, and when stdin is a
**redirected regular file** the child's read leaves the shared file position at EOF. The parent's first
`fread()` then returns 0 bytes while PHP's own `ftell()` is still `0` and `feof()` still `false`, so
`Bridge::pollLine()` latches `eof` and the command loop ends before its first command.

Pipes are unaffected (`type cmds.jsonl | php agent.php --bridge` works) and so is `< NUL`, which is why
this survived every previous bridge verification: supervisors (MyAgentManager) spawn with pipes, and
the documented EOF smoke test uses `< NUL`.

### 1.2 Root cause, verified (evidence, not inference)

Bisect: a single destructive `fread(STDIN, 65536)` probe inserted at seven successive startup points of
a throwaway copy of `agent.php` (`agent-probe.php`, never the real file), once per run:

| Probe point | bytes read | conclusion |
|---|---|---|
| after `declare(strict_types=1)` | (state only) `feof=n ftell=0` | stdin intact at start |
| before `Terminal::detect()` | **196** | config/catalog/CLI parsing are innocent |
| after `Terminal::detect()` | **0** | ← the consumer is this call |
| after the media block | 0 | media store / prune innocent |
| after `SessionStore` | 0 | session lock innocent |
| after MCP connect | 0 | **MCP is innocent** (also fails with `mcpServers: {}`) |
| before the bridge loop | 0 | — |

Minimal reproduction of the mechanism (no MyAgent code involved beyond the probe itself):

```php
// .agent/width-probe.php  (scratch)
shell_exec('mode con 2>NUL');           // prints "Status for device CON: …"
$chunk = fread(STDIN, 65536);
echo strlen((string) $chunk);           // 0        <-- stdin was consumed
```

```php
shell_exec('mode con 2>NUL < NUL');     // same output, child stdin redirected
$chunk = fread(STDIN, 65536);
echo strlen((string) $chunk);           // 196      <-- stdin intact
```

Same script with the command file on a **pipe** reads 196 bytes even after the un-redirected probe,
because a pipe has no shared file position to advance — which is exactly why the pipe path has always
worked.

`src/Terminal.php:878-900`:

```php
$columns = getenv('COLUMNS');
if ($columns !== false && is_numeric($columns) && (int) $columns >= 40) {
    return min(200, (int) $columns);                      // workaround path: no child spawned
}
if (PHP_OS_FAMILY === 'Windows') {
    $output = @shell_exec('mode con 2>NUL');               // <-- the defect
    ...
} else {
    $output = @shell_exec('stty size < /dev/tty 2>/dev/null');  // already redirects; Linux is safe
}
```

Two workarounds work today and are worth documenting regardless: set `COLUMNS` (≥ 40) or pass
`--width=N` (≥ 40); both return before the `shell_exec`. Verified: `set "COLUMNS=100" && php agent.php
--bridge < cmds.jsonl` processes the command, attaches the image and runs the turn.

### 1.3 Definition of Done

1. `php agent.php --bridge < commands.jsonl` processes commands **exactly** like the same bytes on a
   pipe: `attachment` (when present) → `turn_start` → `assistant` → `usage` → `turn_end` → `bye`.
2. Zero behaviour change for the other three channel kinds: a TTY, a pipe, and `< NUL`
   (`ready` + `bye{idle-exit,0}`, exit 0, byte-identical to today).
3. `Terminal::resolveWidth()` can never hand a child process our stdin: every width probe redirects the
   child's stdin from `NUL`/`/dev/null`, and the probe is skipped entirely when there is no console to
   measure (stdin **and** stdout are not TTYs — e.g. a supervisor or CI run).
4. A single 0-byte read is no longer treated as end-of-channel when the stream is not at EOF:
   `Bridge::pollLine()` retries a bounded number of times (bounded wall clock, no busy loop) before
   latching `eof`, so any future child that inherits stdin cannot silently end a session.
5. When the channel does end without processing a single command *and* the stream is a regular file
   with bytes in it, the bridge emits one diagnostic (`warning` event **and** one STDERR line). No
   diagnostic for the legitimate `< NUL` / immediate-EOF case, so existing byte-level expectations for
   `--bridge < NUL` stay identical.
6. New deterministic regression tests: the defect is reproduced **without any API call** by a fixture
   that builds `Terminal::detect()` + `Bridge` over a redirected file, plus a flaky-stream wrapper that
   proves the EOF discipline.
7. `composer validate`, `composer test`, `php tests/ui.php`, `php tests/selector.php`,
   `php tests/media.php` all green; `php tests/render-demo.php --force --color=always --width=100`
   still renders every section (the width path must not regress).
8. README documents that the bridge command channel accepts a redirected file, and lists the
   `COLUMNS` / `--width` interaction in the troubleshooting note.

### 1.4 Out of scope (explicitly)

| Not doing | Why |
|---|---|
| Replacing `shell_exec('mode con')` with a Win32 console API call | Needs FFI or a COM extension, both absent on this machine; the redirect fixes the defect with one argument. |
| Changing how the width is *chosen* (defaults, clamping 40–200, `COLUMNS` precedence) | Unrelated to the defect; the existing behaviour is covered by `tests/ui.php`. |
| Making `ask_user` work over a redirected file stdin | Out of scope for a supervisor channel; `ask_user` belongs on the bridge command channel (`answer`), which is unaffected. |
| Detecting *any* future stdio inheritance (e.g. a `proc_open` audit) | The two fixes below remove the class of failure for the width probe; a general audit is a separate task. |
| Windows job objects / handle inheritance flags on every child | Bigger surface, no evidence of need beyond this call site. |

---

## 2. Prerequisites & Baseline

### 2.1 P0 — Environment facts (verified on this machine, 2026-11-01)

- PHP 8.4.22 NTS, Windows; `ext-curl`, `ext-json`, `ext-mbstring`, `ext-gd` present;
  `ext-fileinfo`, `ext-imagick`, `ext-exif`, `ext-sodium`, `ext-ffi` **absent**.
- `NO_COLOR=1` is set in this shell; `COLUMNS` is **not** set — so the width probe runs (this is why
  the bug shows up here).
- `mode con` writes its report to **STDOUT**; `2>NUL` only silences errors, which is why the original
  author reasonably did not think about stdin at all.

### 2.2 P1 — Reproduction set (all four must be run before and after the fix)

```powershell
# 1. FAILS today: command channel ignored, exit 0, no stderr
php agent.php --bridge < .agent\bridge-probe.jsonl

# 2. WORKS today: same bytes on a pipe
type .agent\bridge-probe.jsonl | php agent.php --bridge

# 3. WORKS today: legitimate immediate EOF
php agent.php --bridge < NUL

# 4. WORKS today (workaround): COLUMNS skips the probe entirely
set "COLUMNS=100" && php agent.php --bridge < .agent\bridge-probe.jsonl
```

`bridge-probe.jsonl` is one line:
`{"v":1,"type":"prompt","text":"…","attachments":[{"path":"tests/.media-fixture.png"}]}`.
Cases 1–3 make no API call when the prompt is rejected; case 1 and 4 make exactly one paid turn, so run
them last and keep `--max-steps` low if a step budget matters.

### 2.3 P2 — Why this is pre-existing and not a media regression

The same file was reproduced against a copy of `agent.php` from baseline `46fa68e`
(`git show 46fa68e:agent.php > agent-probe.php`): identical `ready` + `bye{idle-exit,0}`. The defect
predates the image release; it only became visible because the bridge was exercised with a *file*
redirect for the first time while verifying `attachments[]`.

### 2.4 P3 — Baseline gate

`composer test` green (UI, Selector, Media, Smoke, Bridge), `php tests/ui.php`,
`php tests/selector.php`, `php tests/media.php` green, working tree clean on `6382970`.

---

## 3. Architecture & Contract Changes

### 3.1 `src/Terminal.php` — `resolveWidth()` must not lend stdin to a child

Two changes, both local to `resolveWidth()`:

**3.1.1 Redirect the child's stdin on every probe.**

```php
if (PHP_OS_FAMILY === 'Windows') {
    // The child must never inherit our stdin: with a redirected file (--bridge < cmds.jsonl)
    // the shared file position would be advanced past the first command and the parent's
    // next read would look like EOF. See §1.2.
    $output = @shell_exec('mode con 2>NUL < NUL');
    ...
} else {
    $output = @shell_exec('stty size < /dev/tty 2>/dev/null');
}
```

**3.1.2 Do not probe when there is no console.** A redirected or piped run has no console width to
discover; measuring one spawns a process for nothing (and is the only reason this defect existed).

```php
private static function hasConsole(): bool
{
    return @stream_isatty(STDIN) || @stream_isatty(STDOUT);
}
```

`resolveWidth()` returns `100` (the documented default) when `hasConsole()` is false and neither
`--width` nor `COLUMNS` supplied a value. Order of precedence stays exactly as today:
`--width` → `COLUMNS` → console probe → 100.

**Contract note:** the visible width of a piped/redirected run changes only in the case where
`mode con` used to answer after the width had already been forced by `--width`/`COLUMNS` — those paths
return before the new check, so all existing width assertions keep their values. `tests/ui.php` plus the
40-column assertions are the guard; if any case regresses, the mitigation is to keep 3.1.1 and drop
3.1.2 (they are independently safe).

### 3.2 `src/Bridge.php` — a 0-byte read is not proof of EOF

Today (`pollLine()`):

```php
$chunk = @fread($this->in, 65536);
if ($chunk === false || $chunk === '') {
    if (!is_resource($this->in) || feof($this->in)) {
        $this->eof = true;      // <-- one empty read latches EOF for the whole session
    }
    return null;
}
```

Proposed:

```php
$chunk = @fread($this->in, 65536);
if ($chunk === false) {                       // hard error: real EOF
    $this->eof = true;
    return null;
}
if ($chunk === '') {
    if (!is_resource($this->in) || feof($this->in)) {
        // A seekable regular file that still has bytes is NOT at EOF even when a
        // child process advanced the shared position behind our back (§1.2): give it
        // a bounded number of retries before declaring the channel closed.
        if ($this->emptyReads < self::EMPTY_READ_RETRIES && $this->streamLooksUnread()) {
            $this->emptyReads++;
            usleep(5000);
            return null;                      // poll again; do NOT latch eof
        }
        $this->eof = true;
    }
    return null;
}
$this->emptyReads = 0;
```

with `EMPTY_READ_RETRIES = 3` and

```php
/** True when a regular file reports bytes we have not seen (size > ftell). */
private function streamLooksUnread(): bool
{
    $stat = @fstat($this->in);
    if ($stat === false || (($stat['mode'] & 0170000) !== 0100000)) {
        return false;                        // not a regular file: nothing to reason about
    }
    $position = @ftell($this->in);
    return $position !== false && $stat['size'] > $position;
}
```

Bounded by `3 × 5 ms` per empty read, so a genuinely closed pipe still exits immediately (the retry
path only applies to regular files that advertise unread bytes). No public API change; `Bridge::VERSION`
stays `1`; the wire format is untouched.

**What this can and cannot do (measured after implementation):** the retry recovers a *transient*
empty read — proved by the `flaky-stream` double (B19, which fails against the pre-fix `Bridge`). It
cannot recover bytes a child process already consumed: in the real failure mode the shared position
is past the data, so the retries exhaust and the channel still ends. What M3 adds there is the
diagnostic, not a rescue — **M2 is the actual fix**; M3 is defence in depth plus observability.

### 3.3 Diagnostics for a channel that ends before its first command

At the end of the command loop in `agent.php`, when `$commandsProcessed === 0` and
`Bridge::stdinWasRegularFileWithBytes()` reports the anomalous case:

- one STDERR line: `[bridge] command channel closed before any command was processed (stdin redirected from a file? see README)`.
- one `warning` event on STDOUT (`{"v":1,"type":"warning","text":"…"}`), so a supervisor sees it in the
  event stream rather than only in a log file.

`< NUL` produces `size == 0`, so it stays byte-identical to today (no warning) — this is asserted.

### 3.4 No config, session, or wire-format change

Nothing new in `config.json` / `config.example.json`, no session checkpoint change, no new event types
beyond the conditional `warning` above (already part of the protocol).

---

## 4. Step-by-Step Execution Sequence

Each milestone ends with `php -l` on the touched files plus the named suite.

### M0 — Freeze the evidence (no product code)

- **Files:** scratch only (`.agent/bridge-probe.jsonl`, a probe copy of `agent.php`). Nothing committed.
- **Steps:** run §2.2 cases 1–4 and record the output; re-run the seven-point bisect if the environment
  changes (different shell, `COLUMNS` set, different Windows build). Fill §9.1.
- **Exit criteria:** the table in §1.2 is confirmed on the current machine, with the four command lines
  and their exact output pasted into §9.1.

### M1 — Deterministic reproduction without an API call (test fixture first)

- **Files created:** `tests/fixtures/bridge-file-probe.php` — a tiny script that requires the
  autoloader, calls `Terminal::detect([])` (exercising the width probe), then constructs `Bridge` on
  STDIN, emits `ready`, prints `command=<type>` for each command it decodes, and emits `bye`.
- **Files modified:** none.
- **Validation:** `php tests/fixtures/bridge-file-probe.php < .agent/bridge-probe.jsonl` prints
  `command=prompt` after the fix and nothing before it — the reproducer must **fail** on the current
  tree first (record that).
- **Exit criteria:** the fixture reproduces the defect with zero provider calls and zero dependencies.

### M2 — Fix the width probe (`src/Terminal.php`)

- **Files modified:** `src/Terminal.php` (§3.1.1 + §3.1.2).
- **Validation:**
  ```powershell
  php -l src/Terminal.php
  php tests/fixtures/bridge-file-probe.php < .agent\bridge-probe.jsonl   # now prints command=prompt
  php agent.php --bridge < .agent\bridge-probe.jsonl                     # turn_start … turn_end
  php tests/ui.php                                                       # width/render assertions
  ```
- **Exit criteria:** §2.2 case 1 now behaves like case 2; `COLUMNS`/`--width` paths unchanged; no test
  in `tests/ui.php` needed a new expectation except the new dedicated cases (M4).

### M3 — Harden the bridge against an empty non-blocking read (`src/Bridge.php`)

- **Files modified:** `src/Bridge.php` (§3.2).
- **Validation:** `php -l src/Bridge.php`, `php tests/bridge.php` (existing B1–B17 stay green).
- **Exit criteria:** a single spurious empty read no longer ends the channel (B19, red against the
  pre-fix `Bridge`), and a genuinely consumed command file reports `danglingInputWarning()` instead of
  exiting silently. The retry cannot *recover* consumed bytes — see the correction in §3.2.

### M4 — Regression tests

- **Files modified:** `tests/bridge.php` (B18–B20), `tests/ui.php` (width-probe cases),
  `tests/fixtures/flaky-stream.php` (new).
- **Cases:**
  - **B18** a file-backed `Bridge` (`fopen($tmp, 'r')`) processes its command — the plain regression.
  - **B19** the flaky-stream wrapper returns `''` on the first `stream_read` and the command on the
    second: the loop must decode it (proves the EOF discipline; fails on the current code).
  - **B20** the diagnostic path: a file-backed stream with bytes but no command processed emits exactly
    one `warning` event, and `< NUL`-style empty input emits **none**.
  - **U-width-1** `Terminal::detect()` in a subprocess with stdin redirected from a temp file must leave
    a subsequent `fread(STDIN)` intact (this is the actual defect, asserted directly).
  - **U-width-2** `resolveWidth()` still honours `--width`, then `COLUMNS`, then the default, unchanged.
- **Validation:** `php tests/bridge.php`, `php tests/ui.php`, `composer test`.

### M5 — Documentation and full verification

- **Files modified:** `README.md` (bridge section: the command channel accepts a redirected file; note
  that `COLUMNS`/`--width` skip the console-width probe), this document's §9.
- **Validation:** the full gate in §5.1, plus the §5.2 runbook on Windows.
- **Exit criteria:** every DoD item in §1.3 checked off in §9; a reader can script a bridge session from a
  file using the README alone.

---

## 5. Testing & Verification Strategy

### 5.1 Automated (all offline, no paid call)

```powershell
composer validate
php -l src/Terminal.php; php -l src/Bridge.php; php -l agent.php
composer test              # smoke (incl. UI, Selector, Media) + bridge
php tests/ui.php
php tests/selector.php
php tests/media.php
php tests/render-demo.php --force --color=always --width=100
```

### 5.2 Manual runbook (Windows, live provider)

```powershell
# 1. the defect: file-redirected channel
php agent.php --bridge < .agent\bridge-probe.jsonl
#    expect: attachment → turn_start → assistant → usage → turn_end → bye{idle-exit,0}

# 2. pipe parity (must stay identical to 1)
type .agent\bridge-probe.jsonl | php agent.php --bridge

# 3. legitimate immediate EOF (must stay: no warning, no extra event)
php agent.php --bridge < NUL

# 4. workaround still true (should be irrelevant after the fix, still valid)
set "COLUMNS=100" && php agent.php --bridge < .agent\bridge-probe.jsonl

# 5. real supervisor shape (pipes, two commands)
#    {"v":1,"type":"prompt","text":"say ok"}
#    {"v":1,"type":"cache_report"}
type .agent\two-commands.jsonl | php agent.php --bridge
```

### 5.3 Regression + security checks

- The width probe must not become a new injection surface: the command string stays a constant literal
  (`mode con 2>NUL < NUL`), never interpolated.
- `Bridge` must not busy-loop: the retry budget is fixed (3 × 5 ms) and only applies to seekable regular
  files advertising unread bytes; a closed pipe must still exit `bye{idle-exit,0}` immediately
  (measured with `Measure-Command`).
- No new process is spawned during a non-console run (assert with the fixture: `proc_open` count stays
  zero when stdin/stdout are redirected) — this is the actual root-cause removal.
- `ask_user` over the bridge (`answer`/`cancel`) and the `verdict` round-trip are untouched; B1–B17 keep
  covering them.

### 5.4 Performance notes

The probe happens once per process. Removing it for non-console runs removes one `cmd.exe` spawn from
every supervisor-spawned session — a small but real startup win (measured before/after in M2).

---

## 6. Risks, Decisions & Rollback

### 6.1 Decision log

| # | Decision | Rationale |
|---|---|---|
| D1 | Fix the probe (`< NUL`) **and** stop probing without a console | The redirect removes the defect; skipping the probe also removes the child process, which is the real cause. Each is independently safe, so a partial revert is possible. |
| D2 | Do not rewrite `resolveWidth()` to call the Win32 API | No FFI/COM on this machine; the shell probe stays, correctly redirected. |
| D3 | Harden `Bridge::pollLine()` as well, though the probe fix alone is sufficient | Any future child that inherits stdin would otherwise silently end a session again; the retry is bounded and only for seekable files. |
| D4 | Keep `Bridge::VERSION` at `1` and the event set unchanged (one conditional `warning`) | Supervisors must not need a protocol change for a bug fix. |
| D5 | Diagnostics only for the anomalous case (regular file, bytes present, zero commands) | `< NUL` and closed pipes are legitimate and must stay byte-identical. |
| D6 | Test the defect through a fixture, not through a live agent run | The regression must be runnable in CI with no API key. |

### 6.2 Risks and mitigations

| Risk | Likelihood | Mitigation |
|---|---|---|
| Skipping the probe changes rendered width in some piped test | Medium | Do 3.1.1 first; only add 3.1.2 once `tests/ui.php` is green. The 40-column assertions plus `render-demo` are the guard. |
| The retry loop turns into a busy wait | Low | Fixed budget (3 × 5 ms), regular files only, `usleep` between attempts. |
| A future stream type (socket, named pipe) mis-detected as "unread bytes" | Low | `streamLooksUnread()` returns false for anything that is not `S_IFREG`. |
| The new `warning` event breaks MyAgentManager expectations | Low | Only emitted in a case MyAgentManager never hits (it uses pipes) and `warning` is already a documented event type. |
| Someone "fixes" this again by removing `< NUL` | Low | The comment in `resolveWidth()` names the failure mode and the test asserts it. |

### 6.3 Rollback

Single-purpose commits; `git revert` of the series restores today's behaviour exactly. No config, session
format, media store, or wire-protocol change is involved, so no data cleanup is needed. The only
user-visible difference after reverting is the defect coming back.

---

## 7. File-by-File Summary

### New files

| File | Purpose |
|---|---|
| `tests/fixtures/bridge-file-probe.php` | Minimal reproducer: `Terminal::detect()` + `Bridge` over STDIN, prints `command=<type>`; fails today with a redirected file. |
| `tests/fixtures/flaky-stream.php` | Userland stream wrapper that returns `''` on the first read — the EOF-discipline test double. |
| `tests/fixtures/width-probe.php` | Subprocess fixture for `tests/ui.php`: `Terminal::detect()` then a raw stdin read, printing `width=<n> len=<n>` (§9.2 records why this is a second fixture). |

### Modified files

| File | Change |
|---|---|
| `src/Terminal.php` | `< NUL` on the Windows probe; `hasConsole()` gate before probing; comment naming the failure mode. |
| `src/Bridge.php` | `EMPTY_READ_RETRIES`, `streamLooksUnread()`, bounded retry instead of latching `eof` on one empty read; accessor for the diagnostics. |
| `agent.php` | One conditional diagnostic at the end of the bridge loop (counter of processed commands). |
| `tests/bridge.php` | B18–B21 (file-backed channel, empty-read retry, dangling diagnostic, redirected-file end to end). |
| `tests/ui.php` | Two width-probe cases (subprocess with a redirected stdin). |
| `README.md` | Bridge section: a redirected command file is supported; `COLUMNS`/`--width` note. |

### Intentionally unchanged

`Bridge` wire protocol and `VERSION`; `Ansi`/`Theme`; the width defaults and clamping; `ask_user` and
`verdict` handling; sessions, checkpoints, media store; `composer.json` dependencies.

---

## 8. Commit Plan

Three reviewable commits, each independently green:

1. `fix(cli): stop the console-width probe from consuming a redirected stdin`
   → `src/Terminal.php`, `tests/fixtures/bridge-file-probe.php`, `tests/ui.php` (width cases).
2. `fix(bridge): do not latch EOF on an empty non-blocking read`
   → `src/Bridge.php`, `agent.php` (diagnostic), `tests/bridge.php` (B18–B20),
   `tests/fixtures/flaky-stream.php`.
3. `docs(bridge): document the redirected command channel`
   → `README.md`, §9 of this document.

---

## 9. Implementation Record

**Status: IMPLEMENTED (2026-11-01).** M0–M5 are complete and every DoD item in §1.3 is checked off below.

### 9.1 M0 evidence (commands + exact output)

Bisect (one destructive `fread(STDIN, 65536)` per run, inserted into a throwaway `agent-probe.php`):

```text
[read@config]        feof=n ftell=0 len=196   <- before Terminal::detect()
[read@after-terminal-detect] feof=n ftell=0 len=0
[read@after-flagvalues]      len=0
[read@after-limits]          len=0
[read@after-store]           len=0
[read@after-prune]           len=0
[read@mcp] / [read@preready] / [read@preloop]  len=0
```

Minimal mechanism (`shell_exec` then one read, no MyAgent code):

```text
shell_exec('mode con 2>NUL')       -> read len=0      (stdin consumed)
shell_exec('mode con 2>NUL < NUL') -> read len=196    (stdin intact)
same, command file on a pipe       -> read len=196    (pipes have no shared position)
```

M1 reproducer before the fix:

```text
php tests/fixtures/bridge-file-probe.php < .agent\bridge-probe.jsonl  -> width=120 command=none
type .agent\bridge-probe.jsonl | php tests\fixtures\bridge-file-probe.php -> width=120 command=prompt
```

### 9.2 Decisions changed during implementation

1. **M3 was mis-specified in §4 and is corrected in §3.2.** The plan's exit criterion claimed the bridge
   hardening would "still survive the consumed-file case" with M2 reverted. It cannot: once a child has
   consumed the bytes the shared position is past them and no retry can bring them back. Measured with
   the new `Bridge`: `command=none, delivered=0, warning=the command channel closed before any command
   was read …`. So **M2 is the fix**, and M3 is (a) protection against a single transient empty read and
   (b) the diagnostic. Recorded rather than quietly reworded.
2. **A second fixture was needed.** `tests/fixtures/bridge-file-probe.php` proves the command channel end
   to end, but the width-probe assertion needs the raw byte count of stdin after `Terminal::detect()`;
   that is `tests/fixtures/width-probe.php` (`width=<n> len=<n>`), run as a subprocess from `tests/ui.php`
   because a process cannot re-redirect its own stdin.
3. **The `hasConsole()` gate changes the width of piped/redirected runs from the console-derived value to
   `100`.** `mode con` queries the console device directly, so before this change a piped run still got
   the console width (e.g. 120). Both `tests/ui.php` and `tests/smoke.php` stayed green with the change, and
   explicit widths (`--width`, `COLUMNS`) are unaffected. Kept, because the alternative is spawning a child
   on every supervised session for a width that is not visible to anyone.
4. **The diagnostic is emitted as both a STDERR line and one `warning` event**, so a supervisor parsing
   STDOUT sees it too. It is gated on "regular file with bytes and zero decoded commands", which keeps
   `< NUL` and closed pipes byte-identical to before (asserted by B20).
5. **The commit order of §8 was swapped: the `Bridge` hardening lands first, then the `Terminal` fix.**
   `tests/bridge.php` is one file, and B18–B20 (which need the new `Bridge` API) plus B21 (which needs the
   `Terminal` fix) live in it, so the only way to keep every commit green is to put the file in the second
   commit. An extra `B21` was added beyond the plan: the exact user-visible symptom, as an end-to-end
   subprocess test (`php tests/fixtures/bridge-file-probe.php < commands.jsonl` must print `command=prompt`).

### 9.3 Verification performed

Suites (all green):

```text
composer validate                 -> ./composer.json is valid
composer test                     -> UI / Selector / Media / Smoke / Bridge passed
php tests/ui.php                  -> UI tests passed (incl. the two new width cases)
php tests/selector.php            -> Selector tests passed
php tests/media.php               -> Media tests passed
php tests/render-demo.php --force --color=always --width=100 -> renders (56 lines)
```

The three channel shapes, after the fix:

```text
php agent.php --bridge < .agent\bridge-probe.jsonl   -> ready, attachment, turn_start, assistant, turn_end{complete:true}, bye
type .agent\bridge-probe.jsonl | php agent.php --bridge -> identical events
php agent.php --bridge < NUL                         -> ready, bye{idle-exit,0} (no warning, unchanged)
```

Regression proofs (each new test is red against the pre-fix code):

- **B19** against `git show HEAD:src/Bridge.php` → `old Bridge command=none (B19 would FAIL)`.
- **U-width-1** against `git show HEAD:src/Terminal.php` → `old Terminal width=120 len=0 (U-width-1 would
  FAIL)`; after the fix the same fixture reports `len=<file size>` and `width=100`.
- **B18** proves a file-backed channel delivers (`commandsDelivered() === 1`, no warning); **B20** proves
  the diagnostic fires for a file with bytes and no command, and stays silent for an empty file.

The real failure mode still ends the channel if something consumes stdin, but no longer silently — see
the measured `warning=` line in §9.2-1.

### 9.4 Known limitations after the fix

- The retry is a guard against a *transient* empty read, not a recovery mechanism; nothing can un-consume
  bytes a child process read (M2 prevents that child from existing in the first place).
- A run with no console now reports `width=100` unless `--width` or `COLUMNS` is set. That is the
  documented fallback, but it is a visible change for piped runs that used to inherit the console width.
- Windows-only regression coverage: the width probe is a Windows branch, so `U-width-1` exercises the
  skip path on other platforms (fine — the POSIX branch already redirected) and B18–B20 are
  platform-neutral.
---

## 10. Open Questions (before M1)

| # | Question | Default if unanswered |
|---|---|---|
| 1 | Should the diagnostic also bump the process exit code (e.g. `1` for "channel ended before its first command")? | **No** — a supervisor that closed the channel early is legitimate; the `warning` event is the signal. |
| 2 | Should `--bridge` warn when `COLUMNS`/`--width` are absent in a non-console run? | No; after M2 there is nothing to warn about. |
| 3 | Keep the retry budget at 3 × 5 ms, or make it configurable (`bridge.empty_read_retries`)? | Fixed constant; no config surface for a 15 ms edge case. |
| 4 | Should the same `< NUL` treatment be applied to any other `shell_exec` in the codebase? | Audit once in M2 and record the result in §9.2. Today `shell_exec` appears only in `resolveWidth()`; `Agent.php` also runs `exec('taskkill /F /T /PID …')` on a `run_command` timeout, but that path already gives the child file-backed stdio, so it cannot consume our stdin — confirm and record. |
