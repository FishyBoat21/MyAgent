# Implementation Plan — Model and Effort Selector

**Revision:** 1 (2026-10-31)
**Target repo:** `D:\Project\MyAgent` (PHP 8.4 CLI agent, Composer autoloading, no third-party packages)
**Status:** Implemented 2026-10-31 — M0–M6 complete; see §9 for verification and deviations.
**Depends on:** current `main` (`c1acf4d`), green baseline (`composer test`, `php tests/ui.php`).

---

## 1. Overview & Scope

### Objective

Make the acting model and its reasoning **effort** first-class, user-selectable settings:

1. The model keeps defaulting to `config.model`, but at runtime the user can pick any model
   the provider advertises, either with `/model <id>` or through an interactive picker.
2. The effort level defaults to `config.effort` (empty = provider default) and can be changed
   with `/effort <level>` or an interactive picker.
3. Both can be overridden per process with `--model=` (already exists) and the new
   `--effort=`, and both persist in the existing session checkpoint.
4. The provider model catalog is fetched **on demand** (never at startup) from an
   OpenAI-compatible `/models` endpoint, cached under `.agent/`, and normalized into a
   provider-agnostic shape. The catalog also supplies each model's supported effort levels.

Token usage is explicitly not a concern (unlimited quota), so the design favors clarity,
complete metadata, and defensive fallbacks over saving tokens. No compaction or
prompt-trimming behavior changes.

### Definition of Done

1. `php agent.php --list-models` prints the provider's models with ids, display names,
   context window, output cap, and declared effort levels, then exits `0`.
2. `/model` in an interactive REPL shows a numbered picker; selecting a model changes the
   model used by the **next** turn without restarting the process.
3. `/model <id>` works non-interactively and accepts a number, exact id, or unique id prefix.
4. `/effort` shows only the levels the current model declares (e.g. `low`, `high`, `max` for
   DeepSeek), marks the current/default level, and changes the effort for the next turn.
5. `/effort <level>` clamps to the nearest level the current model supports and tells the
   user when it clamped.
6. An empty/absent effort omits `output_config` entirely, so the provider default applies.
7. Every acting-model request includes `output_config.effort` **only** when an effort is set;
   the security reviewer never inherits the actor's effort.
8. The model/effort pair is written to the session checkpoint and restored by
   `--session=NAME --continue` unless overridden by `--model` / `--effort`.
9. A provider catalog failure never crashes the CLI: the picker falls back to stale cache,
   then to the static `models` config, then to typed ids, and prints one warning.
10. `composer test` (smoke + UI) and `php tests/ui.php` pass with no live API request.
11. README, `config.example.json`, and `--help` document the new settings and commands.

### Out of Scope

- A full-screen/alternate-screen TUI, arrow-key menus, or mouse input. The picker stays
  line-based, matching the existing `ask_user` and security `[y/N]` prompts.
- Streaming token output. Model/effort selection does not add streaming.
- Model-specific `max_tokens` or context-window enforcement. The config value stays
  authoritative; catalog metadata is displayed only.
- Changing the security reviewer's model or effort at runtime. It remains pinned at startup
  (see D6).
- Talking to provider-specific admin/model APIs beyond a single GET on `models_url`.
- Automatic model fallback/retry on provider errors.
- Cost tracking or token budgeting.

---

## 2. Prerequisites & Baseline

### P0 — Verified baseline

Run before touching code:

```powershell
composer test          # prints "UI tests passed" then "Smoke tests passed"
php tests/ui.php       # prints "UI tests passed"
git status --short     # expected clean
```

Verified on 2026-10-31 at `c1acf4d`: both suites are green.

### P1 — Live provider facts (probed 2026-10-31, DeepSeek)

These facts drive the catalog contract. Re-probe only if the provider changes.

| Probe | Result |
|------|--------|
| `GET https://api.deepseek.com/models` with `Authorization: Bearer <key>` | `200`, OpenAI-style `{"object":"list","data":[...]}` |
| `GET https://api.deepseek.com/anthropic/v1/models` | `404` — the Anthropic-compatible route has **no** model list |
| `deepseek-flash` metadata | name `DeepSeek-V4.1-Flash`, context `1048576`, max output `393216`, `effort.supported_levels = ["low","high","max"]`, `default_level = "high"`, input modalities `text,image` |
| `deepseek-v4-pro` metadata | name `DeepSeek-V4-Pro`, same context/output, same effort levels/default |
| `POST /anthropic/v1/messages` with `output_config.effort` in `low, medium, high, xhigh, max` | All `200`. `low` returned an empty `thinking` block; `high`/`max` returned non-empty reasoning |
| Replaying a `deepseek-flash` `thinking` block to `deepseek-v4-pro` | `200` — cross-model thinking blocks are accepted by this provider, so **no history rewrite is required on model switch** |

**Consequence:** declare only the provider-advertised levels in the UI (`low`, `high`, `max`
for DeepSeek). Treat undeclared levels as clampable, not selectable.

### P2 — Provider config shape (current live `config.json`, gitignored)

```json
{
  "model": "deepseek-flash",
  "api_url": "https://api.deepseek.com/anthropic/v1/messages",
  "api_key": "sk-…",
  "api_key_header": "x-api-key",
  "api_key_prefix": "",
  "api_version": "2023-06-01"
}
```

`api_url` is the **full Messages endpoint**; do not derive the models URL by string surgery
(`/anthropic/v1/models` is 404). Use an explicit `models_url` or the known-provider map in
§3.6.

---

## 3. Architecture & Contract Changes

### 3.1 Concepts

- **Effort** is the Anthropic Messages API field `output_config.effort`, with canonical
  levels ordered `low < medium < high < xhigh < max`. It is not a thinking-token budget.
- **Model catalog** is a normalized list of entries:

  ```php
  [
      'id' => 'deepseek-flash',
      'name' => 'DeepSeek-V4.1-Flash',
      'context_window' => 1048576,
      'max_output_tokens' => 393216,
      'effort_levels' => ['low', 'high', 'max'],
      'default_effort' => 'high',
  ]
  ```

  `effort_levels` is `[]` when the provider does not declare them; callers then allow the
  canonical five. `default_effort` is `null` when undeclared.

- **Current effort** is `?string`: `null` means "omit `output_config` and let the provider
  decide". A non-null value is always one of the canonical five, and is clamped to the
  selected model's declared levels before being sent.

### 3.2 New class — `src/ModelCatalog.php`

```php
namespace MyAgent;

final class ModelCatalog
{
    public const EFFORT_ORDER = ['low', 'medium', 'high', 'xhigh', 'max'];

    public function __construct(
        private string $root,
        private string $modelsUrl = '',
        private string $auth = 'bearer',        // bearer|header|none
        private string $apiKey = '',
        private string $apiKeyHeader = 'x-api-key',
        private string $apiKeyPrefix = '',
        private string $apiVersion = '2023-06-01',
        private int $cacheTtl = 86400,          // seconds; <=0 disables cache
        private array $staticModels = [],       // config "models"
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function models(bool $refresh = false): array;

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array;

    /** @return list<string> */
    public function effortLevels(string $id): array;

    public function defaultEffort(string $id): ?string;

    /** live|cache|static|none */
    public function source(): string;

    public function lastError(): ?string;

    /** @return array<int, array<string, mixed>> */
    public static function normalize(array $payload): array;

    /** @return array<int, array<string, mixed>> */
    public static function normalizeStatic(array $entries): array;

    /** @return array{0: ?string, 1: bool} [level, clamped] */
    public static function clampEffort(?string $requested, array $levels, ?string $default = null): array;

    public static function validEffort(?string $effort): bool;
}
```

**Resolution order for `models()`**

1. If `!$refresh` and the cache is fresh (`cacheTtl > 0`, age `< ttl`, URL matches), return it
   with `source() === 'cache'`.
2. If `modelsUrl` is non-empty, `GET` it (20 s total / 5 s connect, redirects **disabled**).
   On HTTP 2xx + decodable JSON, normalize, atomically write the cache, return it with
   `source() === 'live'`.
3. On any failure, return a stale cache if one exists (`source() === 'cache'`) and set
   `lastError()`.
4. Else return `normalizeStatic($staticModels)` if non-empty (`source() === 'static'`).
5. Else return `[]` (`source() === 'none'`) and keep `lastError()`.

**Cache contract** — `.agent/models-cache.json` (directory `0700`, atomic `tempnam` +
`rename`, best-effort `chmod 0600`):

```json
{
  "version": 1,
  "url": "https://api.deepseek.com/models",
  "fetched_at": 1793491200,
  "models": [ { "id": "...", "name": "...", "...": "..." } ]
}
```

The cache MUST NOT contain the API key, headers, or any response fields other than the
normalized entries.

**Auth selection for the GET**

| `models_auth` | Headers sent |
|---|---|
| `bearer` (default) | `Authorization: Bearer <api_key>` |
| `header` | `<api_key_header>: <api_key_prefix><api_key>` and `anthropic-version: <api_version>` |
| `none` | none |

Reject non-`http(s)` URLs and CR/LF in any header value (same guard style as `agent.php`).

**`normalize()` accepts both shapes**

- DeepSeek/OpenAI: `data[]` with `id`, `name`, `context_window`, `max_output_tokens`,
  `effort.supported_levels`, `effort.default_level`.
- Anthropic Models API: `data[]` with `id`, `display_name`, `max_input_tokens`, `max_tokens`,
  and optionally `capabilities`. Map `display_name → name`, `max_input_tokens →
  context_window`, `max_tokens → max_output_tokens`; leave effort levels empty unless a
  `capabilities.effort.supported_levels` (or `effort.supported_levels`) array is present.
- Unknown fields are ignored. Entries without a non-empty string `id` are dropped. Result is
  de-duplicated by `id`, preserving provider order.

**`normalizeStatic()`** accepts a list of strings or objects:

```json
"models": [
  "deepseek-flash",
  { "id": "deepseek-v4-pro", "name": "DeepSeek-V4-Pro",
    "effort_levels": ["low", "high", "max"], "default_effort": "high",
    "context_window": 1048576, "max_output_tokens": 393216 }
]
```

A bare string becomes `['id' => $s, 'name' => $s, 'context_window' => null,
'max_output_tokens' => null, 'effort_levels' => [], 'default_effort' => null]`.

**`clampEffort()`**

- `null`/`''` → `[null, false]`.
- Not in `EFFORT_ORDER` → throw `RuntimeException('Invalid effort level: …')`.
- `$levels === []` → `[$requested, false]`.
- Requested is declared → `[$requested, false]`.
- Otherwise pick the declared level with the smallest ordinal distance; ties go **higher**
  (`medium` → `high` on DeepSeek, `xhigh` → `max`). If `$levels` is malformed, fall back to
  `$default` when it is valid, else `null`.

### 3.3 New class — `src/ModelSelector.php`

Line-based picker modeled on `AskUser` (same injected `readLine` / `write` closures, same
sanitization, same 3-attempt cap). Rendering goes to **STDERR** through `Terminal`, so
STDOUT stays assistant prose.

```php
namespace MyAgent;

final class ModelSelector
{
    public function __construct(
        private ?Closure $readLine = null,
        private ?Closure $write = null,
        private ?Terminal $terminal = null,
    ) {}

    public function available(): bool;          // readLine !== null

    /**
     * @param array<int, array<string, mixed>> $models
     * @param null|callable(bool): array<int, array<string, mixed>> $refresh
     * @return array{action: 'select'|'keep'|'cancel', model: ?string}
     */
    public function pickModel(array $models, ?string $current, ?string $defaultModel, ?callable $refresh = null): array;

    /**
     * @param list<string> $levels
     * @return array{action: 'select'|'keep'|'cancel', effort: ?string}
     */
    public function pickEffort(array $levels, ?string $current, ?string $defaultLevel): array;

    /**
     * Shared resolver for /model <arg>: number, exact id, or unique prefix.
     * @param array<int, array<string, mixed>> $models
     */
    public static function resolve(string $arg, array $models): array; // {status: 'match'|'ambiguous'|'unknown', model: ?string, candidates: list<string>}
}
```

**Model picker rendering (styled; plain fallback shown first):**

```
[?] Select a model  (provider: live catalog, 2 models)

  * 1) deepseek-flash        DeepSeek-V4.1-Flash   1M ctx · 384K out · effort low/high/max
    2) deepseek-v4-pro       DeepSeek-V4-Pro       1M ctx · 384K out · effort low/high/max

    Default: deepseek-flash · Current: deepseek-flash
Select 1-2, type a model id, Enter to keep the current model, r to refresh, q to cancel:
```

- Numbers beyond the list, blank after retries, `q`, `quit`, `cancel`, or EOF → cancel.
- Empty line → keep the current model.
- `r` / `refresh` → call `$refresh(true)`, re-render, continue (max one refresh per pick to
  avoid a network loop; further `r` just re-renders).
- Exact/unique-prefix id → select.
- Ambiguous prefix → print the matching ids and retry.
- Unknown text → ask one confirmation (`Use "<id>" anyway? [y/N]`). `y` selects it; anything
  else retries. This keeps stale catalogs usable without silently accepting typos.
- Every model name/id is passed through `Ansi::sanitize()` before rendering. Long lists are
  capped at 50 rows with a `… and N more; type an id` line (no pagination in v1).

**Effort picker rendering:**

```
[?] Select effort for deepseek-flash  (declared: low/high/max · default high)

  * 1) low         3) max
    2) high        0) provider default (omit output_config)

Select 0-3, Enter to keep the current effort, q to cancel:
```

- When `$levels === []`, show the canonical five instead.
- `0` / `default` → select `null` (omit).
- A level that the model does not declare is clamped by `clampEffort()` before returning,
  and the caller prints the clamp notice.
- Current `null` marks row `0`.

`available() === false` (piped stdin): the caller prints the same list as plain text and
instructs the user to use `/model <id>` / `/effort <level>`.

### 3.4 Changes to `src/Agent.php`

#### `AnthropicClient`

Do **not** add a parameter to `message()`: existing tests subclass it with the five-argument
signature, and PHP rejects an incompatible override (verified). Use state + a `protected`
body builder instead.

```php
private const EFFORT_LEVELS = ['low', 'medium', 'high', 'xhigh', 'max'];
private ?string $effort = null;

public function setEffort(?string $effort): void
{
    $effort = $effort === null ? null : strtolower(trim($effort));
    if ($effort === '') {
        $effort = null;
    }
    if ($effort !== null && !in_array($effort, self::EFFORT_LEVELS, true)) {
        throw new RuntimeException('Invalid effort level: ' . $effort);
    }
    $this->effort = $effort;
}

public function effort(): ?string { return $this->effort; }

/** Run a callback with effort omitted, then restore it (reviewer isolation). */
public function withoutEffort(callable $callback): mixed
{
    $effort = $this->effort;
    $this->effort = null;
    try { return $callback(); }
    finally { $this->effort = $effort; }
}

/**
 * @return array<string, mixed>
 */
protected function requestBody(string $model, int $maxTokens, string $system, array $messages, array $tools): array
{
    $body = ['model' => $model, 'max_tokens' => $maxTokens, 'messages' => $messages];
    if ($system !== '') {
        $body['system'] = [['type' => 'text', 'text' => $system, 'cache_control' => ['type' => 'ephemeral']]];
    }
    if ($tools !== []) {
        $tools = array_values($tools);
        $tools[count($tools) - 1]['cache_control'] = ['type' => 'ephemeral'];
        $body['tools'] = $tools;
    }
    if ($this->effort !== null) {
        $body['output_config'] = ['effort' => $this->effort];
    }
    // existing compaction block: unchanged, appended to $betas and $body
    return $body;
}
```

`message()` becomes:

```php
public function message(string $model, int $maxTokens, string $system, array $messages, array $tools): array
{
    $betas = [];
    $body = $this->requestBody($model, $maxTokens, $system, $messages, $tools);
    $approxChars = strlen($system) + strlen((string) json_encode($messages, JSON_INVALID_UTF8_SUBSTITUTE));
    if ($this->compactionThreshold > 0 && $approxChars >= $this->compactionThreshold) {
        $body['context_management'] = ['edits' => [['type' => 'compact_20260112', 'summary_max_tokens' => $this->summaryMaxTokens]]];
        $betas[] = 'compact-2026-01-12';
    }
    $payload = $this->encodeRequestBody($body);
    // …unchanged headers/retry loop…
}
```

`output_config` and `context_management` coexist in the same body; no ordering dependency.

#### `Agent`

```php
private ?string $effort = null;   // mirrored for checkpoints

public function model(): string { return $this->model; }
public function setModel(string $model): void { /* validate non-empty + id charset */ $this->model = $model; }
public function effort(): ?string { return $this->effort; }
public function setEffort(?string $effort): void { $this->api->setEffort($effort); $this->effort = $this->api->effort(); }
```

- Constructor: after the existing `setCompactionSettings()` call, add
  `$this->effort = $api->effort();`.
- `checkpoint()`: add `'effort' => $this->effort` next to `'model'`.
- `setModel()` validation: trim; reject empty, `\r`, `\n`, and length > 200; allow
  `[A-Za-z0-9._:/@+\[\]-]` (covers `deepseek-flash`, `deepseek/v4-pro`, Bedrock-style
  prefixed ids, and `[1m]` suffixes). Throw `RuntimeException('Invalid model name')`
  otherwise.
- No change to `systemPrompt()`, tool definitions, or the loop. A switch takes effect on the
  next `$this->api->message($this->model, …)` call.

#### `SecurityClassifier` (line ~194)

The reviewer shares the actor's client, so it must not inherit the actor's effort:

```php
$response = $this->client->withoutCompaction(fn(): array => $this->client->withoutEffort(
    fn(): array => $this->client->message(
        $this->model,
        $this->maxTokens,
        $this->prompt(),
        $messages,
        []
    )
));
```

(The existing call's exact arguments stay as they are; only the nesting is added.)

### 3.5 Changes to `agent.php`

**New flags**

```php
$options = getopt('', [
    'config:', 'model:', 'effort:', 'max-steps:', 'command-timeout:', 'session:',
    'continue', 'help', 'plain', 'no-color', 'width:', 'no-banner', 'tool-details:',
    'list-models', 'refresh-models',
]);
```

**Model precedence (changed — fixes the inherited `ANTHROPIC_MODEL` hazard)**

1. `--model=`
2. session checkpoint `model` when `--continue` is present
3. `config.model`
4. `LLM_MODEL` env (explicit MyAgent override)
5. `ANTHROPIC_MODEL` env (last-resort alias)
6. built-in `claude-sonnet-5-5`

The previous order put `LLM_MODEL`/`ANTHROPIC_MODEL` ahead of `config.model`; a Claude Code
shell exports `ANTHROPIC_MODEL=deepseek/deepseek-flash[1M]`, which the provider rejects. The
new order makes `config.model` the documented default, as requested.

**Effort precedence**

1. `--effort=`
2. session checkpoint `effort` when `--continue`
3. `config.effort`
4. `null` (omit)

Validate with `AnthropicClient::setEffort()` (canonical five or null). After the model is
known, clamp with `ModelCatalog::clampEffort()`; if clamped, print
`Effort "medium" is not supported by deepseek-flash; using "high".` once at startup.

**Config parsing / validation (after the existing `ui` block)**

```php
$modelsUrl = trim((string) ($config['models_url'] ?? ''));
if ($modelsUrl === '') {
    $modelsUrl = self::defaultModelsUrl($apiUrl); // known-provider map
}
$modelsAuth = (string) ($config['models_auth'] ?? 'bearer');
if (!in_array($modelsAuth, ['bearer', 'header', 'none'], true)) {
    throw new RuntimeException('models_auth must be bearer, header, or none');
}
$cacheTtl = (int) ($config['models_cache_ttl'] ?? 86400);
$staticModels = $config['models'] ?? [];
if (!is_array($staticModels)) {
    throw new RuntimeException('models must be an array');
}
$catalog = new ModelCatalog(
    $root, $modelsUrl, $modelsAuth, (string) $apiKey,
    $apiKeyHeader, $apiKeyPrefix, $apiVersion, $cacheTtl, $staticModels
);
```

**Known-provider map (only when `models_url` is absent)**

| `api_url` host | Default `models_url` | Default auth |
|---|---|---|
| `api.deepseek.com` | `https://api.deepseek.com/models` | `bearer` |
| `api.anthropic.com` | `https://api.anthropic.com/v1/models` | `header` |
| anything else | `''` (static list only) | `bearer` |

This is a convenience map, not inference: the host alone does not tell us where the catalog
lives, so unknown hosts require explicit `models_url`.

**Session restore** — before constructing `Agent`:

```php
$state = $store?->load();
if (isset($options['continue']) && $state !== null && !isset($options['model'])) {
    $model = (string) ($state['model'] ?? $model);
}
if (isset($options['continue']) && $state !== null && !isset($options['effort']) && array_key_exists('effort', $state)) {
    $effort = $state['effort'] === null ? null : (string) $state['effort'];
}
```

Pass the resolved effort to the client before the reviewer is built:

```php
$api = new AnthropicClient(...);
$api->setEffort($effort);
```

**Banner** — add one row and update the hint:

```php
'model' => (string) $model . ($effort !== null ? ' · effort ' . $effort : ''),
```

or keep them separate:

```php
'effort' => $effort ?? 'provider default',
```

Add `'effort' => 'effort'` to `Terminal::banner()`'s `$labels` map. Update the hint to:
`Type a task. /model and /effort change the run. /continue resumes. Ctrl+C exits.`

**`--list-models`**

```
$ models = $catalog->models(isset($options['refresh-models']));
if ($models === []) { $ui->warning('No models available: ' . $catalog->lastError()); exit(1); }
// print: id, name, context/output, effort levels, current marker; then exit(0)
```

Must not build MCP clients or the reviewer. Fetch happens only for this flag or `/model`.

**REPL dispatch** (`while (true)` loop)

Recognize exactly these command prefixes, everything else stays a prompt (preserving the
existing "unknown input goes to the model" behavior, including `/exit`):

```php
if ($input === '/continue') { … existing … }
elseif ($input === '/help') { $ui->heading('Commands'); /* /continue, /model, /effort, Ctrl+C */ }
elseif ($input === '/model' || str_starts_with($input, '/model ')) { … }
elseif ($input === '/effort' || str_starts_with($input, '/effort ')) { … }
else { $complete = $agent->run($input); }
```

**`/model` handling**

1. `$arg = trim(substr($input, 6));`
2. `refresh` / `r` → force fetch and print the list (no selection).
3. No arg:
   - non-TTY → print the list (`$ui->info`) and a usage line; no prompt.
   - TTY → `ModelSelector::pickModel(...)`. On `select`, apply; on `keep`/`cancel`, print
     `Model unchanged (<id>).`
4. With arg → `ModelSelector::resolve($arg, $models)`:
   - `match` → apply.
   - `ambiguous` → print candidates, do not apply.
   - `unknown` → print a warning and apply it anyway (catalogs can be stale; the next API
     call surfaces an invalid id). `/model <number>` still requires the catalog.
5. Apply:

```php
$agent->setModel($id);
$levels = $catalog->effortLevels($id);
[$clampedEffort, $clamped] = ModelCatalog::clampEffort($agent->effort(), $levels,
    $catalog->defaultEffort($id));
if ($clamped && $agent->effort() !== null) {
    $agent->setEffort($clampedEffort);
    $ui->info("Effort \"{$old}\" is not supported by {$id}; using \"" . ($clampedEffort ?? 'provider default') . '".');
}
$ui->info("Model set to {$id}" . ($agent->effort() !== null ? " · effort {$agent->effort()}" : ' · provider-default effort'));
```

Do **not** clear conversation history and do **not** strip thinking blocks (verified accepted
by this provider; Anthropic drops unreadable blocks itself). Note in the docs that switching
invalidates the prompt cache for the next turn.

**`/effort` handling**

1. No arg → picker (TTY) or a plain list + usage (non-TTY).
2. `default` / `none` / `off` → `$agent->setEffort(null); $ui->info('Effort set to provider default.');`
3. Otherwise clamp against the **current model's** levels, apply, and print a clamp notice
   when it changed.

**Error containment** — every catalog/selector call is wrapped so a network or JSON failure
prints `$ui->warning(...)` and keeps the REPL alive. No new exit codes; `--list-models`
failure exits `1`.

### 3.6 Config contract

`config.example.json` gains:

```json
"effort": null,
"models_url": "",
"models_auth": "bearer",
"models_cache_ttl": 86400,
"models": []
```

| Key | Type | Default | Meaning |
|---|---|---|---|
| `effort` | string\|null | `null` | Canonical level; `null`/`""` omits `output_config` |
| `models_url` | string | `""` | Catalog endpoint; empty → known-provider map, then static list |
| `models_auth` | `bearer\|header\|none` | `bearer` | How the catalog GET authenticates |
| `models_cache_ttl` | int | `86400` | Seconds; `0` = always refetch; negative rejected |
| `models` | array | `[]` | Static fallback entries (strings or objects) |

The live, gitignored `config.json` gets:

```json
"models_url": "https://api.deepseek.com/models",
"models_auth": "bearer",
"effort": "high"
```

`models_url`/`models_auth` are optional for DeepSeek because of the known-provider map; set
them explicitly anyway so the intent is visible.

### 3.7 Session checkpoint

Version stays `1`; the new key is optional:

```json
{
  "version": 1,
  "model": "deepseek-v4-pro",
  "effort": "max",
  "in_progress": false,
  "pending_results": [],
  "messages": []
}
```

Old checkpoints without `effort` load normally (`effort` treated as `null`). `SessionStore`
needs no change.

---

## 4. Step-by-Step Execution Sequence

### M0 — Baseline and evidence (no code)

- **Files:** none.
- **Steps:** run the P0 commands; record the P1 probe output in the commit message or PR.
- **Validation:** `composer test` and `php tests/ui.php` both green.
- **Gate:** do not start M1 until green.

### M1 — Request body and effort plumbing

- **Files modified:** `src/Agent.php` (`AnthropicClient`, `Agent`), `src/SecurityClassifier.php`.
- **Changes:** §3.4 in full. No signature change to `message()`.
- **Dependencies:** M0.
- **Validation:**
  ```powershell
  php -l src/Agent.php
  php -l src/SecurityClassifier.php
  composer test
  ```
  Existing fake clients in `tests/smoke.php` must still load (proves no override break).
- **Exit criteria:** an effort can be set on the client and appears in a body built by
  `requestBody()`; the reviewer call clears it.

### M2 — Model catalog

- **Files created:** `src/ModelCatalog.php`.
- **Files modified:** `composer.json` (no dependency change; run
  `composer dump-autoload -o` after adding the class so the classmap picks it up).
- **Changes:** §3.2 in full, including atomic cache writes and both normalizers.
- **Dependencies:** M1 (uses canonical effort validation).
- **Validation:**
  ```powershell
  php -l src/ModelCatalog.php
  php -r "require 'vendor/autoload.php'; var_export(MyAgent\ModelCatalog::normalize(json_decode(file_get_contents('php://stdin'), true)));"
  ```
  Feed a saved DeepSeek `/models` response and confirm two entries with
  `effort_levels = ['low','high','max']`.
- **Exit criteria:** offline behavior returns static entries, live failure returns stale
  cache, and `source()`/`lastError()` report which path ran.

### M3 — Interactive selector

- **Files created:** `src/ModelSelector.php`.
- **Changes:** §3.3 in full. Reuse `Ansi::sanitize`, `Ansi::wrap`, `Ansi::truncateMiddle`,
  and the `Terminal::styleErr`/`symbolErr`/`width` APIs.
- **Dependencies:** M2 (entry shape) and M1 (effort validators).
- **Validation:** `php -l src/ModelSelector.php`, then a scratch script wiring scripted
  `readLine` lines (`"1\n"`, `"\n"`, `"q\n"`, `"deepseek-v4-p\n"`, `"r\n2\n"`).
- **Exit criteria:** every input path returns the documented action without throwing.

### M4 — CLI and REPL wiring

- **Files modified:** `agent.php`, `src/Terminal.php`.
- **Changes:** §3.5 in full: flags, precedence, restore, banner label, `--list-models`,
  `/model`, `/effort`, `/help`, error containment.
- **Dependencies:** M1–M3.
- **Validation:**
  ```powershell
  php -l agent.php
  php agent.php --help
  php agent.php --list-models
  php agent.php --effort=low --model=deepseek-flash "Reply with OK"
  ```
  Then an interactive run: `/model` → pick `deepseek-v4-pro`, `/effort` → `max`, send a task,
  `/model` again to confirm the current marker moved.
- **Exit criteria:** banner shows the effort; switching survives into the next turn; the
  provider accepts the request.

### M5 — Documentation and examples

- **Files modified:** `README.md`, `config.example.json`, `config.json` (gitignored).
- **README:** new `## Models and effort` section after `## Start`:
  - default comes from `config.model`; `--model`/`LLM_MODEL` override order
  - `/model`, `/model <id|number|prefix>`, `/effort`, `/effort default`
  - `--list-models` and `--refresh-models`
  - catalog config keys, cache location/TTL, known-provider map, offline fallback
  - that switching invalidates the prompt cache and does not rewrite history
  - that the security reviewer keeps its own pinned model and provider-default effort
- **`--help`:** list `/model`, `/effort`, `/help`, `--effort`, `--list-models`.
- **Validation:** `php agent.php --help | Select-String "effort"` shows the new lines;
  `composer validate`.
- **Exit criteria:** a reader can configure DeepSeek and Anthropic catalog endpoints from
  the README alone.

### M6 — Tests and full verification

- **Files modified:** `tests/smoke.php`, `composer.json`.
- **Files created:** `tests/selector.php`.
- **Changes:** §5 below.
- **Dependencies:** M1–M5.
- **Validation:**
  ```powershell
  composer dump-autoload -o
  composer validate
  composer test
  php tests/ui.php
  php tests/selector.php
  ```
  All exit `0`; smoke prints `UI tests passed` and `Smoke tests passed`.
- **Exit criteria:** no live API call is required for the suite; all new assertions pass.

---

## 5. Testing & Verification Strategy

### 5.1 Deterministic smoke additions (`tests/smoke.php`)

1. **Catalog normalization — DeepSeek shape**
   ```php
   $models = ModelCatalog::normalize(['object' => 'list', 'data' => [
       ['id' => 'deepseek-flash', 'name' => 'DeepSeek-V4.1-Flash',
        'context_window' => 1048576, 'max_output_tokens' => 393216,
        'effort' => ['supported_levels' => ['low','high','max'], 'default_level' => 'high']],
   ]]);
   check($models[0]['id'] === 'deepseek-flash', '…');
   check($models[0]['effort_levels'] === ['low','high','max'], '…');
   check($models[0]['default_effort'] === 'high', '…');
   ```
2. **Catalog normalization — Anthropic shape**
   `display_name` → `name`, `max_input_tokens` → `context_window`, `max_tokens` →
   `max_output_tokens`, empty effort levels.
3. **Catalog normalization robustness** — missing `id` dropped, duplicate ids collapsed,
   non-array payload returns `[]`, unknown fields ignored.
4. **Static models** — string and object entries; malformed entries dropped.
5. **Effort clamping**
   ```php
   check(ModelCatalog::clampEffort('medium', ['low','high','max']) === ['high', true], '…');
   check(ModelCatalog::clampEffort('xhigh', ['low','high','max']) === ['max', true], '…');
   check(ModelCatalog::clampEffort('high', ['low','high','max']) === ['high', false], '…');
   check(ModelCatalog::clampEffort(null, ['low','high','max']) === [null, false], '…');
   check(ModelCatalog::clampEffort('low', []) === ['low', false], '…');
   ```
   Plus an invalid level throws `Invalid effort level`.
6. **Request body** — a subclass exposes `requestBody()` (same pattern as the existing
   `encodeRequestBody` test):
   - effort set → `output_config.effort` present;
   - effort null → `output_config` absent;
   - `context_management` and `output_config` coexist;
   - the `model` field is the argument passed to `message()`, not a stored default.
7. **Agent propagation** — a fake client records the `$model` argument and `effort()` at each
   `message()` call: after `setModel('deepseek-v4-pro')` and `setEffort('max')`, the next
   call uses both.
8. **Checkpoint round-trip** — `save`/`load` preserves `model` and `effort`, including
   `effort => null`; an old checkpoint without `effort` still loads.
9. **Reviewer isolation** — a fake client records `effort()` during
   `SecurityClassifier::review()`; it must be `null` even when the actor has `max`.
   (Drive it through an allowed call, e.g. `read_file`, or the `review_all` path.)
10. **Model-name validation** — empty, newline, oversized, and illegal-character ids throw;
    `deepseek-flash`, `deepseek/v4-pro`, and `anthropic.claude-opus-5-5` pass.
11. **Selector** — see `tests/selector.php`.

### 5.2 `tests/selector.php`

Mirror `tests/ui.php`'s standalone style (own `check()`, bootstrap via
`vendor/autoload.php`) and add `require_once __DIR__ . '/selector.php'` near the end of
`tests/smoke.php`, matching how `tests/ui.php` is required.

Cases (scripted `readLine` queues + an accumulated `$out` buffer):

| Input | Expected |
|---|---|
| `1` | selects `models[0]` |
| `2` | selects `models[1]` |
| empty | `keep` |
| `q` | `cancel` |
| `deepseek-v4-p` | selects `deepseek-v4-pro` (unique prefix) |
| `deepseek-` | ambiguous → prints candidates, then retries |
| `nope` then `y` | selects `nope` after confirmation |
| `nope` then `n` then `1` | selects `models[0]` |
| `r` then `2` | refresh callback invoked once, selects `models[1]` |
| `0` / `default` (effort) | selects `null` |
| `medium` (effort, levels low/high/max) | selects `high`, reports clamped |
| 4 invalid inputs | throws `No valid answer` after the attempt cap |
| no `readLine` | `available() === false` |
| model name containing `\e]52;…\a` | output contains no escape sequence |

Also assert `ModelSelector::resolve()` directly for `match` / `ambiguous` / `unknown`.

### 5.3 End-to-end manual runbook (live, DeepSeek)

```powershell
# 1. Catalog
php agent.php --list-models
#    expect: deepseek-flash and deepseek-v4-pro with effort low/high/max

# 2. Non-interactive effort
php agent.php --model=deepseek-flash --effort=low "Reply with the single word OK"
#    expect: a normal answer; no HTTP 400

# 3. Interactive switch
php agent.php
#    /model            -> pick 2 (deepseek-v4-pro)
#    /effort           -> pick max
#    reply with OK     -> answer from v4-pro at max effort
#    /model            -> current marker on deepseek-v4-pro
#    /effort           -> current marker on max
#    Ctrl+C

# 4. Session persistence
php agent.php --session=sel-test
#    /model <v4-pro>, /effort max, Ctrl+C
php agent.php --session=sel-test --continue --list-models  # sanity only
php agent.php --session=sel-test --continue
#    banner shows v4-pro / max
#    delete .agent/sessions/sel-test.json afterwards

# 5. Offline fallback
#    temporarily set models_url to http://127.0.0.1:9/models
php agent.php
#    /model -> warning + static/empty list, typed ids still accepted; REPL alive
```

### 5.4 Security checks

- The catalog GET must not follow redirects (`CURLOPT_FOLLOWLOCATION = false`), so a
  compromised endpoint cannot exfiltrate the API key.
- Only `http`/`https` URLs are accepted; CR/LF in generated headers is rejected.
- The cache file contains model metadata only — no key, no request headers.
- Model ids and display names are `Ansi::sanitize()`d before rendering.
- The reviewer never inherits the actor's effort (test 9).
- `output_config.effort` is validated against a fixed allow-list before it enters the body.

### 5.5 Performance checks

- No catalog fetch at startup; startup latency is unchanged unless `--list-models` is used.
- Catalog GET: 20 s total / 5 s connect; a failure returns quickly and does not block the
  REPL for the full timeout in normal use (the picker is user-initiated).
- Cache prevents a network round-trip on every `/model`.

---

## 6. Risks, Decisions & Rollback

### Decision log

| # | Decision | Rationale |
|---|---|---|
| D1 | Effort is `output_config.effort`, not a thinking budget | Current API; provider accepts it (probed) |
| D2 | Model list is fetched lazily from the provider catalog | No startup cost; user asked for the provider's list |
| D3 | `config.model` outranks `LLM_MODEL`/`ANTHROPIC_MODEL` | Matches "model default in config"; fixes the inherited `ANTHROPIC_MODEL` hazard |
| D4 | `effort = null` omits `output_config` | Provider default; avoids claiming a level a model may not support |
| D5 | Clamp to declared levels; reject unknown level names | The UI should not offer choices the provider does not advertise |
| D6 | Reviewer ignores the actor's model/effort | It must stay an independent, deterministic gate |
| D7 | No history rewrite on model switch | Verified cross-model thinking replay succeeds; Anthropic drops unreadable blocks |
| D8 | Session format stays v1 with an optional `effort` key | Backward compatible; no migration |
| D9 | Only exact `/model`, `/effort`, `/continue`, `/help` are commands | Avoids swallowing prompts that happen to start with `/` |
| D10 | Catalog cache holds metadata only | Never persist credentials |

### Risks and mitigations

| Risk | Mitigation |
|---|---|
| Provider catalog has a different shape | Two normalizers + static `models` fallback + typed-id escape hatch |
| Catalog endpoint offline / slow | Cache-first, stale-cache fallback, 5 s connect timeout, warning instead of crash |
| User picks a stale/invalid model id | Unknown ids are accepted with a warning; the provider's next 400 is visible |
| Effort change invalidates the prompt cache | Documented; acceptable under the unlimited-token constraint |
| A different provider rejects `output_config` | Set `"effort": null` (or omit) to send nothing; documented |
| Reviewer gets weaker under low actor effort | Reviewer call clears effort (`withoutEffort`) |
| Session restore surprises a user | `--model`/`--effort` always win; the banner shows the effective pair |

### Rollback

No schema or on-disk migration. To roll back:

1. Revert the feature commit(s) (`git revert <sha>` or reset the branch).
2. Delete `.agent/models-cache.json` (optional; unused by the reverted code).
3. Existing session files remain valid — the reverted code ignores the extra `effort` key.
4. `composer dump-autoload -o` after the revert so the classmap drops `ModelCatalog` and
   `ModelSelector`.

---

## 7. File-by-File Summary

| File | Action | Purpose |
|---|---|---|
| `src/ModelCatalog.php` | create | Fetch/normalize/cache the provider catalog; effort clamping |
| `src/ModelSelector.php` | create | Line-based model/effort pickers; shared resolver |
| `src/Agent.php` | modify | `setEffort`/`withoutEffort`/`requestBody`; `setModel`; checkpoint effort |
| `src/SecurityClassifier.php` | modify | Reviewer call runs with effort cleared |
| `src/Terminal.php` | modify | Add the `effort` banner row |
| `agent.php` | modify | Flags, precedence, session restore, `/model`, `/effort`, `/help`, `--list-models` |
| `config.example.json` | modify | `effort`, `models_url`, `models_auth`, `models_cache_ttl`, `models` |
| `config.json` | modify (local) | DeepSeek catalog URL + default effort |
| `README.md` | modify | "Models and effort" section; verify commands |
| `tests/smoke.php` | modify | Catalog, body, Agent, reviewer, checkpoint assertions; require selector tests |
| `tests/selector.php` | create | Scripted picker tests |
| `composer.json` | modify (scripts only) | Optional `test:selector` script |
| `IMPLEMENTATION_PLAN-model-effort-selector.md` | create | This document |

---

## 8. Commit Plan

One atomic commit per milestone is acceptable, but the feature is small enough for a single
reviewable commit after M6:

```
feat(model): add model catalog and effort selector

- fetch and cache the provider model list (DeepSeek /models, Anthropic /v1/models)
- send output_config.effort only when selected; clamp to declared levels
- add /model, /effort, /help, --effort, --list-models
- persist model/effort in session checkpoints
- keep the security reviewer on its pinned model and provider-default effort
- make config.model the default ahead of inherited ANTHROPIC_MODEL

Verified: composer test, php tests/ui.php, php tests/selector.php,
php agent.php --list-models, live switch deepseek-flash -> deepseek-v4-pro at max effort.
```

---

## 9. Implementation Record (2026-10-31)

### Files added

- `src/ModelCatalog.php` — catalog fetch/normalize/cache, effort clamping.
- `src/ModelSelector.php` — line-based model/effort pickers and shared resolver.
- `tests/selector.php` — scripted picker tests (also required from `tests/smoke.php`).

### Files changed

- `src/Agent.php` — `AnthropicClient::setEffort()/effort()/withoutEffort()` and a
  protected `requestBody()` builder (the `message()` signature is unchanged);
  `Agent::model()/setModel()/effort()/setEffort()`; `effort` in the checkpoint.
- `src/SecurityClassifier.php` — reviewer call is wrapped in `withoutEffort()` as well
  as `withoutCompaction()`.
- `src/Terminal.php` — `effort` banner row and updated hint.
- `agent.php` — `--effort`, `--list-models`, `--refresh-models`; catalog config;
  revised model precedence; session restore; `/model`, `/effort`, `/help`.
- `config.example.json`, `config.json` (gitignored), `README.md`, `composer.json`,
  `tests/smoke.php`.

### Deviations from the original plan

1. **No startup clamp.** The catalog is not fetched at startup, so `config.effort` is
   sent verbatim (the provider accepted every canonical level in probing). Clamping
   happens on `/model`, `/effort`, and model switches, where the catalog is loaded.
2. **`ModelSelector::resolve()` returns a fourth status, `invalid`,** for a numeric
   input outside the list, so `/model 99` is rejected instead of being applied as a
   literal model id.
3. **`/effort` loads the catalog cache-first on invocation.** This keeps the declared
   levels accurate (low/high/max) without a startup request.
4. **Token formatting:** one decimal for millions (`1M`), integer thousands (`393K`).
5. **`models_auth` defaults** follow the known-provider map (`bearer` for DeepSeek,
   `header` for Anthropic) when the key is absent.
6. **The picker caps at 50 rows** and accepts typed ids beyond the cap.
7. **Session restore happens in `agent.php`** before constructing `Agent`; the
   checkpoint format and `SessionStore` are unchanged.

### Verification performed

```text
composer validate                 -> valid
composer test                     -> UI tests passed / Selector tests passed / Smoke tests passed
php tests/ui.php                  -> UI tests passed
php tests/selector.php            -> Selector tests passed
php agent.php --help              -> lists --effort, --list-models, /model, /effort
php agent.php --list-models       -> deepseek-flash, deepseek-v4-pro; effort low/high/max
php agent.php --effort=turbo      -> exit 1, "Invalid effort level: turbo"
```

Live provider runs (DeepSeek, 2026-10-31):

- `--effort=max` on `deepseek-flash` returned a normal answer.
- Piped session: `/model deepseek-v4-pro`, `/effort max`, then a prompt returned `OK`.
- `/effort medium` on a low/high/max model reported the clamp and used `high`.
- A resumed session restored `deepseek-v4-pro` / `max` from its checkpoint.
- Unknown model ids are applied with a warning; malformed ids are rejected.
- The live catalog cache was written to `.agent/models-cache.json` with metadata only.

### Known limitations

- Effort clamping requires a loaded catalog. If the provider is unreachable and no
  static `models` list is configured, any canonical level is passed through.
- Switching models keeps history and may invalidate the prompt cache for one turn.
- The security reviewer stays on its startup-pinned model; changing it requires a
  restart.
