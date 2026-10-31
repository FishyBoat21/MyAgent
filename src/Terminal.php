<?php
declare(strict_types=1);

namespace MyAgent;

use Closure;

/**
 * Session renderer. Owns STDOUT/STDERR writers, capability detection, and all
 * human-facing chrome. Text passed here is untrusted and is sanitized before
 * it reaches a terminal.
 */
final class Terminal
{
    private const DEFAULTS = [
        'color' => 'auto',
        'unicode' => 'auto',
        'markdown' => true,
        'spinner' => true,
        'banner' => true,
        'usage' => true,
        'width' => 0,
        'tool_details' => 'summary',
        'color_depth' => 'auto',
        'plain' => false,
    ];

    /** @var array<string, mixed> */
    private array $options;
    private Theme $themeOut;
    private Theme $themeErr;
    /** @var Closure(string): void */
    private Closure $out;
    /** @var Closure(string): void */
    private Closure $err;
    private bool $plain;
    private bool $cursorHidden = false;
    private bool $progressActive = false;

    public function __construct(
        private bool $outTty,
        private bool $errTty,
        array $options = [],
        ?Closure $out = null,
        ?Closure $err = null,
    ) {
        $this->plain = (bool) ($options['plain'] ?? false);
        $this->options = array_merge(self::DEFAULTS, $options);
        $width = (int) $this->options['width'];
        $this->options['width'] = $width >= 40 ? min(200, $width) : 100;

        $ownsStreams = $out === null && $err === null;
        $this->out = $out ?? static function (string $text): void {
            fwrite(STDOUT, $text);
            fflush(STDOUT);
        };
        $this->err = $err ?? static function (string $text): void {
            fwrite(STDERR, $text);
            fflush(STDERR);
        };

        $colorOut = $this->resolveColor($this->outTty);
        $colorErr = $this->resolveColor($this->errTty);
        $unicode = self::resolveUnicode((string) $this->options['unicode']);
        $this->themeOut = new Theme($colorOut, $unicode, $colorOut ? $this->resolveDepth() : 0);
        $this->themeErr = new Theme($colorErr, $unicode, $colorErr ? $this->resolveDepth() : 0);

        if ($ownsStreams && $this->errTty && !$this->plain) {
            register_shutdown_function(function (): void {
                $this->progressClear();
                $this->showCursor();
            });
        }
    }

    /** Detect terminal capabilities and build a renderer for this process. */
    public static function detect(array $ui = [], bool $plain = false): self
    {
        $options = $ui;
        if ($plain) {
            $options['plain'] = true;
            $options['color'] = 'never';
            $options['unicode'] = 'never';
            $options['markdown'] = false;
            $options['spinner'] = false;
            $options['banner'] = false;
            $options['usage'] = false;
            $options['tool_details'] = 'summary';
        }

        $outTty = @stream_isatty(STDOUT);
        $errTty = @stream_isatty(STDERR);

        if (!$plain && PHP_OS_FAMILY === 'Windows' && function_exists('sapi_windows_vt100_support')) {
            $outVt = !$outTty || @sapi_windows_vt100_support(STDOUT, true);
            $errVt = !$errTty || @sapi_windows_vt100_support(STDERR, true);
            if (!$outVt || !$errVt) {
                $options['color'] = 'never';
            }
        }

        $options['width'] = self::resolveWidth($options);
        return new self($outTty, $errTty, $options);
    }

    /** Non-interactive fallback used by tests and non-TTY callers. */
    public static function plain(): self
    {
        return new self(false, false, [
            'plain' => true,
            'color' => 'never',
            'unicode' => 'never',
            'markdown' => false,
            'spinner' => false,
            'banner' => false,
            'usage' => false,
            'width' => 100,
        ]);
    }

    public function width(): int
    {
        return (int) $this->options['width'];
    }

    public function colorOut(): bool
    {
        return $this->themeOut->color();
    }

    public function colorErr(): bool
    {
        return $this->themeErr->color();
    }

    public function unicode(): bool
    {
        return $this->themeErr->unicode();
    }

    public function markdown(): bool
    {
        return !$this->plain && $this->outTty && (bool) $this->options['markdown'];
    }

    public function spinnerEnabled(): bool
    {
        return !$this->plain && $this->errTty && (bool) $this->options['spinner'];
    }

    public function styleOut(string $text, string ...$styles): string
    {
        return $this->themeOut->style($text, ...$styles);
    }

    public function styleErr(string $text, string ...$styles): string
    {
        return $this->themeErr->style($text, ...$styles);
    }

    public function symbolOut(string $name): string
    {
        return $this->themeOut->symbol($name);
    }

    public function symbolErr(string $name): string
    {
        return $this->themeErr->symbol($name);
    }

    public function out(string $text): void
    {
        if ($text === '') {
            return;
        }
        @fflush(STDERR);
        ($this->out)($text);
    }

    public function err(string $text): void
    {
        if ($text === '') {
            return;
        }
        @fflush(STDOUT);
        ($this->err)($text);
    }

    public function prompt(): void
    {
        if ($this->plain || !$this->outTty) {
            $this->out('you> ');
            return;
        }
        $this->out($this->themeOut->style($this->themeOut->symbol('prompt') . ' ', 'bold', 'accent'));
    }

    public function heading(string $text): void
    {
        $text = Ansi::sanitize($text);
        if ($this->plain || !$this->outTty) {
            $this->out($text . "\n");
            return;
        }
        $this->out($this->themeOut->style($text, 'bold', 'accent') . "\n");
    }

    /** @param array<string, mixed> $facts */
    public function banner(array $facts): bool
    {
        if ($this->plain || !$this->errTty || !(bool) $this->options['banner']) {
            return false;
        }

        $labels = [
            'model' => 'model',
            'effort' => 'effort',
            'workspace' => 'workspace',
            'session' => 'session',
            'security' => 'security',
            'tools' => 'tools',
            'mcp' => 'mcp',
        ];
        $rows = [];
        foreach ($labels as $key => $label) {
            $value = $facts[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $rows[$label] = Ansi::sanitize((string) $value);
        }

        $width = min($this->width(), 100);
        $hint = 'Type a task. /model and /effort change the run. /continue resumes. Ctrl+C exits.';

        if ($width < 60) {
            $lines = ['MyAgent'];
            foreach ($rows as $label => $value) {
                $lines[] = '  ' . Ansi::pad($label, 10) . '  ' . Ansi::truncateMiddle($value, max(10, $width - 14));
            }
            $lines[] = '';
            foreach (Ansi::wrap($hint, max(10, $width - 2)) as $hintLine) {
                $lines[] = '  ' . $hintLine;
            }
            foreach ($lines as $index => $line) {
                if ($index === 0) {
                    $this->err($this->themeErr->style($line, 'bold', 'accent') . "\n");
                } else {
                    $this->err($this->themeErr->style($line, 'muted') . "\n");
                }
            }
            return true;
        }

        $inner = $width - 4;
        $content = [];
        foreach ($rows as $label => $value) {
            $value = Ansi::truncateMiddle($value, max(8, $inner - 13));
            $content[] = $this->themeErr->style(Ansi::pad($label, 10), 'muted') . '  ' . $value;
        }
        foreach ($this->boxed('MyAgent', $content, $width, $this->themeErr) as $line) {
            $this->err($line . "\n");
        }
        foreach (Ansi::wrap($hint, max(10, $width - 2)) as $hintLine) {
            $this->err('  ' . $this->themeErr->style($hintLine, 'muted') . "\n");
        }
        return true;
    }

    public function assistant(string $markdown): void
    {
        if (trim($markdown) === '') {
            return;
        }
        if (!$this->markdown()) {
            $this->out(rtrim(Ansi::sanitize($markdown)) . "\n");
            return;
        }

        $renderer = new Markdown(max(20, $this->width() - 2), $this->themeOut);
        $rendered = $renderer->render($markdown);
        if ($rendered === '') {
            return;
        }
        $lines = explode("\n", rtrim($rendered, "\n"));
        $first = true;
        foreach ($lines as $line) {
            if ($first) {
                $this->out($this->themeOut->style($this->themeOut->symbol('assistant') . ' ', 'accent') . $line . "\n");
                $first = false;
            } elseif ($line === '') {
                $this->out("\n");
            } else {
                $this->out('  ' . $line . "\n");
            }
        }
        $this->out("\n");
    }

    /** @param array<string, mixed> $input */
    public function toolStart(string $name, array $input): void
    {
        $details = (string) $this->options['tool_details'];
        if ($details === 'hidden') {
            return;
        }
        $name = Ansi::sanitize($name);
        if ($this->plain || !$this->errTty) {
            $this->err('· ' . $name . ' ' . Ansi::truncate($this->compactJson($input), 120) . "\n");
            return;
        }
        $summary = $this->toolSummary($name, $input);
        $line = '  ' . $this->themeErr->style($this->themeErr->symbol('tool') . ' ', 'tool')
            . $this->themeErr->style($name, 'tool', 'bold')
            . ($summary !== '' ? '  ' . $this->themeErr->style($summary, 'muted') : '');
        $this->err(Ansi::truncate($line, $this->width()) . "\n");
    }

    /** @param array<string, mixed> $input */
    public function toolResult(string $name, array $input, string $result, bool $isError, float $duration): void
    {
        $content = Ansi::sanitize($result);
        $firstLine = $this->firstLine($content);
        $details = (string) $this->options['tool_details'];

        if ($isError) {
            if (str_starts_with($firstLine, 'Security reviewer')) {
                return;
            }
            if ($details === 'hidden' || $this->plain || !$this->errTty) {
                $this->err('  ' . $firstLine . "\n");
                return;
            }
            $this->err(
                Ansi::truncate(
                    '  ' . $this->themeErr->style($this->themeErr->symbol('error') . ' ', 'error')
                    . $this->themeErr->style($firstLine, 'error')
                    . $this->themeErr->style(' · ' . $this->formatDuration($duration), 'muted'),
                    $this->width()
                ) . "\n"
            );
            return;
        }

        if ($details === 'hidden' || $this->plain || !$this->errTty) {
            return;
        }
        if ($details === 'full') {
            $this->toolDetailBox($name, $input, $content, $duration);
        }

        $symbol = $this->themeErr->symbol('success');
        $style = 'success';
        if ($name === 'run_command' && preg_match('/\[exit code:\s*(-?\d+)\]/', $content, $match) === 1 && (int) $match[1] !== 0) {
            $symbol = $this->themeErr->symbol('warning');
            $style = 'warn';
        }
        $this->err(
            Ansi::truncate(
                '    ' . $this->themeErr->style($symbol . ' ', $style)
                . $this->themeErr->style($this->resultSummary($name, $content), 'muted')
                . $this->themeErr->style(' · ' . $this->formatDuration($duration), 'muted'),
                $this->width()
            ) . "\n"
        );
    }

    /** @param array<string, mixed> $usage */
    public function usage(array $usage, float $duration, string $stopReason): void
    {
        if ($this->plain || !$this->errTty || !(bool) $this->options['usage']) {
            return;
        }
        $parts = [];
        $input = (int) ($usage['input_tokens'] ?? 0);
        $output = (int) ($usage['output_tokens'] ?? 0);
        $cacheRead = (int) ($usage['cache_read_input_tokens'] ?? 0);
        if ($input > 0 || $output > 0) {
            $parts[] = $this->formatTokens($input) . ' in' . ($cacheRead > 0 ? ' (' . $this->formatTokens($cacheRead) . ' cached)' : '');
            $parts[] = $this->formatTokens($output) . ' out';
        }
        $parts[] = $this->formatDuration($duration);
        if ($stopReason !== '') {
            $parts[] = $stopReason;
        }
        $line = '  ' . $this->themeErr->symbol('info') . ' ' . implode(' · ', $parts);
        foreach (Ansi::wrap($line, $this->width()) as $wrapped) {
            $this->err($this->themeErr->style($wrapped, 'muted') . "\n");
        }
    }

    /** @param array<string, mixed> $call @param array<string, mixed> $decision */
    public function securityApproval(array $call, array $decision): void
    {
        $name = Ansi::sanitize((string) ($call['name'] ?? 'unknown'));
        $risk = strtoupper(Ansi::sanitize((string) ($decision['risk'] ?? 'UNKNOWN')));
        $category = Ansi::sanitize((string) ($decision['category'] ?? 'other'));
        $reason = Ansi::sanitize((string) ($decision['reason'] ?? ''));

        $encoded = json_encode(
            (array) ($call['input'] ?? []),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if (!is_string($encoded)) {
            $encoded = '';
        }
        if (strlen($encoded) > 700) {
            $encoded = substr($encoded, 0, 700) . '…';
        }

        if ($this->plain || !$this->errTty) {
            $this->err("\nSecurity approval required for {$name} (risk: {$risk}, {$category})\n");
            $this->err("  Why: {$reason}\n");
            if ($encoded !== '') {
                $this->err("  Action: {$encoded}\n");
            }
            $this->err('  Approve? [y/N] ');
            return;
        }

        $width = min($this->width(), 100);
        $inner = $width - 4;
        $valueWidth = max(10, $inner - 10);
        $rows = [
            Ansi::pad('tool', 8) . '  ' . $name,
            Ansi::pad('risk', 8) . '  ' . $this->themeErr->style("{$risk} · {$category}", 'warn'),
        ];
        $reasonLines = Ansi::wrap($reason, $valueWidth);
        $rows[] = Ansi::pad('reason', 8) . '  ' . ($reasonLines[0] ?? '');
        foreach (array_slice($reasonLines, 1) as $line) {
            $rows[] = str_repeat(' ', 10) . $line;
        }
        if ($encoded !== '') {
            $actionLines = Ansi::wrap($encoded, $valueWidth);
            $rows[] = Ansi::pad('action', 8) . '  ' . ($actionLines[0] ?? '');
            foreach (array_slice($actionLines, 1) as $line) {
                $rows[] = str_repeat(' ', 10) . $line;
            }
        }
        $title = $this->themeErr->symbol('warning') . ' Security approval required';
        foreach ($this->boxed($title, $rows, $width, $this->themeErr) as $line) {
            $this->err($line . "\n");
        }
        $this->err($this->themeErr->style('Approve? [y/N] ', 'bold', 'warn'));
    }

    /** @param array<string, mixed> $decision */
    public function securityDenied(array $decision): void
    {
        $verdict = Ansi::sanitize((string) ($decision['verdict'] ?? 'deny'));
        $risk = Ansi::sanitize((string) ($decision['risk'] ?? 'high'));
        $category = Ansi::sanitize((string) ($decision['category'] ?? 'other'));
        $reason = Ansi::sanitize((string) ($decision['reason'] ?? ''));

        if ($this->plain || !$this->errTty) {
            $this->err("security {$verdict} ({$risk}): {$reason}\n");
            return;
        }
        $text = $this->themeErr->symbol('error') . " security {$verdict} ({$risk} · {$category}): {$reason}";
        foreach (Ansi::wrap($text, $this->width()) as $line) {
            $this->err($this->themeErr->style($line, 'error') . "\n");
        }
    }

    public function securityResolved(bool $approved): void
    {
        if ($this->plain || !$this->errTty) {
            return;
        }
        $symbol = $this->themeErr->symbol($approved ? 'success' : 'error');
        $label = $approved ? 'approved' : 'declined';
        $this->err('    ' . $this->themeErr->style("{$symbol} {$label}", $approved ? 'success' : 'error') . "\n");
    }

    public function info(string $text): void
    {
        $this->status('info', 'muted', $text);
    }

    public function warning(string $text): void
    {
        $this->status('warning', 'warn', $text);
    }

    public function error(string $text): void
    {
        $this->status('error', 'error', $text);
    }

    private function status(string $symbol, string $style, string $text): void
    {
        $text = rtrim(Ansi::sanitize($text), "\n");
        if ($text === '') {
            return;
        }
        if ($this->plain || !$this->errTty) {
            $this->err($text . "\n");
            return;
        }
        $width = max(10, $this->width() - 2);
        foreach (Ansi::wrap($text, $width) as $index => $line) {
            $prefix = $index === 0 ? $this->themeErr->symbol($symbol) . ' ' : '  ';
            $this->err($this->themeErr->style($prefix, $style) . $this->themeErr->style($line, $style) . "\n");
        }
    }

    public function spinner(string $label = 'Thinking'): ?Spinner
    {
        return $this->spinnerEnabled() ? new Spinner($this, $label) : null;
    }

    public function progressLine(string $text): void
    {
        if (!$this->errTty || $this->plain) {
            return;
        }
        $this->progressActive = true;
        $this->err("\r\x1B[2K" . $text);
    }

    public function progressClear(): void
    {
        if (!$this->errTty || $this->plain || !$this->progressActive) {
            return;
        }
        $this->progressActive = false;
        $this->err("\r\x1B[2K");
    }

    public function hideCursor(): void
    {
        if (!$this->errTty || $this->plain || $this->cursorHidden) {
            return;
        }
        $this->cursorHidden = true;
        $this->err("\x1B[?25l");
    }

    public function showCursor(): void
    {
        if (!$this->errTty || $this->plain || !$this->cursorHidden) {
            return;
        }
        $this->cursorHidden = false;
        $this->err("\x1B[?25h");
    }

    /** @param list<string> $rows */
    private function boxed(string $title, array $rows, int $width, Theme $theme): array
    {
        $unicode = $theme->unicode();
        [$tl, $tr, $bl, $br, $horizontal, $vertical] = $unicode
            ? ['╭', '╮', '╰', '╯', '─', '│']
            : ['+', '+', '+', '+', '-', '|'];

        $inner = max(10, $width - 4);
        $titlePlain = Ansi::truncate(Ansi::sanitize($title), max(1, $width - 6));
        $titleWidth = Ansi::visibleWidth($titlePlain);
        $fill = max(0, $width - $titleWidth - 5);
        $top = $theme->style($tl . $horizontal . ' ', 'muted')
            . $theme->style($titlePlain, 'bold', 'accent')
            . $theme->style(' ' . str_repeat($horizontal, $fill) . $tr, 'muted');

        $out = [$top];
        foreach ($rows as $row) {
            $row = Ansi::truncate((string) $row, $inner);
            $out[] = $theme->style($vertical, 'muted') . ' ' . Ansi::pad($row, $inner) . ' ' . $theme->style($vertical, 'muted');
        }
        $out[] = $theme->style($bl . str_repeat($horizontal, max(0, $width - 2)) . $br, 'muted');
        return $out;
    }

    /** @param array<string, mixed> $input */
    private function toolSummary(string $name, array $input): string
    {
        return match ($name) {
            'read_file', 'list_files', 'write_file' => 'path=' . Ansi::truncateMiddle((string) ($input['path'] ?? ''), 60),
            'read_skill' => 'name=' . Ansi::truncate((string) ($input['name'] ?? ''), 60),
            'run_command' => '$ ' . Ansi::truncate((string) ($input['command'] ?? ''), 100),
            'ask_user' => '"' . Ansi::truncate((string) ($input['question'] ?? ''), 80) . '"',
            default => Ansi::truncate($this->compactJson($input), 100),
        };
    }

    private function resultSummary(string $name, string $content): string
    {
        $lines = $content === '' ? 0 : substr_count(rtrim($content), "\n") + 1;
        $bytes = strlen($content);
        return match ($name) {
            'read_file', 'read_skill' => "{$lines} lines · {$bytes} B",
            'write_file' => $this->firstLine($content),
            'list_files' => $content === '(empty)' ? 'empty' : "{$lines} entries",
            'run_command' => $this->runCommandSummary($content),
            'ask_user' => str_starts_with($content, 'The user answered: ')
                ? 'Answer: ' . substr($content, strlen('The user answered: '))
                : $this->firstLine($content),
            default => $this->firstLine($content) . ($lines > 1 ? " · {$lines} lines" : ''),
        };
    }

    private function runCommandSummary(string $content): string
    {
        $exit = null;
        if (preg_match('/\[exit code:\s*(-?\d+)\]/', $content, $match) === 1) {
            $exit = (int) $match[1];
            $content = trim((string) preg_replace('/\n?\[exit code:.*\]\s*$/', '', $content));
        }
        $lines = $content === '' ? 0 : substr_count($content, "\n") + 1;
        return ($exit !== null ? "exit {$exit} · " : '') . "{$lines} lines";
    }

    /** @param array<string, mixed> $input */
    private function toolDetailBox(string $name, array $input, string $content, float $duration): void
    {
        $width = min($this->width(), 100);
        $inner = $width - 4;
        $rows = [];
        $json = $this->compactJson($input);
        if ($json !== '') {
            foreach (Ansi::wrap('input: ' . Ansi::truncate($json, 2000), max(10, $inner)) as $line) {
                $rows[] = $line;
            }
        }
        foreach (explode("\n", $this->clipLines($content, 60, 8000)) as $line) {
            $rows[] = $this->themeErr->style(Ansi::truncate($line, $inner), 'muted');
        }
        $rows[] = 'duration: ' . $this->formatDuration($duration);
        foreach ($this->boxed($name, $rows, $width, $this->themeErr) as $line) {
            $this->err($line . "\n");
        }
    }

    private function compactJson(mixed $value): string
    {
        $json = json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        return is_string($json) ? Ansi::sanitize($json) : '';
    }

    private function firstLine(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '(no output)';
        }
        $position = strpos($text, "\n");
        return $position === false ? $text : substr($text, 0, $position);
    }

    private function clipLines(string $text, int $maxLines, int $maxBytes): string
    {
        if (strlen($text) > $maxBytes) {
            $text = substr($text, 0, $maxBytes) . "\n… (output clipped)";
        }
        $lines = explode("\n", $text);
        if (count($lines) > $maxLines) {
            $remaining = count($lines) - $maxLines;
            $lines = array_slice($lines, 0, $maxLines);
            $lines[] = "… ({$remaining} more lines)";
        }
        return implode("\n", $lines);
    }

    private function formatDuration(float $seconds): string
    {
        if ($seconds < 0.001) {
            return '<1 ms';
        }
        if ($seconds < 1) {
            return max(1, (int) round($seconds * 1000)) . ' ms';
        }
        return number_format($seconds, 1) . ' s';
    }

    private function formatTokens(int $tokens): string
    {
        if ($tokens >= 1_000_000) {
            return number_format($tokens / 1_000_000, 1) . 'M';
        }
        if ($tokens >= 1_000) {
            return number_format($tokens / 1_000, 1) . 'k';
        }
        return (string) $tokens;
    }

    private function resolveColor(bool $tty): bool
    {
        if ($this->plain) {
            return false;
        }
        $mode = (string) $this->options['color'];
        if ($mode === 'never') {
            return false;
        }
        // An explicit always (flag or ui config) is a deliberate override.
        if ($mode === 'always') {
            return true;
        }
        if (strtolower((string) getenv('TERM')) === 'dumb') {
            return false;
        }
        $noColor = getenv('NO_COLOR');
        if ($noColor !== false && $noColor !== '') {
            return false;
        }
        $force = getenv('FORCE_COLOR');
        if ($force !== false && $force !== '' && $force !== '0') {
            return true;
        }
        return $tty;
    }

    private function resolveDepth(): int
    {
        $depth = (string) $this->options['color_depth'];
        if ($depth === '16') {
            return 16;
        }
        if ($depth === '256') {
            return 256;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            return 256;
        }
        $term = strtolower((string) getenv('TERM'));
        $colorterm = strtolower((string) getenv('COLORTERM'));
        if (str_contains($term, '256') || str_contains($colorterm, 'truecolor') || str_contains($colorterm, '24bit')) {
            return 256;
        }
        return 16;
    }

    private static function resolveUnicode(string $mode): bool
    {
        if ($mode === 'never') {
            return false;
        }
        if ($mode === 'always') {
            return true;
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            return true;
        }
        if (getenv('WT_SESSION') !== false || getenv('TERM_PROGRAM') !== false) {
            return true;
        }
        if (function_exists('sapi_windows_cp_get')) {
            return @sapi_windows_cp_get() === 65001;
        }
        return false;
    }

    private static function resolveWidth(array $options): int
    {
        $forced = (int) ($options['width'] ?? 0);
        if ($forced >= 40) {
            return min(200, $forced);
        }
        $columns = getenv('COLUMNS');
        if ($columns !== false && is_numeric($columns) && (int) $columns >= 40) {
            return min(200, (int) $columns);
        }
        if (PHP_OS_FAMILY === 'Windows') {
            $output = @shell_exec('mode con 2>NUL');
            if (is_string($output) && preg_match('/Columns:\s*(\d+)/i', $output, $match) === 1) {
                return max(40, min(200, (int) $match[1]));
            }
        } else {
            $output = @shell_exec('stty size < /dev/tty 2>/dev/null');
            if (is_string($output) && preg_match('/^\s*\d+\s+(\d+)\s*$/', $output, $match) === 1) {
                return max(40, min(200, (int) $match[1]));
            }
        }
        return 100;
    }
}
