<?php
declare(strict_types=1);

namespace MyAgent;

use Closure;
use RuntimeException;
use Throwable;

/**
 * Anthropic Messages API client over raw HTTP (ext-curl, no Composer).
 * Subclass and override message() to fake responses in tests.
 */
class AnthropicClient
{
    /** Canonical effort levels, ordered from cheapest to most thorough. */
    public const EFFORT_LEVELS = ['low', 'medium', 'high', 'xhigh', 'max'];

    /** Supported prompt-cache breakpoint TTLs. */
    public const CACHE_TTLS = ['5m', '1h'];

    private int $compactionThreshold = 0;
    private int $summaryMaxTokens = 2048;
    /** null means "omit output_config and let the provider choose". */
    private ?string $effort = null;
    /**
     * Identity sent with every request: the provider partitions its prompt
     * cache on this value (measured: a different id misses entirely), so it
     * is one shared constant per process and never per session.
     */
    private ?string $userId = null;
    /** Also send an OpenAI-style top-level prompt_cache_key (opt-in). */
    private bool $sendCacheKey = false;
    /** Breakpoint TTL: "5m" (default) or "1h". */
    private string $cacheTtl = '5m';
    /** Mark the newest message so the next turn reuses the conversation. */
    private bool $conversationBreakpoint = true;
    /** @var null|Closure(): void */
    private ?Closure $progress = null;

    public function __construct(
        private string $apiKey,
        private string $apiUrl = 'https://api.anthropic.com/v1/messages',
        private string $apiKeyHeader = 'x-api-key',
        private string $apiKeyPrefix = '',
        private string $apiVersion = '2023-06-01',
        private int $requestTimeout = 600,
    ) {
    }

    /**
     * Called repeatedly while a request is in flight and during retry waits.
     * Used by the terminal spinner; exceptions are swallowed so UI code can
     * never break an API call.
     */
    public function setProgressCallback(?Closure $progress): void
    {
        $this->progress = $progress;
    }

    private function tick(): void
    {
        if ($this->progress === null) {
            return;
        }
        try {
            ($this->progress)();
        } catch (Throwable) {
            // Rendering must never affect the request.
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

    /** Enable server-side compaction when the request grows past threshold characters (0 disables). */
    public function setCompactionSettings(int $thresholdChars, int $summaryMaxTokens): void
    {
        $this->compactionThreshold = $thresholdChars;
        $this->summaryMaxTokens = $summaryMaxTokens;
    }

    /**
     * Run a callback with compaction disabled, then restore the previous
     * setting. The security reviewer shares this client with the actor but
     * must never have its policy prompt compacted away.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function withoutCompaction(callable $callback): mixed
    {
        $threshold = $this->compactionThreshold;
        $this->compactionThreshold = 0;
        try {
            return $callback();
        } finally {
            $this->compactionThreshold = $threshold;
        }
    }

    /**
     * Set the reasoning effort sent as output_config.effort. null (or an
     * empty string) omits the field entirely so the provider default applies.
     *
     * @throws RuntimeException for a level outside the canonical set.
     */
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

    public function effort(): ?string
    {
        return $this->effort;
    }

    /**
     * Set the identity sent with every request. One value per process: the
     * prompt cache is partitioned on it, so a per-session value would force a
     * cold prefix in every session.
     *
     * @throws RuntimeException for a value that cannot be sent safely.
     */
    public function setUserId(?string $userId): void
    {
        if ($userId === null) {
            $this->userId = null;
            return;
        }
        self::assertValidUserId($userId);
        $this->userId = $userId;
    }

    public function userId(): ?string
    {
        return $this->userId;
    }

    /** Send an OpenAI-style top-level prompt_cache_key as well (opt-in). */
    public function setSendCacheKey(bool $send): void
    {
        $this->sendCacheKey = $send;
    }

    /**
     * Prompt-cache behaviour: whether the newest message gets a breakpoint,
     * and how long every breakpoint lives.
     *
     * @throws RuntimeException for an unsupported TTL.
     */
    public function setCacheOptions(bool $conversationBreakpoint, string $ttl = '5m'): void
    {
        $this->conversationBreakpoint = $conversationBreakpoint;
        $this->setCacheTtl($ttl);
    }

    /** @throws RuntimeException for an unsupported TTL. */
    public function setCacheTtl(string $ttl): void
    {
        $ttl = strtolower(trim($ttl));
        if (!in_array($ttl, self::CACHE_TTLS, true)) {
            throw new RuntimeException('cache.ttl must be "5m" or "1h"');
        }
        $this->cacheTtl = $ttl;
    }

    public function cacheTtl(): string
    {
        return $this->cacheTtl;
    }

    /** True when the newest message carries a cache breakpoint. */
    public function hasConversationBreakpoint(): bool
    {
        return $this->conversationBreakpoint;
    }

    /** @throws RuntimeException when the id could not be sent safely. */
    public static function assertValidUserId(string $userId): void
    {
        if (preg_match('/\A[A-Za-z0-9._:@+-]{1,256}\z/', $userId) !== 1) {
            throw new RuntimeException('Invalid user id');
        }
    }

    /** @return array<string, string> One prompt-cache breakpoint marker. */
    private function cacheControl(): array
    {
        return $this->cacheTtl === '1h'
            ? ['type' => 'ephemeral', 'ttl' => '1h']
            : ['type' => 'ephemeral'];
    }

    /**
     * Mark the end of the conversation so the next turn can reuse it.
     *
     * Works on a copy: the caller's messages - and therefore the session
     * checkpoint - must never carry cache_control.
     *
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    private function withConversationBreakpoint(array $messages): array
    {
        // The first request of a session has nothing to reuse yet.
        if (!$this->conversationBreakpoint || count($messages) < 2) {
            return $messages;
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

    /**
     * Run a callback with effort omitted, then restore the previous setting.
     * The security reviewer shares this client with the actor but must never
     * inherit the actor's reasoning depth.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function withoutEffort(callable $callback): mixed
    {
        $effort = $this->effort;
        $this->effort = null;
        try {
            return $callback();
        } finally {
            $this->effort = $effort;
        }
    }

    /**
     * Build the request body for one Messages call. Kept separate from
     * message() so the exact outgoing shape can be asserted in tests.
     *
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $tools
     * @return array<string, mixed>
     */
    protected function requestBody(string $model, int $maxTokens, string $system, array $messages, array $tools): array
    {
        $body = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'messages' => $this->withConversationBreakpoint($messages),
        ];
        if ($this->userId !== null) {
            // Stable identity, identical in every session: the provider
            // partitions its prompt cache on this value.
            $body['metadata'] = ['user_id' => $this->userId];
            if ($this->sendCacheKey) {
                $body['prompt_cache_key'] = $this->userId;
            }
        }
        if ($system !== '') {
            // Stable prefix: cache the system prompt and the tool definitions.
            $body['system'] = [['type' => 'text', 'text' => $system, 'cache_control' => $this->cacheControl()]];
        }
        if ($tools !== []) {
            $tools = array_values($tools);
            $tools[count($tools) - 1]['cache_control'] = $this->cacheControl();
            $body['tools'] = $tools;
        }
        if ($this->effort !== null) {
            $body['output_config'] = ['effort' => $this->effort];
        }
        return $body;
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<int, array<string, mixed>> $tools
     * @return array<string, mixed> Decoded assistant message.
     */
    public function message(string $model, int $maxTokens, string $system, array $messages, array $tools): array
    {
        $body = $this->requestBody($model, $maxTokens, $system, $messages, $tools);
        $betas = [];
        if ($this->cacheTtl === '1h') {
            // Extended-TTL breakpoints; harmless where the provider ignores it.
            $betas[] = 'extended-cache-ttl-2025-04-11';
        }
        $approxChars = strlen($system) + strlen((string) json_encode($messages, JSON_INVALID_UTF8_SUBSTITUTE));
        if ($this->compactionThreshold > 0 && $approxChars >= $this->compactionThreshold) {
            $body['context_management'] = ['edits' => [['type' => 'compact_20260112', 'summary_max_tokens' => $this->summaryMaxTokens]]];
            $betas[] = 'compact-2026-01-12';
        }
        $headers = ['Content-Type: application/json', 'anthropic-version: ' . $this->apiVersion];
        if ($this->apiKeyHeader !== '' && $this->apiKey !== '') {
            $headers[] = $this->apiKeyHeader . ': ' . $this->apiKeyPrefix . $this->apiKey;
        }
        if ($betas !== []) {
            $headers[] = 'anthropic-beta: ' . implode(',', $betas);
        }
        $payload = $this->encodeRequestBody($body);

        $retryAfter = null;
        $lastError = '';
        for ($attempt = 0; $attempt <= 2; $attempt++) {
            if ($attempt > 0) {
                $wait = $retryAfter !== null ? min($retryAfter, 30.0) : (float) [2, 5][$attempt - 1];
                $this->waitWithTicks($wait);
            }
            $ch = curl_init($this->apiUrl);
            if ($ch === false) {
                throw new RuntimeException('curl could not initialize');
            }
            $curlOptions = [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->requestTimeout,
                CURLOPT_HEADERFUNCTION => function ($ch, $header) use (&$retryAfter) {
                    if (preg_match('/^retry-after:\s*(\d+)/i', trim($header), $m)) {
                        $retryAfter = (float) $m[1];
                    }
                    return strlen($header);
                },
            ];
            if ($this->progress !== null) {
                $curlOptions[CURLOPT_NOPROGRESS] = false;
                $curlOptions[CURLOPT_XFERINFOFUNCTION] = function ($ch, $downloadTotal, $downloaded, $uploadTotal, $uploaded): int {
                    $this->tick();
                    return 0;
                };
            }
            curl_setopt_array($ch, $curlOptions);
            $responseBody = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $curlError = curl_error($ch);
            unset($ch); // freed automatically; curl_close() is deprecated in newer PHP

            if ($responseBody === false) {
                $lastError = 'Network error: ' . $curlError; // Transient: retry.
                continue;
            }
            $decoded = json_decode((string) $responseBody, true);
            if (!is_array($decoded)) {
                $detail = trim(substr(trim((string) $responseBody), 0, 300));
                throw new RuntimeException(
                    'API returned invalid JSON (HTTP ' . $status . ')' . ($detail === '' ? '' : ': ' . $detail)
                );
            }
            if ($status >= 200 && $status < 300) {
                if (isset($decoded['error']) && is_array($decoded['error'])) {
                    $type = (string) ($decoded['error']['type'] ?? 'api_error');
                    $message = (string) ($decoded['error']['message'] ?? 'Unknown API error');
                    if (in_array($type, ['overloaded_error', 'rate_limit_error'], true)) {
                        $lastError = "API error ({$type}): {$message}";
                        continue;
                    }
                    throw new RuntimeException("API error ({$type}): {$message}");
                }
                return $decoded;
            }
            $message = (string) ($decoded['error']['message'] ?? substr((string) $responseBody, 0, 300));
            $lastError = "API error HTTP {$status}: {$message}";
            if (!in_array($status, [408, 409, 429, 500, 502, 503, 504, 529], true)) {
                throw new RuntimeException($lastError); // Permanent error: do not retry.
            }
        }
        throw new RuntimeException($lastError === '' ? 'API request failed' : $lastError . ' (after retries)');
    }

    /**
     * Encode the request body, substituting U+FFFD for invalid UTF-8 so tool
     * output (binary files, command bytes) cannot make the whole payload
     * unencodable. Encoding an empty body would trigger a provider 400.
     *
     * @param array<string, mixed> $body
     * @throws RuntimeException when the body still cannot be represented as JSON.
     */
    protected function encodeRequestBody(array $body): string
    {
        $payload = json_encode(
            $body,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($payload === false) {
            throw new RuntimeException('Could not encode the API request as JSON: ' . json_last_error_msg());
        }
        return $payload;
    }
}

/**
 * Project-local Agent Skills: skills/<name>/SKILL.md and .agents/skills/<name>/SKILL.md
 * in the working directory only. Frontmatter name + description are loaded at startup;
 * the full body is read on demand via read_skill.
 */
final class Skills
{
    /** @var array<string, array{file: string, description: string}> */
    private array $skills = [];

    public function __construct(string $root)
    {
        foreach ([$root . '/skills', $root . '/.agents/skills'] as $directory) {
            if (!is_dir($directory)) {
                continue;
            }
            foreach (scandir($directory) ?: [] as $folder) {
                if ($folder === '.' || $folder === '..') {
                    continue;
                }
                $file = $directory . '/' . $folder . '/SKILL.md';
                if (!is_file($file)) {
                    continue;
                }
                $frontmatter = self::parseFrontmatter((string) file_get_contents($file));
                $name = (string) ($frontmatter['name'] ?? $folder);
                if ($name !== $folder) {
                    fwrite(STDERR, "Skipping skill {$folder}: frontmatter name '{$name}' must match the folder name\n");
                    continue;
                }
                if (!isset($this->skills[$name])) {
                    $this->skills[$name] = ['file' => $file, 'description' => (string) ($frontmatter['description'] ?? '')];
                }
            }
        }
    }

    /** @return array<string, string> Parsed simple YAML frontmatter (key: value, folded | > - blocks). */
    private static function parseFrontmatter(string $content): array
    {
        if (!preg_match('/\A---\r?\n(.*?)\r?\n---\r?\n/s', $content, $block)) {
            return [];
        }
        $values = [];
        $lines = preg_split('/\r?\n/', $block[1]) ?: [];
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            if (!preg_match('/\A([A-Za-z0-9_-]+):\s*(.*)\z/', $lines[$i], $m)) {
                continue;
            }
            [, $key, $inline] = $m;
            if ($inline === '' || preg_match('/\A[|>][-+]?\z/', $inline)) {
                $parts = [];
                while ($i + 1 < $count && preg_match('/\A(?:\s+\S.*|\s*)\z/', $lines[$i + 1])) {
                    $i++;
                    $parts[] = trim($lines[$i]);
                }
                $values[$key] = trim(implode(' ', array_filter($parts, static fn($p) => $p !== '')));
            } else {
                $values[$key] = trim($inline, " \t\"'");
            }
        }
        return $values;
    }

    /** Catalog line for the system prompt. */
    public function summary(): string
    {
        $lines = [];
        foreach ($this->skills as $name => $skill) {
            $lines[] = '- ' . $name . ($skill['description'] === '' ? '' : ': ' . $skill['description']);
        }
        return implode("\n", $lines);
    }

    public function read(string $name): string
    {
        if (!isset($this->skills[$name])) {
            throw new RuntimeException("Unknown skill: {$name}. Available: " . ($this->summary() === '' ? '(none)' : implode(', ', array_keys($this->skills))));
        }
        $content = file_get_contents($this->skills[$name]['file']);
        if ($content === false) {
            throw new RuntimeException('Could not read skill file');
        }
        return $content;
    }
}

/** Local sandboxed tools: file listing/reading/writing, shell commands, skill loading. */
final class LocalTools
{
    public function __construct(
        private string $root,
        private Skills $skills,
        private int $commandTimeout = 300,
        private ?AskUser $askUser = null,
    ) {
    }

    public function skillsSummary(): string
    {
        return $this->skills->summary();
    }

    public function root(): string
    {
        return $this->root;
    }

    /** True when a human at a terminal can answer ask_user. */
    public function canAskUser(): bool
    {
        return $this->askUser !== null && $this->askUser->available();
    }

    /** @return array<int, array<string, mixed>> Anthropic tool definitions. */
    public function definitions(): array
    {
        $path = ['type' => 'string', 'description' => 'File or directory path relative to the project root'];
        $definitions = [
            ['name' => 'list_files', 'description' => 'List files and directories under a path (recursive).', 'input_schema' => [
                'type' => 'object', 'properties' => ['path' => $path], 'required' => [],
            ]],
            ['name' => 'read_file', 'description' => 'Read a text file from the project.', 'input_schema' => [
                'type' => 'object', 'properties' => ['path' => $path], 'required' => ['path'],
            ]],
            ['name' => 'write_file', 'description' => 'Create or overwrite a text file in the project.', 'input_schema' => [
                'type' => 'object', 'properties' => [
                    'path' => $path,
                    'content' => ['type' => 'string', 'description' => 'Full file content to write'],
                ], 'required' => ['path', 'content'],
            ]],
            ['name' => 'run_command', 'description' => 'Run a shell command in the project directory and return its output.', 'input_schema' => [
                'type' => 'object', 'properties' => ['command' => ['type' => 'string', 'description' => 'Command line to execute']], 'required' => ['command'],
            ]],
            ['name' => 'read_skill', 'description' => 'Load the full SKILL.md content of a project skill listed in the system prompt.', 'input_schema' => [
                'type' => 'object', 'properties' => ['name' => ['type' => 'string', 'description' => 'Skill name from the catalog']], 'required' => ['name'],
            ]],
        ];
        if ($this->canAskUser()) {
            $definitions[] = [
                'name' => 'ask_user',
                'description' => 'Ask the human user a single multiple-choice question when you need a decision only they can make. '
                    . 'Provide 1-4 short, mutually exclusive text options; the user can also type a custom answer or cancel. '
                    . 'Returns the chosen answer as text. Use only when the answer changes what you do; do not ask for permission you already have or for trivial preferences.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'question' => ['type' => 'string', 'description' => 'One clear question, in plain text.'],
                        'options' => [
                            'type' => 'array',
                            'minItems' => 1,
                            'maxItems' => AskUser::MAX_OPTIONS,
                            'items' => ['type' => 'string'],
                            'description' => 'Short, mutually exclusive text choices. Put the recommended option first.',
                        ],
                    ],
                    'required' => ['question', 'options'],
                ],
            ];
        }
        return $definitions;
    }

    /** Execute a tool call and return its textual result. */
    public function call(string $name, array $args): string
    {
        return match ($name) {
            'list_files' => $this->listFiles((string) ($args['path'] ?? '.')),
            'read_file' => $this->readFile((string) ($args['path'] ?? '')),
            'write_file' => $this->writeFile((string) ($args['path'] ?? ''), (string) ($args['content'] ?? '')),
            'run_command' => $this->runCommand((string) ($args['command'] ?? '')),
            'read_skill' => $this->skills->read((string) ($args['name'] ?? '')),
            'ask_user' => $this->askQuestion($args['question'] ?? null, $args['options'] ?? null),
            default => throw new RuntimeException("Unknown local tool: {$name}"),
        };
    }

    private function askQuestion(mixed $question, mixed $options): string
    {
        if ($this->askUser === null || !$this->askUser->available()) {
            throw new RuntimeException('ask_user is unavailable: no interactive terminal is attached. State the question in your final answer instead.');
        }
        if (!is_string($question)) {
            throw new RuntimeException('ask_user requires a non-empty question.');
        }
        if (!is_array($options)) {
            throw new RuntimeException('ask_user requires an options array of 1-4 text items.');
        }
        return 'The user answered: ' . $this->askUser->ask($question, array_values($options));
    }

    /** Resolve a path inside the project, blocking traversal and symlink escapes. */
    private function resolve(string $path, bool $mustExist): string
    {
        $root = realpath($this->root);
        if ($root === false) {
            throw new RuntimeException('Project root is unavailable');
        }
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '') {
            $path = '.';
        }
        if (preg_match('#\A([A-Za-z]:|/)#', $path)) {
            throw new RuntimeException("Path outside project: {$path}");
        }
        $target = $root . DIRECTORY_SEPARATOR . $path;
        $resolved = realpath($target);
        if ($resolved !== false) {
            $ok = $resolved;
        } else {
            if ($mustExist) {
                throw new RuntimeException("Not found: {$path}");
            }
            // Walk up to the nearest existing ancestor and re-resolve the remainder.
            $ancestor = $target;
            $tail = [];
            while (!is_dir($ancestor) && !is_file($ancestor)) {
                $tail[] = basename($ancestor);
                $ancestor = dirname($ancestor);
                if ($ancestor === '') {
                    throw new RuntimeException("Cannot resolve path: {$path}");
                }
            }
            $resolvedAncestor = realpath($ancestor);
            if ($resolvedAncestor === false) {
                throw new RuntimeException("Cannot resolve path: {$path}");
            }
            $ok = $resolvedAncestor . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, array_reverse($tail));
        }
        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (strtolower($ok) !== strtolower(rtrim($root, DIRECTORY_SEPARATOR)) && !str_starts_with(strtolower($ok), strtolower($prefix))) {
            throw new RuntimeException("Path outside project: {$path}");
        }
        return $ok;
    }

    private function listFiles(string $path): string
    {
        $dir = $this->resolve($path, true);
        if (!is_dir($dir)) {
            throw new RuntimeException("Not a directory: {$path}");
        }
        $root = (string) realpath($this->root);
        $lines = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            $relative = str_replace('\\', '/', substr((string) $item->getPathname(), strlen($root) + 1));
            if (preg_match('#(^|/)(\.git|node_modules|vendor|\.agent)(/|$)#', $relative)) {
                continue;
            }
            $lines[] = $relative . ($item->isDir() ? '/' : '');
            if (count($lines) >= 500) {
                $lines[] = '… (listing truncated at 500 entries)';
                break;
            }
        }
        sort($lines);
        return $lines === [] ? '(empty)' : implode("\n", $lines);
    }

    private function readFile(string $path): string
    {
        $file = $this->resolve($path, true);
        if (!is_file($file)) {
            throw new RuntimeException("Not a file: {$path}");
        }
        $content = file_get_contents($file);
        if ($content === false) {
            throw new RuntimeException("Could not read: {$path}");
        }
        return $content;
    }

    private function writeFile(string $path, string $content): string
    {
        $file = $this->resolve($path, false);
        if (is_dir($file)) {
            throw new RuntimeException("Path is a directory: {$path}");
        }
        $dir = dirname($file);
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create directory for: {$path}");
        }
        $written = file_put_contents($file, $content);
        if ($written === false) {
            throw new RuntimeException("Could not write: {$path}");
        }
        return "Wrote {$written} bytes to {$path}";
    }

    private function runCommand(string $command): string
    {
        if (trim($command) === '') {
            throw new RuntimeException('Empty command');
        }
        // Capture the child's stdio through files instead of pipes. A child that
        // waits for console input (for example `... | more`) never drains a pipe:
        // the writer blocks, the reader blocks in fread(), and the timeout below
        // would never get a chance to fire. Files have no such deadlock.
        $stdoutFile = tempnam(sys_get_temp_dir(), 'myagent-cmd-out-');
        $stderrFile = tempnam(sys_get_temp_dir(), 'myagent-cmd-err-');
        if ($stdoutFile === false || $stderrFile === false) {
            throw new RuntimeException('Could not create temporary files for the command output');
        }
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $descriptors = [
            0 => ['file', $null, 'r'],
            1 => ['file', $stdoutFile, 'w'],
            2 => ['file', $stderrFile, 'w'],
        ];
        $process = proc_open($command, $descriptors, $pipes, $this->root);
        if (!is_resource($process)) {
            @unlink($stdoutFile);
            @unlink($stderrFile);
            throw new RuntimeException('Could not start command');
        }

        $deadline = microtime(true) + $this->commandTimeout;
        $status = proc_get_status($process);
        $timedOut = false;
        while ($status['running']) {
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                $this->terminateProcessTree($process, (int) ($status['pid'] ?? 0));
                break;
            }
            usleep(20000);
            $status = proc_get_status($process);
        }
        if ($timedOut) {
            // Give the killed tree a moment to release the output files.
            $grace = microtime(true) + 2.0;
            while ($status['running'] && microtime(true) < $grace) {
                usleep(20000);
                $status = proc_get_status($process);
            }
        }
        $exitCode = (int) $status['exitcode'];
        // clip() keeps at most 20000 bytes, so there is no need to hold more.
        $stdout = (string) @file_get_contents($stdoutFile, false, null, 0, 20001);
        $stderr = (string) @file_get_contents($stderrFile, false, null, 0, 20001);
        proc_close($process);
        @unlink($stdoutFile);
        @unlink($stderrFile);
        $output = $this->clip($stdout);
        if ($timedOut) {
            throw new RuntimeException("Command timed out after {$this->commandTimeout}s\n{$output}\nSTDERR: {$this->clip($stderr)}");
        }
        if (trim($stderr) !== '') {
            $output .= ($output === '' ? '' : "\n") . 'STDERR: ' . $this->clip($stderr);
        }
        if ($output === '') {
            $output = '(no output)';
        }
        return $output . "\n[exit code: {$exitCode}]";
    }

    /** Kill a command and, on Windows, every process it spawned. */
    private function terminateProcessTree($process, int $pid): void
    {
        if (PHP_OS_FAMILY === 'Windows' && $pid > 0 && function_exists('exec')) {
            // proc_terminate() only kills the cmd.exe wrapper, leaving the real
            // command (and any pager) running; /T terminates the whole tree.
            $ignored = [];
            exec('taskkill /F /T /PID ' . $pid . ' 2>NUL', $ignored, $code);
            if ($code === 0) {
                return;
            }
        }
        proc_terminate($process, 9);
    }

    private function clip(string $text): string
    {
        $text = rtrim($text);
        if (strlen($text) > 20000) {
            $text = substr($text, 0, 20000) . "\n… (output truncated at 20000 chars)";
        }
        return $text;
    }
}

/** stdio MCP client: JSON-RPC 2.0 line protocol over a child process. */
final class McpClient
{
    /** @var resource|null */
    private $process = null;
    /** @var resource|null */
    private $stdin = null;
    /** @var resource|null */
    private $stdout = null;
    private int $nextId = 1;
    /** @var array<string, array<string, mixed>> alias => Anthropic tool definition */
    private array $tools = [];
    /** @var array<string, string> alias => remote tool name */
    private array $remote = [];
    private int $timeout = 30;

    /** @param array<string, mixed> $config */
    public function __construct(
        private string $serverName,
        private array $config,
        private string $root,
    ) {
        $this->timeout = max(1, (int) ($config['timeout'] ?? 30));
    }

    public function connect(): void
    {
        $command = (string) ($this->config['command'] ?? '');
        if ($command === '') {
            throw new RuntimeException('MCP server config requires a command');
        }
        $args = (array) ($this->config['args'] ?? []);
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'file', 'nul', 'w']];
        if (PHP_OS_FAMILY !== 'Windows') {
            $descriptors[2] = ['pipe', 'file', '/dev/null', 'w'];
        }
        $env = null;
        if ((array) ($this->config['env'] ?? []) !== []) {
            $env = getenv();
            foreach ((array) $this->config['env'] as $key => $value) {
                $env[(string) $key] = preg_replace_callback('/\$\{([A-Za-z0-9_]+)\}/',
                    static fn($m) => (string) (getenv($m[1]) !== false ? getenv($m[1]) : ''), (string) $value) ?? (string) $value;
            }
        }
        $full = escapeshellarg($command);
        foreach ($args as $arg) {
            $full .= ' ' . escapeshellarg((string) $arg);
        }
        $process = proc_open($full, $descriptors, $pipes, $this->root, $env);
        if (!is_resource($process)) {
            throw new RuntimeException("Could not start MCP server: {$this->serverName}");
        }
        $this->process = $process;
        $this->stdin = $pipes[0];
        $this->stdout = $pipes[1];
        $init = $this->request('initialize', [
            'protocolVersion' => '2025-11-25',
            'clientInfo' => ['name' => 'MyAgent', 'version' => '1.0'],
            'capabilities' => new \stdClass(),
        ]);
        if (!isset($init['result'])) {
            throw new RuntimeException("MCP {$this->serverName} initialize failed");
        }
        $this->notify('notifications/initialized');
        $cursor = null;
        do {
            $params = $cursor === null ? [] : ['cursor' => $cursor];
            $page = $this->request('tools/list', $params);
            $cursor = null;
            foreach ((array) ($page['result']['tools'] ?? []) as $tool) {
                if (!isset($tool['name'])) {
                    continue;
                }
                $alias = $this->alias((string) $tool['name']);
                $this->remote[$alias] = (string) $tool['name'];
                $this->tools[$alias] = [
                    'name' => $alias,
                    'description' => '[MCP ' . $this->serverName . '] ' . (string) ($tool['description'] ?? ''),
                    'input_schema' => self::normalizeInputSchema($tool['inputSchema'] ?? null),
                ];
            }
            $next = $page['result']['nextCursor'] ?? null;
            if (is_string($next) && $next !== '') {
                $cursor = $next;
            }
        } while ($cursor !== null);
    }

    /**
     * Make an MCP tool schema safe to send to the Messages API.
     *
     * Servers commonly advertise tools without arguments as `"properties": {}`.
     * json_decode(..., true) turns that JSON object into PHP [], and the next
     * json_encode emits `"properties": []`, which the API rejects with
     * `[] is not of type "object"`. Rebuild the object maps, and also repair an
     * empty or list-shaped schema.
     *
     * @param mixed $schema Decoded JSON-RPC inputSchema.
     * @return array<string, mixed>
     */
    private static function normalizeInputSchema(mixed $schema): array
    {
        if (!is_array($schema) || $schema === [] || array_is_list($schema)) {
            return ['type' => 'object', 'properties' => new \stdClass()];
        }
        if (!is_string($schema['type'] ?? null) || $schema['type'] === '') {
            $schema['type'] = 'object';
        }
        $schema['properties'] = self::normalizeProperties($schema['properties'] ?? []);
        return $schema;
    }

    /**
     * @param mixed $properties Decoded `properties` map.
     * @return array<string, mixed>|object A PHP value the encoder writes as a JSON object.
     */
    private static function normalizeProperties(mixed $properties): array|object
    {
        if (!is_array($properties) || $properties === [] || array_is_list($properties)) {
            return new \stdClass();
        }
        foreach ($properties as $name => $property) {
            if (is_array($property) && !array_is_list($property) && array_key_exists('properties', $property)) {
                $properties[$name]['properties'] = self::normalizeProperties($property['properties']);
            }
        }
        return $properties;
    }

    private function alias(string $toolName): string
    {
        $raw = 'mcp_' . $this->serverName . '_' . $toolName;
        $alias = preg_replace('/[^A-Za-z0-9_-]/', '_', $raw) ?? $raw;
        if (strlen($alias) > 64) {
            $alias = substr($alias, 0, 54) . '_' . substr(md5($raw), 0, 8);
        }
        return $alias;
    }

    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        return $this->tools;
    }

    public function call(string $alias, array $args): string
    {
        if (!isset($this->remote[$alias])) {
            throw new RuntimeException("Unknown MCP tool: {$alias}");
        }
        $response = $this->request('tools/call', ['name' => $this->remote[$alias], 'arguments' => $args]);
        if (isset($response['error']) && is_array($response['error'])) {
            throw new RuntimeException('MCP error: ' . (string) ($response['error']['message'] ?? json_encode($response['error'])));
        }
        $parts = [];
        foreach ((array) ($response['result']['content'] ?? []) as $block) {
            $type = (string) ($block['type'] ?? '');
            if ($type === 'text') {
                $parts[] = (string) ($block['text'] ?? '');
            } else {
                $parts[] = '[' . $type . ' content]';
            }
        }
        $output = implode("\n", $parts);
        if (($response['result']['isError'] ?? false) === true) {
            throw new RuntimeException('MCP tool error: ' . ($output === '' ? '(no detail)' : $output));
        }
        return $output === '' ? '(empty result)' : $output;
    }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    private function request(string $method, array $params): array
    {
        $id = $this->nextId++;
        $this->write(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]);
        $deadline = microtime(true) + $this->timeout;
        while (microtime(true) < $deadline) {
            $line = $this->readLine($deadline);
            if ($line === null) {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded) && ($decoded['id'] ?? null) === $id) {
                return $decoded;
            }
            // Ignore unrelated notifications/ids while waiting.
        }
        throw new RuntimeException("MCP {$this->serverName} timed out waiting for {$method}");
    }

    private function notify(string $method): void
    {
        $this->write(['jsonrpc' => '2.0', 'method' => $method]);
    }

    /** @param array<string, mixed> $message */
    private function write(array $message): void
    {
        if (!is_resource($this->stdin)) {
            throw new RuntimeException("MCP {$this->serverName} is not connected");
        }
        $json = (string) json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (fwrite($this->stdin, $json . "\n") === false) {
            throw new RuntimeException("MCP {$this->serverName} write failed");
        }
        fflush($this->stdin);
    }

    private function readLine(float $deadline): ?string
    {
        if (!is_resource($this->stdout)) {
            throw new RuntimeException("MCP {$this->serverName} is not connected");
        }
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) {
            return null;
        }
        $read = [$this->stdout];
        $write = $except = null;
        if (@stream_select($read, $write, $except, (int) $remaining, (int) (($remaining - (int) $remaining) * 1000000)) !== 1) {
            return null;
        }
        $line = fgets($this->stdout);
        return $line === false ? null : $line;
    }

    public function close(): void
    {
        if (is_resource($this->stdin)) {
            fclose($this->stdin);
        }
        if (is_resource($this->stdout)) {
            fclose($this->stdout);
        }
        $this->stdin = $this->stdout = null;
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        $this->process = null;
    }

    public function __destruct()
    {
        $this->close();
    }
}

/** The agent loop: request -> run tools -> feed results back, with session checkpoints. */
final class Agent
{
    /** @var array<int, array<string, mixed>> */
    private array $messages = [];
    /** @var array<string, McpClient> alias => client */
    private array $mcpByAlias = [];
    /** Mirrors AnthropicClient::effort() for checkpoints and the banner. */
    private ?string $effort = null;
    /**
     * Identity sent with every request: the provider partitions its prompt
     * cache on this value (measured: a different id misses entirely), so it
     * is one shared constant per process and never per session.
     */
    private ?string $userId = null;
    /** Also send an OpenAI-style top-level prompt_cache_key (opt-in). */
    private bool $sendCacheKey = false;
    /** Breakpoint TTL: "5m" (default) or "1h". */
    private string $cacheTtl = '5m';
    /** Mark the newest message so the next turn reuses the conversation. */
    private bool $conversationBreakpoint = true;
    /** Maximum bytes kept from one tool result; larger output is spilled to a temp file. 0 disables the cap. */
    private int $toolResultLimit = 100000;
    /** @var array{requests:int,input:int,output:int,cache_read:int,cache_creation:int} */
    private array $usageTotals = ['requests' => 0, 'input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_creation' => 0];

    /** @param McpClient[] $mcp */
    public function __construct(
        private AnthropicClient $api,
        private LocalTools $local,
        private array $mcp,
        private string $model,
        private int $maxTokens,
        private int $maxSteps,
        private ?SessionStore $store = null,
        private int $compactionThreshold = 24000,
        private int $summaryMaxTokens = 2048,
        private ?SecurityClassifier $security = null,
        private ?Terminal $terminal = null,
    ) {
        if ($this->terminal === null) {
            $this->terminal = Terminal::plain();
        }
        $api->setCompactionSettings($compactionThreshold, $summaryMaxTokens);
        $this->effort = $api->effort();
        foreach ($mcp as $client) {
            foreach (array_keys($client->definitions()) as $alias) {
                $this->mcpByAlias[$alias] = $client;
            }
        }
        if ($store !== null) {
            $state = $store->load();
            if ($state !== null) {
                $this->messages = $state['messages'];
            }
        }
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * Switch the acting model. Takes effect on the next model call; the
     * conversation history is deliberately left intact.
     *
     * @throws RuntimeException for an empty or malformed model name.
     */
    public function setModel(string $model): void
    {
        $model = trim($model);
        if ($model === '' || strlen($model) > 200
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/@+\[\]-]*\z/', $model) !== 1) {
            throw new RuntimeException('Invalid model name');
        }
        $this->model = $model;
    }

    public function effort(): ?string
    {
        return $this->effort;
    }

    /**
     * Cumulative actor usage for this process. Security-reviewer calls are
     * deliberately excluded: they never reach run(), and their prompt is a
     * constant, so they self-cache; mixing them in would hide the actor number.
     *
     * @return array{requests:int,input:int,output:int,cache_read:int,cache_creation:int}
     */
    public function usageTotals(): array
    {
        return $this->usageTotals;
    }

    /** Share of billable input tokens served from the prompt cache (0.0 before the first call). */
    public function cacheHitRatio(): float
    {
        $denominator = $this->usageTotals['input'] + $this->usageTotals['cache_read'] + $this->usageTotals['cache_creation'];
        return $denominator > 0 ? $this->usageTotals['cache_read'] / $denominator : 0.0;
    }

    /** @param array<string, mixed> $usage */
    private function recordUsage(array $usage): void
    {
        $this->usageTotals['requests']++;
        foreach ([
            'input_tokens' => 'input',
            'output_tokens' => 'output',
            'cache_read_input_tokens' => 'cache_read',
            'cache_creation_input_tokens' => 'cache_creation',
        ] as $key => $slot) {
            $this->usageTotals[$slot] += (int) ($usage[$key] ?? 0);
        }
    }

    /** Set the acting effort; null omits output_config for the next request. */
    public function setEffort(?string $effort): void
    {
        $this->api->setEffort($effort);
        $this->effort = $this->api->effort();
    }

    /** Bytes kept from one tool result; 0 means unlimited. */
    public function toolResultLimit(): int
    {
        return $this->toolResultLimit;
    }

    /** Cap the text a single tool call may add to the conversation (0 = unlimited). */
    public function setToolResultLimit(int $chars): void
    {
        if ($chars < 0) {
            throw new RuntimeException('Tool result limit cannot be negative');
        }
        $this->toolResultLimit = $chars;
    }

    public function run(string $prompt): bool
    {
        $this->messages[] = ['role' => 'user', 'content' => $prompt];
        return $this->loop($this->maxSteps);
    }

    public function resume(): bool
    {
        if ($this->store === null) {
            throw new RuntimeException('resume() requires a session store');
        }
        $state = $this->store->load();
        if ($state === null || ($state['in_progress'] ?? false) !== true) {
            $this->terminal->info('Nothing to resume: session has no unfinished work.');
            return true;
        }
        $pending = array_values(array_filter((array) ($state['pending_results'] ?? []),
            static fn($p) => is_array($p) && isset($p['id'])));
        $pendingIds = array_map(static fn(array $p) => (string) $p['id'], $pending);
        $answered = [];
        foreach ($this->messages as $message) {
            foreach ((array) $message['content'] as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'tool_result') {
                    $answered[(string) ($block['tool_use_id'] ?? '')] = true;
                }
            }
        }
        $unanswered = [];
        foreach ($this->messages as $message) {
            if (($message['role'] ?? '') !== 'assistant') {
                continue;
            }
            foreach ((array) $message['content'] as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'tool_use' && !isset($answered[(string) ($block['id'] ?? '')])) {
                    $unanswered[] = $block;
                }
            }
        }
        $results = [];
        foreach ($unanswered as $block) {
            $id = (string) $block['id'];
            if (in_array($id, $pendingIds, true)) {
                // Checkpointed as requested-but-not-executed: run it now.
                $name = (string) ($block['name'] ?? '');
                $input = (array) ($block['input'] ?? []);
                $this->terminal->toolStart($name, $input);
                $toolStarted = microtime(true);
                $result = $this->executeBlock($block);
                $this->terminal->toolResult(
                    $name,
                    $input,
                    (string) ($result['content'] ?? ''),
                    (bool) ($result['is_error'] ?? false),
                    microtime(true) - $toolStarted
                );
                $results[] = $result;
            } else {
                // Interrupted with unknown effects: never auto-repeat; let the model inspect.
                $results[] = [
                    'type' => 'tool_result',
                    'tool_use_id' => $id,
                    'content' => 'This tool call was interrupted mid-flight and its outcome is uncertain. It was NOT re-run. Inspect the current state (files, processes) before deciding whether to call it again.',
                    'is_error' => true,
                ];
            }
        }
        if ($results !== []) {
            $this->messages[] = ['role' => 'user', 'content' => $results];
            $this->checkpoint(true, []);
        }
        return $this->loop($this->maxSteps);
    }

    private function loop(int $steps): bool
    {
        $system = $this->systemPrompt();
        $tools = $this->toolDefinitions();
        while ($steps > 0) {
            $steps--;
            $started = microtime(true);
            $spinner = $this->terminal->spinner('Thinking');
            $spinner?->start();
            $this->api->setProgressCallback($spinner === null ? null : static fn() => $spinner->tick());
            try {
                $response = $this->api->message($this->model, $this->maxTokens, $system, $this->messages, $tools);
            } finally {
                $this->api->setProgressCallback(null);
                $spinner?->stop();
            }
            $modelDuration = microtime(true) - $started;

            $content = (array) ($response['content'] ?? []);
            if ($content === []) {
                throw new RuntimeException('API returned an empty response');
            }
            // Server-side compaction: the compaction block replaces all earlier history.
            $firstCompaction = null;
            foreach ($content as $index => $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'compaction') {
                    $firstCompaction = $index;
                    break;
                }
            }
            if ($firstCompaction !== null) {
                $this->messages = [['role' => 'assistant', 'content' => array_slice($content, $firstCompaction)]];
                $system = $this->systemPrompt();
                $this->checkpoint(true, []);
                $this->terminal->info('Context compacted by the provider.');
            } else {
                $this->messages[] = ['role' => 'assistant', 'content' => $content];
            }

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

            $stopReason = (string) ($response['stop_reason'] ?? 'end_turn');
            $usage = (array) ($response['usage'] ?? []);
            $this->recordUsage($usage);
            $this->terminal->usage($usage, $modelDuration, $stopReason);
            if ($stopReason === 'pause_turn') {
                $this->checkpoint(true, []);
                continue;
            }
            $calls = [];
            foreach ($content as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'tool_use') {
                    $calls[] = $block;
                }
            }
            if ($stopReason !== 'tool_use' || $calls === []) {
                $this->checkpoint(false, []);
                return true;
            }
            // Checkpoint tool requests before executing them; results after executing each.
            $this->checkpoint(true, $calls);
            $results = [];
            $total = count($calls);
            foreach ($calls as $index => $call) {
                $name = (string) ($call['name'] ?? '');
                $input = (array) ($call['input'] ?? []);
                $this->terminal->toolStart($name, $input);
                $toolStarted = microtime(true);
                $result = $this->executeBlock($call);
                $this->terminal->toolResult(
                    $name,
                    $input,
                    (string) ($result['content'] ?? ''),
                    (bool) ($result['is_error'] ?? false),
                    microtime(true) - $toolStarted
                );
                $results[] = $result;
                $this->messages[] = ['role' => 'user', 'content' => $results];
                $this->checkpoint(true, array_slice($calls, $index + 1));
                if ($index + 1 < $total) {
                    array_pop($this->messages); // next iteration re-adds it with one more result
                }
            }
            $this->checkpoint(true, []);
        }
        $this->terminal->warning("Step limit ({$this->maxSteps}) reached with work in progress. Resume with --session --continue.");
        return false;
    }

    /** @return array<string, mixed> A tool_result block for one tool_use block. */
    private function executeBlock(array $call): array
    {
        $name = (string) ($call['name'] ?? '');
        $id = (string) ($call['id'] ?? '');
        $input = (array) ($call['input'] ?? []);
        $block = ['type' => 'tool_result', 'tool_use_id' => $id];
        if ($this->security !== null) {
            // Dedicated-model gate: nothing runs until the reviewer allows it.
            try {
                $decision = $this->security->review($call, $this->messages, $this->toolCatalog());
            } catch (Throwable $e) {
                $decision = [
                    'verdict' => 'deny',
                    'risk' => 'high',
                    'category' => 'other',
                    'reason' => 'Security reviewer failed: ' . $e->getMessage(),
                ];
            }
            if (($decision['verdict'] ?? 'deny') !== 'allow') {
                $block['content'] = $this->security->blockMessage($decision);
                $block['is_error'] = true;
                $this->terminal->securityDenied($decision);
                return $block;
            }
            if (($decision['source'] ?? '') === 'user-approved') {
                $this->terminal->securityResolved(true);
            }
        }
        try {
            if (isset($this->mcpByAlias[$name])) {
                $output = $this->mcpByAlias[$name]->call($name, $input);
            } else {
                $output = $this->local->call($name, $input);
            }
            $block['content'] = $output;
        } catch (RuntimeException $e) {
            $block['content'] = 'Error: ' . $e->getMessage();
            $block['is_error'] = true;
        } catch (Throwable $e) {
            $block['content'] = 'Error: ' . get_class($e) . ': ' . $e->getMessage();
            $block['is_error'] = true;
        }
        $block['content'] = $this->limitToolResult($name, (string) ($block['content'] ?? ''));
        return $block;
    }

    /**
     * Keep one tool result within the configured size. Oversized output is
     * written in full to a text file in the system temp directory; the
     * conversation keeps a prefix and a pointer, so a single huge result
     * cannot flood the context or the request body.
     */
    private function limitToolResult(string $tool, string $output): string
    {
        $limit = $this->toolResultLimit;
        $length = strlen($output);
        if ($limit === 0 || $length <= $limit) {
            return $output;
        }
        $prefix = function_exists('mb_strcut')
            ? mb_strcut($output, 0, $limit, 'UTF-8')
            : substr($output, 0, $limit);
        $shown = strlen($prefix);
        $path = self::writeToolOutputFile($tool, $output);
        if ($path === null) {
            return $prefix . "\n\n[myagent] Tool output truncated: showing the first {$shown} of {$length} bytes. "
                . 'The full output could not be saved to a temporary file.';
        }
        return $prefix . "\n\n[myagent] Tool output truncated: showing the first {$shown} of {$length} bytes. "
            . "Full output saved to: {$path}\n"
            . 'Read the rest with run_command (for example Get-Content -TotalCount N or Select-Object -Skip N -First M on Windows; '
            . 'head, tail, or sed -n elsewhere). read_file cannot open paths outside the project.';
    }

    /** Write the full output to a new file in the system temp directory; null on failure. */
    private static function writeToolOutputFile(string $tool, string $output): ?string
    {
        $directory = sys_get_temp_dir();
        if ($directory === '' || !is_dir($directory)) {
            return null;
        }
        $safe = substr((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', $tool), 0, 40);
        if ($safe === '' || $safe === '-') {
            $safe = 'tool';
        }
        try {
            $suffix = bin2hex(random_bytes(4));
        } catch (Throwable) {
            $suffix = str_replace('.', '', uniqid('', true));
        }
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $path = $directory . DIRECTORY_SEPARATOR
                . 'myagent-' . $safe . '-' . date('Ymd-His') . '-' . $suffix . '.txt';
            // Exclusive create: never overwrite a file another process owns.
            $handle = @fopen($path, 'xb');
            if ($handle === false) {
                // Name collision (or an unwritable temp dir); try a fresh suffix.
                $suffix = str_replace('.', '', uniqid('', true));
                continue;
            }
            $written = fwrite($handle, $output);
            fclose($handle);
            if ($written !== strlen($output)) {
                @unlink($path);
                return null;
            }
            @chmod($path, 0600);
            return $path;
        }
        return null;
    }

    /** @return array<string, array<string, mixed>> name => definition, for the security reviewer. */
    private function toolCatalog(): array
    {
        $catalog = [];
        foreach ($this->local->definitions() as $definition) {
            $catalog[(string) $definition['name']] = $definition;
        }
        foreach ($this->mcp as $client) {
            foreach ($client->definitions() as $definition) {
                $catalog[(string) $definition['name']] = $definition;
            }
        }
        return $catalog;
    }

    /** @return array<int, array<string, mixed>> Local + MCP tool definitions in stable order. */
    private function toolDefinitions(): array
    {
        $tools = $this->local->definitions();
        foreach ($this->mcp as $client) {
            foreach ($client->definitions() as $definition) {
                $tools[] = $definition;
            }
        }
        return $tools;
    }

    private function systemPrompt(): string
    {
        $toolList = 'list_files, read_file, write_file, run_command, read_skill';
        if ($this->local->canAskUser()) {
            $toolList .= ', ask_user';
        }
        $prompt = "You are MyAgent, a coding agent running as a PHP CLI in the user's working directory: {$this->local->root()}\n"
            . "You complete tasks by calling the provided tools: {$toolList}, and MCP server tools. "
            . "Stay inside the working directory. Make one tool call at a time only when you need its result; prefer batching independent reads.\n";
        if ($this->security !== null) {
            $prompt .= "Every tool call is checked by a separate security reviewer before it runs. Destructive, irreversible, out-of-project, secret-exposing, or outward-facing actions can be blocked. If a call is blocked, do not retry it verbatim: choose a safer approach or say what you need from the user.\n";
        }
        if ($this->local->canAskUser()) {
            $prompt .= "Asking the user (ask_user):\n"
                . "- Use it only when the user's intent is genuinely ambiguous and the answer changes what you build. Do not use it for permission you already have, for trivial preferences, or to confirm every step.\n"
                . "- Ask exactly one question per call with 1-4 short, mutually exclusive options. Put the option you recommend first and end its text with \" (Recommended)\".\n"
                . "- The user may select an option, type a custom answer, or cancel. The tool returns the chosen text.\n"
                . "- Never repeat a question that was answered or cancelled. If the tool reports that no interactive user is available, state the question in your final answer instead.\n";
        }
        $prompt .= "Finish with a plain text answer when the task is done. If you cannot complete the task, say what blocked you instead of pretending success.";
        $skills = $this->local->skillsSummary();
        if ($skills !== '') {
            $prompt .= "\n\nAvailable project skills (call read_skill with the name to load a skill's full instructions before acting on it):\n" . $skills;
        }
        return $prompt;
    }

    /** Persist the conversation and the list of not-yet-executed tool calls. */
    private function checkpoint(bool $inProgress, array $pending): void
    {
        if ($this->store === null) {
            return;
        }
        $this->store->save([
            'version' => 1,
            'model' => $this->model,
            'effort' => $this->effort,
            'in_progress' => $inProgress,
            'pending_results' => $pending,
            'messages' => $this->messages,
        ]);
    }
}
