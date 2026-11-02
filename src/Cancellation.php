<?php
declare(strict_types=1);

namespace MyAgent;

use Closure;
use RuntimeException;
use Throwable;

/**
 * The run was stopped on purpose. Never a failure: the turn ends early, the
 * session is kept, and nothing else is treated as broken.
 */
final class ExecutionStopped extends RuntimeException
{
}

/**
 * A stop request for the execution in progress.
 *
 * Exactly one instance exists per process. A stop is raised from a context that
 * must not throw - the console control handler, or the bridge command channel -
 * and is read at every safe point of a turn:
 *
 *  - before a model request and inside its curl progress callback, so a request
 *    already in flight is aborted instead of waited out;
 *  - before every tool call and inside the run_command wait loop, so a long
 *    command is killed instead of finished;
 *  - between steps, so nothing new starts after a stop.
 *
 * The flag is armed per turn: beginTurn() clears it, endTurn() disarms it.
 * Interrupts are armed for the same window (see setInterruptible()), so outside
 * a turn Ctrl+C keeps its historical meaning of "exit the process" - handled by
 * the OS default, not by us.
 */
final class Cancellation
{
    private bool $requested = false;
    private bool $active = false;
    private bool $interruptible = false;
    private bool $forceExit = false;
    private string $reason = '';
    /** @var null|Closure(): void Out-of-band stop source (the bridge channel). */
    private ?Closure $poller = null;
    private float $lastPoll = 0.0;

    /**
     * Let Ctrl+C stop the turns of this process. The hidden state lives here,
     * not in the caller, so a turn can never end with the handler still armed.
     */
    public function setInterruptible(bool $enabled): void
    {
        $this->interruptible = $enabled;
        if (!$enabled) {
            $this->forceExit = false;
        }
    }

    /** Arm the flag for one turn; a stop raised earlier is forgotten. */
    public function beginTurn(): void
    {
        $this->active = true;
        $this->requested = false;
        $this->forceExit = false;
        $this->reason = '';
        $this->lastPoll = 0.0;
        if ($this->interruptible) {
            Interrupt::arm($this);
        }
    }

    /** Disarm: the turn is over, so nothing can be stopped any more. */
    public function endTurn(): void
    {
        $this->active = false;
        $this->requested = false;
        $this->forceExit = false;
        $this->reason = '';
        if ($this->interruptible) {
            // Back to the OS default, so Ctrl+C exits again.
            Interrupt::disarm();
        }
    }

    /** True while a turn is running. */
    public function active(): bool
    {
        return $this->active;
    }

    /**
     * Raise a stop. Idempotent: the first request wins and keeps its reason.
     *
     * @return bool True when this call raised the stop, false when one was pending.
     */
    public function request(string $reason = 'user'): bool
    {
        if ($this->requested) {
            return false;
        }
        $this->requested = true;
        $this->reason = $reason;
        return true;
    }

    public function requested(): bool
    {
        return $this->requested;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    /** One human-facing line for the stop, naming where it came from. */
    public function message(): string
    {
        return match ($this->reason) {
            'ctrl-c' => 'Stopped with Ctrl+C.',
            'bridge' => 'Stopped by a cancel command.',
            default => 'Stopped.',
        };
    }

    /** @throws ExecutionStopped when a stop is pending. */
    public function throwIfRequested(): void
    {
        if ($this->requested) {
            throw new ExecutionStopped($this->message());
        }
    }

    /**
     * Ask the process to exit at its next safe point: the second Ctrl+C while a
     * stop is already pending. The exit itself has to happen on the main thread;
     * see Interrupt for why the handler must not do it.
     */
    public function forceExit(): void
    {
        $this->forceExit = true;
    }

    public function forceExitRequested(): bool
    {
        return $this->forceExit;
    }

    /**
     * Install the out-of-band stop source (the bridge command channel). It is
     * polled from the wait loops at most ten times a second, and must never
     * throw or block.
     *
     * @param null|Closure(): void $poller
     */
    public function setPoller(?Closure $poller): void
    {
        $this->poller = $poller;
        $this->lastPoll = 0.0;
    }

    /** Give the poller a chance to raise a stop, and honour a forced exit. */
    public function poll(): void
    {
        if ($this->forceExit) {
            // A deliberate, user-requested exit from the main thread: shutdown
            // functions still run, so the terminal is restored.
            @fwrite(STDERR, PHP_EOL);
            exit(Interrupt::EXIT_CODE);
        }
        if ($this->poller === null || $this->requested) {
            return;
        }
        $now = microtime(true);
        if ($now - $this->lastPoll < 0.1) {
            return;
        }
        $this->lastPoll = $now;
        try {
            ($this->poller)();
        } catch (Throwable) {
            // A broken stop channel must never break the run itself.
        }
    }
}

/**
 * Ctrl+C, wired to a stop request while a turn is running.
 *
 * The handler is installed for the duration of a turn and removed afterwards,
 * which is the whole trick: PHP cannot interrupt a blocking curl transfer from
 * userland, so the handler must exist while a request is in flight, but once PHP
 * owns the console handler the process can no longer be killed by Ctrl+C at all
 * (measured: a handler that returns false is ignored, and exit() from the
 * console thread deadlocks when the main thread is blocked in a read). So an
 * idle process keeps the OS default - Ctrl+C still exits, exactly as before -
 * and a running turn only ever sets a flag.
 *
 * A second Ctrl+C during a turn sets a forced exit that the main thread performs
 * at its next poll, so a wedged tool cannot trap the terminal without any thread
 * trying to unwind the VM from the outside.
 */
final class Interrupt
{
    /** 128 + SIGINT: the shell convention for Ctrl+C. */
    public const EXIT_CODE = 130;

    /** @var null|Closure(int): bool The callable currently installed. */
    private static ?Closure $handler = null;

    /** True when this platform can turn Ctrl+C into a stop request. */
    public static function supported(): bool
    {
        return function_exists('sapi_windows_set_ctrl_handler')
            || (function_exists('pcntl_signal') && function_exists('pcntl_async_signals'));
    }

    /** True while a Ctrl+C handler is installed. */
    public static function armed(): bool
    {
        return self::$handler !== null;
    }

    /** Watch for Ctrl+C until disarm(). Idempotent, and safe outside a terminal. */
    public static function arm(Cancellation $cancel): bool
    {
        if (self::$handler !== null) {
            return true;
        }
        $handler = static function (int $event) use ($cancel): bool {
            if (!$cancel->request('ctrl-c')) {
                // Already stopping: this press means "quit now", which the main
                // thread performs at its next safe point.
                $cancel->forceExit();
            }
            return true; // keep the process alive: the stop unwinds the turn
        };
        if (function_exists('sapi_windows_set_ctrl_handler')) {
            if (!@sapi_windows_set_ctrl_handler($handler)) {
                return false;
            }
            self::$handler = $handler;
            return true;
        }
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            if (!pcntl_signal(SIGINT, static function () use ($handler): void {
                $handler(SIGINT);
            })) {
                return false;
            }
            self::$handler = $handler;
            return true;
        }
        return false;
    }

    /** Back to the platform default, where Ctrl+C ends the process. */
    public static function disarm(): void
    {
        $handler = self::$handler;
        self::$handler = null;
        if ($handler === null) {
            return;
        }
        if (function_exists('sapi_windows_set_ctrl_handler')) {
            @sapi_windows_set_ctrl_handler($handler, false);
            return;
        }
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, SIG_DFL);
        }
    }
}
