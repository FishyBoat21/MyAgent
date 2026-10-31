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
use MyAgent\LocalTools;
use MyAgent\McpClient;
use MyAgent\ModelCatalog;
use MyAgent\ModelSelector;
use MyAgent\SecurityClassifier;
use MyAgent\Skills;
use MyAgent\SessionStore;
use MyAgent\Terminal;

$root = getcwd();
$options = getopt('', ['config:', 'model:', 'effort:', 'max-steps:', 'command-timeout:', 'session:', 'continue', 'help', 'plain', 'no-color', 'width:', 'no-banner', 'tool-details:', 'list-models', 'refresh-models']);
$plain = isset($options['plain']);

if (isset($options['help'])) {
    $help = Terminal::detect([], $plain);
    $help->heading('MyAgent — PHP CLI coding agent');
    $help->out("Usage: php agent.php [options] [prompt...]\n\n");
    $help->out("Options:\n");
    $help->out("  --config=FILE           Config file (default: config.json next to agent.php)\n");
    $help->out("  --model=MODEL           Override the model (default: config.model)\n");
    $help->out("  --effort=LEVEL          low|medium|high|xhigh|max; omit for provider default\n");
    $help->out("  --list-models           Print the provider's model catalog and exit\n");
    $help->out("  --refresh-models        Bypass the cached catalog with --list-models\n");
    $help->out("  --max-steps=N           Model calls per run (default: 100)\n");
    $help->out("  --command-timeout=N     run_command timeout in seconds (default: 300)\n");
    $help->out("  --session=NAME          Save/restore progress under .agent/sessions/\n");
    $help->out("  --continue              Resume an unfinished named session\n");
    $help->out("  --plain                 Raw output for scripts and logs\n");
    $help->out("  --no-color              Disable ANSI colors\n");
    $help->out("  --width=N               Force terminal width (40-200)\n");
    $help->out("  --no-banner             Hide the startup banner\n");
    $help->out("  --tool-details=MODE     summary|full|hidden (default: summary)\n");
    $help->out("  --help                  Show this help\n");
    $help->out("\nInteractive commands:\n");
    $help->out("  /model [id|number]      Show or switch the model\n");
    $help->out("  /effort [level]         Show or switch the reasoning effort\n");
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
    $commandTimeout = (int) ($options['command-timeout'] ?? $config['command_timeout'] ?? 300);
    $compactionThreshold = (int) ($config['compaction_threshold_chars'] ?? $config['compaction_threshold'] ?? 24000);
    $summaryMaxTokens = (int) ($config['summary_max_tokens'] ?? 2048);
    if ($maxSteps < 1 || $maxTokens < 1 || $commandTimeout < 1 || $compactionThreshold < 0 || $summaryMaxTokens < 1) {
        throw new RuntimeException('Invalid step, token, command timeout, or compaction threshold setting');
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

    $skills = new Skills($root);
    $isTty = @stream_isatty(STDIN);
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
    $askUser = $isTty ? new AskUser($readLine, null, $ui) : new AskUser();
    $selector = $isTty ? new ModelSelector($readLine, null, $ui) : new ModelSelector();
    $local = new LocalTools($root, $skills, $commandTimeout, $askUser);
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

    $mcp = [];
    $mcpStatuses = [];
    if (!is_array($config['mcpServers'] ?? [])) {
        throw new RuntimeException('mcpServers must be an object');
    }
    foreach (($config['mcpServers'] ?? []) as $name => $server) {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', (string) $name) || !is_array($server)) {
            throw new RuntimeException('Invalid MCP server configuration');
        }
        $client = new McpClient((string) $name, $server, $root);
        try {
            $client->connect();
            $mcp[] = $client;
            $mcpStatuses[] = ['ok', (string) $name, count($client->definitions())];
        } catch (Throwable $e) {
            $client->close();
            $mcpStatuses[] = ['fail', (string) $name, $e->getMessage()];
        }
    }

    $api = new AnthropicClient((string) $apiKey, $apiUrl, $apiKeyHeader, $apiKeyPrefix, $apiVersion);
    $api->setEffort($effort);
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
        if ($isTty) {
            $approver = static function (array $call, array $decision) use ($readLine, $ui): bool {
                $ui->securityApproval($call, $decision);
                $line = $readLine();
                return $line !== null && in_array(strtolower(trim($line)), ['y', 'yes'], true);
            };
        }
        $security = new SecurityClassifier($api, $reviewerModel, $root, $securityOptions, $approver);
    }

    $agent = new Agent($api, $local, $mcp, (string) $model, $maxTokens, $maxSteps, $store, $compactionThreshold, $summaryMaxTokens, $security, $ui);

    $bannerShown = $ui->banner([
        'model' => (string) $model,
        'effort' => $effort ?? 'provider default',
        'workspace' => $root,
        'session' => isset($options['session'])
            ? (string) $options['session'] . (isset($options['continue']) ? ' (resume)' : '')
            : null,
        'security' => $security !== null
            ? 'reviewer ' . $reviewerModel . ((bool) ($securityOptions['fail_closed'] ?? true) ? ' · fail-closed' : '')
            : 'off',
        'tools' => count($local->definitions()) . ' local',
        'mcp' => count($mcp) . ' server' . (count($mcp) === 1 ? '' : 's'),
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

    $prompt = implode(' ', array_values(array_filter(array_slice($argv, 1), static fn($arg) => !str_starts_with($arg, '--'))));
    $complete = true;
    if (isset($options['continue'])) {
        if ($store === null || $prompt !== '') {
            throw new RuntimeException('--continue requires --session=NAME and no new prompt');
        }
        $complete = $agent->resume();
    } elseif ($prompt !== '') {
        $complete = $agent->run($prompt);
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
    $applyModel = static function (string $id) use ($agent, $catalog, $ui): void {
        $previousEffort = $agent->effort();
        $agent->setModel($id);
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
                '/continue           Give unfinished work another step budget',
                '/help               Show this command list',
                'Ctrl+C              Exit the session',
            ] as $commandLine) {
                $ui->info($commandLine);
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
            $complete = $agent->run($input);
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
    exit(1);
}
