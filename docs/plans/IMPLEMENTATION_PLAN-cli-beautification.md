# MyAgent CLI Beautification — Implementation Plan

**Revision:** 1
**Date:** 2026-10-31
**Status:** Implemented (2026-10-31) — automated tests green; live interactive QA pending (see §10).
**Target:** `D:\Project\MyAgent`
**Audience:** An engineer or coding agent implementing the CLI/TUI polish end to end.

---

## 1. Overview & Scope

### 1.1 Objective

Turn MyAgent's current raw `fwrite()` terminal output into a polished, width-aware,
ANSI-styled inline CLI experience: a startup banner, a styled prompt, rendered Markdown
for assistant replies, compact tool-activity cards, token/timing telemetry, a waiting
spinner, and styled security/`ask_user` prompts — without adding dependencies, changing
the system prompt, changing tool schemas, or changing the session format.

The visual target is **inline, scrollback-preserving output** (like Claude Code), not a
full-screen alternate-buffer TUI. Every enhancement must degrade to clean plain text for
pipes, logs, `NO_COLOR`, and legacy Windows consoles.

### 1.2 Current state (verified)

All human-facing output is emitted ad hoc:

| Where | Line | Current output |
|---|---|---|
| `agent.php` | 110, 113 | `Connected MCP: name` / `MCP name unavailable: ...` on STDERR |
| `agent.php` | 135–147 | Yellow `⚠ Security approval required` prompt with raw ANSI |
| `agent.php` | 150 | `Security reviewer: model ...` on STDERR |
| `agent.php` | 164, 193 | `Work is unfinished ...` on STDERR |
| `agent.php` | 174 | `you> ` prompt on STDOUT |
| `agent.php` | 203 | `Error: ...` on STDERR |
| `src/Agent.php` | 841 | Raw assistant text on STDOUT |
| `src/Agent.php` | 873 | Raw step-limit notice on STDERR |
| `src/Agent.php` | 883 | Cyan `· tool {json}` on STDERR |
| `src/Agent.php` | 900 | Red security-deny line on STDERR |
| `src/AskUser.php` | 91–98, 125 | Plain ASCII question/options on STDERR |

Consequences today: output wraps at terminal width by accident, assistant Markdown is
shown raw, no response timing/token usage is visible, no waiting feedback exists, colors
are hard-coded 16-color literals, and ANSI is emitted even when STDERR is not a TTY.

### 1.3 Definition of Done (DoD)

- **Interactive (TTY) session shows:**
  - A compact startup banner with model, workspace, session, security reviewer, and tool/MCP counts.
  - A styled prompt (`❯ `, or `you> ` in plain mode).
  - Assistant replies rendered as Markdown: headings, lists, blockquotes, rules, inline code, fenced code with a language label and basic highlighting, GFM tables, links.
  - One compact block per tool call: tool name, semantic argument summary, result summary (success/error), and elapsed time.
  - A dim one-line telemetry line per model response: input/output tokens, elapsed time, stop reason.
  - A throttled spinner while waiting for the model.
  - Styled security deny/approve prompts and styled `ask_user` questions.
  - All rendered lines stay within the terminal width (minimum supported width: 40 columns); no horizontal scrolling.
- **Degradation:**
  - `--plain`, non-TTY streams, `TERM=dumb`, and `NO_COLOR` emit **zero** ANSI escape bytes.
  - Non-UTF-8 Windows consoles fall back to ASCII box/symbol glyphs automatically.
  - Piped STDOUT contains only assistant prose (no banner, no tool lines, no telemetry).
  - `AskUser`'s plain output remains byte-identical to today's text.
- **Compatibility:**
  - No new Composer packages; PHP 8.2+; `curl` + `json` only; `mbstring` optional.
  - No change to the system prompt, tool definitions, API request bodies, session JSON, exit codes (0/1/2), or `Agent`/`AnthropicClient` public contracts used by the smoke suite.
  - `php tests/smoke.php` passes and also runs the new UI tests.
- **Docs/config:**
  - `README.md` documents `ui` config, environment variables, flags, and the plain-output contract.
  - `config.example.json` gains a `ui` block.
  - `php tests/render-demo.php` renders a visual gallery for review.

### 1.4 Out of scope (v1)

- Alternate-screen/full-screen TUI, mouse input, arrow-key option selection.
- Token-by-token SSE streaming (the spinner covers perceived latency; see §9).
- User-defined color themes/plugins.
- Localization.
- Any change to security verdict policy or reviewer behavior.
- Any change to model prompts or compaction (rendering is local-only; token usage is unaffected).

### 1.5 Prerequisites

1. `php tests/smoke.php` is green before starting (baseline).
2. `config.json` already exists with a working provider key (do not print or commit it).
3. Manual visual QA is done in Windows Terminal / PowerShell 7; `cmd.exe` is a fallback check.
4. Automated tests must use fake `AnthropicClient` subclasses (as `tests/smoke.php` already does) and must not call a paid API.

---

## 2. Architecture & Contracts

### 2.1 New components

Five new zero-dependency files in `src/`, all in namespace `MyAgent`:

| File | Responsibility |
|---|---|
| `src/Ansi.php` | Pure static helpers: ANSI stripping/sanitizing, visible width (CJK/emoji aware), ANSI-aware wrapping, truncation, padding, box drawing, tab expansion. No I/O. |
| `src/Theme.php` | Color/symbol palette. Depth-aware (none/16/256), unicode-aware. `style($text, ...$styles)` composes one SGR sequence; `symbol($name)` maps unicode → ASCII. |
| `src/Terminal.php` | Session renderer and capability detector. Owns STDOUT/STDERR writers, TTY/color/unicode/width detection, banner, prompt, assistant rendering, tool activity, telemetry, security/ask_user chrome, cursor handling, shutdown restoration. |
| `src/Markdown.php` | Line-oriented Markdown → ANSI renderer at a fixed width. Uses `Ansi` + `Theme`. Headings, paragraphs, lists, task lists, blockquotes, rules, fenced code + highlighting, tables, links. |
| `src/Spinner.php` | Throttled single-line spinner on STDERR. No-op when not a TTY or disabled. Driven by curl progress callbacks. |

No autoloader exists, so every entry point must `require` them explicitly (see §3).

### 2.2 Stream contract (must not regress)

| Channel | Content |
|---|---|
| **STDOUT** | Assistant prose (rendered Markdown in a TTY; raw text otherwise) and the input prompt. |
| **STDERR** | Banner, startup status, tool activity, spinner, telemetry, security prompts/notices, warnings, errors, `ask_user` chrome. |

Rules:

- `Terminal::out()` writes STDOUT; `Terminal::err()` writes STDERR.
- Before writing on one channel, flush the other (`@fflush(STDOUT)` / `@fflush(STDERR)`) to preserve visual order.
- Assistant Markdown is emitted as one complete string per response (no partial-line interleaving).
- When STDOUT is not a TTY, assistant output is **exactly** the current raw contract:
  `rtrim($text) . "\n"` for each text block, joined the same way as today.
- Telemetry and tool lines never go to STDOUT.

### 2.3 Capability detection & degradation

`Terminal::detect(array $ui, bool $plain): self` resolves, in order:

1. **Programmatic `--plain`** → `color=never`, `unicode=never`, `markdown=false`, `spinner=false`, `banner=false`, `usage=false`, `tool_details=summary`; raw legacy output.
2. **TTY** per stream via `@stream_isatty(STDOUT|STDERR)`.
3. **Color** (per stream):
   - `ui.color=always` (explicit) → always, overriding the environment.
   - `ui.color=never` or `--no-color` → never.
   - `TERM=dumb` → never.
   - `NO_COLOR` set and non-empty → never.
   - `FORCE_COLOR` non-empty and not `"0"` → always.
   - Otherwise: color iff the stream is a TTY.
4. **Unicode**:
   - `ui.unicode` = `never`/`always`/`auto`.
   - auto: Unix → true; Windows → true if `WT_SESSION` or `TERM_PROGRAM` is set, or `sapi_windows_cp_get() === 65001`; else false.
5. **Width** (integer columns):
   - `--width=N` or `ui.width` (40–200) wins.
   - Else `COLUMNS` env if 40–200.
   - Else best-effort detection (cached, refreshed at most once per second):
     - Windows: parse `mode con` output for `Columns: N`.
     - Unix: `stty size < /dev/tty`.
   - Else 100. Clamp to 40–200.
6. **Color depth:** `ui.color_depth` (`16`/`256`, default auto). Auto: 256 on Windows VT-capable consoles, `COLORTERM`/`TERM` containing `256color`, or modern Windows Terminal; otherwise 16. `0` when color is off.
7. **Windows VT:** if `PHP_OS_FAMILY === 'Windows'` and `function_exists('sapi_windows_vt100_support')`, call `@sapi_windows_vt100_support($stream, true)` for each TTY stream. If it returns false, disable color on that stream.

`Terminal::plain(): self` is the test/fallback constructor: no TTY, no color, width 100,
no banner/spinner/markdown, writers to `STDOUT`/`STDERR`.

**Shutdown safety:** `Terminal` registers a shutdown function (and an error-handler-safe
path) that shows the cursor and clears any spinner line, so Ctrl+C/fatal errors never
leave a hidden or half-drawn cursor.

### 2.4 Visual language

#### Symbols (unicode / ASCII fallback)

| Purpose | Unicode | ASCII |
|---|---|---|
| Assistant marker | `⏺` | `*` |
| Prompt | `❯` | `>` |
| Tool activity | `⚙` | `*` |
| Success | `✓` | `ok` |
| Error | `✖` | `x` |
| Warning | `⚠` | `!` |
| Info/telemetry | `◦` | `-` |
| Bullet / nested bullet | `•` / `◦` | `-` / `*` |
| Task done / todo | `☑` / `☐` | `[x]` / `[ ]` |
| Box corners/edges | `╭ ╮ ╰ ╯ ─ │` | `+ + + + - \|` |
| Spinner frames | `⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏` | `\|/-\` |

#### Color tokens (256-color, 16-color fallback)

| Token | 256 | 16 | Use |
|---|---|---|---|
| `accent` | `38;5;45` | `36` | assistant marker, prompt, headings, tool names |
| `user` | `38;5;111` | `34` | user-side accents |
| `tool` | `38;5;141` | `35` | tool activity |
| `success` | `38;5;42` | `32` | success lines |
| `warn` | `38;5;214` | `33` | warnings, approval prompts |
| `error` | `38;5;203` | `31` | errors, denies |
| `muted` | `38;5;244` | `90` | telemetry, borders, secondary text |
| `heading` | `1;38;5;45` | `1;36` | headings |
| `code` | `38;5;252;48;5;236` | `30;47` | inline code |
| `code_border` | `38;5;240` | `90` | code-block borders |
| `bold` | `1` | `1` | emphasis |
| `italic` | `3` | `3` | emphasis |
| `strike` | `9` | `9` | strikethrough |

`Theme::style()` must emit **one** SGR sequence per call (`\033[<codes>m<text>\033[0m`) so
nested styling cannot cancel an outer style. Callers must pass all styles to one call
(e.g. `style($text, 'bold', 'accent')`, not `bold(accent($text))`).

#### Layout

- Assistant text uses a 2-column gutter: first line `⏺ `, continuation lines `  `.
- Chrome (banner, approval, tool detail boxes) is capped at `min(terminalWidth, 100)` columns.
- Markdown prose uses `terminalWidth - 2` columns.
- Minimum width is 40; below that everything clamps to 40 and the terminal may hard-wrap.
- One blank line between turn groups; no blank line before the prompt.

### 2.5 Component interfaces

```php
final class Ansi
{
    public static function strip(string $text): string;                 // remove ANSI only
    public static function sanitize(string $text): string;              // strip ANSI + control chars
    public static function visibleWidth(string $text): int;
    public static function truncate(string $text, int $width, string $ellipsis = '…'): string;
    public static function truncateMiddle(string $text, int $width, string $ellipsis = '…'): string;
    /** @return list<string> */
    public static function wrap(string $text, int $width): array;
    public static function pad(string $text, int $width, string $align = 'left'): string;
    /** @param list<string> $lines @return list<string> */
    public static function box(string $title, array $lines, int $width): array;
    public static function expandTabs(string $text, int $tabSize = 4): string;
}

final class Theme
{
    public function __construct(bool $color, bool $unicode, int $depth = 256);
    public function color(): bool;
    public function unicode(): bool;
    public function style(string $text, string ...$styles): string;
    public function symbol(string $name): string;
}

final class Terminal
{
    /** @param null|Closure(string): void $out @param null|Closure(string): void $err */
    public function __construct(
        bool $outTty,
        bool $errTty,
        array $options = [],   // color, unicode, markdown, spinner, banner, usage,
                               // width, tool_details, color_depth
        ?Closure $out = null,
        ?Closure $err = null,
    );
    public static function detect(array $ui = [], bool $plain = false): self;
    public static function plain(): self;

    public function width(): int;
    public function colorOut(): bool;
    public function colorErr(): bool;
    public function unicode(): bool;
    public function markdown(): bool;
    public function spinnerEnabled(): bool;

    public function out(string $text): void;
    public function err(string $text): void;
    public function prompt(): void;

    public function banner(array $facts): void;
    public function assistant(string $markdown): void;
    public function toolStart(string $name, array $input): void;
    public function toolResult(string $name, array $input, string $result, bool $isError, float $duration): void;
    public function usage(array $usage, float $duration, string $stopReason): void;
    public function securityApproval(array $call, array $decision): void;
    public function securityDenied(array $decision): void;
    public function securityResolved(bool $approved): void;
    public function info(string $text): void;
    public function warning(string $text): void;
    public function error(string $text): void;

    public function spinner(string $label = 'Thinking'): ?Spinner;
    public function progressLine(string $text): void;  // \r + erase line + text (STDERR)
    public function progressClear(): void;
    public function hideCursor(): void;
    public function showCursor(): void;
}

final class Markdown
{
    public function __construct(int $width, Theme $theme);
    public function render(string $markdown): string;  // always ends with "\n"; empty input → ""
}

final class Spinner
{
    public function __construct(Terminal $terminal, string $label = 'Thinking');
    public function start(): void;
    public function tick(): void;
    public function stop(): void;
    public function elapsed(): float;
}
```

### 2.6 Config, environment, CLI

`config.example.json` gains (order: after `security`, before `mcpServers`):

```json
"ui": {
  "color": "auto",
  "unicode": "auto",
  "markdown": true,
  "spinner": true,
  "banner": true,
  "usage": true,
  "width": 0,
  "tool_details": "summary"
}
```

- `color`: `auto|always|never`
- `unicode`: `auto|always|never`
- `width`: `0` (detect) or `40..200`
- `tool_details`: `summary|full|hidden`
- An `ui` value that is not an object throws `RuntimeException('ui must be an object')`;
  individual invalid values fall back to defaults (cosmetic config must not block startup).

Environment:

- `NO_COLOR` (non-empty) → color off.
- `FORCE_COLOR` (non-empty, not `0`) → color on, unless `NO_COLOR`.
- `COLUMNS` → width, if `ui.width`/`--width` are not set.

New CLI flags parsed in `agent.php`:

| Flag | Effect |
|---|---|
| `--plain` | Fully raw legacy output (no color, layout, banner, spinner, telemetry). |
| `--no-color` | Color off; layout/Markdown remain (structured but monochrome). |
| `--width=N` | Force terminal width (clamped 40–200). |
| `--no-banner` | Suppress the startup banner. |
| `--tool-details=MODE` | `summary` (default), `full`, `hidden`. |

Extend the `getopt(...)` call to:
`['config:', 'model:', 'max-steps:', 'command-timeout:', 'session:', 'continue', 'help', 'plain', 'no-color', 'width:', 'no-banner', 'tool-details:']`.

### 2.7 Robustness / security rules

- **Untrusted text is sanitized before rendering**: assistant text, tool names/inputs/results, MCP payloads, error messages, and session/banner values pass through `Ansi::sanitize()` (removes ANSI CSI/OSC sequences and control chars except `\n`/`\t`). This prevents terminal escape/clipboard-OSC injection from model or tool output.
- **No width surprise**: every chrome line is padded/truncated to its computed width; `Ansi::box()` asserts equal visible width in tests.
- **No new network/API calls**; no prompt changes; token usage unchanged.
- **Spinner cannot break the API**: progress callbacks are wrapped in `try/catch (Throwable)` inside `AnthropicClient`.
- **Model/tool output only**: never execute or interpret ANSI from untrusted text.

---

## 3. Milestones

Each milestone is independently committable. Run every `php -l` command listed before moving on.

### M0 — Baseline and safety net

**Goal:** Confirm the current suite is green and freeze the plain-output contract in tests.

**Files:** `tests/ui.php` (created in M1), no source changes.

**Steps:**
1. Run `php tests/smoke.php`; confirm `Smoke tests passed`.
2. Record the exact plain assistant contract used today (`src/Agent.php:841`): `rtrim($text) . "\n"`.
3. Record the exact `AskUser` plain strings (`tests/smoke.php:84–88`) that must not change.

**Validation:**
```powershell
php tests/smoke.php
```
Expected: exit 0, `Smoke tests passed`.

---

### M1 — `Ansi` foundation + pure tests

**Goal:** Build and test every low-level text primitive before any output is wired.

**Files:**
- Create `src/Ansi.php`.
- Create `tests/ui.php` (pure tests; no API).
- Modify `tests/smoke.php` to `require __DIR__ . '/ui.php';` (after the existing requires) so the one documented command runs everything.

**Required behavior:**

1. `strip()` removes CSI (`\e[...m`, cursor moves), OSC (`\e]...\a` / `\e]...\e\\`), and single-character escapes; leaves visible text.
2. `sanitize()` = `strip()` plus removal of `\x00-\x08`, `\x0B`, `\x0C`, `\x0E-\x1F`, `\x7F`, and C1 `\x80-\x9F`; normalizes `\r\n` → `\n`.
3. `visibleWidth()` ignores ANSI and sums character widths:
   - Combining marks = 0.
   - East-Asian wide + emoji ranges = 2.
   - Otherwise 1.
   - Use `mb_strwidth()` per character when `mbstring` is loaded; otherwise use the explicit range table below.
4. `wrap($text, $width)`:
   - Preserves explicit `\n`.
   - Breaks at spaces; hard-splits over-long tokens by visible width without splitting a wide char or an ANSI sequence.
   - Carries the active SGR sequence across line boundaries (track the last non-reset `\e[...m`; on break emit `\e[0m`, then re-emit the active sequence).
   - Never emits a line wider than `$width` (after stripping ANSI).
5. `truncate()` appends the ellipsis only when truncation occurs; `truncateMiddle()` is used for long paths.
6. `pad()` pads by **visible** width; ANSI content is handled correctly.
7. `box($title, $lines, $width)`:
   - Width includes borders; inner content width = `$width - 4` (`│ ` + content + ` │`).
   - Title line `╭─ Title ─────╮` padded to exact width.
   - `truncate()`/`pad()` each content line.
   - Every returned line has `visibleWidth === $width`.
8. `expandTabs()` expands to the next multiple of 4.

**Character-width table (fallback):**
- Zero width: U+0300–U+036F, U+1AB0–U+1AFF, U+1DC0–U+1DFF, U+20D0–U+20FF, U+FE20–U+FE2F.
- Width 2: U+1100–U+115F, U+2329–U+232A, U+2E80–U+303E, U+3041–U+33FF, U+3400–U+4DBF, U+4E00–U+9FFF, U+A000–U+A4CF, U+AC00–U+D7A3, U+F900–U+FAFF, U+FE10–U+FE19, U+FE30–U+FE6F, U+FF00–U+FF60, U+FFE0–U+FFE6, U+1F300–U+1FAFF, U+20000–U+3FFFD.

**Tests to add in `tests/ui.php`:**

```php
check(Ansi::strip("\033[31mred\033[0m") === 'red', 'strip failed');
check(Ansi::sanitize("\e]52;c;Zm9v\ahello") === 'hello', 'OSC injection not removed');
check(Ansi::sanitize("a\e[31mb\e[0m") === 'ab', 'CSI injection not removed');
check(Ansi::visibleWidth('中a') === 3, 'CJK width wrong');
check(Ansi::visibleWidth("\033[1mabc\033[0m") === 3, 'ANSI width wrong');
check(Ansi::wrap('one two three', 7) === ['one two', 'three'], 'wrap failed');
check(Ansi::wrap(str_repeat('x', 25), 10) === ['xxxxxxxxxx', 'xxxxxxxxxx', 'xxxxx'], 'hard wrap failed');
check(count(Ansi::box('T', ['hello'], 20)) === 3, 'box height wrong');
foreach (Ansi::box('T', ['hello', '中'], 20) as $line) {
    check(Ansi::visibleWidth($line) === 20, 'box width wrong');
}
check(Ansi::truncate('abcdefgh', 5) === 'abcd…', 'truncate failed');
check(Ansi::truncateMiddle('D:/very/long/path/file.php', 16) === 'D:/very…file.php', 'middle truncate failed');
```

Also add a loop asserting `max(visibleWidth(line)) <= width` for widths 40, 60, 80, 120 over a mixed CJK/emoji/ANSI corpus.

**Validation:**
```powershell
php -l src/Ansi.php
php tests/smoke.php
```
Expected: no lint errors; both `UI tests passed` (printed by `tests/ui.php`) and `Smoke tests passed`.

---

### M2 — `Theme` + `Terminal` capabilities, banner, prompt, status

**Goal:** Introduce the session renderer and wire startup/prompt/errors. Assistant output
stays raw in this milestone (Markdown arrives in M3), keeping the change easy to review.

**Files:**
- Create `src/Theme.php`.
- Create `src/Terminal.php`.
- Modify `agent.php` (flags, requires, config, construction, banner, status, prompt, errors, help).
- Modify `config.example.json` (add `ui`).
- Modify `tests/ui.php` (Theme/Terminal tests).

**Steps:**

1. **`Theme`**
   - Implement the token table from §2.4.
   - `style()` builds one SGR sequence; when `color === false`, returns the text unchanged.
   - `symbol()` maps from §2.4; ASCII when `unicode === false`.
   - Depth `16` maps each token to its 16-color code; depth `256` uses the 256 codes.

2. **`Terminal::__construct` / `detect` / `plain`**
   - Store writers (`$out`, `$err`) as closures; defaults `fwrite(STDOUT|STDERR, $text)`.
   - Build `Theme` for each channel (out theme, err theme) from per-stream color flags.
   - Implement detection per §2.3.
   - Enable Windows VT before the first write; disable color for a stream if enabling fails.
   - `register_shutdown_function` → `showCursor()` + `progressClear()`.

3. **`Terminal` output primitives**
   - `out($text)`: `@fflush(STDERR)`, write, flush.
   - `err($text)`: `@fflush(STDOUT)`, write, flush.
   - `prompt()`:
     - styled: `$this->out($themeOut->style('❯ ', 'bold', 'accent'))`
     - plain: `$this->out('you> ')`
   - `info()`, `warning()`, `error()`:
     - styled: `◦ text`, `⚠ text`, `✖ text` with muted/warn/error colors.
     - plain: `text`, `Warning: text`, `Error: text` (preserve current `Error:` prefix for scripts).
   - `banner(array $facts)`:
     - Only when `errTty`, `options['banner']`, and not plain.
     - Build a box capped at `min(width, 100)`, title `MyAgent`.
     - Rows: `model`, `workspace` (middle-truncated path), `session` (`—` when absent; `(resume)` when continuing), `security`, `tools`, `mcp`.
     - Footer hint line: `Type a task. /continue resumes unfinished work. Ctrl+C exits.`
     - If width < 60, render the same facts as aligned `label  value` lines without a box.
   - Cursor/progress primitives per §2.5.

4. **`agent.php`**
   - Add the new `getopt` flags and `--plain`/`--no-color`/`--width`/`--no-banner`/`--tool-details` resolution into `$uiOptions`.
   - Require the new files at the top in dependency order:
     `Ansi.php`, `Theme.php`, `Markdown.php`, `Terminal.php`, `Spinner.php` (Markdown/Spinner may not be used until M3/M4, but loading them is harmless).
   - Build `$ui = Terminal::detect($uiOptions, $plain);` immediately after `$config` is validated. Errors before that point keep a raw `fwrite(STDERR, ...)`.
   - Replace raw startup writes:
     - MCP: collect per-server result; after the loop, `$ui->info("MCP {$name} connected ({$count} tools)")` for successes and `$ui->warning("MCP {$name} unavailable: ...")` for failures. (Do not print success lines before the banner; see step 5.)
     - Reviewer: fold into banner facts; if the reviewer fails to construct, use `$ui->error(...)`.
     - Step-limit and unfinished-work notices: `$ui->warning(...)`.
     - Top-level catch: `$ui->error("Error: {$e->getMessage()}")`, exit 1.
   - `--help`: build a `Terminal::detect([], $plain)` first (no config needed), print a styled usage screen when STDOUT is a TTY, raw text otherwise. Include the new flags and the interactive commands (`/continue`, `Ctrl+C`).
   - Do **not** pass `$ui` to `new Agent(...)` yet; M4 adds the 11th constructor argument and wires it there.

5. **Startup order**
   1. Validate config, build `$ui`.
   2. Discover skills, build `AskUser`.
   3. Connect MCP servers, collecting `connected`/`unavailable` results (no output yet).
   4. Build the security reviewer (no output yet).
   5. Print the banner with full facts.
   6. Print MCP failure warnings and (optionally) connected lines.
   7. Print the prompt / run the initial prompt.

6. **Banner facts**
   ```php
   $ui->banner([
       'model'    => $model,
       'workspace'=> $root,
       'session'  => isset($options['session']) ? (string) $options['session'] . (isset($options['continue']) ? ' (resume)' : '') : null,
       'security' => $security !== null
           ? 'reviewer ' . $reviewerModel . ((bool) ($securityOptions['fail_closed'] ?? true) ? ' · fail-closed' : '')
           : 'off',
       'tools'    => count($local->definitions()) . ' local',
       'mcp'      => count($mcp) . ' server' . (count($mcp) === 1 ? '' : 's'),
   ]);
   ```

**Tests to add in `tests/ui.php`:**

```php
$out = ''; $err = '';
$capOut = static function (string $t) use (&$out): void { $out .= $t; };
$capErr = static function (string $t) use (&$err): void { $err .= $t; };

$plain = Terminal::plain();
$plain->prompt();
check($out === '', 'plain terminal wrote to captured stream unexpectedly');

$t = new Terminal(true, true, ['color' => 'always', 'unicode' => 'always', 'width' => 60], $capOut, $capErr);
$t->banner(['model' => 'test', 'workspace' => 'D:/Project/MyAgent', 'session' => null, 'security' => 'off', 'tools' => '5 local', 'mcp' => '0 servers']);
check(str_contains($err, 'MyAgent'), 'banner title missing');
check(str_contains($err, 'test'), 'banner model missing');
check(str_contains($err, "\033["), 'banner not styled');
foreach (explode("\n", trim($err)) as $line) {
    check(Ansi::visibleWidth($line) <= 60, 'banner exceeds width');
}

$noColor = new Terminal(true, true, ['color' => 'never', 'unicode' => 'never', 'width' => 60], $capOut, $capErr);
$noColor->prompt();
check(!str_contains($out, "\033["), 'plain prompt contains ANSI');
check(str_contains($out, 'you>'), 'plain prompt text missing');
```

**Validation:**
```powershell
php -l src/Theme.php
php -l src/Terminal.php
php -l agent.php
php tests/smoke.php
php agent.php --help
```
Manual: `php agent.php` in Windows Terminal shows a banner and `❯ ` prompt before any task; `Ctrl+C` exits and restores the cursor.

---

### M3 — Markdown renderer and assistant output

**Goal:** Render assistant replies as Markdown in a TTY, with a strict raw fallback.

**Files:**
- Create `src/Markdown.php`.
- Modify `src/Agent.php` (`loop()` text emission).
- Modify `tests/ui.php` (renderer tests).
- Create `tests/render-demo.php` (used as a visual gallery from M3 onward; expand in M6).

**Renderer requirements:**

1. **Container:** constructor `(int $width, Theme $theme)`. `render()` normalizes `\r\n`, sanitizes untrusted text, expands tabs, and ends with `"\n"` (empty input → `""`).
2. **Blocks:**
   - ATX headings `#{1,6} `:
     - H1 → `◆ Title` bold accent; H2 → `▸ Title` bold accent; H3 → `▹ Title` bold; H4–H6 → Title bold muted (ASCII: `#`, `##`, `###`).
   - Paragraphs: wrap at `width`.
   - Unordered lists (`-`, `*`, `+`) with 2-space nesting (max 4 levels): `•` / `◦` / `▪`, continuation lines indented under the content.
   - Ordered lists `1.`: number in accent, continuation aligned.
   - Task lists `- [ ]` / `- [x]`: `☐` / `☑` (ASCII `[ ]` / `[x]`).
   - Blockquotes `> `: one level, `│ ` muted prefix on every line.
   - Horizontal rules (`---`, `***`, `___`): `─` × width, muted.
   - Fenced code (``` or ~~~, optional language):
     ```
     ┌─ php ────────────────────
     │ <?php
     │ echo "hi";
     └──────────────────────────
     ```
     Language label muted; long lines wrapped at `width - 4` with a `│ ` continuation.
     ASCII corners use `+`, `-`, `|`.
   - GFM tables: header + delimiter row; compute widths; box-draw with `┌┬┐ ├┼┤ └┴┘ ─ │`; alignment from `:---`, `:---:`, `---:`; wrap cell content; if the minimum width exceeds the available width, degrade to a key/value list.
3. **Inline:**
   - Code spans `` `code` `` → `code` on the `code` token (keep backticks when color is off).
   - `**bold**`/`__bold__`, `*italic*`/`_italic_`, `***bold italic***`, `~~strike~~`.
   - Links `[text](url)` → styled `text` plus muted ` (url)`.
   - Images `![alt](url)` → `alt (url)`.
   - Autolinks `<http://...>` and bare URLs are left as text.
   - Backslash escapes are honored; no inline parsing inside code spans.
4. **Code highlighting** (basic, no dependencies):
   - Languages: `php`, `js`/`javascript`/`ts`/`typescript`, `json`, `bash`/`sh`/`shell`/`powershell`/`ps1`, `python`/`py`, `sql`, `html`/`xml`, `diff`.
   - Token classes: comments, strings, numbers, keywords, variables (`$foo`, `%VAR%`, `{{...}}` minimal), function calls, operators/punctuation.
   - Diff blocks: `+` lines success, `-` lines error, `@@` accent.
   - Unknown language → no highlighting, border only.
   - The highlighter must never reorder or drop characters; a round-trip test strips ANSI and compares to the input.
5. **Width discipline:** every line's `Ansi::visibleWidth()` ≤ width (after the 2-column gutter is accounted for by the caller).

**`src/Agent.php` change (around line 839–843):**

Replace per-block `fwrite(STDOUT, ...)` with:

```php
$texts = [];
foreach ($content as $block) {
    if (is_array($block) && ($block['type'] ?? '') === 'text') {
        $text = (string) ($block['text'] ?? '');
        if (trim($text) !== '') {
            $texts[] = $text;
        }
    }
}
if ($texts !== []) {
    $this->terminal->assistant(implode("\n", $texts));
}
```

`Terminal::assistant()`:

- If `!$this->outTty || !$this->options['markdown'] || $this->plain`:
  - `$this->out(Ansi::sanitize($text) . "\n");` for each block, preserving the current raw contract (`rtrim` + `\n`).
- Else:
  - Render with `new Markdown($this->width() - 2, $this->themeOut())`.
  - Prefix the first line with `⏺ ` (accent) and every subsequent line with two spaces.
  - Emit one string ending with `"\n\n"`.

**Tests to add in `tests/ui.php`:**

```php
$theme = new Theme(true, true, 256);
$md = new Markdown(60, $theme);
$rendered = $md->render("# Title\n\n**bold** and `code`\n\n- one\n- two\n\n```php\necho \"hi\";\n```\n");
check(!str_contains($rendered, '**'), 'bold markers survived');
check(str_contains($rendered, 'bold'), 'bold text missing');
check(str_contains($rendered, "\033["), 'markdown not styled');
foreach (explode("\n", $rendered) as $line) {
    check(Ansi::visibleWidth($line) <= 60, 'markdown exceeds width');
}

$monoTheme = new Theme(false, false, 0);
$mono = (new Markdown(50, $monoTheme))->render("| A | B |\n| --- | --- |\n| 1 | 2 |\n");
check(!str_contains($mono, "\033["), 'monochrome markdown contains ANSI');
check(str_contains($mono, 'A') && str_contains($mono, '1'), 'table content missing');

$long = (new Markdown(40, $theme))->render(str_repeat('word ', 40));
foreach (explode("\n", $long) as $line) {
    check(Ansi::visibleWidth($line) <= 40, 'long paragraph exceeds width');
}
```

Also test: headings, ordered/task lists, blockquotes, rules, links, code-fence round-trip,
Sanitization of model-supplied ANSI, and CJK wrapping.

**Validation:**
```powershell
php -l src/Markdown.php
php -l src/Agent.php
php tests/smoke.php
php tests/render-demo.php
```
Manual: run an interactive task whose answer contains headings/lists/code; confirm styled
rendering and no line overflow.

---

### M4 — Tool activity, telemetry, security chrome, spinner

**Goal:** Replace every remaining raw output point in `Agent` and `agent.php` with
rendered equivalents, add timing/token telemetry, and animate model waits.

**Files:**
- Create `src/Spinner.php`.
- Modify `src/Agent.php` (constructor, loop, executeBlock, resume, step-limit).
- Modify `src/Agent.php`'s `AnthropicClient` (progress callback hook).
- Modify `agent.php` (security approval closure).
- Modify `tests/ui.php` (tool summary/spinner tests).
- Modify `tests/smoke.php` (UI integration tests).

**Step 1 — `AnthropicClient` progress hook**

Add:

```php
use Closure;

private ?Closure $progress = null;

public function setProgressCallback(?Closure $progress): void
{
    $this->progress = $progress;
}

private function tick(): void
{
    if ($this->progress !== null) {
        try { ($this->progress)(); } catch (Throwable) { /* UI must never break API calls */ }
    }
}

private function waitWithTicks(float $seconds): void
{
    $deadline = microtime(true) + $seconds;
    while (($remaining = $deadline - microtime(true)) > 0) {
        $this->tick();
        usleep((int) (min($remaining, 0.1) * 1_000_000));
    }
}
```

In `message()`:
- Replace the retry `usleep(...)` at line 92 with `$this->waitWithTicks($wait);`.
- When `$this->progress !== null`, add to the curl options:
  ```php
  CURLOPT_NOPROGRESS => false,
  CURLOPT_XFERINFOFUNCTION => function ($ch, $downloadTotal, $downloaded, $uploadTotal, $uploaded): int {
      $this->tick();
      return 0;
  },
  ```
- Do not change the request body, headers, retry policy, or return shape.

**Step 2 — `Spinner`**

- `start()`: record `microtime(true)`; if `!$terminal->spinnerEnabled()`, do nothing.
- `tick()`: throttle to 100 ms; compute frame from `(int) (elapsed * 10) % count(frames)`;
  call `$terminal->progressLine(frame + ' ' + label + ' ' + elapsed)`, using accent for
  the frame and muted for elapsed.
- `stop()`: clear the progress line (`progressClear()`), mark stopped.
- Frames: unicode `⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏`; ASCII `|/-\`.
- `elapsed()`: seconds since `start()`.

**Step 3 — `Agent` constructor**

Append the 11th parameter:

```php
private ?Terminal $terminal = null,
```

In the constructor body:

```php
$this->terminal ??= Terminal::plain();
```

This preserves every existing positional call in `tests/smoke.php` (they pass at most 10
arguments).

**Step 4 — `Agent::loop()` model call and telemetry**

Time only the model call; print the telemetry line **after** the assistant text has been
rendered, so the visual order is: assistant prose → telemetry → tool activity.

```php
$started = microtime(true);
$spinner = $this->terminal->spinner('Thinking');
$this->api->setProgressCallback($spinner === null ? null : static fn() => $spinner->tick());
try {
    $response = $this->api->message($this->model, $this->maxTokens, $system, $this->messages, $tools);
} finally {
    $this->api->setProgressCallback(null);
    $spinner?->stop();
}
$modelDuration = microtime(true) - $started;
// … append the assistant message and render its text blocks (M3) …
// Then, after the text render and before executing tools:
$this->terminal->usage(
    (array) ($response['usage'] ?? []),
    $modelDuration,
    (string) ($response['stop_reason'] ?? 'end_turn')
);
```

- The spinner is stopped before any assistant text is written.
- `Terminal::usage()`:
  - Only when `errTty`, `options['usage']`, and not plain.
  - One dim line to STDERR: `◦ 1.2k in · 348 out · 2.4s · end_turn`.
  - Include cache tokens when present: `1.2k in (0.9k cached) · ...`.
  - Format `>= 1e6` as `1.1M`, `>= 1e3` as `1.2k`, else the integer.
  - Suppress entirely when usage is empty and `$duration < 0.05`? No: always show elapsed when usage exists or duration ≥ 1s; otherwise show elapsed only. Keep deterministic: if `usage` is empty, print `◦ 2.4s · end_turn`.
- Compaction blocks: when `$firstCompaction !== null`, call `$this->terminal->info('Context compacted by the provider.')` after the checkpoint (styled `◦`, plain line otherwise).

**Step 5 — tool activity**

In the call loop (around line 863):

```php
$startedTool = microtime(true);
$name = (string) ($call['name'] ?? '');
$input = (array) ($call['input'] ?? []);
$this->terminal->toolStart($name, $input);
$result = $this->executeBlock($call);
$this->terminal->toolResult(
    $name,
    $input,
    (string) ($result['content'] ?? ''),
    (bool) ($result['is_error'] ?? false),
    microtime(true) - $startedTool
);
$results[] = $result;
```

Remove the raw `fwrite(STDERR, "\033[36m· ...` line at `src/Agent.php:883` (the
`executeBlock` method no longer prints the start line; `toolStart` does).

`Terminal::toolStart($name, $input)`:
- Plain / non-TTY: `· {$name} {compact JSON}` (legacy text, no ANSI), STDERR.
- Styled: `⚙ {$name}  {semantic summary}`, tool name in `tool` color, summary muted.
- Semantic summary by tool:
  - `read_file`/`list_files`/`write_file`: `path=...` (middle-truncate at 60)
  - `read_skill`: `name=...`
  - `run_command`: `$ ` + command (truncate at 100)
  - `ask_user`: `"question"` (truncate at 80)
  - MCP / unknown: compact JSON (truncate at 100)
- `ui.tool_details=hidden`: print nothing; errors still render in `toolResult`.

`Terminal::toolResult($name, $input, $result, $isError, $duration)`:
- Plain / non-TTY: print nothing extra (legacy behavior; the tool result is returned to the model, not shown). **Exception:** error results print `  Error: ...` to STDERR in plain mode (today errors are silent, but this is a deliberate improvement; keep it one line).
- Styled, `summary`:
  - Success indicator `✓` (success) or `✖` (error) on a continuation line:
    `  ✓ 32 lines · 3 ms`
  - Summaries:
    - `read_file`/`read_skill`: `N lines · N B`
    - `write_file`: the `Wrote N bytes...` message
    - `list_files`: `N entries`
    - `run_command`: parse `[exit code: N]`; `exit N · N lines · duration`; append the first non-empty output line (muted, truncated) on a third line only when it exists and `tool_details=full` or the exit code is non-zero.
    - `ask_user`: `Answer: <text>` (the user's own answer)
    - MCP/unknown: first line of the result (truncated) · `N lines`
  - Error: `✖ <first line of the error>` in `error` color, plus duration.
- Styled, `full`: before the summary, print a box (max 100 cols) with `tool`, `duration`, input JSON (clipped 2000 chars), and result (clipped 60 lines / 8000 chars, with `… (N more lines)`). Border muted; title tool name.
- `hidden`: only error results print.

**Step 6 — security chrome**

In `Agent::executeBlock()`:
- Replace the raw deny `fwrite(...)` at line 900 with `$this->terminal->securityDenied($decision);`.
- On `user-approved` decisions, call `$this->terminal->securityResolved(true);`.
- On `user-declined`, call `$this->terminal->securityResolved(false);` before returning.

`Terminal::securityDenied($decision)`:
- Plain: `security {$verdict} ({$risk}): {$reason}` (legacy text, no ANSI).
- Styled: `✖ security deny (high · category): reason` in error color, wrapped to width.

`Terminal::securityResolved(bool $approved)`:
- Styled: `  ✓ approved` (success) / `  ✖ declined` (error).
- Plain: `approved` / `declined`.

`Terminal::securityApproval($call, $decision)` (used by `agent.php`):
- Plain: reproduce the current text exactly (yellow-less):
  ```
  Security approval required for {name} (risk: {RISK}, {category})
    Why: {reason}
    Action: {json, 700-char clip}
    Approve? [y/N] 
  ```
- Styled: a warning box (max 100 cols):
  ```
  ╭─ ⚠ Security approval required ───────────────────────╮
  │ tool    run_command                                  │
  │ risk    HIGH · shared-state                          │
  │ reason  Pushes to a shared branch.                    │
  │ action  {"command":"git push origin main"}           │
  ╰──────────────────────────────────────────────────────╯
  Approve? [y/N] 
  ```
  Wrap the reason and action; clip the action at 700 chars as today.

Replace the closure in `agent.php:131–147` with:

```php
$askUser = static function (array $call, array $decision) use ($readLine, $ui): bool {
    $ui->securityApproval($call, $decision);
    $line = $readLine();
    return $line !== null && in_array(strtolower(trim($line)), ['y', 'yes'], true);
};
```

**Step 7 — remaining `Agent` raw writes**

- `resume()` line 765 `Nothing to resume...` → `$this->terminal->info(...)`.
- `loop()` line 873 step-limit → `$this->terminal->warning("Step limit ({$this->maxSteps}) reached with work in progress. Resume with --session --continue.")`.
- Delete `summarize()` if `Terminal::toolStart` fully replaces it; otherwise keep it for plain mode (preferred: move the logic to `Terminal` and delete the private method).

**Tests to add in `tests/ui.php`:**

```php
$t = new Terminal(true, true, ['color' => 'always', 'unicode' => 'always', 'width' => 70], $capOut, $capErr);
$t->toolStart('read_file', ['path' => 'README.md']);
$t->toolResult('read_file', ['path' => 'README.md'], "line1\nline2", false, 0.003);
check(str_contains($err, 'read_file'), 'tool name missing');
check(str_contains($err, '2 lines'), 'line count missing');

$err = '';
$t->toolResult('run_command', ['command' => 'false'], "output\n[exit code: 1]", false, 1.2);
check(str_contains($err, 'exit 1'), 'exit code missing');

$err = '';
$t->securityDenied(['verdict' => 'deny', 'risk' => 'high', 'category' => 'destructive', 'reason' => 'Nope']);
check(str_contains($err, 'deny'), 'deny text missing');

$plain = new Terminal(false, false, ['color' => 'never', 'unicode' => 'never'], $capOut, $capErr);
check($plain->spinner('Thinking') === null, 'plain terminal returned a spinner');
```

Spinner tests: with a `Terminal` whose stderr is/was TTY, assert `start()/tick()/stop()`
writes contain `\r` and `\033[2K` and that the elapsed text appears; with a non-TTY
terminal, assert no writes.

**Tests to add in `tests/smoke.php` (integration):**

```php
// Styled UI: assistant text is rendered, not raw Markdown.
$uiOut = ''; $uiErr = '';
$ui = new Terminal(true, true, ['color' => 'always', 'unicode' => 'always', 'width' => 72, 'usage' => false],
    static function (string $t) use (&$uiOut): void { $uiOut .= $t; },
    static function (string $t) use (&$uiErr): void { $uiErr .= $t; });
$styled = new class('unused') extends AnthropicClient {
    public function message(string $model, int $maxTokens, string $system, array $messages, array $tools): array {
        return ['content' => [['type' => 'text', 'text' => "# Done\n\n**ok**"]], 'stop_reason' => 'end_turn'];
    }
};
(new Agent($styled, $local, [], 'test', 100, 2, null, 24000, 2048, null, $ui))->run('Hi');
check(str_contains($uiOut, 'Done') && !str_contains($uiOut, '**'), 'assistant markdown not rendered');
check(str_contains($uiOut, "\033["), 'styled assistant output has no ANSI');

// Plain compatibility: byte-identical raw assistant output.
$plainOut = '';
$plain = new Terminal(false, false, ['color' => 'never', 'unicode' => 'never', 'markdown' => false, 'usage' => false],
    static function (string $t) use (&$plainOut): void { $plainOut .= $t; },
    static function (string $t): void {});
(new Agent($styled, $local, [], 'test', 100, 2, null, 24000, 2048, null, $plain))->run('Hi');
check($plainOut === "# Done\n\n**ok**\n", 'plain assistant output changed');
```

**Validation:**
```powershell
php -l src/Spinner.php
php -l src/Agent.php
php -l agent.php
php tests/smoke.php
```
Manual: an interactive task with at least one tool call shows a spinner, a tool line, a
result summary, and a telemetry line; a denied call shows the styled deny line.

---

### M5 — `ask_user` styling and Windows/Unicode polish

**Goal:** Style the question prompt without changing its plain text or input semantics.

**Files:**
- Modify `src/AskUser.php`.
- Modify `tests/ui.php` and `tests/smoke.php` if needed.
- Modify `agent.php` to pass `$ui` into `new AskUser(...)`.

**Steps:**

1. Constructor becomes:
   ```php
   public function __construct(
       ?Closure $readLine = null,
       ?Closure $write = null,
       ?Terminal $terminal = null,
   )
   ```
2. Store `$terminal`. The default writer remains `fwrite(STDERR, $text)` when neither
   `$write` nor `$terminal` is supplied (so existing tests are untouched).
3. `ask()` rendering:
   - Plain (no terminal, or terminal without color):
     ```php
     ($this->write)("\n[?] {$question}\n");
     // options exactly as today
     ```
   - Styled:
     - `[?]` accent + bold, question bold.
     - Option numbers accent; option text default; `Other` line muted.
     - If an option ends with `(Recommended)`, append a muted ` ★` (ASCII `*`) marker — display only; the returned string is unchanged.
     - Input prompt accent: `Select 1-3, type a custom answer, or press Enter to cancel: `.
   - The strings `1) staging`, `3) Other - type your own answer`, and the input prompt text
     must remain present in both modes (tests rely on them).
4. Windows/Unicode polish (mostly in `Terminal`):
   - Verify VT enabling on STDERR before the first styled write.
   - If `ui.unicode=never` or auto-detection fails, every symbol/box/spinner uses ASCII.
   - Test in `cmd.exe` with a non-UTF-8 code page: no mojibake, ASCII boxes.
5. `agent.php`: `$askUser = $isTty ? new AskUser($readLine, null, $ui) : new AskUser();`

**Tests to add:**

```php
$rendered = '';
$scriptedOut = static function (string $t) use (&$rendered): void { $rendered .= $t; };
$styled = new Terminal(true, true, ['color' => 'always', 'unicode' => 'always'], null, $scriptedOut);
$ask = new AskUser(static fn() => "2\n", $scriptedOut, $styled);
check($ask->ask('Pick', ['staging', 'production']) === 'production', 'styled ask failed');
check(str_contains($rendered, '1) staging'), 'styled ask lost plain option text');
check(str_contains($rendered, "\033["), 'styled ask has no ANSI');

$plainRendered = '';
$plain = new Terminal(false, false, ['color' => 'never', 'unicode' => 'never'], null,
    static function (string $t) use (&$plainRendered): void { $plainRendered .= $t; });
$askPlain = new AskUser(static fn() => "1\n", null, $plain);
$askPlain->ask('Pick', ['A']);
check(!str_contains($plainRendered, "\033["), 'plain ask contains ANSI');
check(str_contains($plainRendered, '[?] Pick'), 'plain ask text changed');
```

**Validation:**
```powershell
php -l src/AskUser.php
php -l agent.php
php tests/smoke.php
```
Manual: trigger `ask_user` (ask the agent a question that requires a choice), verify the
styled list and that answers still work; run with `NO_COLOR=1` and confirm plain output.

---

### M6 — Demo, docs, and full verification

**Goal:** Make the visual system reviewable, document it, and run the complete matrix.

**Files:**
- Create `tests/render-demo.php`.
- Modify `README.md`.
- Modify `config.example.json` (if not already updated in M2).
- Modify `tests/smoke.php` (final integration tests).
- Update `IMPLEMENTATION_PLAN-cli-beautification.md` status when done.

**`tests/render-demo.php`:**

- Requires the new classes; accepts `--color=never|always`, `--width=N`, `--ascii`.
- Prints, with section labels:
  1. Banner (model/workspace/session/security/tools/MCP).
  2. Styled prompt.
  3. Markdown showcase: headings, bold/italic, inline code, ordered/unordered/task lists, blockquote, rule, code fence (php/json/bash/diff), and a table.
  4. Tool activity: `read_file`, `run_command` success, `run_command` failure, `ask_user` answer.
  5. Telemetry line.
  6. Security deny + approval box.
  7. Long-line/CJK/emoji wrapping at the chosen width.
- Ends with a plain-mode section that asserts (in-process) no `\033` bytes.
- Exit 0 always; intended for humans.

**README additions:**

- New `## Terminal UI` section:
  - What interactive mode looks like (fenced code sample).
  - `ui` config block and each key.
  - Flags: `--plain`, `--no-color`, `--width`, `--no-banner`, `--tool-details`.
  - Environment: `NO_COLOR`, `FORCE_COLOR`, `COLUMNS`.
  - Stream contract: STDERR chrome, STDOUT prose, piped output unchanged.
  - Windows notes: VT/UTF-8 detection, ASCII fallback.
  - Accessibility: symbols + labels, not color alone.
  - `--plain` is the supported mode for logs/CI.
- Update `## Verify` to mention `tests/ui.php` runs inside `tests/smoke.php` and the demo.
- Do not document internal class names unless useful.

**Final integration tests in `tests/smoke.php`:**

- Styled agent output renders Markdown (from M4).
- Plain agent output is byte-identical (from M4).
- `Terminal::plain()` never emits ANSI.
- A tool error renders an error line in styled mode.
- A security deny renders a deny line in styled mode.
- `AskUser` plain output still matches the existing assertions.
- No new API calls: assert fake client call counts are unchanged.

**Validation:**
```powershell
php -l tests/ui.php
php -l tests/render-demo.php
php tests/smoke.php
php tests/render-demo.php --color=always --width=100
php tests/render-demo.php --color=never --ascii --width=60
```

---

## 4. Testing & Verification Strategy

### 4.1 Automated tests

`php tests/smoke.php` remains the single entry point and must load `tests/ui.php`.

| Area | Test |
|---|---|
| ANSI stripping/sanitizing | CSI, OSC 52, control chars removed; visible text preserved |
| Width | ASCII, CJK, emoji, combining marks, ANSI-styled text |
| Wrapping | spaces, hard splits, explicit newlines, active-SGR carry |
| Truncation/padding/box | exact visible widths, ellipsis only when needed |
| Markdown blocks | headings, paragraphs, lists, tasks, quotes, rules, fences, tables |
| Markdown inline | bold/italic/strike/code/links; no parsing inside code |
| Markdown highlighting | round-trip content equality after stripping ANSI |
| Markdown width | every line ≤ width at 40/60/80/120 |
| Terminal degradation | non-TTY/plain emits no ANSI; NO_COLOR; ASCII fallback |
| Banner/approval boxes | equal width, title, content, no overflow |
| Tool summaries | read/write/list/run/ask/MCP/error; exit codes; line counts |
| Spinner | no-op when disabled; `\r`/erase-line when enabled |
| Agent integration | styled Markdown rendering; plain byte-identical output |
| Regression | existing smoke assertions still pass |

### 4.2 Manual runbook

Run from `D:\Project\MyAgent` in Windows Terminal (PowerShell 7):

1. `php tests/render-demo.php --color=always --width=100` — inspect every section.
2. `php tests/render-demo.php --color=always --width=60` — verify wrapping and tables.
3. `php tests/render-demo.php --color=never --ascii --width=60` — verify ASCII fallback.
4. `php agent.php --help` — styled help; no task is started.
5. `php agent.php` — banner + `❯ ` prompt; `Ctrl+C` exits; cursor is visible after exit.
6. `php agent.php --session=ui-check "List the files in this project, then read README.md"` — observe spinner, tool lines, result summaries, telemetry, Markdown.
7. Trigger `ask_user` with a task that requires a choice (e.g. "Ask me which environment to use before doing anything").
8. Trigger a security deny by asking for an action the reviewer blocks (use a harmless, deliberately blocked command such as `rm -rf /` only if it is never executed; the deterministic gate blocks it before execution). Confirm the styled deny.
9. `NO_COLOR=1 php agent.php` — no ANSI; layout may remain structured.
10. `php agent.php --plain` — fully raw legacy output.
11. Resize the terminal to 50 and 150 columns, run a new session, verify no horizontal overflow.
12. Piped: `"Summarize README.md" | php agent.php --plain > out.txt 2> err.txt` (requires a valid key). Verify `out.txt` has only assistant prose and no `ESC` bytes; exit code is 0/2 as appropriate.
13. `cmd.exe` fallback: if the code page is not UTF-8, verify ASCII glyphs and no mojibake.
14. `php tests/smoke.php` — final green.

### 4.3 Compatibility matrix

| Environment | Expected |
|---|---|
| Windows Terminal / PowerShell 7 (UTF-8) | Full unicode + 256-color |
| VS Code integrated terminal | Full unicode + color |
| `cmd.exe` legacy code page | ASCII fallback, color only if VT enabled |
| Linux/macOS terminal | Full unicode + 256-color |
| Piped STDOUT / CI | Raw prose only; no ANSI; exit codes unchanged |
| `NO_COLOR=1` | No ANSI; structured layout may remain |
| `--plain` | No ANSI, no layout, no banner, no spinner |

### 4.4 Performance checks

- Rendering is local; no extra API calls. Confirm with fake-client call-count assertions.
- Spinner redraws are throttled to ≤ 10/second.
- Rendering a 100 KB assistant response should stay under ~200 ms (spot-check; not a hard gate).
- Width detection is cached and refreshed at most once per second.

---

## 5. Deployment, Rollout & Rollback

There is no schema migration and no external service. "Deployment" is a code update.

### 5.1 Rollout sequence

1. Land M1 (pure helpers) with tests; no behavior change.
2. Land M2 (entry point/status/banner) with `ui.banner` defaulting to on for TTYs.
3. Land M3 (Markdown assistant rendering).
4. Land M4 (tools/telemetry/spinner/security).
5. Land M5 (ask_user/Windows polish).
6. Land M6 (demo/docs/tests).

Each milestone must pass `php tests/smoke.php` before the next.

### 5.2 Feature controls (no redeploy needed)

- `--plain` — instant opt-out for any broken rendering.
- `ui.color=never`, `ui.markdown=false`, `ui.spinner=false`, `ui.banner=false`, `ui.usage=false`.
- `NO_COLOR=1`.
- `ui.tool_details=hidden` — quieter output if tool cards are noisy.

### 5.3 Rollback

1. Set `--plain` (or `"markdown": false`, `"spinner": false`, `"banner": false`) to recover immediately.
2. If a renderer bug persists, revert the M3/M4 commit(s); M1/M2 remain harmless.
3. No data migration or session-format rollback is needed: session JSON is unchanged.
4. Delete `tests/render-demo.php` and the `ui` block if a full revert is required.

---

## 6. Risks & Mitigations

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Legacy Windows console shows garbled ANSI/Unicode | Medium | Medium | Detect VT + code page; ASCII fallback; `--plain` |
| Width detection wrong or unavailable | Medium | Low | `COLUMNS`/`--width`; clamp 40–200; no overflow by construction |
| Markdown renderer mangles edge-case content | Medium | Medium | TTY-only rendering; raw fallback; extensive tests; `--plain` |
| Terminal escape injection from model/tool output | Low | High | `Ansi::sanitize()` on all untrusted text; no OSC in plain mode |
| Spinner/cursor artifacts after Ctrl+C | Medium | Low | Shutdown restoration; clear before every non-spinner write |
| STDOUT/STDERR interleaving reorders output | Low | Low | Flush the other channel before each write; batch assistant output |
| Existing tests depend on exact `AskUser` text | Low | Medium | Plain path preserved byte-for-byte; styled path keeps the same substrings |
| Scope creep into a full-screen TUI | Medium | Medium | Explicit out-of-scope list; inline/scrollback design |
| Unicode width mis-measures ZWJ emoji | Low | Low | Best-effort width table; never crash; wrapping may be off by one column |
| Added complexity slows startup | Low | Low | Pure string work; width detection cached; no new dependencies |

---

## 7. File Change Summary

### New files

| Path | Purpose |
|---|---|
| `src/Ansi.php` | ANSI/width/wrap/box primitives |
| `src/Theme.php` | Color tokens and symbol fallback |
| `src/Terminal.php` | Session renderer, capability detection, all chrome |
| `src/Markdown.php` | Markdown → ANSI renderer |
| `src/Spinner.php` | Throttled waiting indicator |
| `tests/ui.php` | Pure renderer tests |
| `tests/render-demo.php` | Visual gallery for manual review |
| `IMPLEMENTATION_PLAN-cli-beautification.md` | This plan |

### Modified files

| Path | Change |
|---|---|
| `agent.php` | Requires; new flags; `ui` config; `Terminal` construction; banner; styled status/errors/help; security approval closure; pass `$ui` to `Agent` and `AskUser` |
| `src/Agent.php` | `AnthropicClient::setProgressCallback` + retry ticks; `Terminal` injection (11th constructor arg); markdown assistant output; tool start/result; telemetry; security chrome; step-limit/info messages; remove raw writes |
| `src/AskUser.php` | Optional `Terminal`; styled question/options/prompt; plain output unchanged |
| `tests/smoke.php` | Requires `tests/ui.php`; adds styled/plain integration assertions |
| `config.example.json` | Adds `ui` block |
| `README.md` | Adds Terminal UI section; documents flags/env/config; updates Verify |

### Intentionally unchanged

- `src/SecurityClassifier.php` (verdict policy and reviewer prompt).
- `src/SessionStore.php` (session JSON).
- System prompt, tool schemas, API body, compaction, retry policy, exit codes.
- `examples/echo-mcp.php`.

---

## 8. Execution Checklist

- [x] M0: `php tests/smoke.php` green baseline recorded.
- [x] M1: `src/Ansi.php` + `tests/ui.php`; ANSI/width/wrap/box tests pass.
- [x] M2: `src/Theme.php`, `src/Terminal.php`, `agent.php`, `config.example.json`; banner/prompt/status wired; help styled.
- [x] M3: `src/Markdown.php`; assistant Markdown rendering; raw plain contract preserved.
- [x] M4: `src/Spinner.php`; progress hook; tool cards; telemetry; security chrome; raw writes removed.
- [x] M5: `AskUser` styling; Windows VT/UTF-8/ASCII checks; plain text unchanged.
- [x] M6: demo script; README; final integration tests; full manual matrix.
- [x] Confirm no ANSI bytes under `--plain`, `NO_COLOR`, and non-TTY STDOUT.
- [x] Confirm no line exceeds the terminal width at 40/60/80/120 columns.
- [x] Confirm no new API calls, prompt changes, or dependencies.
- [x] `php tests/smoke.php` green.
- [x] Update this plan's status to **Implemented** and record verification dates.
- [ ] Live interactive QA against the real provider in Windows Terminal (banner, markdown, tool cards, spinner, real `ask_user`, real security approval, `NO_COLOR`, `--plain`, piped stdin).

---

## 10. Implementation Notes (2026-10-31)

### 10.1 Delivered

- `src/Ansi.php`, `src/Theme.php`, `src/Markdown.php`, `src/Terminal.php`, `src/Spinner.php`.
- `agent.php`: new flags, `ui` config, banner, styled status/help/errors, security approval
  closure, `Terminal` wired into `Agent` and `AskUser`.
- `src/Agent.php`: progress callback + retry ticks; `Terminal` injection (11th arg);
  Markdown assistant output; tool start/result cards; usage telemetry; security chrome;
  plain/raw fallback preserved.
- `src/AskUser.php`: optional `Terminal`; styled question/options/prompt; plain output
  unchanged.
- `tests/ui.php`: pure renderer/terminal tests, executed from `tests/smoke.php`.
- `tests/smoke.php`: styled Markdown, plain byte-compatibility, and tool-error integration
  assertions.
- `tests/render-demo.php`: visual gallery; `--force` renders as if attached to a TTY.
- `config.example.json`: `ui` block.
- `README.md`: Terminal UI section, flags, environment, config, and verification.

### 10.2 Verification performed

```text
php tests/smoke.php
→ UI tests passed
→ Smoke tests passed

php tests/render-demo.php --force --color=always --width=80
→ banner, Markdown, table, code, tool cards, telemetry, security, ask_user all render
→ "Plain-mode ANSI check: OK"

php tests/render-demo.php --force --color=always --width=40|60|100
→ visible-width audit: 0 lines over the limit

php agent.php --model=deepseek-flash "Reply with exactly: OK"
→ OK (real non-TTY turn)
php agent.php --plain ...
→ 0 ANSI bytes; STDOUT = assistant prose, STDERR = status
```

`tests/ui.php` also includes permanent 40-column assertions for status, usage, tool
lines, security prompts, and `ask_user`; `tests/smoke.php` asserts styled Markdown,
plain byte-compatibility, the spinner, and styled tool errors. A `TERM=dumb` assertion
confirms automatic color is disabled unless explicitly forced.

Live interactive QA (real terminal + provider) remains for the user: `php agent.php`,
`NO_COLOR=1 php agent.php`, `php agent.php --plain`, piped stdin, a real `ask_user`, and a
real security approval.

### 10.3 Deviations from the original plan

- **OSC 8 hyperlinks dropped.** Links render as styled `text (url)`. This avoids splitting
  a hyperlink's open/close sequence across wrapped lines, which could leak link state into
  following text.
- **`ui.color=always` overrides `NO_COLOR`.** An explicit config choice is treated as
  stronger than the environment default; `NO_COLOR` still wins in `auto` mode.
- **Status/usage/tool lines are width-clamped or wrapped**, not only the Markdown body, so
  narrow terminals cannot overflow.
- **`tests/render-demo.php --force`** was added so the gallery can be reviewed without an
  attached TTY.
- **Cursor/progress escapes are stateful**: `showCursor()` and `progressClear()` are no-ops
  when the corresponding state was never set, preventing stray control bytes at exit.
- **The spinner does not hide the cursor.** Hiding it risks leaving the user's cursor
  invisible if the process is killed abruptly (especially on Windows, where shutdown
  handlers are not guaranteed); the spinner redraws its line normally instead.
  `hideCursor()`/`showCursor()` remain available on `Terminal` for future use.

---

## Appendix A — Visual mockups

### A.1 Interactive startup

```text
╭─ MyAgent ────────────────────────────────────────────────╮
│ model      deepseek-flash                                │
│ workspace  D:\Project\MyAgent                            │
│ session    refactor (resume)                             │
│ security   reviewer deepseek-flash · fail-closed         │
│ tools      5 local · 1 MCP server                        │
╰──────────────────────────────────────────────────────────╯
  Type a task. /continue resumes unfinished work. Ctrl+C exits.

❯ Summarize the architecture and run the tests.
```

### A.2 Assistant + tool + telemetry

```text
⏺ The project has three main layers.

  ## Architecture
  - `agent.php` — entry point
  - `src/Agent.php` — API client, tools, and the loop
  - `src/SecurityClassifier.php` — per-call safety gate

  ```php
  $agent->run('…');
  ```

  ⚙ read_file  path=src/Agent.php
    ✓ 995 lines · 4 ms
  ⚙ run_command  $ php tests/smoke.php
    ✓ exit 0 · 3 lines · 1.2 s
  ◦ 1.8k in · 412 out · 4.1s · end_turn
```

### A.3 Markdown table and code

```text
⏺ Results:

  ┌────────────┬──────────┬───────────┐
  │ Tool       │ Risk     │ Verdict   │
  ├────────────┼──────────┼───────────┤
  │ read_file  │ low      │ allow     │
  │ git push   │ high     │ ask       │
  └────────────┴──────────┴───────────┘

  ┌─ bash ──────────────────────────────
  │ php tests/smoke.php
  └────────────────────────────────────
```

### A.4 Security approval

```text
╭─ ⚠ Security approval required ───────────────────────────╮
│ tool    run_command                                      │
│ risk    HIGH · shared-state                              │
│ reason  Pushes to a shared branch.                       │
│ action  {"command":"git push origin main"}               │
╰──────────────────────────────────────────────────────────╯
  Approve? [y/N]
```

### A.5 `ask_user`

```text
[?] Which environment should I deploy to?
  1) staging
  2) production
  3) Other - type your own answer
Select 1-3, type a custom answer, or press Enter to cancel:
```

### A.6 Plain mode (piped / `--plain`)

```text
# Architecture

The project has three layers.
```

Tool and diagnostic lines are plain on STDERR (`· read_file {"path":"..."}`), with no
ANSI bytes anywhere.

---

## Appendix B — Plain-mode compatibility contract

These exact behaviors must survive all milestones:

1. **STDOUT assistant text (non-TTY / `--plain`)**:
   - Each non-empty text block is emitted as `rtrim($text) . "\n"`.
   - Multiple text blocks are joined with `"\n"` before the final newline (matches the
     current sequential-write behavior for the common single-block case).
2. **STDOUT prompt**: `you> ` (with trailing space), exactly as today.
3. **STDERR tool line**: `· {name} {compact JSON}\n` (ANSI removed; cyan wrapper dropped).
4. **STDERR security deny**: `security {verdict} ({risk}): {reason}\n`.
5. **STDERR errors**: `Error: {message}\n`.
6. **STDERR step limit**: the current sentence, unchanged.
7. **`AskUser` plain output**: identical strings to `tests/smoke.php:84–88` and the
   README example.
8. **Exit codes**: 0 success, 1 error, 2 interactive step limit — unchanged.

Any change to items 1–8 is a regression and must be caught by `tests/smoke.php`.

---

## Appendix C — Future work (not part of this plan)

- Token-by-token SSE streaming with incremental Markdown rendering.
- Arrow-key/raw-mode `ask_user` selection (requires per-platform raw TTY handling).
- User-defined themes (JSON palette files) and `NO_COLOR`-aware theme preview.
- Optional desktop/OS notifications when a long task completes.
- Collapsible tool output / pager for very large results.
