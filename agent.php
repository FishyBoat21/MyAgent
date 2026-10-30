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
use MyAgent\SecurityClassifier;
use MyAgent\Skills;
use MyAgent\SessionStore;
use MyAgent\Terminal;

$root = getcwd();
$options = getopt('', ['config:', 'model:', 'max-steps:', 'command-timeout:', 'session:', 'continue', 'help', 'plain', 'no-color', 'width:', 'no-banner', 'tool-details:']);
$plain = isset($options['plain']);

if (isset($options['help'])) {
    $help = Terminal::detect([], $plain);
    $help->heading('MyAgent — PHP CLI coding agent');
    $help->out("Usage: php agent.php [options] [prompt...]\n\n");
    $help->out("Options:\n");
    $help->out("  --config=FILE           Config file (default: config.json)\n");
    $help->out("  --model=MODEL           Override the model\n");
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
    $help->out("  /continue               Give unfinished work another step budget\n");
    $help->out("  Ctrl+C                  Exit the session\n");
    $help->out("\nSet \"api_key\" in the config file, or set the api_key_env environment variable\n");
    $help->out("(default ANTHROPIC_API_KEY). A dedicated security reviewer checks every tool call.\n");
    $help->out("In an interactive terminal the agent can ask single-choice questions via ask_user.\n");
    exit(0);
}

$ui = null;

try {
    $configPath = $options['config'] ?? 'config.json';
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
    $model = $options['model'] ?? (getenv('LLM_MODEL') ?: (getenv('ANTHROPIC_MODEL') ?: ($config['model'] ?? 'claude-sonnet-5-5')));
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
    $local = new LocalTools($root, $skills, $commandTimeout, $askUser);
    $store = isset($options['session']) ? new SessionStore($root, (string) $options['session']) : null;

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
        $complete = $input === '/continue' ? $agent->resume() : $agent->run($input);
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
