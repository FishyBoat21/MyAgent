# Implementation Plan — Shared Session Identity for Prompt-Cache Reuse

**Rev 3 · 2026-10-31 · Status: IMPLEMENTED** — M1–M6 shipped; probes and live verification measured (§1.2.1, §7)
**Repo:** `D:\Project\MyAgent` (MyAgent PHP CLI)
**Requested by the user:** *"Plan change to share user id for every session to maximize cache hit rate; don't care about token usage, we have unlimited credit."*

---

## 0. Prerequisites

| # | Requirement | Status today |
|---|---|---|
| P1 | `composer install` has been run (`vendor/autoload.php` exists) | done |
| P2 | A live API key in `config.json` (`api_key`), provider `https://api.deepseek.com/anthropic/v1/messages` | done |
| P3 | Ability to make a handful of **paid** probe requests during M5 (curl against the live endpoint) | allowed — unlimited credit |
| P4 | Confirm the provider accepts an unknown-to-it `metadata` object **before** enabling it by default (probe in M5.1) | open, probed in this plan |
| P5 | MCP server `myrag` (stdio) reachable, so the tool catalog in the cached prefix matches production shape | done (`d:\Project\SimpleMCP\stdio.php`) |

---

## 1. Overview & Scope

### 1.1 Objective

Give MyAgent **one fixed, process-independent identity value** that is sent on *every* provider request from *every* session, so that (a) providers/gateways that route or partition prompt cache by a client-supplied key always land in the same cache partition, and (b) the request body is byte-identical across sessions and can therefore reuse one cached prefix. Today MyAgent sends **no identity at all** — `AnthropicClient::requestBody()` (`src/Agent.php:145-161`) emits only `model`, `max_tokens`, `messages`, `system`, `tools`, `output_config`, `context_management`.

Secondary objective (this is where most of the hit rate actually comes from — see 1.2): stop silently *fragmenting* the cached prefix, and make cache reuse measurable with a `/cache` command.

### 1.2 What actually decides a cache hit — read this before estimating the win

The premise was tested against the live provider on 2026-10-31 (scripts `.agent/probe-user-id.php`, `.agent/probe-user-id-2.php`; raw numbers in §1.2.1). The result is **stronger than the public documentation implies**:

- Anthropic documents `metadata.user_id` as an *opaque abuse-monitoring identifier*, so a prefix-only cache was the expected behaviour. **On the DeepSeek Anthropic-compatible endpoint that is wrong: `metadata.user_id` is part of the prompt-cache key.** Changing *only* that value, with a byte-identical prefix, produced a full cache miss (1883 in / 0 cached); restoring it produced a hit again (219 in / 1664 cached).
- Consequence 1: **a per-session id is a cache destroyer** — it would cost a cold prefix per session, re-billing ~88 % of the prompt every time. One shared id is therefore not a nicety; it is the requirement.
- Consequence 2: there are two places that must never inject a per-session id — this code, and anything in front of it (proxy/gateway). That is why §2.4 forbids deriving the id from pid/session/time.
- Nuance that keeps us honest: MyAgent sends **no** `metadata` today, and the absent-id bucket is itself stable and shared (P7 cold → P8 hit). So cross-session caching already works today; a constant id moves the process into one new, equally stable bucket — **one** cold request per prefix after the switch, then parity. The id's value is (a) making the cache partition explicit and under our control, (b) protecting it from ever becoming per-session, (c) preparing for multi-profile fan-out.
- `cache_creation_input_tokens` is reported as `0` on every probe, including cold writes: this provider does not bill or count cache writes separately. That removes the classic downside of conversation breakpoints — **M4.2 costs nothing extra here**.
- The largest *remaining* win is still **F6** (two breakpoints only, so the whole conversation is re-billed every turn) plus prefix stability: M4.2/M4.3, measured by `/cache` in M5.3.

#### 1.2.1 Measured probe results (2026-10-31, `deepseek-flash`)

Prefix used for the cache probes: ~1.9 k tokens of stable system text with `cache_control: ephemeral`; the user message was `Reply with the single word: ok`.

| Probe | Request | `input_tokens` | `cache_read_input_tokens` | Verdict |
|---|---|---|---|---|
| P1 | `metadata.user_id` set, small body | 37 | 0 | **field accepted** (HTTP 200) |
| P2 | top-level `prompt_cache_key` | 37 | 0 | accepted too — lenient endpoint; keep it opt-in for strict Anthropic |
| P3 | big prefix, `id=probe-alpha` (cold) | 1883 | 0 | cold write |
| P4 | identical, `id=probe-alpha` | 219 | **1664** | **hit — 88 % of the prompt served from cache** |
| P5 | identical prefix, `id=probe-beta` | 1883 | 0 | **MISS — the id partitions the cache** |
| P6 | `id=probe-alpha` again | 219 | **1664** | hit — the alpha entry survived; two buckets coexist (not eviction) |
| P7 | no `metadata` at all (cold) | 1883 | 0 | today's default bucket, cold |
| P8 | no `metadata` again | 219 | **1664** | hit — the absent-id bucket is stable and shared |
| P9 | `id=probe-alpha` again | 219 | **1664** | hit — independent of P7/P8 |
| P10 | `cache_control.ttl="1h"` + `extended-cache-ttl` beta | 1883 | 0 | **accepted**, cold write |
| P11 | same, identical | 219 | **1664** | hit |
| P12 | same body **without** the beta header | 219 | **1664** | hit — the `anthropic-beta` header is not part of the cache key |

Only P5 differs from its predecessor by the value of `metadata.user_id`, which is what makes P5 the load-bearing result.

Known prefix fragmenters in the current code (verified by reading it):

| # | Fragmenter | Location | Effect |
|---|---|---|---|
| F1 | `ask_user` is only advertised when STDIN is a TTY | `src/Agent.php:415`, `:1317` | A piped run and an interactive run have **different `tools` arrays** → different prefix → guaranteed miss between them |
| F2 | The working directory is embedded in the system prompt | `src/Agent.php:1320` | Same project = same prefix; switching projects = miss (expected, leave alone) |
| F3 | Skills catalog is appended to the system prompt | `src/Agent.php:1334-1337` | Editing/adding a `skills/*/SKILL.md` invalidates every session's prefix |
| F4 | An MCP server that fails to connect contributes no tools | `agent.php:289-299` | A flaky server start changes the `tools` array for that session only → miss storm |
| F5 | Model / effort switch mid-session | `README.md:83-84` | Caches are model-scoped → the turn after a switch is a miss (documented, acceptable) |
| F6 | Only **two** cache breakpoints exist (system, last tool) | `src/Agent.php:150`, `:154` | Conversation tokens are re-sent at full price every turn — the largest unclaimed win in an agent loop (fixed in M4.2) |
| F7 | `cache_control` is `ephemeral` with the default ~5 min TTL | `src/Agent.php:150`, `:154` | A session resumed after a coffee break pays a full re-write (M4.3 offers an opt-in 1 h TTL) |

### 1.3 Definition of Done

1. `php agent.php --user-id=myagent "hi"` sends `"metadata": {"user_id": "myagent"}` in every Messages request body of that process (actor **and** security reviewer).
2. With no flag/env/config, the default id is the constant `myagent` — never `getmypid()`, `uniqid()`, the session name, or a timestamp. Two separate processes started minutes apart produce byte-identical `metadata`.
3. `identity.send_metadata: false` in `config.json` removes the field entirely, leaving the body byte-identical to today's.
4. An invalid id (`"bad id"`, 300 chars, embedded `\n`, empty after trim) is rejected at startup with `Invalid user id`, exit code 1, and no request is attempted.
5. `--help` documents `--user-id=`, and the startup banner shows an `identity` row.
6. `cache.conversation_breakpoint: true` (default) adds a third `cache_control` breakpoint on the **last** message, from the second request of a session onward, **without mutating the stored conversation** (proved by asserting the checkpoint JSON contains no `cache_control`).
7. Total `cache_control` breakpoints per request never exceed 4.
8. `/cache` prints the shared id, the active breakpoints, cumulative token counters and the cache hit ratio.
9. `composer test` is green (smoke + UI + selector) with the new assertions.
10. ~~Live probe~~ **(MET 2026-10-31)** — the provider accepts `metadata` and *does* partition the cache on `metadata.user_id`: 1664 of 1883 input tokens came from cache for a repeated id, 0 for a changed one (§1.2.1). `identity.send_metadata` stays `true`.

### 1.4 Out of Scope

- **Per-profile identities** (e.g. `myagent:reviewer`). The reviewer shares the actor's `AnthropicClient` (`agent.php:303`, `:329`); splitting the id would create a second cache partition and *lower* the overall hit rate. Keep one id.
- **Initialising the identity per project** (e.g. `myagent:<project>`). It would fragment the cache across projects for no benefit; the id is deliberately global.
- **Always advertising `ask_user`** to remove fragmenter F1. It would let the model call a tool that cannot answer in piped runs; the correct fix is a behavioural decision, not a cache tweak.
- **A keep-alive pinger** that re-sends the prefix every ~4 minutes to defeat the 5 min TTL. Worth discussing as a follow-up (M7 candidate) but it burns credit continuously while idle.
- Server-side compaction tuning (`compaction_threshold_chars`), model/effort selector changes, session-store format changes.
- Any change to `d:\Project\SimpleMCP\stdio.php` or to the MCP identity (already `local` for every session, verified via `get_current_user`).

---

## 2. Architecture & Contract Changes

### 2.1 Config contract (`config.example.json`, optional live `config.json`)

Two independent, default-safe blocks:

```json
"identity": {
  "user_id": "myagent",
  "send_metadata": true,
  "prompt_cache_key": false
},
"cache": {
  "conversation_breakpoint": true,
  "ttl": "5m"
}
```

| Key | Type | Default | Meaning |
|---|---|---|---|
| `identity.user_id` | string | `"myagent"` | The shared value. Must be identical in every session by design. |
| `identity.send_metadata` | bool | `true` | Send `metadata.user_id` (Anthropic-native field). Set `false` if M5.1 shows the provider rejects it. |
| `identity.prompt_cache_key` | bool | `false` | Additionally send a **top-level** `prompt_cache_key` (OpenAI-style cache routing key) for gateways that understand it. Off by default: strict Anthropic endpoints reject unknown top-level fields with HTTP 400. |
| `cache.conversation_breakpoint` | bool | `true` | Add the third breakpoint on the last message (F6). Set `false` for the old two-breakpoint body. |
| `cache.ttl` | `"5m"\|"1h"` | `"5m"` | Passed as `cache_control.ttl`; `1h` additionally sends the `extended-cache-ttl-2025-04-11` beta header. M5.2 passed 2026-10-31 — safe to enable. |

Absent block = current behaviour plus the documented defaults above; an unknown `identity`/`cache` shape (non-object, unknown `ttl`) is a startup error.

### 2.2 Request body contract (`AnthropicClient::requestBody()`)

```jsonc
{
  "model": "deepseek-flash",
  "max_tokens": 393216,
  "metadata": { "user_id": "myagent" },        // NEW — omitted when disabled
  "system": [ { "type": "text", "text": "…", "cache_control": { "type": "ephemeral" } } ],
  "tools": [ /* … last tool carries cache_control */ ],
  "messages": [ /* … last message's last block carries cache_control (NEW, M4.2) */ ],
  "output_config": { "effort": "high" }
}
```

Invariants:
- `metadata` is inserted **once**, at a stable position, before `output_config`, so `json_encode` produces a deterministic key order.
- `metadata.user_id` must be ≤ 256 chars and match `\A[A-Za-z0-9._:@+-]{1,256}\z`.
- The conversation breakpoint is added to a **value copy** of `$messages`; `requestBody()`'s `array $messages` parameter is by value and PHP arrays are copy-on-write, so `$this->messages` in `Agent` (and therefore the checkpoint) can never pick up a `cache_control` key. This is an explicit invariant with a test (T6).

### 2.3 CLI contract (`agent.php`)

- New flag `--user-id=ID` in the `getopt()` list (`agent.php:25`) and in `--help` (`agent.php:29-54`).
- New interactive command `/cache` (documented in `--help` and `/help`), printing:

```
identity   myagent (shared across sessions) · metadata on · cache key off
breakpoints system · tools · last-message
usage      7 requests · 84.2K in · 31.4K cached (37.3% hit) · 9.1K written · 12.0K out
```

### 2.4 Precedence and validation

`--user-id=ID` → `MYAGENT_USER_ID` env → `identity.user_id` → literal `"myagent"`.

- Trim before use; an empty result at the *last* step falls back to `"myagent"`, never to `''`.
- Validation (single helper, `AnthropicClient::assertValidUserId()`): type string, 1–256 chars after trim, regex above, no `\r`/`\n`. Failure → `RuntimeException('Invalid user id')` → caught by the existing top-level handler (`agent.php:551-556`) → exit 1.
- `MYAGENT_USER_ID` exists for CI/headless overrides only; the plan documents "do not export a per-terminal value" because that recreates the fragmentation the change is meant to remove.

---

## 3. Step-by-Step Execution Sequence

### M1 — Config + CLI plumbing

**Files:** `config.example.json`, `agent.php`, `README.md`

1. `config.example.json`: add the `identity` and `cache` blocks from §2.1 verbatim, after `api_version` and before `models_url` (keeps provider settings together).
2. `agent.php`:
   - extend the `getopt()` string at line 25 with `'user-id:'`;
   - add to the help block (after `--model=`): `"  --user-id=ID            Shared cache/identity key sent with every request (default: config identity.user_id or \"myagent\")\n"`;
   - after the API URL/header validation block (~line 110-125), parse and validate:
     ```php
     $identityConfig = $config['identity'] ?? [];
     if (!is_array($identityConfig)) { throw new RuntimeException('identity must be an object'); }
     $sendMetadata = (bool) ($identityConfig['send_metadata'] ?? true);
     $sendCacheKey = (bool) ($identityConfig['prompt_cache_key'] ?? false);
     $userId = isset($options['user-id'])
         ? (string) $options['user-id']
         : ((string) (getenv('MYAGENT_USER_ID') ?: ($identityConfig['user_id'] ?? 'myagent')));
     $userId = trim($userId) === '' ? 'myagent' : trim($userId);
     AnthropicClient::assertValidUserId($userId);   // throws on bad input
     ```
   - parse the `cache` block the same way (`conversation_breakpoint` bool default true, `ttl` in `['5m','1h']` else throw).
3. `README.md`: extend the prompt-cache paragraph at lines 83-84 to describe the shared id and the three breakpoints (full text in M6).

**Depends on:** nothing. **Validation:** `php -l agent.php`; `php agent.php --help | findstr user-id`; `php agent.php --user-id="bad id" "hi"` exits 1 with `Invalid user id` **before** any MCP connect or API call (verify by the absence of the `MCP myrag connected` line).

### M2 — Carry the id in every request (`AnthropicClient`)

**Files:** `src/Agent.php` (`AnthropicClient`, lines 14-274)

1. New state next to `$effort` (line 22):
   ```php
   /** Shared identity for every request in this process; null omits metadata. */
   private ?string $userId = null;
   private bool $sendCacheKey = false;
   /** "5m" (default ephemeral) or "1h". */
   private string $cacheTtl = '5m';
   private bool $conversationBreakpoint = true;
   ```
2. New public API (place next to `setEffort()` for symmetry):
   ```php
   public function setUserId(?string $userId): void;          // validates, stores, null = omit
   public function userId(): ?string;
   public function setCacheOptions(bool $conversationBreakpoint, string $ttl): void;
   public static function assertValidUserId(string $userId): void;
   ```
   `assertValidUserId()` throws `RuntimeException('Invalid user id')` unless `preg_match('/\A[A-Za-z0-9._:@+-]{1,256}\z/', $userId) === 1`.
3. `requestBody()` (lines 145-161) — insert `metadata` immediately after the `$body` initialiser, before `$system`:
   ```php
   if ($this->userId !== null) {
       $body['metadata'] = ['user_id' => $this->userId];
       if ($this->sendCacheKey) {
           $body['prompt_cache_key'] = $this->userId;   // opt-in gateway key
       }
   }
   ```
4. Factor the breakpoint marker so both the system block and the tool block use one helper:
   ```php
   private function cacheControl(): array
   {
       return $this->cacheTtl === '1h'
           ? ['type' => 'ephemeral', 'ttl' => '1h']
           : ['type' => 'ephemeral'];
   }
   ```
   Replace the two literal arrays at lines 150 and 154 with `$this->cacheControl()`.

**Depends on:** M1 (config values exist). **Validation:** unit-shaped assertions from T1–T3 run via `php tests/smoke.php`.

### M3 — Wire the process-level identity (`agent.php`, banner)

**Files:** `agent.php`, `src/Terminal.php`

1. `agent.php:303` — after constructing the client:
   ```php
   $api = new AnthropicClient((string) $apiKey, $apiUrl, $apiKeyHeader, $apiKeyPrefix, $apiVersion);
   if ($sendMetadata) { $api->setUserId($userId); }
   $api->setCacheOptions($cacheConversationBreakpoint, $cacheTtl);
   ```
   One client, set once: actor **and** the security reviewer (`agent.php:329`) inherit the same id automatically — intended (§1.4).
2. `src/Terminal.php:215-223` — add `'identity' => 'identity',` to the `$labels` map (banner prints rows in that map's order; place it after `session`).
3. `agent.php:332-341` — add to the banner facts array:
   ```php
   'identity' => $userId . ($sendMetadata ? '' : ' (metadata off)'),
   ```
4. **Deliberately do not** persist the id in the session checkpoint (`Agent::checkpoint()`, `src/Agent.php:1341-1355`). A resumed session must pick up the *current* shared value; pinning a stale one would silently reintroduce a second partition.
5. `agent.php:~455` `/help` list: add `'/cache [id|number]…'` → `'/cache              Show identity, breakpoints and cache hit rate'`.

**Depends on:** M2. **Validation:** `php agent.php --no-banner --plain --user-id=probe "say ok"` completes; the banner row appears without `--no-banner`; a resumed `--session=… --continue` run still reports the current id.

### M4 — The actual hit-rate levers

#### M4.1 — Shared, stable prefix (already satisfied; verify, do not change)

Confirm, and record in the KG, that `system` + `tools` are byte-identical between two sessions in the same project with the same MCP server set: `src/Agent.php:1303-1338` (stable order: local definitions in fixed array order, then MCP servers in config order, then each server's `tools/list` order). No code change unless T7 fails.

#### M4.2 — Conversation breakpoint (fixes F6)

**File:** `src/Agent.php::requestBody()`

After system/tools are set:

```php
$messages = $this->withConversationBreakpoint($messages);
```

```php
/**
 * Mark the tail of the conversation so the next turn can reuse it.
 * Works on a copy: the caller's messages (and therefore the session
 * checkpoint) must never carry cache_control.
 *
 * @param array<int, array<string, mixed>> $messages
 * @return array<int, array<string, mixed>>
 */
private function withConversationBreakpoint(array $messages): array
{
    if (!$this->conversationBreakpoint || count($messages) < 2) {
        return $messages;   // first request: nothing to reuse yet
    }
    $last = array_key_last($messages);
    $message = $messages[$last];
    if (is_string($message['content'] ?? null)) {
        $message['content'] = [['type' => 'text', 'text' => (string) $message['content']]];
    }
    if (!is_array($message['content'] ?? null) || $message['content'] === []) {
        return $messages;
    }
    $block = array_key_last($message['content']);
    if (is_array($message['content'][$block])) {
        $message['content'][$block]['cache_control'] = $this->cacheControl();
        $messages[$last] = $message;
    }
    return $messages;
}
```

Rules: at most **3** breakpoints (system, last tool, last message) — inside the API's limit of 4; `count($messages) < 2` skips the very first request of a session, where a write cannot pay off; a non-array last block (e.g. a `thinking` block that the provider returns as a string) is left untouched.

#### M4.3 — Optional 1 h TTL (fixes F7)

In `message()`, where `$betas` is assembled (lines 171-183): when `$this->cacheTtl === '1h'`, append `'extended-cache-ttl-2025-04-11'`. Default stays `5m`; M5.2 passed on 2026-10-31, so `"ttl": "1h"` is an approved `config.json` value (with the beta header, which is harmless on this provider and required on Anthropic).

**Depends on:** M2. **Validation:** T5, T6, T7.

### M5 — Measurement

#### M5.1 — Provider probe ✅ **DONE 2026-10-31**

Results are in §1.2.1. The scripts live at `.agent/probe-user-id.php` (round 1) and `.agent/probe-user-id-2.php` (round 2); `.agent/` is gitignored, so the §1.2.1 table — not the scripts — is the durable record. Conclusions, already applied to this plan:

- `metadata` and `metadata.user_id` are **accepted** (HTTP 200) → keep `identity.send_metadata: true`. No kill-switch needed for this provider; keep the flag for portability.
- The id **is** a cache partition key → one stable id is mandatory (see §1.2, Consequence 1). A test (T4) and the banner row guard it.
- Top-level `prompt_cache_key` is also accepted here, but stays **opt-in** (`identity.prompt_cache_key: false`) because a strict Anthropic endpoint rejects unknown top-level fields.
- Cache writes are free/uncounted on this provider (`cache_creation_input_tokens` is always 0) → M4.2 has no cost downside.

#### M5.2 — 1 h TTL probe ✅ **DONE 2026-10-31 — passed**

`cache_control = {"type":"ephemeral","ttl":"1h"}` returns 200 with and without `anthropic-beta: extended-cache-ttl-2025-04-11`, and the entry is reused across both header variants (P12 hit). `cache.ttl: "1h"` is therefore **approved** for `config.json`; keep sending the beta header anyway, since real Anthropic endpoints require it for the extended TTL.


#### M5.3 — `/cache` (the in-product metric)

**Files:** `src/Agent.php` (`Agent`), `agent.php`

1. `Agent`: new property and accessors
   ```php
   /** @var array{requests:int,input:int,output:int,cache_read:int,cache_creation:int} */
   private array $usageTotals = ['requests'=>0,'input'=>0,'output'=>0,'cache_read'=>0,'cache_creation'=>0];
   public function usageTotals(): array;
   public function cacheHitRatio(): float;   // cache_read / max(1, input + cache_read + cache_creation)
   ```
2. In `run()`, immediately before `$this->terminal->usage(...)` at line 1129:
   ```php
   $usage = (array) ($response['usage'] ?? []);
   $this->usageTotals['requests']++;
   foreach (['input_tokens'=>'input','output_tokens'=>'output',
             'cache_read_input_tokens'=>'cache_read',
             'cache_creation_input_tokens'=>'cache_creation'] as $key => $slot) {
       $this->usageTotals[$slot] += (int) ($usage[$key] ?? 0);
   }
   ```
   Reviewer traffic is *not* counted (it never reaches this line); note that in the docblock — the reviewer's prompt is a constant so it self-caches, and mixing it in would hide the actor's number.
3. `agent.php`: handle `'/cache'` next to `'/model'` (~line 472), printing the block from §2.3 via `$ui->info()`, and add `Terminal::formatTokens()`-equivalent formatting through the existing `$ui` helpers (do not add a new public Terminal method; `usage()` stays as-is).

**Depends on:** M4. **Validation:** T8 plus a live two-turn session showing a non-zero hit ratio on turn 2.

### M6 — Docs + KG

**Files:** `README.md`, knowledge graph

1. `README.md`: replace lines 83-84 with a subsection **"Prompt cache and shared identity"** covering: the id and its precedence, that the id is the same in every session *on purpose*, the three breakpoints and the min-prefix requirement, why model/effort switches and project switches still miss, `cache.ttl`, and the `/cache` command.
2. `--help` (agent.php) gains `--user-id=` and `/cache` (already in M1/M3).
3. Knowledge-graph updates are part of the definition of done: the plan entity, the decision entity, the new facts on `myagent-file-src-agent` (single choke point `requestBody()`, two→three breakpoints) and the M5 probe numbers.

---

## 4. Testing & Verification Strategy

### 4.1 Automated — extend `tests/smoke.php`

The existing `$bodyProbe` harness (`tests/smoke.php:695-712`) already exposes `requestBody()`; use it.

| ID | Test | Assertion |
|---|---|---|
| T1 | Shared id is emitted | `setUserId('myagent')` → `body()['metadata']['user_id'] === 'myagent'` |
| T2 | Opt-out is byte-clean | `setUserId(null)` → `!isset(body()['metadata'])` and `!isset(body()['prompt_cache_key'])` |
| T3 | Validation | `assertValidUserId` rejects `"bad id"`, `str_repeat('a',257)`, `"a\nb"`, `""`; accepts `myagent`, `myagent:v2`, `team@host.local` |
| T4 | Stability across calls | a `message()`-capturing fake (pattern of `$propProbe`, `tests/smoke.php:714-738`) records two `run()` turns → both bodies carry the **same** id |
| T5 | Reviewer inherits the id | extend `$reviewProbe` (`tests/smoke.php:758+`): the reviewer body carries the same `metadata.user_id` as the actor |
| T6 | No leakage into the checkpoint | after two turns, the saved session JSON (`$store->load()`) contains no `cache_control` and no `metadata` |
| T7 | Breakpoint discipline | `count_breakpoints(body()) === 3` with messages ≥ 2, `=== 2` for a single-message body, and the last message's last block carries `cache_control` |
| T8 | `/cache` rendering | 40/80-column output contains the id, the hit percentage, and never exceeds the width (follow the existing 40-column assertions in `tests/ui.php`) |

### 4.2 Live smoke runbook

```bash
composer validate && composer test
php agent.php --user-id=myagent --plain --no-banner "Reply with OK"      # 1 turn, no metadata error
php agent.php --session=cache-probe --user-id=myagent "Read README.md and reply with one line"
php agent.php --session=cache-probe --continue                           # resumed: id unchanged, turn 2
php agent.php --user-id=myagent                                        # interactive: banner shows identity, /cache works
```

Expected: no `HTTP 400` from the provider; in the interactive session the second turn's status line shows `(N cached)` with N ≥ the system+tools size, and `/cache` reports a hit ratio > 0 after two turns.

### 4.3 Regression + security checks

- `php -l` on every touched PHP file.
- The security reviewer path is unchanged except for the added `metadata`; confirm a reviewed `run_command` still executes (proves the reviewer's request body is accepted).
- Confirm the id contains no secret material (it is logged in the banner and printed by `/cache`, so it must never be derived from the API key).
- Confirm no `\r`/`\n` can reach a header or the body (the existing header-injection guards in `agent.php:110-125` remain the model to follow).

### 4.4 Performance check

Two consecutive identical one-turn sessions must show, on the second, `cache_read_input_tokens ≈ size(system)+size(tools)` for the *same* prefix. Record actual numbers in the KG; the estimate to beat is "conversation tokens re-billed at full price every turn" (F6) — after M4.2 the tail should start appearing in `cache_read_input_tokens` from the second request of a session.

---

## 5. Deployment, Rollout & Rollback

### 5.1 Rollout (no downtime — this is a CLI, ship it behind config)

| Step | Action | Effect |
|---|---|---|
| 1 | Merge M1+M2+M3 with `identity.send_metadata: false` in `config.json` | Code path live, body unchanged |
| 2 | ~~Run M5.1 in a paid probe~~ **done 2026-10-31** (§1.2.1) | `send_metadata: true` confirmed safe |
| 3 | Flip `identity.send_metadata: true` (default) | Shared id on every request |
| 4 | Enable `cache.conversation_breakpoint` (default already true — set explicitly in `config.json`) | Third breakpoint |
| 5 | `cache.ttl: "1h"` (M5.2 green 2026-10-31) | Longer reuse window across breaks |
| 6 | Ship M6 docs | — |

Each step is independently revertible; steps 3-5 are one-line `config.json` edits, so no code redeploy is needed to back out.

### 5.2 Rollback

- Provider rejects `metadata` → `"send_metadata": false` (body byte-identical to today).
- Higher cost than expected from conversation breakpoints → `"conversation_breakpoint": false`.
- Any behavioural surprise → `git revert` the M1-M3 commit; the session checkpoint format is untouched, so existing `.agent/sessions/*.json` files keep working in both directions.

### 5.3 Failure modes

| Failure | Symptom | Mitigation |
|---|---|---|
| Provider rejects `metadata` | HTTP 400, `invalid request` | `send_metadata: false`; record in README + KG |
| Provider rejects `prompt_cache_key` | HTTP 400 | it is opt-in and off by default |
| Strict endpoint rejects the 3rd breakpoint position | HTTP 400 about `cache_control` | `conversation_breakpoint: false` |
| `1h` TTL unsupported | HTTP 400 / unknown beta | keep `ttl: "5m"` |
| Id accidentally made per-process | Two sessions report different ids in the banner | T4 + the banner row make it visible; the default is a literal constant, not a derived value |
| Prefix still fragmented | `/cache` hit ratio stays ~0 across sessions | Work the F1/F3/F4 rows of §1.2; the id is not the cause |

---

---

## 7. Implementation record (2026-10-31)

**Status: implemented.** M1–M6 landed in one pass. `composer test` is green (UI,
Selector, Smoke — including the new identity/breakpoint tests), and the live run below
was measured against the provider.

Files changed: `src/Agent.php` (identity state; `setUserId()`, `userId()`,
`setSendCacheKey()`, `setCacheOptions()`, `setCacheTtl()`, `cacheTtl()`,
`hasConversationBreakpoint()`, `assertValidUserId()`, `cacheControl()`,
`withConversationBreakpoint()`; `metadata` + `prompt_cache_key` in `requestBody()`; the
`extended-cache-ttl` beta; `Agent::usageTotals()`, `cacheHitRatio()`,
`recordUsage()`), `src/Terminal.php` (banner `identity` row, `cacheReport()`),
`agent.php` (`--user-id=ID`, the `identity`/`cache` config blocks, `/cache`, help text,
client wiring, banner row), `config.example.json`, `config.json`, `tests/smoke.php`
(T1–T7), `tests/ui.php` (T8), `README.md`.

### 7.1 Deviations from this plan, recorded deliberately

1. **`Terminal::cacheReport()` was added** although §M5.3 said not to add a public
   `Terminal` method. The `/cache` output must survive the 40-column width guarantee,
   and that guarantee is only testable where the rendering lives (T8). `usage()` is
   unchanged.
2. **`setCacheOptions()` delegates to `setCacheTtl()`**, so an unsupported TTL is
   rejected by one code path instead of two.
3. **`cacheTtl()` / `hasConversationBreakpoint()` accessors** were added so the tests can
   assert state without going through the wire.
4. **`config.json` pins `cache.ttl: "1h"`** (M5.2 passed) while `config.example.json`
   keeps the portable `5m`.
5. The probe scripts stayed as gitignored scratch (`.agent/probe-user-id*.php`); the
   numbers in §1.2.1 are the durable record, not the scripts.

### 7.2 Live verification

Two turns in one process, prompts piped in (`--plain --no-banner`):

```text
identity    myagent (shared by every session) · metadata on
breakpoints system · tools · last-message · ttl 1h
usage       2 requests · 7.0k in · 6.5k cached (48.3% hit) · 0 written · 5 out
```

Reading the numbers: turn 1 paid full price for the ~6.5 k token system+tools prefix;
turn 2 read 6.5 k from cache and billed only ~0.5 k of new tokens — about 93 % of the
second prompt was reused. `cache_creation_input_tokens` stayed 0, matching the probe.

Fail-fast check: `php agent.php --user-id="bad id" hi` exits non-zero with
`Error: Invalid user id`, before any MCP connect or API call.

### 7.3 What is still not measured

The 5-minute-vs-1-hour TTL difference across a real idle gap: `cache.ttl: "1h"` is
enabled and the field is accepted, but proving the longer window needs a session paused
longer than 5 minutes. Worth a follow-up capture in `/cache`.

## 6. Follow-ups (not part of this plan)

1. **Cache keep-alive** — optional idle pinger revisiting the prefix every <5 min to hold a hot cache; only sensible given unlimited credit.
2. **Prefix hygiene pass** — decide F1 (`ask_user` advertisement) and F4 (MCP failure) explicitly: either accept the variance or normalise it (e.g. always include `ask_user` in the tool list and rely on its runtime error).
3. **Per-session cache report** — persist `usageTotals` into the session checkpoint so `/cache` can compare sessions over time.
