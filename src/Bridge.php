<?php
declare(strict_types=1);

namespace MyAgent;

use Closure;
use RuntimeException;

/**
 * Machine-facing transport for `agent.php --bridge`.
 *
 * STDOUT becomes a JSONL event stream and STDIN a JSONL command channel. The
 * bridge owns the ONLY reader of STDIN: the command loop, the ask_user hook and
 * the security-approval hook all pull through awaitCommand(). That single-reader
 * invariant is what keeps the protocol unambiguous.
 *
 * Rules enforced here:
 *  - every emitted line is one JSON object with "v":1, terminated by "\n";
 *  - no ANSI, no prose, ever (callers hand us raw payloads);
 *  - an over-long event is replaced by a warning so a pipe can never be flooded;
 *  - an over-long *command* line is dropped with a message on STDERR.
 */
final class Bridge
{
    public const VERSION = 1;

    private string $buffer = '';
    private bool $eof = false;
    private bool $streamsReady = false;
    private int $questionSeq = 0;
    private int $approvalSeq = 0;
    private ?float $lastProgress = null;
    /** @var array<string, mixed>|null A command that arrived while we waited for another one. */
    private ?array $held = null;

    /**
     * @param resource|null $in
     * @param resource|null $out
     */
    public function __construct(
        private int $maxEventBytes = 32768,
        private int $maxLineBytes = 262144,
        private int $questionTimeout = 900,
        private int $approvalTimeout = 300,
        $in = null,
        $out = null,
    ) {
        $this->in = $in ?? STDIN;
        $this->out = $out ?? STDOUT;
    }

    /** @var resource */
    private $in;

    /** @var resource */
    private $out;

    /** @return Closure(string,array): void */
    public function emitter(): Closure
    {
        return function (string $type, array $payload = []): void {
            $this->emit($type, $payload);
        };
    }

    /** Write one protocol line to STDOUT. Never throws on oversized payloads. */
    public function emit(string $type, array $payload = []): void
    {
        $event = ['v' => self::VERSION, 'type' => $type] + $payload;
        $line = json_encode(
            $event,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if (!is_string($line)) {
            $line = '{"v":1,"type":"warning","text":"unencodable event"}';
        }
        if (strlen($line) > $this->maxLineBytes) {
            $bytes = strlen($line);
            $line = (string) json_encode([
                'v' => self::VERSION,
                'type' => 'warning',
                'text' => 'event too large: ' . $type . ' ' . $bytes . ' bytes',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }
        @fwrite($this->out, $line . "\n");
        @fflush($this->out);
    }

    /**
     * Line reader for AskUser. Returns the answer text of the next `answer`
     * command, or null when the channel closes.
     *
     * @return Closure(): ?string
     */
    public function reader(): Closure
    {
        return function (): ?string {
            $command = $this->nextCommand();
            if ($command === null) {
                return null;
            }
            $type = (string) ($command['type'] ?? '');
            if ($type === 'answer') {
                return (string) ($command['answer'] ?? '');
            }
            if ($type === 'shutdown') {
                $this->held = $command;

                return null;
            }

            return null;
        };
    }

    /** Block until one command object arrives. Null on EOF. */
    public function nextCommand(): ?array
    {
        if ($this->held !== null) {
            $command = $this->held;
            $this->held = null;

            return $command;
        }

        return $this->awaitCommand(null, null);
    }

    /**
     * Wait for one decoded command.
     *
     * @param null|float     $deadline   absolute microtime(true) deadline
     * @param null|list<string> $types   accept only these types (null = any)
     * @return array<string, mixed>|null
     */
    public function awaitCommand(?float $deadline, ?array $types): ?array
    {
        if ($this->held !== null && ($types === null || in_array((string) ($this->held['type'] ?? ''), $types, true))) {
            $command = $this->held;
            $this->held = null;

            return $command;
        }

        while (true) {
            $line = $this->pollLine();
            if ($line !== null) {
                $command = $this->decodeLine($line);
                if ($command === null) {
                    continue;
                }
                $type = (string) $command['type'];
                if ($types !== null && !in_array($type, $types, true)) {
                    // `shutdown` is always honoured, whichever wait is active.
                    if ($type === 'shutdown') {
                        return $command;
                    }
                    fwrite(STDERR, "[bridge] dropping out-of-context command: {$type}\n");

                    continue;
                }

                return $command;
            }
            if ($this->eof) {
                return null;
            }
            if ($deadline !== null && microtime(true) >= $deadline) {
                return null;
            }
            usleep(5000);
        }
    }

    /** @return Closure(string,list<string>): ?string */
    public function askHook(): Closure
    {
        return function (string $question, array $options): ?string {
            $id = 'q' . (++$this->questionSeq);
            $this->emit('question', [
                'id' => $id,
                'question' => $question,
                'options' => array_values(array_map(static fn($option): string => (string) $option, $options)),
            ]);

            $deadline = microtime(true) + $this->questionTimeout;
            while (true) {
                $command = $this->awaitCommand($deadline, ['answer', 'cancel']);
                if ($command === null) {
                    $this->emit('question_closed', ['id' => $id, 'reason' => 'timeout']);
                    throw new RuntimeException('No answer arrived in time. Continue with your best judgment or explain what you need.');
                }
                $type = (string) ($command['type'] ?? '');
                if ($type === 'shutdown') {
                    $this->held = $command;
                    $this->emit('question_closed', ['id' => $id, 'reason' => 'cancelled']);
                    throw new RuntimeException('The user cancelled the question without answering. Do not ask it again.');
                }
                if ((string) ($command['id'] ?? '') !== $id) {
                    continue;
                }
                if ($type === 'cancel') {
                    $this->emit('question_closed', ['id' => $id, 'reason' => 'cancelled']);
                    throw new RuntimeException('The user cancelled the question without answering. Do not ask it again; continue with your best judgment or explain what you need.');
                }
                $answer = trim(str_replace(["\r", "\n"], ' ', (string) ($command['answer'] ?? '')));
                if ($answer === '') {
                    $this->emit('question_closed', ['id' => $id, 'reason' => 'cancelled']);
                    throw new RuntimeException('The user cancelled the question without answering. Do not ask it again; continue with your best judgment or explain what you need.');
                }
                $this->emit('question_closed', ['id' => $id, 'reason' => 'answered']);

                return $answer;
            }
        };
    }

    /** @return Closure(array,array): bool */
    public function approver(): Closure
    {
        return function (array $call, array $decision): bool {
            $id = 'a' . (++$this->approvalSeq);
            $this->emit('approval', [
                'id' => $id,
                'tool' => (string) ($call['name'] ?? 'unknown'),
                'input' => (array) ($call['input'] ?? []),
                'decision' => [
                    'verdict' => (string) ($decision['verdict'] ?? 'ask'),
                    'risk' => (string) ($decision['risk'] ?? 'unknown'),
                    'category' => (string) ($decision['category'] ?? 'other'),
                    'reason' => (string) ($decision['reason'] ?? ''),
                    'source' => (string) ($decision['source'] ?? ''),
                ],
            ]);

            $deadline = microtime(true) + $this->approvalTimeout;
            $verdict = 'timeout';
            while (true) {
                $command = $this->awaitCommand($deadline, ['verdict']);
                if ($command === null) {
                    break;
                }
                $type = (string) ($command['type'] ?? '');
                if ($type === 'shutdown') {
                    $this->held = $command;
                    break;
                }
                if ((string) ($command['id'] ?? '') !== $id) {
                    continue;
                }
                $verdict = strtolower(trim((string) ($command['verdict'] ?? 'deny'))) === 'allow' ? 'allow' : 'deny';
                break;
            }
            $this->emit('approval_closed', ['id' => $id, 'verdict' => $verdict]);

            return $verdict === 'allow';
        };
    }

    /** @return Closure(): void Throttled to at most one event per second. */
    public function progressHook(): Closure
    {
        return function (): void {
            $now = microtime(true);
            if ($this->lastProgress !== null && $now - $this->lastProgress < 1.0) {
                return;
            }
            $this->lastProgress = $now;
            $this->emit('progress', ['elapsed' => round($now, 1), 'bytes' => 0]);
        };
    }

    /** Cap a tool-result string for UI traffic; returns [text, truncated, trueBytes]. */
    public function capResult(string $text): array
    {
        $bytes = strlen($text);
        $limit = max(1024, $this->maxEventBytes);
        if ($bytes <= $limit) {
            return [$text, false, $bytes];
        }
        $prefix = function_exists('mb_strcut') ? mb_strcut($text, 0, $limit, 'UTF-8') : substr($text, 0, $limit);

        return [$prefix, true, $bytes];
    }

    // ---------------------------------------------------------------- internals

    private function ready(): void
    {
        if ($this->streamsReady) {
            return;
        }
        $this->streamsReady = true;
        if (is_resource($this->in)) {
            @stream_set_blocking($this->in, false);
        }
    }

    /** One complete buffered line, or one non-blocking read attempt. */
    private function pollLine(): ?string
    {
        $this->ready();
        $position = strpos($this->buffer, "\n");
        if ($position !== false) {
            $line = substr($this->buffer, 0, $position);
            $this->buffer = substr($this->buffer, $position + 1);

            return $line;
        }
        if ($this->eof) {
            return null;
        }
        $chunk = @fread($this->in, 65536);
        if ($chunk === false || $chunk === '') {
            if (!is_resource($this->in) || feof($this->in)) {
                $this->eof = true;
            }

            return null;
        }
        $this->buffer .= $chunk;
        if (strlen($this->buffer) > 4 * 1024 * 1024) {
            $this->buffer = '';
            fwrite(STDERR, "[bridge] command line exceeded 4 MiB and was dropped\n");

            return null;
        }
        $position = strpos($this->buffer, "\n");
        if ($position === false) {
            return null;
        }
        $line = substr($this->buffer, 0, $position);
        $this->buffer = substr($this->buffer, $position + 1);

        return $line;
    }

    /** @return array<string, mixed>|null */
    private function decodeLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }
        $decoded = json_decode($line, true);
        if (!is_array($decoded)) {
            fwrite(STDERR, "[bridge] ignoring non-object command line\n");

            return null;
        }
        if (($decoded['v'] ?? null) !== self::VERSION) {
            $this->emit('error', ['text' => 'unsupported protocol version']);
            @fflush($this->out);
            exit(3);
        }
        $type = $decoded['type'] ?? null;
        if (!is_string($type) || $type === '') {
            fwrite(STDERR, "[bridge] ignoring command without a type\n");

            return null;
        }
        unset($decoded['v']);

        return $decoded;
    }
}
