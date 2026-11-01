#!/usr/bin/env php
<?php
declare(strict_types=1);

$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Dependencies are not installed. Run: composer install\n");
    exit(1);
}
require $autoload;

use MyAgent\Agent;
use MyAgent\AnthropicClient;
use MyAgent\AskUser;
use MyAgent\Bridge;
use MyAgent\LocalTools;
use MyAgent\McpClient;
use MyAgent\MediaError;
use MyAgent\MediaLimits;
use MyAgent\MediaNormalizer;
use MyAgent\MediaStore;
use MyAgent\Mime;
use MyAgent\ModelCatalog;
use MyAgent\ModelSelector;
use MyAgent\SecurityClassifier;
use MyAgent\Skills;
use MyAgent\SessionStore;
use MyAgent\Terminal;

$root = getcwd();
$options = getopt('', ['config:', 'model:', 'effort:', 'user-id:', 'max-steps:', 'command-timeout:', 'tool-result-limit:', 'session:', 'continue', 'help', 'plain', 'no-color', 'width:', 'no-banner', 'tool-details:', 'list-models', 'refresh-models', 'bridge', 'bridge-max-event-bytes:', 'image:', 'no-media', 'media-max-edge:']);
$plain = isset($options['plain']);
// Machine mode: stdout becomes JSONL events, stdin a JSONL command channel.
$bridge = isset($options['bridge'])
    ? new Bridge((int) ($options['bridge-max-event-bytes'] ?? 32768))
    : null;

if (isset($options['help'])) {
    $help = Terminal::detect([], $plain);
    $help->heading('MyAgent — PHP CLI coding agent');
    $help->out("Usage: php agent.php [options] [prompt...]\n\n");
    $help->out("Options:\n");
    $help->out("  --config=FILE           Config file (default: config.json next to agent.php)\n");
    $help->out("  --model=MODEL           Override the model (default: config.model)\n");
    $help->out("  --effort=LEVEL          low|medium|high|xhigh|max; omit for provider default\n");
    $help->out("  --user-id=ID            Shared identity sent with every request (default: identity.user_id or \"myagent\")\n");
    $help->out("  --list-models           Print the provider's model catalog and exit\n");
    $help->out("  --refresh-models        Bypass the cached catalog with --list-models\n");
    $help->out("  --max-steps=N           Model calls per run (default: 100)\n");
    $help->out("  --command-timeout=N     run_command timeout in seconds (default: 300)\n");
    $help->out("  --tool-result-limit=N   Max bytes per tool result; larger output goes to a temp file (default: 100000, 0 = unlimited)\n");
    $help->out("  --image=FILE            Attach an image to the first task (repeatable)\n");
    $help->out("  --no-media              Disable image input for this process\n");
    $help->out("  --media-max-edge=N      Downscale images to this long edge (0 = never)\n");
    $help->out("  --session=NAME          Save/restore progress under .agent/sessions/\n");
    $help->out("  --continue              Resume an unfinished named session\n");
    $help->out("  --plain                 Raw output for scripts and logs\n");
    $help->out("  --no-color              Disable ANSI colors\n");
    $help->out("  --width=N               Force terminal width (40-200)\n");
    $help->out("  --no-banner             Hide the startup banner\n");
    $help->out("  --tool-details=MODE     summary|full|hidden (default: summary)\n");
    $help->out("  --bridge                Machine mode: JSONL events on stdout, JSONL commands on stdin\n");
    $help->out("  --bridge-max-event-bytes=N  Cap a single bridge event (default 32768)\n");
    $help->out("  --help                  Show this help\n");
    $help->out("\nInteractive commands:\n");
    $help->out("  /model [id|number]      Show or switch the model\n");
    $help->out("  /effort [level]         Show or switch the reasoning effort\n");
    $help->out("  /cache                  Show identity, cache breakpoints and hit rate\n");
    $help->out("  /attach <path...>       Attach images to the next prompt\n");
    $help->out("  /attachments            List the queued images\n");
    $help->out("  /detach [n|all]         Drop a queued image, or all of them\n");
    $help->out("  /paste                  Attach the clipboard image (Windows)\n");
    $help->out("  /media                  Show image capability, limits and usage\n");
    $help->out("  /continue               Give unfinished work another step budget\n");
    $help->out("  /help                   Show this command list\n");
    $help->out("  Ctrl+C                  Exit the session\n");
    $help->out("\nSet \"api_key\" in the config file, or set the api_key_env environment variable\n");
    $help->out("(default ANTHROPIC_API_KEY). A dedicated security reviewer checks every tool call.\n");
    $help->out("In an interactive terminal the agent can ask single-choice questions via ask_user.\n");
    exit(0);
}

$ui = null;

try {
    // The default config lives next to agent.php, not in the working
    // directory: the agent can be launched from any project while still
    // reading its own provider settings. An explicit --config is used as
    // given (relative paths resolve against the working directory).
    $configPath = $options['config'] ?? __DIR__ . '/config.json';
    $config = [];
    if (is_file($configPath)) {
        $config = json_decode((string) file_get_contents($configPath), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($config)) {
            throw new RuntimeException('Config must be a JSON object');
        }
    } elseif (isset($options['config'])) {
        throw new RuntimeException("Config not found: $configPath");
    }
    // A literal "api_key" in the config takes precedence over the environment
    // variable, so the key can be set in one place without shell setup.
    $apiKey = trim((string) ($config['api_key'] ?? ''));
    if ($apiKey === '') {
        $keyEnv = (string) ($config['api_key_env'] ?? 'ANTHROPIC_API_KEY');
        $apiKey = $keyEnv === '' ? '' : (string) getenv($keyEnv);
        if ($keyEnv !== '' && $apiKey === '') {
            throw new RuntimeException("Set \"api_key\" in the config or the $keyEnv environment variable");
        }
    }
    // config.model is the documented default. Environment variables are
    // fallbacks only: Claude Code exports ANTHROPIC_MODEL, and inheriting it
    // would silently override the configured provider model.
    $configModel = trim((string) ($config['model'] ?? ''));
    $envModel = trim((string) getenv('LLM_MODEL'));
    $legacyEnvModel = trim((string) getenv('ANTHROPIC_MODEL'));
    $model = isset($options['model']) ? (string) $options['model'] : (
        $configModel !== '' ? $configModel : (
            $envModel !== '' ? $envModel : ($legacyEnvModel !== '' ? $legacyEnvModel : 'claude-sonnet-5-5')
        )
    );
    $configEffort = $config['effort'] ?? null;
    if ($configEffort !== null && !is_string($configEffort)) {
        throw new RuntimeException('effort must be a string or null');
    }
    $effort = isset($options['effort']) ? (string) $options['effort'] : $configEffort;
    $effort = $effort === null ? null : strtolower(trim($effort));
    if ($effort === '') {
        $effort = null;
    }
    if ($effort !== null && !in_array($effort, AnthropicClient::EFFORT_LEVELS, true)) {
        throw new RuntimeException('Invalid effort level: ' . $effort);
    }
    $apiUrl = (string) (getenv('LLM_API_URL') ?: ($config['api_url'] ?? 'https://api.anthropic.com/v1/messages'));
    $apiKeyHeader = (string) ($config['api_key_header'] ?? 'x-api-key');
    $apiKeyPrefix = (string) ($config['api_key_prefix'] ?? '');
    $apiVersion = (string) ($config['api_version'] ?? '2023-06-01');
    if (!filter_var($apiUrl, FILTER_VALIDATE_URL) || !in_array(parse_url($apiUrl, PHP_URL_SCHEME), ['http', 'https'], true)
        || ($apiKeyHeader !== '' && !preg_match('/^[A-Za-z0-9-]+$/', $apiKeyHeader))
        || str_contains($apiKey, "\r") || str_contains($apiKey, "\n")
        || str_contains($apiKeyPrefix, "\r") || str_contains($apiKeyPrefix, "\n")
        || str_contains($apiVersion, "\r") || str_contains($apiVersion, "\n")) {
        throw new RuntimeException('Invalid API URL or header configuration');
    }
    // Shared identity: ONE value for every session. The provider partitions its
    // prompt cache on it (measured: a different id misses entirely), so it must
    // never be derived from a pid, a session name, or the clock.
    $identityConfig = $config['identity'] ?? [];
    if (!is_array($identityConfig)) {
        throw new RuntimeException('identity must be an object');
    }
    $sendMetadata = (bool) ($identityConfig['send_metadata'] ?? true);
    $sendCacheKey = (bool) ($identityConfig['prompt_cache_key'] ?? false);
    $rawUserId = isset($options['user-id'])
        ? (string) $options['user-id']
        : (string) (getenv('MYAGENT_USER_ID') ?: ($identityConfig['user_id'] ?? 'myagent'));
    $userId = trim($rawUserId);
    if ($userId === '') {
        $userId = 'myagent';
    }
    AnthropicClient::assertValidUserId($userId);
    $cacheConfig = $config['cache'] ?? [];
    if (!is_array($cacheConfig)) {
        throw new RuntimeException('cache must be an object');
    }
    $conversationBreakpoint = (bool) ($cacheConfig['conversation_breakpoint'] ?? true);
    $cacheTtl = strtolower(trim((string) ($cacheConfig['ttl'] ?? '5m')));
    if (!in_array($cacheTtl, AnthropicClient::CACHE_TTLS, true)) {
        throw new RuntimeException('cache.ttl must be "5m" or "1h"');
    }
    // Provider catalog: an explicit models_url always wins. Otherwise a small
    // known-provider map covers the two endpoints this agent targets; unknown
    // hosts must configure models_url or rely on the static "models" list.
    $catalogHost = strtolower((string) parse_url($apiUrl, PHP_URL_HOST));
    $knownModelsUrl = match ($catalogHost) {
        'api.deepseek.com' => 'https://api.deepseek.com/models',
        'api.anthropic.com' => 'https://api.anthropic.com/v1/models',
        default => '',
    };
    $knownModelsAuth = $catalogHost === 'api.anthropic.com' ? 'header' : 'bearer';
    $configuredModelsUrl = trim((string) ($config['models_url'] ?? ''));
    $modelsUrl = $configuredModelsUrl !== '' ? $configuredModelsUrl : $knownModelsUrl;
    $configuredModelsAuth = trim((string) ($config['models_auth'] ?? ''));
    $modelsAuth = $configuredModelsAuth !== '' ? $configuredModelsAuth : $knownModelsAuth;
    $modelsCacheTtl = (int) ($config['models_cache_ttl'] ?? 86400);
    $staticModels = $config['models'] ?? [];
    if (!is_array($staticModels)) {
        throw new RuntimeException('models must be an array');
    }
    $catalog = new ModelCatalog(
        $root,
        $modelsUrl,
        $modelsAuth,
        (string) $apiKey,
        $apiKeyHeader,
        $apiKeyPrefix,
        $apiVersion,
        $modelsCacheTtl,
        $staticModels
    );
    $maxSteps = (int) ($options['max-steps'] ?? $config['max_steps'] ?? 100);
    $maxTokens = (int) ($config['max_tokens'] ?? 4096);
    $toolResultLimit = (int) ($options['tool-result-limit'] ?? $config['max_tool_result_chars'] ?? 100000);
    $commandTimeout = (int) ($options['command-timeout'] ?? $config['command_timeout'] ?? 300);
    $compactionThreshold = (int) ($config['compaction_threshold_chars'] ?? $config['compaction_threshold'] ?? 24000);
    $summaryMaxTokens = (int) ($config['summary_max_tokens'] ?? 2048);
    if ($maxSteps < 1 || $maxTokens < 1 || $commandTimeout < 1 || $compactionThreshold < 0 || $summaryMaxTokens < 1
        || $toolResultLimit < 0) {
        throw new RuntimeException('Invalid step, token, command timeout, tool result limit, or compaction threshold setting');
    }

    $uiConfig = $config['ui'] ?? [];
    if (!is_array($uiConfig)) {
        throw new RuntimeException('ui must be an object');
    }
    $uiOptions = $uiConfig;
    if (isset($options['no-color'])) {
        $uiOptions['color'] = 'never';
    }
    if (isset($options['width'])) {
        $uiOptions['width'] = (int) $options['width'];
    }
    if (isset($options['no-banner'])) {
        $uiOptions['banner'] = false;
    }
    if (isset($options['tool-details'])) {
        $uiOptions['tool_details'] = (string) $options['tool-details'];
    }
    $ui = Terminal::detect($uiOptions, $plain);
    $defaultModelLabel = $model;

    if (isset($options['list-models'])) {
        $models = $catalog->models(isset($options['refresh-models']));
        if ($catalog->lastError() !== null) {
            $ui->warning('Model catalog: ' . $catalog->lastError()
                . ($models === [] ? '' : ' (using the ' . $catalog->source() . ' list)'));
        }
        if ($models === []) {
            $ui->error('No models available. Set models_url or a static "models" list.');
            exit(1);
        }
        $formatTokens = static function (?int $tokens): string {
            if ($tokens === null) {
                return '';
            }
            $trim = static function (float $value): string {
                $text = number_format($value, 1, '.', '');
                return rtrim(rtrim($text, '0'), '.');
            };
            if ($tokens >= 1_000_000) {
                return $trim($tokens / 1_000_000) . 'M';
            }
            if ($tokens >= 1000) {
                return (string) (int) round($tokens / 1000) . 'K';
            }
            return (string) $tokens;
        };
        $ui->heading('Provider models (' . $catalog->source() . ')');
        foreach ($models as $entry) {
            $id = (string) ($entry['id'] ?? '');
            $bits = [];
            $context = $entry['context_window'] ?? null;
            if (is_int($context)) {
                $bits[] = $formatTokens($context) . ' ctx';
            }
            $output = $entry['max_output_tokens'] ?? null;
            if (is_int($output)) {
                $bits[] = $formatTokens($output) . ' out';
            }
            $levels = $entry['effort_levels'] ?? [];
            if (is_array($levels) && $levels !== []) {
                $bits[] = 'effort ' . implode('/', $levels);
            }
            $ui->out(($id === $model ? '* ' : '  ') . $id
                . '  ' . (string) ($entry['name'] ?? $id)
                . ($bits === [] ? '' : '  · ' . implode(' · ', $bits)) . "\n");
        }
        exit(0);
    }

    // ------------------------------------------------------------------ media
    // Images arrive from --image, /attach, /paste, @mentions, the bridge, and the
    // read_image tool. Every route shares one normalisation + store pipeline.
    $flagValues = static function (array $argv, string $name): array {
        $values = [];
        $prefix = '--' . $name . '=';
        foreach ($argv as $arg) {
            if (is_string($arg) && str_starts_with($arg, $prefix)) {
                $values[] = substr($arg, strlen($prefix));
            }
        }
        return $values;
    };
    $mediaConfig = $config['media'] ?? [];
    if (!is_array($mediaConfig)) {
        throw new RuntimeException('media must be an object');
    }
    $mediaLimits = MediaLimits::fromConfig($mediaConfig);
    if (isset($options['no-media'])) {
        $mediaLimits = $mediaLimits->withImagesEnabled(false);
    }
    if (isset($options['media-max-edge'])) {
        $mediaLimits = $mediaLimits->withMaxEdge((int) $options['media-max-edge']);
    }
    $imageArgs = $flagValues($argv, 'image');
    $mediaStore = new MediaStore($root, $mediaLimits);
    $mediaNormalizer = $mediaLimits->enabled ? new MediaNormalizer($mediaLimits, $mediaStore) : null;
    if ($mediaLimits->enabled && $mediaLimits->retainHours > 0) {
        // Referenced images are never pruned, so a live session always keeps its
        // attachments even after the retention window.
        $mediaStore->prune($mediaLimits->retainHours, MediaStore::referencedBy($root));
    }

    $skills = new Skills($root);
    $isTty = $bridge === null ? @stream_isatty(STDIN) : false;
    $inputStream = STDIN;
    // Reads one terminal line. In a terminal, end-of-input (Ctrl+D, or
    // Ctrl+Z then Enter on Windows) never ends the session: the console
    // stream keeps a sticky EOF flag, so reopen it and keep waiting.
    // null means input is gone; only a non-terminal (piped) stdin returns it.
    $readLine = static function () use (&$inputStream, $isTty): ?string {
        $line = fgets($inputStream);
        if ($line !== false) {
            return $line;
        }
        if (!$isTty) {
            return null;
        }
        $reopened = @fopen(PHP_OS_FAMILY === 'Windows' ? 'CONIN$' : '/dev/tty', 'r');
        if (is_resource($reopened)) {
            $inputStream = $reopened;
            $retry = fgets($inputStream);
            if ($retry !== false) {
                return $retry;
            }
        }
        return null;
    };
    if ($bridge !== null) {
        // Bridge mode: the renderer emits events instead of bytes, and ask_user
        // is wired to the JSONL channel so the tool stays advertised.
        $ui = Terminal::bridge($bridge->emitter(), (int) ($options['bridge-max-event-bytes'] ?? 32768));
        $readLine = $bridge->reader();
        $askUser = new AskUser($readLine, null, null, $bridge->askHook());
        $selector = new ModelSelector();
    } else {
        $askUser = $isTty ? new AskUser($readLine, null, $ui) : new AskUser();
        $selector = $isTty ? new ModelSelector($readLine, null, $ui) : new ModelSelector();
    }
    $local = new LocalTools($root, $skills, $commandTimeout, $askUser, $mediaNormalizer);
    $store = isset($options['session']) ? new SessionStore($root, (string) $options['session']) : null;

    // A resumed session restores the model/effort it was using, unless the
    // command line overrides them. Explicit flags always win.
    if (isset($options['continue']) && $store !== null) {
        $sessionState = $store->load();
        if ($sessionState !== null) {
            $savedModel = $sessionState['model'] ?? null;
            if (!isset($options['model']) && is_string($savedModel) && trim($savedModel) !== '') {
                $model = $savedModel;
            }
            if (!isset($options['effort']) && array_key_exists('effort', $sessionState)) {
                $savedEffort = $sessionState['effort'];
                if ($savedEffort === null) {
                    $effort = null;
                } elseif (is_string($savedEffort)
                    && in_array(strtolower(trim($savedEffort)), AnthropicClient::EFFORT_LEVELS, true)) {
                    $effort = strtolower(trim($savedEffort));
                }
            }
        }
    }

    // Capability: the model catalog's declaration (cache only, never a request),
    // then the config fallback. Anything else means text only.
    $declaredModalities = $catalog->cachedInputModalities((string) $model);
    $effectiveModalities = $declaredModalities !== [] ? $declaredModalities : $mediaLimits->inputModalities;
    $imagesEnabled = $mediaLimits->enabled && in_array('image', $effectiveModalities, true);
    $agentModalities = $imagesEnabled ? ['text', 'image'] : ['text'];
    if ($mediaLimits->enabled && !$imagesEnabled) {
        $ui->info('media: ' . $model . ' does not declare image input; images are disabled for this run.');
    }

    /** Resolve a user-named path (flags, /attach, @mentions). Not sandboxed: the user chose it. */
    $resolveUserPath = static function (string $path) use ($root): string {
        $path = trim($path);
        if ($path === '') {
            throw new RuntimeException('media: empty path');
        }
        $isAbsolute = preg_match('#\A(?:[A-Za-z]:[\\\\/]|/|\\\\\\\\)#', $path) === 1;
        $candidate = $isAbsolute ? $path : $root . DIRECTORY_SEPARATOR . $path;
        $resolved = realpath($candidate);
        if ($resolved === false) {
            throw new RuntimeException('media: not found: ' . $path);
        }
        return $resolved;
    };

    /** @return array<string, mixed> MediaRef */
    $attachImage = static function (string $path, string $source) use ($mediaNormalizer, $resolveUserPath): array {
        if ($mediaNormalizer === null) {
            throw new MediaError(MediaError::DISABLED, 'media: image support is disabled (media.enabled or --no-media)');
        }
        $display = basename(str_replace('\\', '/', trim($path)));
        return $mediaNormalizer->fromFile($resolveUserPath($path), $display, $source);
    };

    $mediaStats = ['images' => 0, 'bytes' => 0, 'sha' => []];
    $countMedia = static function (array $ref) use (&$mediaStats): void {
        $sha = (string) ($ref['sha256'] ?? '');
        if ($sha !== '' && isset($mediaStats['sha'][$sha])) {
            return;
        }
        if ($sha !== '') {
            $mediaStats['sha'][$sha] = true;
        }
        $mediaStats['images']++;
        $mediaStats['bytes'] += (int) ($ref['bytes'] ?? 0);
    };

    /** @param list<array<string, mixed>> $refs @return array<int, array<string, mixed>> */
    $promptBlocks = static function (string $text, array $refs) use ($mediaNormalizer, $mediaLimits): array {
        $blocks = [];
        $trimmed = trim($text);
        if ($trimmed !== '') {
            $blocks[] = ['type' => 'text', 'text' => $text];
        } elseif ($refs !== []) {
            $blocks[] = ['type' => 'text', 'text' => 'Describe the attached image(s).'];
        }
        $added = 0;
        foreach ($refs as $ref) {
            if ($added >= $mediaLimits->maxImagesPerMessage) {
                $blocks[] = ['type' => 'text', 'text' => '[myagent] Further images omitted: media.max_images_per_message is '
                    . $mediaLimits->maxImagesPerMessage . '.'];
                break;
            }
            if ($mediaNormalizer === null) {
                break;
            }
            $blocks[] = $mediaNormalizer->block($ref);
            $added++;
        }
        return $blocks;
    };

    /**
     * Attach images referenced as @path in a typed prompt.
     *
     * @return list<array<string, mixed>>
     */
    $refsFromMentions = static function (string $text) use ($mediaLimits, $attachImage, $ui): array {
        if ($mediaLimits->mentions === 'off') {
            return [];
        }
        if (preg_match_all('/(?<![\w@\/])@([^\s"\'`<>()\[\]]+)/u', $text, $matches) < 1) {
            return [];
        }
        $refs = [];
        $seen = [];
        foreach (array_unique($matches[1]) as $token) {
            // Sentence punctuation that follows a mention ("see @shot.png.") is
            // prose, not part of the path; trim it before resolving the file.
            $token = rtrim($token, '.,;:!?');
            if ($token === '' || isset($seen[$token])) {
                continue;
            }
            $seen[$token] = true;
            if ($mediaLimits->mentions === 'images' && !Mime::looksLikeImage($token)) {
                continue;
            }
            try {
                $refs[] = $attachImage($token, 'user');
            } catch (Throwable) {
                // Not a usable file: leave the @token as literal text.
            }
        }
        return $refs;
    };

    $mcp = [];
    $mcpStatuses = [];
    $mcpNames = [];
    if (!is_array($config['mcpServers'] ?? [])) {
        throw new RuntimeException('mcpServers must be an object');
    }
    foreach (($config['mcpServers'] ?? []) as $name => $server) {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', (string) $name) || !is_array($server)) {
            throw new RuntimeException('Invalid MCP server configuration');
        }
        $client = new McpClient((string) $name, $server, $root, $mediaNormalizer);
        try {
            $client->connect();
            $mcp[] = $client;
            $mcpNames[] = (string) $name;
            $mcpStatuses[] = ['ok', (string) $name, count($client->definitions())];
        } catch (Throwable $e) {
            $client->close();
            $mcpStatuses[] = ['fail', (string) $name, $e->getMessage()];
        }
    }

    $api = new AnthropicClient((string) $apiKey, $apiUrl, $apiKeyHeader, $apiKeyPrefix, $apiVersion);
    $api->setEffort($effort);
    // One client, set once: the actor AND the security reviewer inherit the same
    // identity, so both land in the same prompt-cache partition.
    if ($sendMetadata) {
        $api->setUserId($userId);
    }
    $api->setSendCacheKey($sendCacheKey);
    $api->setCacheOptions($conversationBreakpoint, $cacheTtl);
    $securityOptions = $config['security'] ?? [];
    if (!is_array($securityOptions)) {
        throw new RuntimeException('security must be an object');
    }
    $security = null;
    $reviewerModel = '';
    if ((bool) ($securityOptions['enabled'] ?? true)) {
        // The reviewer shares the actor's provider, endpoint, credentials, and
        // headers; only the model name may differ.
        $reviewerModel = trim((string) ($securityOptions['model'] ?? ''));
        if ($reviewerModel === '') {
            $reviewerModel = (string) $model;
        }
        $approver = null;
        if ($bridge !== null) {
            // Every "ask" verdict becomes a UI approval card; a timeout denies.
            $approver = $bridge->approver();
        } elseif ($isTty) {
            $approver = static function (array $call, array $decision) use ($readLine, $ui): bool {
                $ui->securityApproval($call, $decision);
                $line = $readLine();
                return $line !== null && in_array(strtolower(trim($line)), ['y', 'yes'], true);
            };
        }
        $security = new SecurityClassifier($api, $reviewerModel, $root, $securityOptions, $approver);
    }

    $agent = new Agent(
        $api,
        $local,
        $mcp,
        (string) $model,
        $maxTokens,
        $maxSteps,
        $store,
        $compactionThreshold,
        $summaryMaxTokens,
        $security,
        $ui,
        $mediaStore
    );
    $agent->setToolResultLimit($toolResultLimit);
    // Frozen for the process: the tool array is part of the cached prefix and the
    // provider restricts mid-conversation tool mutations.
    $agent->enableMediaTools($imagesEnabled);
    $agent->setInputModalities($agentModalities);

    if ($bridge !== null) {
        $agent->setProgressHook($bridge->progressHook());
        $bridge->emit('ready', [
            'session' => isset($options['session']) ? (string) $options['session'] : null,
            'workspace' => $root,
            'model' => (string) $model,
            'effort' => $effort,
            'identity' => $userId,
            'security' => $security === null
                ? ['enabled' => false]
                : [
                    'enabled' => true,
                    'model' => $reviewerModel,
                    'fail_closed' => (bool) ($securityOptions['fail_closed'] ?? true),
                ],
            'tools' => count($local->definitions()),
            'mcp' => $mcpNames,
            'pid' => getmypid(),
            'ask_user' => $askUser->available(),
            'media' => [
                'enabled' => $imagesEnabled,
                'images' => $imagesEnabled,
                'max_bytes' => $mediaLimits->maxBytes,
                'max_edge' => $mediaLimits->maxEdge,
                'store' => $mediaLimits->store,
            ],
        ]);

        $byeReason = 'idle-exit';
        while (($command = $bridge->nextCommand()) !== null) {
            $commandType = (string) ($command['type'] ?? '');
            if ($commandType === 'prompt') {
                $text = (string) ($command['text'] ?? '');
                if (trim($text) === '') {
                    $bridge->emit('warning', ['text' => 'empty prompt ignored']);
                    continue;
                }
                // Attachments travel as workspace paths; inline base64 would need a
                // much larger line cap and would bypass the path checks.
                $bridgeImages = [];
                foreach ((array) ($command['attachments'] ?? []) as $attachmentIndex => $attachment) {
                    if (!is_array($attachment)) {
                        $bridge->emit('media_error', [
                            'index' => $attachmentIndex,
                            'path' => null,
                            'reason' => 'invalid-attachment',
                            'message' => 'attachments must be objects',
                        ]);
                        continue;
                    }
                    if (isset($attachment['data']) || isset($attachment['data_base64'])) {
                        $bridge->emit('media_error', [
                            'index' => $attachmentIndex,
                            'path' => isset($attachment['path']) ? (string) $attachment['path'] : null,
                            'reason' => 'inline-base64-unsupported',
                            'message' => 'send a workspace path instead of inline bytes',
                        ]);
                        continue;
                    }
                    $attachmentPath = (string) ($attachment['path'] ?? '');
                    if (!$imagesEnabled) {
                        $bridge->emit('media_error', [
                            'index' => $attachmentIndex,
                            'path' => $attachmentPath,
                            'reason' => 'disabled',
                            'message' => 'images are disabled for this session',
                        ]);
                        continue;
                    }
                    try {
                        $ref = $attachImage($attachmentPath, 'bridge');
                        $bridgeImages[] = $ref;
                        $countMedia($ref);
                        $bridge->emit('attachment', [
                            'index' => $attachmentIndex,
                            'name' => (string) $ref['name'],
                            'media_type' => (string) $ref['media_type'],
                            'bytes' => (int) $ref['bytes'],
                            'width' => $ref['width'],
                            'height' => $ref['height'],
                            'sha256' => (string) $ref['sha256'],
                            'stored_path' => (string) $ref['path'],
                        ]);
                    } catch (Throwable $e) {
                        $bridge->emit('media_error', [
                            'index' => $attachmentIndex,
                            'path' => $attachmentPath,
                            'reason' => $e instanceof MediaError ? $e->reason : 'error',
                            'message' => $e->getMessage(),
                        ]);
                    }
                }
                $bridge->emit('turn_start', ['prompt' => $text]);
                $complete = $bridgeImages === []
                    ? $agent->run($text)
                    : $agent->run($promptBlocks($text, $bridgeImages));
                $bridge->emit('turn_end', ['complete' => $complete]);
                if (!$complete) {
                    $bridge->emit('warning', ['text' => 'Step limit reached with work in progress. Send continue for another budget.']);
                }
            } elseif ($commandType === 'continue') {
                $bridge->emit('turn_start', ['prompt' => null]);
                $complete = $agent->resume();
                $bridge->emit('turn_end', ['complete' => $complete]);
            } elseif ($commandType === 'set_model') {
                try {
                    $agent->setModel((string) ($command['model'] ?? ''));
                    $bridge->emit('model', ['model' => $agent->model()]);
                } catch (Throwable $e) {
                    $bridge->emit('error', ['text' => $e->getMessage()]);
                }
            } elseif ($commandType === 'set_effort') {
                try {
                    $requested = $command['effort'] ?? null;
                    $agent->setEffort(is_string($requested) && trim($requested) !== '' ? $requested : null);
                    $bridge->emit('effort', ['effort' => $agent->effort()]);
                } catch (Throwable $e) {
                    $bridge->emit('error', ['text' => $e->getMessage()]);
                }
            } elseif ($commandType === 'cache_report') {
                $cacheTotals = $agent->usageTotals();
                $bridge->emit('cache', ['report' => [
                    'identity' => $userId,
                    'metadata' => $sendMetadata,
                    'cache_key' => $sendCacheKey,
                    'breakpoints' => 'system · tools' . ($conversationBreakpoint ? ' · last-message' : ''),
                    'ttl' => $cacheTtl,
                    'requests' => $cacheTotals['requests'],
                    'input' => $cacheTotals['input'],
                    'output' => $cacheTotals['output'],
                    'cache_read' => $cacheTotals['cache_read'],
                    'cache_creation' => $cacheTotals['cache_creation'],
                    'hit_ratio' => $agent->cacheHitRatio(),
                ]]);
            } elseif ($commandType === 'shutdown') {
                $byeReason = 'shutdown';
                break;
            } elseif (in_array($commandType, ['answer', 'cancel', 'verdict'], true)) {
                $bridge->emit('warning', ['text' => 'stale ' . $commandType . ' ignored: no interaction is pending']);
            } else {
                $bridge->emit('warning', ['text' => 'unknown command: ' . $commandType]);
            }
        }

        if (($dangling = $bridge->danglingInputWarning()) !== null) {
            // A supervisor can see this on the event stream; a human sees it on STDERR.
            fwrite(STDERR, '[bridge] ' . $dangling . PHP_EOL);
            $bridge->emit('warning', ['text' => $dangling]);
        }
        $bridge->emit('bye', ['reason' => $byeReason, 'code' => 0]);
        foreach ($mcp as $client) {
            $client->close();
        }
        exit(0);
    }
    $bannerShown = $ui->banner([
        'model' => (string) $model,
        'effort' => $effort ?? 'provider default',
        'workspace' => $root,
        'session' => isset($options['session'])
            ? (string) $options['session'] . (isset($options['continue']) ? ' (resume)' : '')
            : null,
        'identity' => $userId . ($sendMetadata ? '' : ' (metadata off)'),
        'security' => $security !== null
            ? 'reviewer ' . $reviewerModel . ((bool) ($securityOptions['fail_closed'] ?? true) ? ' · fail-closed' : '')
            : 'off',
        'tools' => count($local->definitions()) . ' local',
        'mcp' => count($mcp) . ' server' . (count($mcp) === 1 ? '' : 's'),
        'images' => !$mediaLimits->enabled
            ? 'off'
            : ($imagesEnabled
                ? 'supported · ' . $mediaLimits->maxEdge . ' px · ' . Mime::formatBytes($mediaLimits->maxBytes)
                : 'model has no image input'),
    ]);
    foreach ($mcpStatuses as $status) {
        if ($status[0] === 'ok') {
            $ui->info("MCP {$status[1]} connected ({$status[2]} tools)");
        } else {
            $ui->warning("MCP {$status[1]} unavailable: {$status[2]}");
        }
    }
    if (!$bannerShown && $security !== null) {
        $ui->info("Security reviewer: {$reviewerModel}" . ($reviewerModel === $model ? ' (same model as actor)' : '') . ' on the main provider');
    }

    /** Split "/attach a.png b c.png" arguments, honouring simple quoting. */
    $splitPaths = static function (string $argument): array {
        $tokens = [];
        if (preg_match_all('/"([^"]+)"|\'([^\']+)\'|(\S+)/', $argument, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $value = (string) ($match[1] !== '' ? $match[1] : ($match[2] !== '' ? $match[2] : ($match[3] ?? '')));
                if ($value !== '') {
                    $tokens[] = $value;
                }
            }
        }
        return $tokens;
    };

    /** Grab the Windows clipboard image into the store; returns the saved path. */
    $pasteImage = static function () use ($mediaStore, $mediaLimits): string {
        if (!$mediaLimits->enabled) {
            throw new RuntimeException('media: image support is disabled (media.enabled or --no-media)');
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            throw new RuntimeException('media: /paste is only available on Windows');
        }
        $inbox = $mediaStore->directory() . '/inbox';
        if (!is_dir($inbox) && !@mkdir($inbox, 0700, true) && !is_dir($inbox)) {
            throw new RuntimeException('media: could not create ' . $inbox);
        }
        $target = $inbox . '/clipboard-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.png';
        $script = 'Add-Type -AssemblyName System.Windows.Forms;'
            . '$img=[System.Windows.Forms.Clipboard]::GetImage();'
            . 'if ($img -eq $null) { "no-image" } else { $img.Save(\''
            . str_replace("'", "''", $target) . '\',[System.Drawing.Imaging.ImageFormat]::Png); "ok" }';
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open(
            ['powershell', '-NoProfile', '-NonInteractive', '-STA', '-Command', $script],
            $descriptors,
            $pipes
        );
        if (!is_resource($process)) {
            throw new RuntimeException('media: could not start PowerShell for the clipboard');
        }
        fclose($pipes[0]);
        $errorOutput = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        if (!is_file($target)) {
            throw new RuntimeException('media: no image on the clipboard'
                . ($errorOutput === '' ? '' : ' (' . $errorOutput . ')'));
        }
        return $target;
    };

    /** True when the model that will see the next request accepts images. */
    $mediaSupportedNow = static function () use ($agent): bool {
        $modalities = $agent->inputModalities();
        return $modalities === null || in_array('image', $modalities, true);
    };

    $prompt = implode(' ', array_values(array_filter(array_slice($argv, 1), static fn($arg) => !str_starts_with($arg, '--'))));

    // --image values are resolved once, in argument order, before the first turn.
    $pendingImages = [];
    foreach ($imageArgs as $imageArg) {
        if (!$imagesEnabled) {
            $ui->warning('media: images are disabled for this run; ignoring --image=' . $imageArg);
            continue;
        }
        try {
            $ref = $attachImage($imageArg, 'user');
            $pendingImages[] = $ref;
            $countMedia($ref);
            $ui->info('+ ' . $ref['name'] . ' — ' . trim(Mime::describe($ref), '[]'));
        } catch (Throwable $e) {
            $ui->warning($e->getMessage());
        }
    }
    if ($imageArgs !== [] && $pendingImages === [] && $prompt === '') {
        throw new RuntimeException('no usable image and no prompt');
    }

    $complete = true;
    if (isset($options['continue'])) {
        if ($store === null || $prompt !== '') {
            throw new RuntimeException('--continue requires --session=NAME and no new prompt');
        }
        $complete = $agent->resume();
    } elseif ($prompt !== '') {
        $refs = array_merge($pendingImages, $refsFromMentions($prompt));
        $pendingImages = [];
        if ($refs === []) {
            $complete = $agent->run($prompt);
        } else {
            foreach ($refs as $ref) {
                $countMedia($ref);
            }
            if (!$mediaSupportedNow()) {
                $ui->warning('media: ' . $agent->model()
                    . ' does not accept images; the attachment(s) are sent as placeholders.');
            }
            $complete = $agent->run($promptBlocks($prompt, $refs));
        }
    }
    if (!$complete) {
        $ui->warning('Work is unfinished (step limit). Type /continue for another step budget.');
    }

    $loadModels = static function (bool $refresh) use ($catalog, $ui): array {
        $models = $catalog->models($refresh);
        if ($catalog->lastError() !== null) {
            $ui->warning('Model catalog: ' . $catalog->lastError()
                . ($models === [] ? '' : ' (using the ' . $catalog->source() . ' list)'));
        }
        return $models;
    };
    $printModels = static function (array $models, string $current) use ($ui): void {
        $ui->info('Current model: ' . $current);
        if ($models === []) {
            $ui->warning('No provider models are available. Use /model <id> to set one directly.');
            return;
        }
        foreach ($models as $index => $entry) {
            $id = (string) ($entry['id'] ?? '');
            $ui->info(($index + 1) . ') ' . $id . ($id === $current ? '  (current)' : ''));
        }
    };
    $applyModel = static function (string $id) use ($agent, $catalog, $ui, $mediaLimits): void {
        $previousEffort = $agent->effort();
        $agent->setModel($id);
        // The tool set stays frozen; only the body gate follows the model.
        $declared = $catalog->cachedInputModalities($id);
        $effective = $declared !== [] ? $declared : $mediaLimits->inputModalities;
        $supported = $mediaLimits->enabled && in_array('image', $effective, true);
        $agent->setInputModalities($supported ? ['text', 'image'] : ['text']);
        if ($mediaLimits->enabled && !$supported) {
            $ui->info('media: ' . $id . ' does not accept images; existing images are sent as placeholders.');
        }
        $levels = $catalog->effortLevels($id);
        $defaultLevel = $catalog->defaultEffort($id);
        if ($previousEffort !== null && $levels !== []) {
            [$clamped, $wasClamped] = ModelCatalog::clampEffort($previousEffort, $levels, $defaultLevel);
            if ($wasClamped) {
                $agent->setEffort($clamped);
                $ui->info('Effort "' . $previousEffort . '" is not supported by ' . $id
                    . '; using "' . ($clamped ?? 'provider default') . '".');
            }
        }
        $ui->info('Model set to ' . $id
            . ($agent->effort() !== null ? ' · effort ' . $agent->effort() : ' · provider-default effort'));
    };
    $applyEffort = static function (string $requested) use ($agent, $catalog, $ui): void {
        $lower = strtolower(trim($requested));
        if (in_array($lower, ['default', 'none', 'off'], true)) {
            $agent->setEffort(null);
            $ui->info('Effort set to provider default.');
            return;
        }
        if (!ModelCatalog::validEffort($lower)) {
            $ui->warning('Unknown effort level "' . $requested . '". Use low, medium, high, xhigh, max, or default.');
            return;
        }
        $levels = $catalog->effortLevels($agent->model());
        $defaultLevel = $catalog->defaultEffort($agent->model());
        [$effort, $clamped] = ModelCatalog::clampEffort($lower, $levels, $defaultLevel);
        $agent->setEffort($effort);
        if ($clamped) {
            $ui->info('Effort "' . $lower . '" is not supported by ' . $agent->model()
                . '; using "' . ($effort ?? 'provider default') . '".');
        } else {
            $ui->info('Effort set to ' . ($effort ?? 'provider default') . '.');
        }
    };

    // A finished model turn never closes the session: fall through to the
    // prompt and keep waiting for the user. In a terminal only Ctrl+C stops
    // the process; /exit and /quit no longer exist. Piped stdin still ends
    // the loop when exhausted, so scripts cannot hang forever.
    $promptShown = false;
    while (true) {
        if (!$promptShown) {
            $ui->prompt();
            $promptShown = true;
        }
        $line = $readLine();
        if ($line === null) {
            if (!$isTty) {
                break;
            }
            // End-of-input must not end the session; keep waiting for Ctrl+C.
            usleep(200000);
            continue;
        }
        $input = trim($line);
        if ($input === '') {
            continue;
        }
        $promptShown = false;
        $complete = true;
        if ($input === '/continue') {
            $complete = $agent->resume();
        } elseif ($input === '/help') {
            $ui->err($ui->styleErr('Commands', 'bold', 'accent') . "\n");
            foreach ([
                '/model [id|number]  Show or switch the model',
                '/effort [level]     Show or switch the reasoning effort',
                '/attach <path...>   Attach images to the next prompt',
                '/attachments        List the queued images',
                '/detach [n|all]     Drop one queued image, or all of them',
                '/paste              Attach the clipboard image (Windows)',
                '/media              Show image capability, limits and usage',
                '/cache              Show identity, breakpoints and cache hit rate',
                '/continue           Give unfinished work another step budget',
                '/help               Show this command list',
                'Ctrl+C              Exit the session',
            ] as $commandLine) {
                $ui->info($commandLine);
            }
        } elseif ($input === '/cache') {
            $cacheTotals = $agent->usageTotals();
            $ui->cacheReport([
                'identity' => $userId,
                'metadata' => $sendMetadata,
                'cache_key' => $sendCacheKey,
                'breakpoints' => 'system · tools' . ($conversationBreakpoint ? ' · last-message' : ''),
                'ttl' => $cacheTtl,
                'requests' => $cacheTotals['requests'],
                'input' => $cacheTotals['input'],
                'output' => $cacheTotals['output'],
                'cache_read' => $cacheTotals['cache_read'],
                'cache_creation' => $cacheTotals['cache_creation'],
                'hit_ratio' => $agent->cacheHitRatio(),
            ]);
        } elseif (preg_match('#^/(attach|attachments|detach|paste|media)(?:\s+(.*))?$#', $input, $mediaCommand) === 1) {
            $verb = (string) $mediaCommand[1];
            $argument = trim((string) ($mediaCommand[2] ?? ''));
            if ($verb === 'attach') {
                if (!$mediaLimits->enabled) {
                    $ui->warning('media: image support is disabled (media.enabled or --no-media).');
                } elseif ($argument === '') {
                    $ui->info('Usage: /attach <path> [path...]');
                } else {
                    foreach ($splitPaths($argument) as $attachPath) {
                        try {
                            $ref = $attachImage($attachPath, 'user');
                            $pendingImages[] = $ref;
                            $countMedia($ref);
                            $ui->info('+ ' . $ref['name'] . ' — ' . trim(Mime::describe($ref), '[]'));
                        } catch (Throwable $e) {
                            $ui->warning($e->getMessage());
                        }
                    }
                    if ($pendingImages !== [] && !$mediaSupportedNow()) {
                        $ui->warning('media: ' . $agent->model()
                            . ' does not accept images; they would be sent as placeholders.');
                    }
                }
            } elseif ($verb === 'attachments') {
                if ($pendingImages === []) {
                    $ui->info('No queued images.');
                } else {
                    $total = 0;
                    foreach ($pendingImages as $index => $ref) {
                        $total += (int) $ref['bytes'];
                        $ui->info(($index + 1) . ') ' . $ref['name'] . ' — ' . trim(Mime::describe($ref), '[]'));
                    }
                    $ui->info('Total: ' . count($pendingImages) . ' image(s), ' . Mime::formatBytes($total)
                        . '. They are sent to the model provider with the next prompt.');
                }
            } elseif ($verb === 'detach') {
                if ($argument === '' || strtolower($argument) === 'all') {
                    $removed = count($pendingImages);
                    $pendingImages = [];
                    $ui->info('Detached ' . $removed . ' image(s).');
                } elseif (ctype_digit($argument)) {
                    $position = (int) $argument - 1;
                    if (isset($pendingImages[$position])) {
                        $removed = $pendingImages[$position];
                        array_splice($pendingImages, $position, 1);
                        $pendingImages = array_values($pendingImages);
                        $ui->info('Detached ' . $removed['name'] . '.');
                    } else {
                        $ui->warning('No queued image at position ' . $argument . '.');
                    }
                } else {
                    $ui->info('Usage: /detach <n|all>');
                }
            } elseif ($verb === 'paste') {
                try {
                    $ref = $attachImage($pasteImage(), 'user');
                    $pendingImages[] = $ref;
                    $countMedia($ref);
                    $ui->info('+ ' . $ref['name'] . ' — ' . trim(Mime::describe($ref), '[]'));
                } catch (Throwable $e) {
                    $ui->warning($e->getMessage());
                }
            } else {
                $ui->info('images      ' . ($mediaLimits->enabled
                    ? ($mediaSupportedNow() ? 'supported' : 'not supported by ' . $agent->model())
                    : 'off'));
                $ui->info('limits      ' . $mediaLimits->maxEdge . ' px · ' . Mime::formatBytes($mediaLimits->maxBytes)
                    . ' · ' . $mediaLimits->maxImagesPerMessage . ' per message · store ' . $mediaLimits->store);
                $ui->info('pending     ' . ($pendingImages === []
                    ? 'no attachments'
                    : count($pendingImages) . ' image(s): ' . implode(', ', array_map(
                        static fn(array $ref): string => (string) $ref['name'],
                        $pendingImages
                    ))));
                $ui->info('used        ' . $mediaStats['images'] . ' image(s) · '
                    . Mime::formatBytes($mediaStats['bytes']) . ' normalised · '
                    . count($mediaStats['sha']) . ' unique');
            }
        } elseif ($input === '/model' || str_starts_with($input, '/model ')) {
            $arg = trim(substr($input, 6));
            if (in_array(strtolower($arg), ['refresh', 'r'], true)) {
                $printModels($loadModels(true), $agent->model());
            } elseif ($arg === '') {
                $models = $loadModels(false);
                if ($selector->available()) {
                    try {
                        $choice = $selector->pickModel(
                            $models,
                            $agent->model(),
                            $defaultModelLabel,
                            static fn(bool $refresh): array => $loadModels($refresh)
                        );
                        if ($choice['action'] === 'select' && $choice['model'] !== null) {
                            $applyModel($choice['model']);
                        } else {
                            $ui->info('Model unchanged (' . $agent->model() . ').');
                        }
                    } catch (RuntimeException $e) {
                        $ui->warning($e->getMessage());
                    }
                } else {
                    $printModels($models, $agent->model());
                    $ui->info('Use /model <id> to switch.');
                }
            } else {
                $resolution = ModelSelector::resolve($arg, $loadModels(false));
                if ($resolution['status'] === 'match' && $resolution['model'] !== null) {
                    try {
                        $applyModel($resolution['model']);
                    } catch (RuntimeException $e) {
                        $ui->warning($e->getMessage());
                    }
                } elseif ($resolution['status'] === 'ambiguous') {
                    $ui->warning('Ambiguous model prefix. Matches: ' . implode(', ', $resolution['candidates']));
                } elseif ($resolution['status'] === 'invalid') {
                    $ui->warning('Model number out of range. Use /model to see the list.');
                } else {
                    try {
                        $applyModel($arg);
                        $ui->warning('Note: "' . $arg . '" is not in the provider list; the next request may fail.');
                    } catch (RuntimeException $e) {
                        $ui->warning($e->getMessage());
                    }
                }
            }
        } elseif ($input === '/effort' || str_starts_with($input, '/effort ')) {
            $arg = trim(substr($input, 8));
            // Load declared levels (cache-first) so the list and clamping use
            // the provider's real capabilities instead of the canonical five.
            $loadModels(false);
            if ($arg === '') {
                $levels = $catalog->effortLevels($agent->model());
                $defaultLevel = $catalog->defaultEffort($agent->model());
                if ($selector->available()) {
                    try {
                        $choice = $selector->pickEffort($levels, $agent->effort(), $defaultLevel, $agent->model());
                        if ($choice['action'] === 'select') {
                            $agent->setEffort($choice['effort']);
                            if ($choice['clamped']) {
                                $ui->info('Effort "' . ($choice['requested'] ?? '') . '" is not supported by '
                                    . $agent->model() . '; using "' . ($choice['effort'] ?? 'provider default') . '".');
                            } else {
                                $ui->info('Effort set to ' . ($choice['effort'] ?? 'provider default') . '.');
                            }
                        } else {
                            $ui->info('Effort unchanged (' . ($agent->effort() ?? 'provider default') . ').');
                        }
                    } catch (RuntimeException $e) {
                        $ui->warning($e->getMessage());
                    }
                } else {
                    $ui->info('Current effort: ' . ($agent->effort() ?? 'provider default'));
                    $ui->info('Available: ' . implode(', ', $levels === [] ? AnthropicClient::EFFORT_LEVELS : $levels));
                    $ui->info('Use /effort <level> to switch.');
                }
            } else {
                $applyEffort($arg);
            }
        } else {
            $refs = array_merge($pendingImages, $refsFromMentions($input));
            if ($refs === []) {
                $complete = $agent->run($input);
            } else {
                foreach ($refs as $ref) {
                    $countMedia($ref);
                }
                if (!$mediaSupportedNow()) {
                    $ui->warning('media: ' . $agent->model()
                        . ' does not accept images; the attachment(s) are sent as placeholders.');
                }
                $complete = $agent->run($promptBlocks($input, $refs));
                $pendingImages = [];
            }
        }
        if (!$complete) {
            $ui->warning('Work is unfinished (step limit). /continue gives it another step budget.');
        }
    }
    foreach ($mcp as $client) {
        $client->close();
    }
    if (!$complete) {
        exit(2);
    }
} catch (Throwable $e) {
    if ($ui !== null) {
        $ui->error("Error: {$e->getMessage()}");
    } else {
        fwrite(STDERR, "Error: {$e->getMessage()}\n");
    }
    if ($bridge !== null) {
        $bridge->emit('bye', ['reason' => 'error', 'code' => 1]);
    }
    exit(1);
}
