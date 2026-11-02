<?php
declare(strict_types=1);

/**
 * Stop-current-execution tests: the Cancellation flag, its out-of-band poller,
 * the curl abort, the killed command, the agent's recovery of a stopped turn,
 * and the real Ctrl+C handler. No API call and no external network: the one
 * transfer test talks to a socket this process opens itself.
 */

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Dependencies are not installed. Run: composer install\n");
    exit(1);
}
require_once $autoload;

use MyAgent\Agent;
use MyAgent\AnthropicClient;
use MyAgent\Bridge;
use MyAgent\Cancellation;
use MyAgent\ExecutionStopped;
use MyAgent\Interrupt;
use MyAgent\LocalTools;
use MyAgent\Skills;
use MyAgent\SessionStore;
use MyAgent\Terminal;

if (!function_exists('check')) {
    function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}

$root = dirname(__DIR__);
$skills = new Skills($root . '/tests/fixtures');
$local = new LocalTools($root, $skills);

/** @param callable(): mixed $run */
$stopOf = static function (callable $run): string {
    try {
        $run();
    } catch (ExecutionStopped $e) {
        return $e->getMessage();
    }
    throw new RuntimeException('Expected an ExecutionStopped');
};


// --- C1/C2: the flag itself --------------------------------------------------

$cancel = new Cancellation();
check(!$cancel->active() && !$cancel->requested(), 'C1 a fresh Cancellation is already armed');
$cancel->beginTurn();
check($cancel->active() && !$cancel->requested(), 'C1 beginTurn did not arm an unrequested turn');
check($cancel->request('ctrl-c') === true, 'C1 the first request was refused');
check($cancel->requested() && $cancel->active(), 'C1 the turn lost its stop request');
check($cancel->request('bridge') === false, 'C1 a second request overwrote the first');
check($cancel->reason() === 'ctrl-c', 'C1 the first reason was replaced');
check(str_contains($cancel->message(), 'Ctrl+C'), 'C1 the message does not name Ctrl+C');
check(str_contains($stopOf(static fn() => $cancel->throwIfRequested()), 'Ctrl+C'), 'C1 throwIfRequested stayed silent');
$cancel->endTurn();
check(!$cancel->active() && !$cancel->requested(), 'C1 endTurn did not disarm the turn');
$cancel->throwIfRequested();
$cancel->beginTurn();
$cancel->request();
check($cancel->message() === 'Stopped.', 'C1 the default stop message is wrong');
$cancel->request('bridge');
$cancel->endTurn();

// The poller is the out-of-band source (the bridge channel). It runs at most
// ten times a second and can never break the run.
/** @var list<float> $pollStamps */
$pollStamps = [];
$poller = new Cancellation();
$poller->setPoller(static function () use (&$pollStamps): void {
    $pollStamps[] = microtime(true);
});
$pollingStarted = microtime(true);
for ($i = 0; $i < 40; $i++) {
    $poller->poll();
    usleep(10000);
}
$pollingElapsed = microtime(true) - $pollingStarted;
check($pollStamps !== [], 'C2 the poller never ran');
// At most ten polls per second: the count and the spacing must both agree.
check(
    count($pollStamps) <= (int) ceil($pollingElapsed / 0.1) + 1,
    'C2 the poller was not throttled: ' . count($pollStamps) . ' polls in ' . round($pollingElapsed, 2) . 's'
);
for ($i = 1; $i < count($pollStamps); $i++) {
    check(
        $pollStamps[$i] - $pollStamps[$i - 1] >= 0.08,
        'C2 two polls were ' . round($pollStamps[$i] - $pollStamps[$i - 1], 3) . 's apart'
    );
}
$poller->setPoller(static function (): void {
    throw new RuntimeException('a broken channel');
});
$poller->poll();
check(!$poller->requested(), 'C2 a throwing poller raised a stop');
$bridgePoller = new Cancellation();
$bridgePoller->setPoller(static function () use ($bridgePoller): void {
    $bridgePoller->request('bridge');
});
$bridgePoller->poll();
check($bridgePoller->requested() && $bridgePoller->reason() === 'bridge', 'C2 the poller could not raise a stop');
$bridgePoller->poll();
check($bridgePoller->reason() === 'bridge', 'C2 a poll after the stop changed the reason');

// The real out-of-band source: a `cancel` on the bridge command channel. A
// command that is not a stop must survive the poll, in arrival order.
$channelIn = fopen('php://memory', 'r+');
fwrite($channelIn, json_encode(['v' => 1, 'type' => 'prompt', 'text' => 'later']) . "\n");
fwrite($channelIn, json_encode(['v' => 1, 'type' => 'cancel']) . "\n");
rewind($channelIn);
$channelOut = fopen('php://memory', 'w+');
$channel = new Bridge(32768, 262144, 5, 5, $channelIn, $channelOut);
$channelStop = new Cancellation();
$channelStop->beginTurn();
$channelStop->setPoller(static function () use ($channel, $channelStop): void {
    if ($channel->pollStop()) {
        $channelStop->request('bridge');
    }
});
$channelStop->poll();
check($channelStop->requested() && $channelStop->reason() === 'bridge', 'C2 the bridge poller did not raise a stop');
check(str_contains($channelStop->message(), 'cancel command'), 'C2 the bridge stop message is wrong');
$queuedPrompt = $channel->nextCommand();
check(($queuedPrompt['type'] ?? '') === 'prompt' && ($queuedPrompt['text'] ?? '') === 'later', 'C2 the poll lost a queued command');
check($channel->nextCommand() === null, 'C2 the stop was not consumed from the channel');
fclose($channelIn);
fclose($channelOut);

// --- C3: nothing is sent once the user has stopped ---------------------------

$idle = new Cancellation();
$idle->beginTurn();
$idle->request('ctrl-c');
$blockedClient = new AnthropicClient('key', 'http://127.0.0.1:9/v1/messages');
$blockedClient->setCancellation($idle);
$started = microtime(true);
check(
    str_contains($stopOf(static fn() => $blockedClient->message('m', 16, 'sys', [['role' => 'user', 'content' => 'hi']], [])), 'Ctrl+C'),
    'C3 a stopped client still tried to send'
);
check(microtime(true) - $started < 5, 'C3 the stopped client waited instead of aborting');

// --- C4: a transfer in flight is aborted, not waited out ---------------------

$server = @stream_socket_server('tcp://127.0.0.1:0', $socketError, $socketMessage);
if (is_resource($server)) {
    $address = (string) stream_socket_get_name($server, false);
    $transfer = new Cancellation();
    $transfer->beginTurn();
    $polls = 0;
    $transfer->setPoller(static function () use ($transfer, &$polls): void {
        // Let curl get the connection up, then stop the request from inside its
        // own progress callback - exactly where a real Ctrl+C lands.
        if (++$polls >= 2) {
            $transfer->request('ctrl-c');
        }
    });
    $hanging = new AnthropicClient('key', 'http://' . $address . '/v1/messages', 'x-api-key', '', '2023-06-01', 20);
    $hanging->setCancellation($transfer);
    $started = microtime(true);
    $message = $stopOf(static fn() => $hanging->message('m', 16, 'sys', [['role' => 'user', 'content' => 'hi']], []));
    $elapsed = microtime(true) - $started;
    check(str_contains($message, 'Ctrl+C'), 'C4 the aborted transfer did not report a stop');
    check($transfer->requested(), 'C4 the transfer finished without noticing the stop');
    check($elapsed < 15, 'C4 the aborted transfer took ' . round($elapsed, 1) . 's');
    fclose($server);
} else {
    // No loopback socket available: the abort path is still covered by C3 and C7.
    fwrite(STDERR, "C4 skipped: cannot open a loopback socket ({$socketMessage})\n");
}

// --- C5/C6: run_command ------------------------------------------------------

$marker = 'tests/.cancel-should-not-exist.txt';
@unlink($root . '/' . $marker);
$preStopped = new Cancellation();
$tools = new LocalTools($root, $skills, 300);
$tools->setCancellation($preStopped);
$preStopped->beginTurn();
$preStopped->request('ctrl-c');
$started = microtime(true);
$refused = $stopOf(static fn() => $tools->call('run_command', [
    'command' => 'php -r "file_put_contents(\'' . $marker . '\', \'ran\');"',
]));
check(str_contains($refused, 'Stopped'), 'C5 a stopped tool did not raise a stop');
check(microtime(true) - $started < 2, 'C5 a stopped tool still started the command');
check(!is_file($root . '/' . $marker), 'C5 a stopped tool ran the command anyway');

$running = new Cancellation();
$runningTools = new LocalTools($root, $skills, 300);
$runningTools->setCancellation($running);
$running->beginTurn();
// The poller stands in for the console handler: it fires on the first poll,
// while the child process is already running.
$running->setPoller(static function () use ($running): void {
    $running->request('ctrl-c');
});
$started = microtime(true);
$killed = $stopOf(static fn() => $runningTools->call('run_command', [
    'command' => 'php -r "usleep(4000000); file_put_contents(\'' . $marker . '\', \'late\');"',
]));
$elapsed = microtime(true) - $started;
check(str_contains($killed, 'stopped'), 'C6 a running command did not report the stop: ' . $killed);
check($elapsed < 3.5, 'C6 the command was not killed early enough (' . round($elapsed, 1) . 's)');
check(!is_file($root . '/' . $marker), 'C6 the killed command still wrote its output');
@unlink($root . '/' . $marker);

// --- C7: a stop during the model call ends the turn cleanly -------------------

/** Captures the plain-mode lines an agent writes to STDERR. */
$recorder = static function (string &$stderr): Terminal {
    $stderr = '';
    return new Terminal(
        false,
        false,
        ['plain' => true, 'color' => 'never', 'unicode' => 'never', 'markdown' => false, 'usage' => false],
        static function (string $text): void {
        },
        static function (string $text) use (&$stderr): void {
            $stderr .= $text;
        }
    );
};

$sessionName = 'cancel-' . getmypid();
$store = new SessionStore($root, $sessionName);
$sessionFile = $root . '/.agent/sessions/' . $sessionName . '.json';
try {
    $uiErr = '';
    $ui = $recorder($uiErr);
    $callStop = new Cancellation();
    $callStop->setInterruptible(true);
    $aborting = new class('unused', $callStop) extends AnthropicClient {
        public int $calls = 0;
        public function __construct(string $key, private Cancellation $cancel)
        {
            parent::__construct($key);
        }
        public function message(string $model, int $maxTokens, string $system, array $messages, array $tools): array
        {
            $this->calls++;
            $this->cancel->request('ctrl-c'); // the console handler
            $this->cancel->throwIfRequested(); // the aborted transfer
            return ['content' => [['type' => 'text', 'text' => 'never']], 'stop_reason' => 'end_turn'];
        }
    };
    $agent = new Agent($aborting, $local, [], 'test', 100, 4, $store, 24000, 2048, null, $ui);
    $agent->setCancellation($callStop);
    check($agent->run('summarise the project') === false, 'C7 a stopped turn reported completion');
    check($agent->lastOutcome() === 'stopped', 'C7 the outcome is not "stopped": ' . $agent->lastOutcome());
    check($aborting->calls === 1, 'C7 the agent kept calling the model after the stop');
    check(str_contains($uiErr, 'Stopped with Ctrl+C'), 'C7 the stop was not reported: ' . trim($uiErr));
    check(str_contains($uiErr, '/continue'), 'C7 the resume hint is missing: ' . trim($uiErr));
    $checkpoint = $store->load();
    check(($checkpoint['in_progress'] ?? false) === true, 'C7 a stopped turn was checkpointed as finished');
    check(count((array) ($checkpoint['messages'] ?? [])) === 1, 'C7 the stopped turn did not keep its user message');
    check(!$callStop->requested(), 'C7 the stop leaked past the turn');
    check(!Interrupt::armed(), 'C7 the stopped turn left the Ctrl+C handler armed');

    // --- C8: a stop in the middle of a tool round ---------------------------
    $roundCancel = new Cancellation();
    $roundActor = new class('unused') extends AnthropicClient {
        public int $calls = 0;
        /** @var array<int, array<string, mixed>> */
        public array $lastMessages = [];
        public string $target = 'tests/.cancel-round.txt';
        public function message(string $model, int $maxTokens, string $system, array $messages, array $tools): array
        {
            $this->calls++;
            $this->lastMessages = $messages;
            if ($this->calls === 1) {
                return ['content' => [
                    ['type' => 'tool_use', 'id' => 'round-1', 'name' => 'read_file', 'input' => ['path' => 'config.example.json']],
                    ['type' => 'tool_use', 'id' => 'round-2', 'name' => 'write_file', 'input' => ['path' => $this->target, 'content' => 'never']],
                ], 'stop_reason' => 'tool_use'];
            }
            return ['content' => [['type' => 'text', 'text' => 'carried on']], 'stop_reason' => 'end_turn'];
        }
    };
    @unlink($root . '/' . $roundActor->target);
    $eventTypes = [];
    $roundUi = new Terminal(
        false,
        false,
        ['plain' => true, 'color' => 'never', 'unicode' => 'never', 'markdown' => false, 'usage' => false],
        static function (string $text): void {
        },
        static function (string $text): void {
        },
        // The first tool result is where the user hits Ctrl+C.
        static function (string $type, array $payload) use (&$eventTypes, $roundCancel): void {
            $eventTypes[] = $type;
            if ($type === 'tool_result') {
                $roundCancel->request('ctrl-c');
            }
        }
    );
    $roundAgent = new Agent($roundActor, $local, [], 'test', 100, 4, $store, 24000, 2048, null, $roundUi);
    $roundAgent->setCancellation($roundCancel);
    check($roundAgent->run('read then write') === false, 'C8 a stopped tool round reported completion');
    check($roundAgent->lastOutcome() === 'stopped', 'C8 the tool round did not end as stopped');
    check($roundActor->calls === 1, 'C8 the agent asked the model again after the stop');
    check(!is_file($root . '/' . $roundActor->target), 'C8 the stopped tool round still wrote a file');
    // The checkpoint is what a resumed session would see: the tool round must be
    // closed there, not only in memory.
    $roundMessages = (array) ((array) $store->load())['messages'];
    $lastBlock = $roundMessages === [] ? [] : (array) ($roundMessages[count($roundMessages) - 1]['content'] ?? []);
    check(count($roundMessages) === 3, 'C8 the stopped tool round has the wrong message count: ' . count($roundMessages));
    check(count($lastBlock) === 2, 'C8 the tool round was not closed with two results: ' . count($lastBlock));
    check(($lastBlock[0]['tool_use_id'] ?? '') === 'round-1', 'C8 the first result came from the wrong call');
    check(!($lastBlock[0]['is_error'] ?? false) && str_contains((string) ($lastBlock[0]['content'] ?? ''), 'mcpServers'), 'C8 the executed tool result was lost');
    check(($lastBlock[1]['tool_use_id'] ?? '') === 'round-2', 'C8 the unanswered call was not closed');
    check(($lastBlock[1]['is_error'] ?? false) === true, 'C8 the skipped call was not flagged as an error');
    check(str_contains((string) ($lastBlock[1]['content'] ?? ''), 'NOT executed'), 'C8 the skipped call is not explained');
    check(in_array('tool_start', $eventTypes, true) && count(array_keys($eventTypes, 'tool_start', true)) === 1, 'C8 more tools ran than the one started');

    // --- C9: the session stays usable after a stop --------------------------
    check($roundAgent->run('carry on') === true, 'C9 the turn after a stop did not complete');
    $carriedOn = $roundActor->lastMessages;
    $roles = array_map(static fn(array $message): string => (string) ($message['role'] ?? ''), $carriedOn);
    check($roles === ['user', 'assistant', 'user'], 'C9 the roles do not alternate after a stop: ' . implode(',', $roles));
    $merged = (array) ($carriedOn[2]['content'] ?? []);
    check(count($merged) === 3, 'C9 the new prompt was not merged into the open user message');
    check(($merged[2]['type'] ?? '') === 'text' && ($merged[2]['text'] ?? '') === 'carry on', 'C9 the merged prompt is wrong');
} finally {
    unset($store);
    @unlink($sessionFile);
    @unlink($root . '/.agent/sessions/' . $sessionName . '.lock');
    @unlink($root . '/tests/.cancel-round.txt');
}

// --- C10: a cancel command stops a real request that is in flight ------------

$hangServer = @stream_socket_server('tcp://127.0.0.1:0', $hangError, $hangMessage);
if (is_resource($hangServer)) {
    $hangAddress = (string) stream_socket_get_name($hangServer, false);
    // A supervisor that sends `cancel` a moment after the prompt: the turn is
    // already waiting on the provider when the command arrives.
    $supervisor = proc_open(
        [PHP_BINARY, '-r', 'usleep(400000); echo json_encode(["v" => 1, "type" => "cancel"]), "\n";'],
        [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['file', 'NUL', 'w']],
        $supervisorPipes,
        $root
    );
    check(is_resource($supervisor), 'C10 could not start the supervisor');
    $bridgeOut = fopen('php://memory', 'w+');
    $liveBridge = new Bridge(32768, 262144, 5, 5, $supervisorPipes[1], $bridgeOut);
    $bridgeCancel = new Cancellation();
    $bridgeCancel->setPoller(static function () use ($liveBridge, $bridgeCancel): void {
        if ($liveBridge->pollStop()) {
            $bridgeCancel->request('bridge');
        }
    });
    $waiting = new AnthropicClient('key', 'http://' . $hangAddress . '/v1/messages', 'x-api-key', '', '2023-06-01', 30);
    $stopErr = '';
    $stopUi = new Terminal(false, false, ['plain' => true, 'color' => 'never', 'unicode' => 'never'], static function (): void {
    }, static function (string $text) use (&$stopErr): void {
        $stopErr .= $text;
    });
    $stopAgent = new Agent($waiting, $local, [], 'test', 100, 4, null, 24000, 2048, null, $stopUi);
    $stopAgent->setCancellation($bridgeCancel);
    $started = microtime(true);
    check($stopAgent->run('a long request') === false, 'C10 the cancelled turn reported completion');
    $elapsed = microtime(true) - $started;
    check($stopAgent->lastOutcome() === 'stopped', 'C10 the cancelled turn did not end as stopped');
    check(str_contains($stopErr, 'cancel command'), 'C10 the stop did not come from the command channel: ' . trim($stopErr));
    check($elapsed < 20, 'C10 the request was not aborted (' . round($elapsed, 1) . 's)');
    fclose($supervisorPipes[1]);
    proc_close($supervisor);
    fclose($bridgeOut);
    fclose($hangServer);
} else {
    fwrite(STDERR, "C10 skipped: cannot open a loopback socket ({$hangMessage})\n");
}

// --- C11: the real Ctrl+C handler --------------------------------------------

check(Interrupt::supported(), 'C11 this PHP build exposes no console or signal API');
check(Interrupt::arm(new Cancellation()) === true, 'C11 the interrupt handler could not be installed');
check(Interrupt::armed(), 'C11 arming left no handler installed');
Interrupt::disarm();
check(!Interrupt::armed(), 'C11 disarming left the handler installed');

/**
 * Start the interrupt fixture in its own console process group, send it
 * $events CTRL_BREAK events, and report its exit code and output.
 *
 * @return array{code: int, output: string}
 */
$runInterruptProbe = static function (string $mode, int $events) use ($root): array {
    $process = proc_open(
        [PHP_BINARY, __DIR__ . '/fixtures/interrupt-probe.php', $mode],
        [0 => ['file', 'NUL', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        null,
        ['create_process_group' => true]
    );
    if (!is_resource($process)) {
        throw new RuntimeException('C11 could not start the interrupt probe');
    }
    $status = proc_get_status($process);
    $childPid = (int) ($status['pid'] ?? 0);
    // Let the child install the handler and start blocking. Reading the pipe
    // first would block until EOF on Windows, which is only after the probe ends.
    usleep(700000);
    for ($event = 0; $event < $events; $event++) {
        // CTRL_BREAK can be targeted at one process group; CTRL_C would reach
        // every process on this console, including the test runner.
        sapi_windows_generate_ctrl_event(PHP_WINDOWS_EVENT_CTRL_BREAK, $childPid);
        if ($event + 1 < $events) {
            usleep(300000);
        }
    }
    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['code' => proc_close($process), 'output' => $output];
};

if (PHP_OS_FAMILY === 'Windows' && function_exists('sapi_windows_generate_ctrl_event')) {
    // A turn is running: the event stops the run and the process survives.
    $stoppedProbe = $runInterruptProbe('stop', 1);
    check(
        str_contains($stoppedProbe['output'], 'armed=1'),
        'C11 the probe did not arm the handler: ' . trim($stoppedProbe['output'])
    );
    check(
        str_contains($stoppedProbe['output'], 'cancelled=1'),
        'C11 Ctrl+C did not reach the run: ' . trim($stoppedProbe['output'])
    );
    check(
        str_contains($stoppedProbe['output'], 'reason=ctrl-c'),
        'C11 the stop did not record Ctrl+C: ' . trim($stoppedProbe['output'])
    );
    check(
        str_contains($stoppedProbe['output'], 'alive'),
        'C11 the process died instead of stopping the run: ' . trim($stoppedProbe['output'])
    );

    // Nothing is running: no handler is installed, so Ctrl+C must quit through
    // the OS default, exactly as it did before this feature existed.
    $idleProbe = $runInterruptProbe('idle', 1);
    check(
        str_contains($idleProbe['output'], 'armed=0'),
        'C11 the idle probe left a handler armed: ' . trim($idleProbe['output'])
    );
    check(
        $idleProbe['code'] !== 0,
        'C11 Ctrl+C with nothing running did not exit (code ' . $idleProbe['code'] . '): ' . trim($idleProbe['output'])
    );
    check(
        !str_contains($idleProbe['output'], 'alive'),
        'C11 Ctrl+C with nothing running let the process continue'
    );

    // A second Ctrl+C while the stop is pending is the hard exit, so a wedged
    // tool can never trap the terminal.
    $twiceProbe = $runInterruptProbe('twice', 2);
    check(
        $twiceProbe['code'] === Interrupt::EXIT_CODE,
        'C11 a second Ctrl+C did not exit (code ' . $twiceProbe['code'] . '): ' . trim($twiceProbe['output'])
    );
    check(!str_contains($twiceProbe['output'], 'alive'), 'C11 a second Ctrl+C let the run continue');
} else {
    fwrite(STDERR, "C11 skipped: no way to send a console event on this platform\n");
}
// --- C12: Ctrl+C is armed exactly for the duration of a turn -----------------

$scoped = new Cancellation();
$scoped->setInterruptible(true);
check(!Interrupt::armed(), 'C12 an idle interruptible turn armed Ctrl+C');
$scoped->beginTurn();
check(Interrupt::armed(), 'C12 beginTurn did not arm Ctrl+C');
$scoped->endTurn();
check(!Interrupt::armed(), 'C12 endTurn left Ctrl+C armed');
for ($turn = 1; $turn <= 3; $turn++) {
    $scoped->beginTurn();
    check(Interrupt::armed(), 'C12 turn ' . $turn . ' was not armed');
    $scoped->endTurn();
    check(!Interrupt::armed(), 'C12 turn ' . $turn . ' left Ctrl+C armed');
}

$armedDuringTurn = new class('unused') extends AnthropicClient {
    public int $calls = 0;
    public function message(string $model, int $maxTokens, string $system, array $messages, array $tools): array
    {
        $this->calls++;
        check(Interrupt::armed(), 'C12 Ctrl+C was not armed while the turn was running');
        return ['content' => [['type' => 'text', 'text' => 'ok']], 'stop_reason' => 'end_turn'];
    }
};
$quietTerminal = new Terminal(
    false,
    false,
    ['plain' => true, 'color' => 'never', 'unicode' => 'never', 'markdown' => false, 'usage' => false],
    static function (string $text): void {
    },
    static function (string $text): void {
    }
);
$scopedAgent = new Agent($armedDuringTurn, $local, [], 'test', 100, 2, null, 24000, 2048, null, $quietTerminal);
$scopedAgent->setCancellation($scoped);
check($scopedAgent->run('hello') === true, 'C12 the turn did not complete');
check($armedDuringTurn->calls === 1, 'C12 the turn made no request');
check(!Interrupt::armed(), 'C12 the Ctrl+C handler stayed armed after the turn');
check(!$scoped->requested(), 'C12 a completed turn kept a stop flag');

// A forced exit is only a request until the main thread polls.
$forced = new Cancellation();
$forced->beginTurn();
$forced->forceExit();
check($forced->forceExitRequested(), 'C12 forceExit did not record the request');
$forced->endTurn();
check(!$forced->forceExitRequested(), 'C12 a new turn inherited a forced exit');

fwrite(STDOUT, "Cancellation tests passed\n");
