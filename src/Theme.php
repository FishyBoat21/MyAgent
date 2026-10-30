<?php
declare(strict_types=1);

namespace MyAgent;

/**
 * Color tokens and unicode/ascii symbols for terminal output.
 *
 * A Theme knows whether color is allowed (per stream) and which color depth
 * the terminal supports. style() always emits a single SGR sequence so callers
 * cannot accidentally cancel an outer style.
 */
final class Theme
{
    /** @var array<string, string> */
    private const STYLES_256 = [
        'accent' => '38;5;45',
        'user' => '38;5;111',
        'tool' => '38;5;141',
        'success' => '38;5;42',
        'warn' => '38;5;214',
        'error' => '38;5;203',
        'muted' => '38;5;244',
        'heading' => '1;38;5;45',
        'code' => '38;5;252;48;5;236',
        'code_border' => '38;5;240',
        'bold' => '1',
        'italic' => '3',
        'strike' => '9',
    ];

    /** @var array<string, string> */
    private const STYLES_16 = [
        'accent' => '36',
        'user' => '34',
        'tool' => '35',
        'success' => '32',
        'warn' => '33',
        'error' => '31',
        'muted' => '90',
        'heading' => '1;36',
        'code' => '30;47',
        'code_border' => '90',
        'bold' => '1',
        'italic' => '3',
        'strike' => '9',
    ];

    /** @var array<string, array{0: string, 1: string}> unicode, ascii */
    private const SYMBOLS = [
        'assistant' => ['⏺', '*'],
        'prompt' => ['❯', '>'],
        'tool' => ['⚙', '*'],
        'success' => ['✓', 'ok'],
        'error' => ['✖', 'x'],
        'warning' => ['⚠', '!'],
        'info' => ['◦', '-'],
        'bullet' => ['•', '-'],
        'bullet_nested' => ['◦', '*'],
        'bullet_deep' => ['▪', '-'],
        'task_done' => ['☑', '[x]'],
        'task_todo' => ['☐', '[ ]'],
        'question' => ['[?]', '[?]'],
        'star' => ['★', '*'],
        'h1' => ['◆', '#'],
        'h2' => ['▸', '##'],
        'h3' => ['▹', '###'],
    ];

    public function __construct(
        private bool $color,
        private bool $unicode,
        private int $depth = 256,
    ) {
        if ($this->color && $this->depth !== 16) {
            $this->depth = 256;
        }
    }

    public function color(): bool
    {
        return $this->color;
    }

    public function unicode(): bool
    {
        return $this->unicode;
    }

    public function depth(): int
    {
        return $this->color ? $this->depth : 0;
    }

    /** Apply one or more named styles; no-op when color is disabled. */
    public function style(string $text, string ...$styles): string
    {
        if (!$this->color || $text === '') {
            return $text;
        }
        $map = $this->depth === 16 ? self::STYLES_16 : self::STYLES_256;
        $codes = [];
        foreach ($styles as $style) {
            if ($style === 'reset') {
                $codes[] = '0';
            } elseif (isset($map[$style])) {
                $codes[] = $map[$style];
            }
        }
        if ($codes === []) {
            return $text;
        }
        return "\x1B[" . implode(';', $codes) . 'm' . $text . "\x1B[0m";
    }

    /** Symbol name to glyph, honoring the unicode/ascii preference. */
    public function symbol(string $name): string
    {
        $pair = self::SYMBOLS[$name] ?? null;
        if ($pair === null) {
            return $name;
        }
        return $this->unicode ? $pair[0] : $pair[1];
    }
}
