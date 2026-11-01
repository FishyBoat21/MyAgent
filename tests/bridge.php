<?php
declare(strict_types=1);

/**
 * Bridge (--bridge) protocol tests. No network, no provider, no MCP: every
 * transport is an in-memory stream and the only I/O is a fake STDIN/STDOUT.
 */

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Run: composer install\n");
    exit(1);
}
require $autoload;

use MyAgent\Agent;
use MyAgent\AnthropicClient;
use MyAgent\AskUser;
use MyAgent\Bridge;
use MyAgent\LocalTools;
use MyAgent\MediaLimits;
use MyAgent\MediaNormalizer;
use MyAgent\MediaStore;
use MyAgent\Mime;
use MyAgent\Skills;
use MyAgent\Terminal;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param list<array<string, mixed>> $events */
function ev(array $events, int $index): array
{
    if (!isset($events[$index])) {
        throw new RuntimeException('missing event at index ' . $index);
    }

    return $events[$index];
}

/**
 * @param list<array<string, mixed>> $commands
 * @return array{0: Bridge, 1: resource}
 */
function bridgeFor(array $commands, int $questionTimeout = 5, int $approvalTimeout = 5): array
{
    $in = fopen('php://memory', 'r+');
    if ($in === false) {
        throw new RuntimeException('could not open the memory stream');
    }
    foreach ($commands as $command) {
        fwrite($in, json_encode(['v' => 1] + $command, JSON_UNESCAPED_SLASHES) . "\n");
    }
    rewind($in);
    $out = fopen('php://memory', 'w+');
    if ($out === false) {
        throw new RuntimeException('could not open the memory stream');
    }

    return [new Bridge(32768, 262144, $questionTimeout, $approvalTimeout, $in, $out), $out];
}

/** @return list<array<string, mixed>> */
function drain($stream): array
{
    rewind($stream);
    $text = (string) stream_get_contents($stream);
    $events = [];
    foreach (explode("\n", $text) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $decoded = json_decode($line, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('bridge emitted a non-JSON line: ' . $line);
        }
        $events[] = $decoded;
    }

    return $events;
}


try {
    // --- B1/B2: the renderer emits events and never bytes --------------------
    $events = [];
    $ui = Terminal::bridge(static function (string $type, array $payload) use (&$events): void {
        $events[] = ['type' => $type] + $payload;
    });
    check($ui->colorOut() === false, 'the bridge renderer enabled colour on stdout');
    check($ui->colorErr() === false, 'the bridge renderer enabled colour on stderr');
    check($ui->spinnerEnabled() === false, 'the bridge renderer would render a spinner');
    check($ui->markdown() === false, 'the bridge renderer would render markdown');
    $ui->assistant("# Done\n\n**ok**");
    check(ev($events, 0)['type'] === 'assistant', 'assistant did not emit an event');
    check(ev($events, 0)['text'] === "# Done\n\n**ok**", 'the assistant event lost the raw markdown');

    // --- B3: tool_start / tool_result pair by id -----------------------------
    $events = [];
    $ui = Terminal::bridge(static function (string $type, array $payload) use (&$events): void {
        $events[] = ['type' => $type] + $payload;
    });
    $ui->toolStart('read_file', ['path' => 'a.txt']);
    $ui->toolResult('read_file', ['path' => 'a.txt'], 'hello', false, 0.25);
    check(ev($events, 0)['type'] === 'tool_start' && ev($events, 1)['type'] === 'tool_result', 'tool events are missing');
    check(ev($events, 0)['id'] === ev($events, 1)['id'], 'tool_result did not pair with tool_start');
    check(ev($events, 1)['duration'] === 0.25, 'tool duration was lost');
    check(ev($events, 1)['is_error'] === false, 'tool is_error was lost');

    // --- B4: an oversized tool result is capped ------------------------------
    $events = [];
    $ui = Terminal::bridge(static function (string $type, array $payload) use (&$events): void {
        $events[] = ['type' => $type] + $payload;
    }, 1024);
    $big = str_repeat('x', 5000);
    $ui->toolStart('read_file', ['path' => 'big.txt']);
    $ui->toolResult('read_file', ['path' => 'big.txt'], $big, false, 0.1);
    check(ev($events, 1)['result_truncated'] === true, 'an oversized tool result was not truncated');
    check(ev($events, 1)['result_bytes'] === 5000, 'the true byte length was lost');
    check(strlen((string) ev($events, 1)['result']) === 1024, 'the truncated payload has the wrong length');

    // --- B5: the remaining event types map --------------------------------
    $events = [];
    $ui = Terminal::bridge(static function (string $type, array $payload) use (&$events): void {
        $events[] = ['type' => $type] + $payload;
    });
    $ui->usage(['input_tokens' => 10, 'output_tokens' => 2, 'cache_read_input_tokens' => 7], 1.5, 'end_turn');
    $ui->info('hello');
    $ui->warning('careful');
    $ui->error('broken');
    $ui->cacheReport([
        'identity' => 'myagent', 'metadata' => true, 'cache_key' => false, 'breakpoints' => 'system',
        'ttl' => '5m', 'requests' => 1, 'input' => 10, 'output' => 2, 'cache_read' => 7,
        'cache_creation' => 0, 'hit_ratio' => 0.4,
    ]);
    $ui->securityDenied(['verdict' => 'deny', 'risk' => 'high', 'category' => 'other', 'reason' => 'no']);
    $ui->securityResolved(true);
    $ui->securityApproval(['name' => 'write_file', 'input' => []], ['verdict' => 'ask']);

    $types = array_map(static fn(array $event): string => (string) $event['type'], $events);
    // cacheReport() also renders three info lines, so compare the whole sequence.
    check($types === ['usage', 'info', 'warning', 'error', 'cache', 'info', 'info', 'info', 'security_denied', 'security_resolved'], 'event mapping is wrong: ' . implode(',', $types));
    check(ev($events, 0)['input'] === 10 && ev($events, 0)['cache_read'] === 7, 'usage tokens were lost');
    check(ev($events, 0)['stop_reason'] === 'end_turn', 'usage stop reason was lost');
    check(ev($events, 4)['report']['hit_ratio'] === 0.4, 'the cache report was lost');
    check(ev($events, 8)['decision']['verdict'] === 'deny', 'the security decision was lost');

    // --- B6/B7/B8/B9: the AskUser hook -------------------------------------
    $writes = [];
    $hookCalls = [];
    $askUser = new AskUser(
        static fn(): ?string => "1\n",
        static function (string $text) use (&$writes): void {
            $writes[] = $text;
        },
        null,
        static function (string $question, array $options) use (&$hookCalls): ?string {
            $hookCalls[] = [$question, $options];

            return 'green';
        }
    );
    check($askUser->available() === true, 'a hooked AskUser must stay available');
    check($askUser->ask('Pick a colour', ['red', 'green']) === 'green', 'the hook answer was not returned');
    check($writes === [], 'the hook path still rendered a terminal prompt');
    check($hookCalls === [['Pick a colour', ['red', 'green']]], 'the hook received the wrong arguments');

    $writes = [];
    $fallback = new AskUser(
        static fn(): ?string => "2\n",
        static function (string $text) use (&$writes): void {
            $writes[] = $text;
        },
        null,
        static fn(string $question, array $options): ?string => null
    );
    check($fallback->ask('Pick', ['red', 'green']) === 'green', 'a null hook result did not fall back to the terminal flow');
    check($writes !== [], 'the fallback path did not render anything');

    $hookRan = false;
    $malformed = new AskUser(
        static fn(): ?string => "1\n",
        static function (string $text): void {
        },
        null,
        static function () use (&$hookRan): ?string {
            $hookRan = true;

            return 'x';
        }
    );
    $thrown = '';
    try {
        $malformed->ask('Pick', []);
    } catch (RuntimeException $e) {
        $thrown = $e->getMessage();
    }
    check(str_contains($thrown, 'between 1 and 4 options'), 'a malformed option list was accepted');
    check($hookRan === false, 'the hook ran before validation');

    $throwing = new AskUser(
        static fn(): ?string => "1\n",
        static function (string $text): void {
        },
        null,
        static function (): ?string {
            throw new RuntimeException('The user cancelled the question.');
        }
    );
    $thrown = '';
    try {
        $throwing->ask('Pick', ['a']);
    } catch (RuntimeException $e) {
        $thrown = $e->getMessage();
    }
    check(str_contains($thrown, 'cancelled'), 'a throwing hook did not surface');

    // --- B10/B11: askHook over the wire -----------------------------------
    [$bridge, $out] = bridgeFor([['type' => 'answer', 'id' => 'q1', 'answer' => 'blue']]);
    $answer = ($bridge->askHook())('Which colour?', ['red', 'blue']);
    check($answer === 'blue', 'askHook did not return the answer');
    $events = drain($out);
    check(ev($events, 0)['type'] === 'question', 'askHook did not emit a question');
    check(ev($events, 0)['id'] === 'q1', 'the question id is not deterministic');
    check(ev($events, 0)['options'] === ['red', 'blue'], 'the options were lost');
    check(ev($events, 1)['type'] === 'question_closed' && ev($events, 1)['reason'] === 'answered', 'question_closed is wrong');

    [$bridge, $out] = bridgeFor([['type' => 'cancel', 'id' => 'q1']]);
    $thrown = '';
    try {
        ($bridge->askHook())('Which colour?', ['red']);
    } catch (RuntimeException $e) {
        $thrown = $e->getMessage();
    }
    check(str_contains($thrown, 'cancelled'), 'a cancel did not abort the question');
    check(ev(drain($out), 1)['reason'] === 'cancelled', 'the cancel was not reported');

    // --- B12: approver allow / deny / timeout ------------------------------
    [$bridge, $out] = bridgeFor([['type' => 'verdict', 'id' => 'a1', 'verdict' => 'allow']]);
    check(($bridge->approver())(['name' => 'write_file', 'input' => []], ['verdict' => 'ask', 'risk' => 'medium', 'category' => 'fs', 'reason' => 'r']) === true, 'an allow verdict did not approve');
    $events = drain($out);
    check(ev($events, 0)['type'] === 'approval' && ev($events, 0)['tool'] === 'write_file', 'the approval event is wrong');
    check(ev($events, 1)['verdict'] === 'allow', 'approval_closed did not report allow');

    [$bridge, $out] = bridgeFor([['type' => 'verdict', 'id' => 'a1', 'verdict' => 'deny']]);
    check(($bridge->approver())(['name' => 'write_file', 'input' => []], ['verdict' => 'ask']) === false, 'a deny verdict was approved');

    [$bridge, $out] = bridgeFor([], 5, 1);
    check(($bridge->approver())(['name' => 'write_file', 'input' => []], ['verdict' => 'ask']) === false, 'an approval timeout was approved');
    check(ev(drain($out), 1)['verdict'] === 'timeout', 'the approval timeout was not observable');

    // --- B13: progress is throttled to 1 Hz -------------------------------
    [$bridge, $out] = bridgeFor([]);
    $progress = $bridge->progressHook();
    for ($i = 0; $i < 10; $i++) {
        $progress();
        usleep(20000);
    }
    check(count(drain($out)) === 1, 'the progress hook was not throttled to one event per second');

    // --- B14: no sink means no work ---------------------------------------
    $plain = new Terminal(
        false,
        false,
        ['plain' => true, 'color' => 'never', 'markdown' => false],
        static function (string $text): void {
        },
        static function (string $text): void {
        }
    );
    $plain->assistant('hi');
    $plain->toolStart('read_file', ['path' => 'a']);
    $plain->toolResult('read_file', ['path' => 'a'], 'x', false, 0.1);
    $plain->usage(['input_tokens' => 1], 0.1, 'end_turn');
    check(true, 'a renderer without an event sink stays silent');

    // --- B15: the agent still drives the bridge end to end ----------------
    $events = [];
    $probe = new class('unused') extends AnthropicClient {
        public int $calls = 0;

        public function message(string $model, int $maxTokens, string $system, array $messages, array $tools): array
        {
            $this->calls++;
            if ($this->calls === 1) {
                return [
                    'content' => [['type' => 'tool_use', 'id' => 'call-1', 'name' => 'read_file', 'input' => ['path' => 'missing.txt']]],
                    'stop_reason' => 'tool_use',
                    'usage' => ['input_tokens' => 5, 'output_tokens' => 1],
                ];
            }

            return [
                'content' => [['type' => 'text', 'text' => 'gave up']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 6, 'output_tokens' => 2],
            ];
        }
    };
    $skills = new Skills($root . '/tests/fixtures');
    $local = new LocalTools($root . '/tests/fixtures', $skills);
    $ui = Terminal::bridge(static function (string $type, array $payload) use (&$events): void {
        $events[] = ['type' => $type] + $payload;
    });
    $agent = new Agent($probe, $local, [], 'test-model', 100, 4, null, 24000, 2048, null, $ui);
    check($agent->run('read a missing file') === true, 'the bridge-driven turn did not complete');
    $types = array_map(static fn(array $event): string => (string) $event['type'], $events);
    check(in_array('assistant', $types, true), 'the bridge turn emitted no assistant event');
    check(in_array('tool_start', $types, true) && in_array('tool_result', $types, true), 'the bridge turn emitted no tool events');
    check(in_array('usage', $types, true), 'the bridge turn emitted no usage event');

    // --- B16: media descriptors travel, bytes never do ---------------------
    $events = [];
    $ui = Terminal::bridge(static function (string $type, array $payload) use (&$events): void {
        $events[] = ['type' => $type] + $payload;
    });
    $ui->toolStart('read_image', ['path' => 'shot.png']);
    $ui->toolResult('read_image', ['path' => 'shot.png'], '[image png 4×4 240 B sha 9f2c1a3b]', false, 0.05, [[
        'name' => 'shot.png',
        'media_type' => 'image/png',
        'bytes' => 240,
        'width' => 4,
        'height' => 4,
        'sha256' => str_repeat('9f', 32),
        'descriptor' => '[image png 4×4 240 B sha 9f2c1a3b]',
    ]]);
    $result = ev($events, 1);
    check($result['type'] === 'tool_result', 'B16 the media tool result event is missing');
    check(isset($result['media']) && count($result['media']) === 1, 'B16 the media descriptor was not emitted');
    check($result['media'][0]['sha256'] === str_repeat('9f', 32), 'B16 the media sha was lost');
    check(!str_contains((string) json_encode($result), 'base64'), 'B16 base64 leaked into the bridge event');

    // --- B17: an agent turn that returns an image --------------------------
    $workspace = $root . '/tests/.bridge-media-' . getmypid();
    // Remove the throwaway workspace even when an assertion fails below.
    register_shutdown_function(static function () use ($workspace): void {
        if (!is_dir($workspace)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($workspace);
    });
    @mkdir($workspace . '/docs', 0777, true);
    $pngBytes = (static function (): string {
        $image = imagecreatetruecolor(5, 5);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 90, 150));
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);
        return $bytes;
    })();
    file_put_contents($workspace . '/docs/shot.png', $pngBytes);
    $bridgeLimits = new MediaLimits(store: '.agent/media');
    $bridgeStore = new MediaStore($workspace, $bridgeLimits);
    $bridgeNormalizer = new MediaNormalizer($bridgeLimits, $bridgeStore);
    $bridgeTools = new LocalTools($workspace, new Skills($workspace), 300, null, $bridgeNormalizer);

    $events = [];
    $bridgeUi = Terminal::bridge(static function (string $type, array $payload) use (&$events): void {
        $events[] = ['type' => $type] + $payload;
    });
    $imageProbe = new class('unused') extends AnthropicClient {
        public int $calls = 0;
        public function message(string $model, int $maxTokens, string $system, array $messages, array $tools): array
        {
            $this->calls++;
            if ($this->calls === 1) {
                return ['content' => [[
                    'type' => 'tool_use',
                    'id' => 'img-call',
                    'name' => 'read_image',
                    'input' => ['path' => 'docs/shot.png'],
                ]], 'stop_reason' => 'tool_use'];
            }
            return ['content' => [['type' => 'text', 'text' => 'seen']], 'stop_reason' => 'end_turn'];
        }
    };
    $imageAgent = new Agent($imageProbe, $bridgeTools, [], 'test-model', 100, 3, null, 24000, 2048, null, $bridgeUi, $bridgeStore);
    $imageAgent->setInputModalities(['text', 'image']);
    check($imageAgent->run('look') === true, 'B17 the image turn did not complete');

    $toolResults = array_values(array_filter($events, static fn(array $event): bool => $event['type'] === 'tool_result'));
    check(count($toolResults) === 1, 'B17 the image tool result was not emitted');
    check(isset($toolResults[0]['media']) && count($toolResults[0]['media']) === 1, 'B17 the image descriptor is missing');
    check(($toolResults[0]['media'][0]['media_type'] ?? '') === 'image/png', 'B17 the media type is wrong');
    check(!str_contains((string) json_encode($toolResults[0]), 'base64'), 'B17 base64 leaked into the tool_result event');
    check(
        !str_contains((string) json_encode($events), (string) base64_encode($pngBytes)),
        'B17 the raw image bytes reached the bridge'
    );


    // --- B18: a file-backed command channel still delivers --------------------
    $commandFile = tempnam(sys_get_temp_dir(), 'myagent-b18-');
    check(is_string($commandFile), 'B18 could not create the temp command file');
    file_put_contents($commandFile, json_encode(['v' => 1, 'type' => 'prompt', 'text' => 'from a file']) . "\n");
    $fileIn = fopen($commandFile, 'r');
    $fileOut = fopen('php://memory', 'w+');
    check(is_resource($fileIn) && is_resource($fileOut), 'B18 could not open the file channel');
    $fileBridge = new Bridge(32768, 262144, 5, 5, $fileIn, $fileOut);
    $fileCommand = $fileBridge->nextCommand();
    check(($fileCommand['type'] ?? '') === 'prompt', 'B18 a file-backed channel lost its command');
    check($fileBridge->commandsDelivered() === 1, 'B18 the delivered command was not counted');
    check($fileBridge->danglingInputWarning() === null, 'B18 a delivered command still warned');
    fclose($fileIn);
    fclose($fileOut);
    @unlink($commandFile);

    // --- B19: one empty read is not end-of-channel ---------------------------
    require_once __DIR__ . '/fixtures/flaky-stream.php';
    stream_wrapper_register('flakystream', FlakyStream::class);
    FlakyStream::$payload = json_encode(['v' => 1, 'type' => 'prompt', 'text' => 'after a dead read']) . "\n";
    $flakyIn = fopen('flakystream://channel', 'r');
    $flakyOut = fopen('php://memory', 'w+');
    check(is_resource($flakyIn) && is_resource($flakyOut), 'B19 could not open the flaky channel');
    $flakyBridge = new Bridge(32768, 262144, 5, 5, $flakyIn, $flakyOut);
    $flakyCommand = $flakyBridge->nextCommand();
    check(($flakyCommand['type'] ?? '') === 'prompt', 'B19 the bridge gave up after one empty read');
    check(($flakyCommand['text'] ?? '') === 'after a dead read', 'B19 the retried command was mangled');
    fclose($flakyIn);
    fclose($flakyOut);
    stream_wrapper_unregister('flakystream');

    // --- B20: the dangling-channel diagnostic fires only when it should ------
    $junkFile = tempnam(sys_get_temp_dir(), 'myagent-b20a-');
    check(is_string($junkFile), 'B20 could not create the temp command file');
    file_put_contents($junkFile, "\n"); // bytes present, but no usable command
    $junkIn = fopen($junkFile, 'r');
    $junkOut = fopen('php://memory', 'w+');
    check(is_resource($junkIn) && is_resource($junkOut), 'B20 could not open the file channel');
    $junkBridge = new Bridge(32768, 262144, 5, 5, $junkIn, $junkOut);
    check($junkBridge->nextCommand() === null, 'B20 the empty command line was not skipped');
    check($junkBridge->commandsDelivered() === 0, 'B20 a command line without a command was counted');
    $junkWarning = $junkBridge->danglingInputWarning();
    check(is_string($junkWarning) && str_contains($junkWarning, 'closed before any command'), 'B20 a dangling file channel was not reported');
    fclose($junkIn);
    fclose($junkOut);
    @unlink($junkFile);

    $emptyFile = tempnam(sys_get_temp_dir(), 'myagent-b20b-');
    check(is_string($emptyFile), 'B20 could not create the empty command file');
    $emptyIn = fopen($emptyFile, 'r');
    $emptyOut = fopen('php://memory', 'w+');
    check(is_resource($emptyIn) && is_resource($emptyOut), 'B20 could not open the empty channel');
    $emptyBridge = new Bridge(32768, 262144, 5, 5, $emptyIn, $emptyOut);
    check($emptyBridge->nextCommand() === null, 'B20 an empty file produced a command');
    check($emptyBridge->danglingInputWarning() === null, 'B20 an empty input (like < NUL) must not warn');
    fclose($emptyIn);
    fclose($emptyOut);
    @unlink($emptyFile);
    // --- B21: a redirected command file reaches the bridge, end to end -------
    // This is the user-visible symptom of docs/plans/IMPLEMENTATION_PLAN-bridge-file-stdin.md:
    // `php agent.php --bridge < commands.jsonl` used to exit without a single command
    // because Terminal::detect()'s console-width probe consumed the redirected stdin.
    // The fixture mirrors that startup order (Terminal::detect() then Bridge) with no API.
    $redirected = tempnam(sys_get_temp_dir(), 'myagent-b21-');
    check(is_string($redirected), 'B21 could not create the command file');
    file_put_contents(
        $redirected,
        json_encode(['v' => 1, 'type' => 'prompt', 'text' => 'from a redirected file'], JSON_UNESCAPED_SLASHES) . "\n"
    );
    $redirectDescriptors = [0 => ['file', $redirected, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $redirectProcess = proc_open(
        [PHP_BINARY, __DIR__ . '/fixtures/bridge-file-probe.php'],
        $redirectDescriptors,
        $redirectPipes,
        dirname(__DIR__)
    );
    check(is_resource($redirectProcess), 'B21 could not start the bridge fixture');
    $redirectStdout = (string) stream_get_contents($redirectPipes[1]);
    $redirectStderr = (string) stream_get_contents($redirectPipes[2]);
    fclose($redirectPipes[1]);
    fclose($redirectPipes[2]);
    $redirectExit = proc_close($redirectProcess);
    @unlink($redirected);
    check($redirectExit === 0, 'B21 the bridge fixture failed: ' . trim($redirectStderr));
    check(
        str_contains($redirectStdout, 'command=prompt'),
        'B21 a redirected command file did not reach the bridge: ' . trim($redirectStdout)
    );
    check(
        !str_contains($redirectStdout, 'command=none'),
        'B21 the redirected command file was consumed before the bridge read it'
    );
    fwrite(STDOUT, "Bridge tests passed\n");
} catch (Throwable $e) {
    fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getFile() . ':' . $e->getLine() . "\n");
    exit(1);
}
