<?php
declare(strict_types=1);

namespace MyAgent;

/**
 * Throttled single-line spinner on STDERR. It is driven by AnthropicClient's
 * curl progress callback, so it animates while the blocking request runs.
 */
final class Spinner
{
    private const UNICODE_FRAMES = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];
    private const ASCII_FRAMES = ['|', '/', '-', '\\'];

    private bool $active = false;
    private float $started = 0.0;
    private float $lastTick = 0.0;
    private int $frameIndex = 0;

    public function __construct(
        private Terminal $terminal,
        private string $label = 'Thinking',
    ) {
    }

    public function start(): void
    {
        if (!$this->terminal->spinnerEnabled()) {
            return;
        }
        $this->active = true;
        $this->started = microtime(true);
        $this->lastTick = 0.0;
        $this->frameIndex = 0;
        $this->tick(true);
    }

    public function tick(bool $force = false): void
    {
        if (!$this->active) {
            return;
        }
        $now = microtime(true);
        if (!$force && $now - $this->lastTick < 0.1) {
            return;
        }
        $this->lastTick = $now;
        $frames = $this->terminal->unicode() ? self::UNICODE_FRAMES : self::ASCII_FRAMES;
        $frame = $frames[$this->frameIndex % count($frames)];
        $this->frameIndex++;
        $elapsed = $now - $this->started;
        $text = $this->terminal->styleErr($frame, 'accent')
            . ' ' . $this->terminal->styleErr($this->label, 'muted')
            . ' ' . $this->terminal->styleErr(number_format($elapsed, 1) . 's', 'muted');
        $this->terminal->progressLine($text);
    }

    public function stop(): void
    {
        if (!$this->active) {
            return;
        }
        $this->active = false;
        $this->terminal->progressClear();
    }

    public function elapsed(): float
    {
        return $this->active ? microtime(true) - $this->started : 0.0;
    }
}
