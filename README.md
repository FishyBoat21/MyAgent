# MyAgent

A small, blocking PHP CLI agent. It sends messages to the Anthropic Messages API, runs local tools, and can call tools exposed by stdio MCP servers. It needs PHP 8.2+ with `curl` and `json`.

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

## Terminal UI

In an interactive terminal MyAgent renders a styled session:

```text
╭─ MyAgent ────────────────────────────────────────────────╮
│ model      deepseek-flash                                │
│ effort     high                                          │
│ workspace  D:\Project\MyAgent                            │
│ session    refactor                                      │
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

## Verify

Run `composer test` (equivalently, `php tests/smoke.php`). The smoke test checks local skills and files, starts the bundled stdio MCP example, and exercises tool use, session recovery, compaction handling, the `ask_user` prompt with scripted input, and the security reviewer with fake Anthropic responses. It also runs `tests/ui.php`, which covers ANSI sanitizing, width/wrapping, Markdown rendering, tool summaries, plain-mode compatibility, and the styled/plain agent output paths, and `tests/selector.php`, which covers model/effort catalog normalization, clamping, request-body construction, reviewer effort isolation, and the scripted pickers. It does not make a paid API request.

`php tests/render-demo.php --force --color=always --width=100` prints the visual gallery
(banner, Markdown, tables, code, tool cards, security prompts, `ask_user`) for manual review.
