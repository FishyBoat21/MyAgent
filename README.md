# MyAgent

A small, blocking PHP CLI agent. It sends messages to the Anthropic Messages API, runs local tools, and can call tools exposed by stdio MCP servers. It needs PHP 8.2+ with `curl` and `json`; image downscaling additionally uses `ext-gd` when it is available.

Composer manages autoloading and the test scripts. There are no third-party runtime packages; `src/` is mapped to the `MyAgent\` namespace with PSR-4.

## Start

1. Install the autoloader:

   ```sh
   composer install
   ```

2. Copy `config.example.json` to `config.json` **next to `agent.php`** and edit it. Remove the example MCP server if you do not need it. The agent always reads this file from its own folder, so it can be launched from any working directory; the working directory only determines what the agent operates on.
2. Set `"api_key"` in `config.json`, or leave it empty and set `ANTHROPIC_API_KEY` in your environment. A non-empty `api_key` wins; `api_key_env` names the fallback variable (default `ANTHROPIC_API_KEY`; set it to `""` to require the config key only).
3. Run `php agent.php "Inspect this project and summarize it"` to open a session with that first task, or `php agent.php` for an empty interactive session. `composer` itself is only needed to install/refresh the autoloader; the agent runs directly with `php`. A finished answer never closes the session: type the next instruction at the `you>` prompt. In a terminal, only `Ctrl+C` ends the process; `/exit` and `/quit` do not exist.

Use `--config=path`, `--model=MODEL`, `--effort=LEVEL`, `--max-steps=N`, or `--command-timeout=N` to override settings. Without `--config`, the agent reads `config.json` from the folder containing `agent.php` — not from the working directory. An explicit `--config` path is used as given, so a relative path resolves against the working directory. The model defaults to `config.model`; see [Models and effort](#models-and-effort). Each model request waits for a complete response before tools run.

## Models and effort

The acting model defaults to `"model"` in `config.json`. Overrides, highest first:

1. `--model=MODEL`
2. the model saved in a resumed session (`--session=... --continue`)
3. `config.model`
4. `LLM_MODEL`
5. `ANTHROPIC_MODEL`
6. built-in `claude-sonnet-5-5`

`config.model` outranks the environment on purpose: Claude Code exports
`ANTHROPIC_MODEL`, and inheriting it would silently replace the configured provider
model.

In a session, `/model` prints the provider catalog as a numbered picker. Choose a
number, type an id or a unique prefix, press Enter to keep the current model, `r` to
refresh the catalog, or `q` to cancel. `/model <id>` switches directly. The catalog
is fetched when you first ask for it (never at startup) from:

- `models_url` when set;
- otherwise `https://api.deepseek.com/models` for a DeepSeek `api_url`, or
  `https://api.anthropic.com/v1/models` for an Anthropic one;
- otherwise the static `models` list.

The result is cached at `.agent/models-cache.json` for `models_cache_ttl` seconds
(default `86400`; `0` always refetches). The cache contains model metadata only,
never the API key. `--list-models` prints the catalog and exits; add
`--refresh-models` to bypass the cache.

`effort` sets the reasoning depth sent as `output_config.effort`:

```json
"effort": "high",
"models_url": "https://api.deepseek.com/models",
"models_auth": "bearer",
"models_cache_ttl": 86400,
"models": []
```

`effort` may be `low`, `medium`, `high`, `xhigh`, `max`, or `null` (omit the field
and let the provider choose). `/effort` shows the levels the current model declares;
`/effort <level>` switches; `/effort default` clears it. A level the model does not
declare is clamped to the nearest supported level, with ties going higher (`medium`
→ `high` on a model that supports only low/high/max). `--effort=LEVEL` overrides the
config for one process.

`models_auth` controls how the catalog GET authenticates: `bearer` (default,
`Authorization: Bearer`), `header` (the configured `api_key_header`/prefix plus
`anthropic-version`), or `none`.

`models` is an optional static fallback used when the endpoint is unreachable. Each
entry is an id string or an object:

```json
"models": [
  "deepseek-flash",
  { "id": "deepseek-v4-pro", "name": "DeepSeek-V4-Pro",
    "effort_levels": ["low", "high", "max"], "default_effort": "high" }
]
```

Switching models keeps the conversation. Prompt caches are model-scoped, so the turn
after a switch is not a cache hit. The security reviewer keeps the model it started
with and always runs at the provider-default effort, regardless of the actor's
selection. Both selections are saved in the session checkpoint and restored by
`--continue` unless a flag overrides them.

## Prompt cache and shared identity

Every request carries one **shared** identity (`identity.user_id`, default `myagent`)
as `metadata.user_id`. It is deliberately identical in every session: the provider
partitions its prompt cache on that value, so a per-session id would start every
session from a cold prefix. Measured against the DeepSeek Anthropic-compatible
endpoint, a repeated 1.9k-token prefix under the same id read 1664 tokens from cache
(88 % of the input); the same prefix under a different id missed completely.

```json
"identity": { "user_id": "myagent", "send_metadata": true, "prompt_cache_key": false },
"cache":    { "conversation_breakpoint": true, "ttl": "5m" }
```

Precedence: `--user-id=ID` → `MYAGENT_USER_ID` → `identity.user_id` → `myagent`. Never
make this value per session, per terminal, or per sub-agent, and do not let a proxy or
gateway inject one. `identity.send_metadata: false` omits the field entirely (the body
is then byte-identical to the pre-identity one).
`identity.prompt_cache_key: true` additionally sends an OpenAI-style top-level
`prompt_cache_key` for gateways that route on it; it is off by default because strict
Anthropic endpoints reject unknown top-level fields.

Each request sets three cache breakpoints — the system prompt, the last tool, and,
from the second request of a session onward, the end of the conversation — so a
growing conversation is reused instead of re-billed every turn. `cache.ttl: "1h"` asks
for extended-TTL breakpoints (the agent sends the `extended-cache-ttl` beta header;
providers that ignore it are unaffected). Caches are model-scoped, so the turn after a
`/model` switch, and the first request in another project (the working directory is
part of the system prompt), are not cache hits.

`/cache` reports what the process is actually doing:

```text
  ◦ identity    myagent (shared by every session) · metadata on
  ◦ breakpoints system · tools · last-message · ttl 1h
  ◦ usage       7 requests · 84.2k in · 31.4k cached (25.2% hit) · 9.1k written · 12k out
```

## Terminal UI

In an interactive terminal MyAgent renders a styled session:

```text
╭─ MyAgent ────────────────────────────────────────────────╮
│ model      deepseek-flash                                │
│ effort     high                                          │
│ workspace  D:\Project\MyAgent                            │
│ session    refactor                                      │
│ identity    myagent                                       │
│ security   reviewer deepseek-flash · fail-closed         │
│ tools      5 local · 1 MCP server                        │
╰──────────────────────────────────────────────────────────╯
  Type a task. /model and /effort change the run. /continue resumes. Ctrl+C exits.

❯ Summarize the architecture.
⏺ The project has three layers. …
  ⚙ read_file  path=src/Agent.php
    ✓ 995 lines · 4 ms
  ◦ 1.8k in · 412 out · 4.1s · end_turn
```

Assistant replies are rendered as Markdown: headings, lists, blockquotes, tables, fenced
code with basic highlighting, and links shown as `text (url)`. Tool activity shows one
compact line per call plus a result summary and duration. A spinner appears while waiting
for the model, and security/`ask_user` prompts are boxed and color-coded.

Only assistant prose and the input prompt go to STDOUT; the banner, tool activity,
telemetry, spinner, and prompts go to STDERR. When STDOUT is not a terminal, assistant text
is written raw, so pipes and logs see the same text as before. Model and tool output is
sanitized before rendering, so escape sequences from untrusted text cannot reach the
terminal.

### Visual configuration

`config.json`:

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

- `color`: `auto` (TTY only), `always`, or `never`.
- `unicode`: `auto`, `always`, or `never` (ASCII box and symbol fallback).
- `width`: `0` to detect, or `40`–`200`.
- `tool_details`: `summary` (default), `full` (input/result boxes), or `hidden`.

Flags:

| Flag | Effect |
|---|---|
| `--plain` | Raw legacy output for scripts and logs (no color, layout, banner, or spinner). |
| `--no-color` | Disable ANSI colors; keep the structured layout. |
| `--width=N` | Force the render width (clamped to 40–200). |
| `--no-banner` | Hide the startup banner. |
| `--tool-details=MODE` | `summary`, `full`, or `hidden`. |
| `--image=FILE` | Attach an image to the first task (repeatable; see [Images and attachments](#images-and-attachments)). |
| `--no-media` | Disable image input for this process. |
| `--media-max-edge=N` | Downscale images to this long edge; `0` never downscales. |

Environment: `NO_COLOR` disables color in `auto` mode; `FORCE_COLOR` forces it on;
`TERM=dumb` disables automatic color; `COLUMNS` supplies a width when no flag or config
value is set. `NO_COLOR` wins over `FORCE_COLOR`; an explicit `"color": "always"` in the
`ui` block wins over both. The `ui` block is optional; the defaults above apply when it is
absent.

For a visual gallery without calling the API:

```sh
php tests/render-demo.php --force --color=always --width=100
php tests/render-demo.php --force --color=never --ascii --width=60
```

## Long tasks and recovery

Give a task a session name to save progress under `.agent/sessions/`:

```sh
php agent.php --session=refactor "Refactor the parser and run its tests"
php agent.php --session=refactor --continue
```

The second command continues if the first process was interrupted or reached `max_steps` (100 model calls by default, per invocation). Repeating `--continue` gives it another step budget. A new prompt with the same session name adds instructions to that conversation. When stdin is not a terminal (piped input), exit code `2` means the step limit was reached with work unfinished and exit code `1` means an error; an interactive terminal keeps the session open instead, and only `Ctrl+C` ends it. Session checkpoints include messages and tool outputs, so keep `.agent/` private; it is gitignored. The API key is never saved there.

In interactive mode, type `/continue` to give an unfinished task another step budget without adding a new user message.

A model `stop` (`end_turn`) only ends the current turn, never the session: a single-task run drops into the same `you>` prompt for follow-ups. End-of-input in a terminal (`Ctrl+D`, or `Ctrl+Z` then Enter on Windows) is ignored too — the console is reopened so the session keeps waiting for the user.

Checkpoints are written before each tool call and after each result. If the process stops during a tool call, the agent reports that call as **uncertain** on resume and asks the model to inspect its effects. It does not automatically repeat the call.

The Anthropic client retries temporary network, rate limit, and server errors twice with backoff. Stable tool definitions use a prompt cache breakpoint. Server-side compaction is enabled at `compaction_threshold` input tokens (100,000 by default) to keep old context concise; set it to `0` to disable it. Compaction is a beta feature and requires a supported Claude model. It can omit details from earlier turns, so keep durable task decisions in project files.

## Local tools

`list_files`, `read_file`, `write_file`, `run_command`, and `read_skill` are available to the model. In an interactive terminal, `ask_user` is also available (see below). File tools stay inside the current working directory. `run_command` executes a shell command there, with a configurable timeout (300 seconds by default) and limited returned output. It can execute arbitrary commands with the user's OS permissions, so run the agent only in a project you trust. Every call also passes the security reviewer described below.

### Tool output size

By default a single tool result is capped at `100000` bytes (`max_tool_result_chars` in `config.json`, or `--tool-result-limit=N`; `0` disables the cap). Larger output is not dropped: the full text is written to a `.txt` file in the system temp directory (for example `%TEMP%` on Windows or `/tmp` elsewhere), and the tool result keeps the first part plus a notice with the file path and size. Read the rest with `run_command` (for example `Get-Content -TotalCount N` or `Select-Object -Skip N -First M` on Windows, `head`/`tail`/`sed -n` elsewhere); `read_file` cannot open paths outside the project. Temp files may contain sensitive tool output, so delete them when they are no longer needed.

## Images and attachments

MyAgent can look at images: screenshots, UI captures, diagrams, charts, photos. An image is sent to the
model as a real image content block (`{"type":"image","source":{"type":"base64","media_type":"image/png","data":"…"}}`),
so any model that advertises image input can use it. Documents (PDF), audio and video are not
supported yet.

There are five ways to get an image into a conversation:

1. **`--image=FILE`** (repeatable) attaches to the first task, in argument order:

   ```sh
   php agent.php --image=bug.png "Why is the sidebar overflowing?"
   php agent.php --image=before.png --image=after.png "What changed between these two?"
   ```

   With no prompt the images are queued for the next line you type.

2. **`/attach`** queues images for the next prompt, `/attachments` lists the queue,
   `/detach <n|all>` drops entries, and **`/paste`** (Windows) grabs the clipboard image:

   ```text
   you> /attach docs/shot.png "tests/golden shot.png"
     + shot.png — 1536×864 png, 240 kB
     + golden shot.png — 800×600 png, 96 kB
   you> /attachments
     1) shot.png — [image png 1536×864 240 kB sha 9f2c1a3b]
     2) golden shot.png — [image png 800×600 96 kB sha 41d7c0e2]
     Total: 2 image(s), 336 kB. They are sent to the model provider with the next prompt.
   you> why is the layout broken?
   ```

   The queue is cleared after that prompt.

3. **`@path`** inside a typed prompt attaches an existing image file; the token stays in the text, so
   the model sees both the reference and the picture. Only paths that resolve to an existing file with
   an image extension are attached — an email address, a stray `@`, or a path outside the project is
   left as literal text and never fails the prompt. `media.mentions` selects the mode: `images`
   (default), `all`, or `off`.

4. **`read_image`** lets the model pull an image itself:
   `read_image {path: "docs/shot.png", note: "what is wrong with the corner"}`. Tool paths stay inside
   the working directory — traversal, absolute paths and symlink escapes are rejected exactly like
   `read_file`. The tool returns the image plus a one-line summary, and is only advertised when the
   active model accepts images (the tool list is fixed for the process, because the provider restricts
   mid-conversation tool changes).

5. **MCP servers** that return `image` content blocks: the image is attached instead of being
   flattened to `[image content]`. Other non-text block types keep that placeholder.

An image the model cannot use is still described: when the active model declares no image input, the
block becomes an `[image omitted: …]` text block instead of being silently dropped or causing a
provider error. Switching models with `/model` only re-points that gate; it never changes the tool list.

### Image limits and normalisation

```json
"media": {
  "enabled": true,
  "input_modalities": ["text", "image"],
  "max_bytes": 5242880,
  "max_edge": 1568,
  "max_dimension": 8000,
  "max_pixels": 40000000,
  "max_images_per_message": 20,
  "max_images_per_request": 20,
  "max_images_per_tool_result": 4,
  "store": ".agent/media",
  "retain_hours": 168,
  "mentions": "images",
  "resize": true
}
```

| Key | Default | Meaning |
|---|---|---|
| `enabled` | `true` | master switch; `--no-media` forces it off for one process |
| `input_modalities` | `["text","image"]` | capability fallback when the model catalog does not declare it |
| `max_bytes` | `5242880` | per-image ceiling after normalisation (the provider's limit) |
| `max_edge` | `1568` | long edge after downscaling; `0` keeps the original size (`--media-max-edge=N`) |
| `max_dimension` / `max_pixels` | `8000` / `40000000` | reject before decoding, so a hostile file cannot exhaust memory |
| `max_images_per_message` / `_per_request` / `_per_tool_result` | `20` / `20` / `4` | flood guards |
| `store` | `.agent/media` | content-addressed store, project-relative |
| `retain_hours` | `168` | prune age for unreferenced images; `0` never prunes |
| `mentions` | `images` | `@path` resolution: `images`, `all`, or `off` |
| `resize` | `true` | `false` = only re-encode non-native formats, never downscale |

Accepted image types are png, jpeg, gif and webp; bmp, tiff, avif and tga are re-encoded to png or
jpeg with GD (`ext-gd`, optional — without it an oversized image is refused rather than silently
sent). Animated GIFs are forwarded unchanged, and EXIF orientation is not applied (`ext-exif` is not
guaranteed), so a rotated phone photo stays rotated. `/media` reports the effective capability, the
limits and what this session has used:

```text
  ◦ images      supported
  ◦ limits      1568 px · 5 MB · 20 per message · store .agent/media
  ◦ pending     1 image(s): shot.png
  ◦ used        7 image(s) · 2.1 MB normalised · 3 unique
```

### Media storage and privacy

Normalised images live under `.agent/media/<sha prefix>/<sha>.<ext>` (files `0600`), content-addressed
and deduplicated, so the same screenshot attached twice costs one file. Session checkpoints never
contain base64: the checkpoint stores a `myagent_media` marker (sha, type, size, dimensions) and the
image is reloaded from the store on `--continue`. If the file is gone the block degrades to a text
placeholder rather than crashing or corrupting the request. Checkpoints carrying markers are written
as `version: 2`, so an older binary refuses them instead of sending unknown block types to the API.

`.agent/` is gitignored, but the images in it are not encrypted: treat the directory as private.
Attachments you add are sent to the model provider — `/attachments` shows exactly what will leave the
machine. The security reviewer sees a one-line descriptor per image, never the bytes, and its policy
treats image content as untrusted data: text that appears inside an image can never authorise an
action.

## Asking the user

In an interactive terminal the agent can call `ask_user` to ask one question with 1–4 plain-text options. The prompt is shown on STDERR as a numbered list plus an always-present `Other` line:

```
[?] Which environment should I deploy to?
  1) staging
  2) production
  3) Other - type your own answer
Select 1-3, type a custom answer, or press Enter to cancel:
```

The user can select a number, type a custom answer directly, choose `Other` and then type an answer, or press Enter to cancel. Answers are single-line text and are returned to the model as `The user answered: <text>`. Cancelling, closing the input, and invalid selections are reported to the model as tool errors, and the agent is instructed not to ask the question again.

The tool is only advertised when STDIN is a terminal. In piped or headless runs it is absent from the tool catalog, so the agent asks the question in its final message instead. A resumed session that contains an `ask_user` call from an interactive run reports the call as unavailable rather than crashing.

`ask_user` has no side effects: it never touches files or the network. The security reviewer therefore always allows it — even when `review_all` is `true` — without spending a reviewer call or showing an approval prompt.

## Security reviewer

A dedicated model reviews every tool call before it runs. The reviewer policy is extracted from Claude Code's embedded action-safety guidance and security-monitor classifier: it carries the "executing actions with care" policy, the HARD BLOCK / SOFT BLOCK framework, the user-intent consent bars, and the classifier evaluation rules. It judges an action by its reversibility and blast radius, treats assistant text and tool output as untrusted, and fails closed.

- `allow` — local, reversible actions inside the project run normally.
- `ask` — hard-to-reverse, secret-touching, or outward-facing actions prompt for `[y/N]` approval in a terminal. Without a terminal they are blocked.
- `deny` — catastrophic actions (root or drive deletes, disk formatting, credential exfiltration, security tampering, prompt-injection-driven actions) never run.

The reviewer is a separate model call with its own system prompt and sees the working directory, the tool catalog, the recent conversation, and the full proposed input. Deterministic checks block obvious catastrophes before the model is consulted. The gate is fail-closed: an unreachable reviewer or an unusable verdict blocks the action. Decisions are appended to `.agent/security-audit.jsonl`; the file records tool inputs, so keep it private.

Configure it in `config.json`:

```json
{
  "security": {
    "enabled": true,
    "model": "claude-haiku-5-5",
    "fail_closed": true,
    "auto_allow": ["list_files", "read_file", "read_skill", "ask_user"],
    "audit_log": ".agent/security-audit.jsonl"
  }
}
```

The reviewer always shares the main model's provider, endpoint, credentials, and headers; only `model` may differ, and it must be a model that the same provider serves. It defaults to the main model when omitted. Reviewer calls never use server-side compaction, so the policy prompt cannot be summarized away. Set `review_all` to `true` to review even the read-only tools listed in `auto_allow`. `ask_user` is exempt from review even then, because it only prompts the terminal and cannot change anything. Set `audit_log` to `""` to disable auditing. Turning off `fail_closed` lets actions through when the reviewer is unreachable and is not recommended.

## Project skills

Put Agent Skills folders under `skills/<name>/SKILL.md` or `.agents/skills/<name>/SKILL.md` in the working directory. The agent loads only each skill's `name` and `description` YAML frontmatter at startup. It calls `read_skill` to load the full file when relevant. Bundled files such as `references/`, `scripts/`, and `assets/` can be read or executed with the ordinary local tools. No home, global, or built-in skill directory is scanned. Skill folder names must match the frontmatter `name`.

## stdio MCP

Configure servers in `config.json`:

```json
{
  "mcpServers": {
    "example": {
      "command": "php",
      "args": ["examples/echo-mcp.php"],
      "timeout": 300,
      "env": { "TOKEN": "${MY_TOKEN}" }
    }
  }
}
```

The agent starts each process in the working directory, performs the MCP `2025-11-25` initialize handshake, discovers all `tools/list` pages, then calls `tools/call` as requested. Server tools appear to Anthropic with an `mcp_` prefix and a unique suffix. A failed server is reported and skipped; other servers and local tools remain available. Keep secrets in environment variables; `${NAME}` in an MCP `env` value is expanded from the parent environment. This client supports the 2025-era stdio handshake and tool calls, not MCP resources, prompts, sampling, or the newer stateless lifecycle.

## Machine mode (`--bridge`)

`--bridge` turns the process into a supervisor-friendly worker (used by
[MyAgentManager](../MyAgentManager)): STDOUT becomes a JSONL event stream and STDIN a
JSONL command channel. There is no banner, no ANSI and no prose on STDOUT — the
renderer discards its byte writers entirely — and a TTY is never trusted in this
mode, so behaviour is identical whether or not one is attached.

```sh
php agent.php --bridge --session=alpha-abc123 --model=deepseek-flash --max-steps=20
echo {"type":"prompt","text":"Reply with the single word PONG"} | php agent.php --bridge --session=smoke
```

Every line in both directions is one JSON object carrying `"v":1`. A receiver must
reject another version: the bridge answers
`{"v":1,"type":"error","text":"unsupported protocol version"}` and exits with code 3.

**STDOUT (child → supervisor)**

| Type | Payload |
|---|---|
| `ready` | `session, workspace, model, effort, identity, security{model,fail_closed,enabled}, tools, mcp[], pid, ask_user, media{enabled,images,max_bytes,max_edge,store}` |
| `turn_start` / `turn_end` | `prompt` (null for a resume) / `complete` (false = step limit) |
| `assistant` | `text` — raw Markdown |
| `tool_start` / `tool_result` | `id, name, input` / `+ result, result_truncated, result_bytes, is_error, duration, media[]` |
| `usage` | `input, output, cache_read, cache_creation, duration, stop_reason` |
| `info` / `warning` / `error` | `text` |
| `cache` | `report` — the same object `/cache` renders |
| `security_denied` / `security_resolved` | `decision` / `approved` |
| `progress` | `elapsed, bytes` (at most 1 Hz) |
| `model` / `effort` | the value now in effect |
| `question` / `question_closed` | `id, question, options[]` / `id, reason` |
| `approval` / `approval_closed` | `id, tool, input, decision` / `id, verdict` |
| `attachment` | `index, name, media_type, bytes, width, height, sha256, stored_path` — emitted before `turn_start` |
| `media_error` | `index, path, reason, message` — one per rejected attachment |
| `bye` | `reason, code` |

**STDIN (supervisor → child)**: `prompt`, `continue`, `answer`, `cancel`, `verdict`,
`set_model`, `set_effort`, `cache_report`, `shutdown`.

A `prompt` command may carry images by **path**, resolved by the child inside the workspace:

```json
{"v":1,"type":"prompt","text":"why is the layout broken?",
 "attachments":[{"path":"assets/shot.png"}]}
```

Inline base64 attachments are rejected with `{"type":"media_error","reason":"inline-base64-unsupported"}`
— the line cap is 4 MiB and paths keep the workspace check meaningful. A bad path produces one
`media_error` and the prompt still runs with the attachments that resolved.

**Delivering commands.** The channel can be a pipe (`type cmds.jsonl | php agent.php --bridge`) or a
redirected file (`php agent.php --bridge < cmds.jsonl`); both are read the same way, one JSON object
per line. A redirected file that ends before a single command could be read is no longer silent: the
bridge prints one line on STDERR and emits one `warning` event, while an empty input (`< NUL`) and a
pipe that closes normally stay silent. The console-width probe is skipped when neither STDIN nor
STDOUT is a terminal, so a redirected or supervised run does not spawn `mode con`; `--width=N` and
`COLUMNS` still win, and a run without a console falls back to 100 columns.

In bridge mode `ask_user` stays advertised (`"ask_user": true` in `ready`) because the
bridge installs a non-null reader: questions are asked over the command channel and
answered by the supervisor. A security-reviewer `ask` verdict likewise becomes an
`approval` event instead of a `[y/N]` prompt, so an unattended session can still be
approved or denied. `--bridge-max-event-bytes=N` caps a single emitted tool result
(default 32768; `result_bytes` still reports the true length).

Nothing else changes: prompts, tool schemas, request bodies, session checkpoints,
the security policy and token/cache behaviour are byte-identical to a normal run.
Without `--bridge`, MyAgent behaves exactly as before.

## Verify

Run `composer test` (equivalently, `php tests/smoke.php && php tests/bridge.php`). The smoke test checks local skills and files, starts the bundled stdio MCP example, and exercises tool use, session recovery, compaction handling, the `ask_user` prompt with scripted input, and the security reviewer with fake Anthropic responses. It also runs `tests/ui.php`, which covers ANSI sanitizing, width/wrapping (including that the console-width probe cannot consume a redirected stdin), Markdown rendering, tool summaries, plain-mode compatibility, and the styled/plain agent output paths, `tests/selector.php`, which covers model/effort catalog normalization, clamping, request-body construction, reviewer effort isolation, and the scripted pickers, and `tests/bridge.php`, which covers the `--bridge` event mapping, the `ask_user` approval/answer hooks, result capping, protocol-version rejection, progress throttling, image events and the redirected-channel cases (a file-backed command channel, an empty read that is not end-of-channel, and the dangling-channel diagnostic), and `tests/media.php`, which covers MIME sniffing, downscaling, the content-addressed store, checkpoint dehydration, model gating and the `read_image` tool. It does not make a paid API request.

`php tests/render-demo.php --force --color=always --width=100` prints the visual gallery
(banner, Markdown, tables, code, tool cards, security prompts, `ask_user`) for manual review.
