<?php
declare(strict_types=1);

namespace MyAgent;

/**
 * Terminal text primitives with no I/O and no dependencies.
 *
 * All methods are safe to call when ANSI is disabled: styling is applied by
 * Theme, while this class only measures, wraps, truncates and draws.
 */
final class Ansi
{
    /**
     * CSI, OSC and single-character escape sequences.
     * The OSC branch accepts both BEL and ST (ESC \) terminators.
     */
    private const ANSI_PATTERN = '#\x1B(?:\[[0-?]*[ -/]*[@-~]|\][^\x07\x1B]*(?:\x07|\x1B\\\\)|[@-_])#';
    private const TOKEN_PATTERN = '#\x1B(?:\[[0-?]*[ -/]*[@-~]|\][^\x07\x1B]*(?:\x07|\x1B\\\\)|[@-_])|.#us';

    /** Remove ANSI escape sequences while keeping visible text. */
    public static function strip(string $text): string
    {
        return (string) preg_replace(self::ANSI_PATTERN, '', $text);
    }

    /**
     * Sanitize untrusted terminal text: normalize line endings, drop ANSI
     * escapes, and remove control characters except newline and tab.
     */
    public static function sanitize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = self::strip($text);
        $clean = preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}]/u', '', $text);
        if ($clean === null) {
            // Invalid UTF-8: fall back to byte-level C0 removal.
            $clean = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        }
        return $clean;
    }

    /** Expand tabs to the next multiple of $tabSize, per line. */
    public static function expandTabs(string $text, int $tabSize = 4): string
    {
        $tabSize = max(1, $tabSize);
        $lines = explode("\n", $text);
        foreach ($lines as &$line) {
            if (!str_contains($line, "\t")) {
                continue;
            }
            $chars = preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY);
            if ($chars === false) {
                $line = str_replace("\t", str_repeat(' ', $tabSize), $line);
                continue;
            }
            $out = '';
            $column = 0;
            foreach ($chars as $char) {
                if ($char === "\t") {
                    $spaces = $tabSize - ($column % $tabSize);
                    $out .= str_repeat(' ', $spaces);
                    $column += $spaces;
                } else {
                    $out .= $char;
                    $column += self::charWidth($char);
                }
            }
            $line = $out;
        }
        unset($line);
        return implode("\n", $lines);
    }

    /** Visible width of a string: ANSI ignored, wide chars count 2. */
    public static function visibleWidth(string $text): int
    {
        $plain = self::expandTabs(self::strip($text));
        $chars = preg_split('//u', $plain, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            return strlen($plain);
        }
        $width = 0;
        $afterJoiner = false;
        foreach ($chars as $char) {
            if ($char === "\u{200D}") {
                $afterJoiner = true;
                continue;
            }
            $charWidth = $afterJoiner ? 0 : self::charWidth($char);
            $afterJoiner = false;
            $width += $charWidth;
        }
        return $width;
    }

    /** Width of one UTF-8 character. */
    public static function charWidth(string $char): int
    {
        if ($char === '') {
            return 0;
        }
        $codepoint = self::codepoint($char);
        if ($codepoint === null) {
            return 1;
        }
        if ($codepoint === 0) {
            return 0;
        }
        foreach ([
            [0x0300, 0x036F], [0x1AB0, 0x1AFF], [0x1DC0, 0x1DFF], [0x20D0, 0x20FF],
            [0x200B, 0x200F], [0x2060, 0x206F], [0x20E3, 0x20E3], [0x1F3FB, 0x1F3FF],
            [0xFE00, 0xFE0F], [0xFE20, 0xFE2F], [0xE0100, 0xE01EF],
        ] as [$start, $end]) {
            if ($codepoint >= $start && $codepoint <= $end) {
                return 0;
            }
        }
        foreach ([
            [0x1100, 0x115F], [0x2329, 0x232A], [0x2E80, 0x303E], [0x3041, 0x33FF],
            [0x3400, 0x4DBF], [0x4E00, 0x9FFF], [0xA000, 0xA4CF], [0xAC00, 0xD7A3],
            [0xF900, 0xFAFF], [0xFE10, 0xFE19], [0xFE30, 0xFE6F], [0xFF00, 0xFF60],
            [0xFFE0, 0xFFE6], [0x1F300, 0x1FAFF], [0x20000, 0x3FFFD],
        ] as [$start, $end]) {
            if ($codepoint >= $start && $codepoint <= $end) {
                return 2;
            }
        }
        return 1;
    }

    /** Truncate to a visible width, appending an ellipsis when cut. */
    public static function truncate(string $text, int $width, string $ellipsis = '…'): string
    {
        if ($width <= 0) {
            return '';
        }
        if (self::visibleWidth($text) <= $width) {
            return $text;
        }
        $ellipsisWidth = self::visibleWidth($ellipsis);
        if ($ellipsisWidth > $width) {
            return self::sliceVisible($ellipsis, $width);
        }
        return self::sliceVisible($text, $width - $ellipsisWidth) . $ellipsis;
    }

    /** Truncate in the middle, useful for long paths. */
    public static function truncateMiddle(string $text, int $width, string $ellipsis = '…'): string
    {
        $text = self::strip($text);
        if ($width <= 0) {
            return '';
        }
        if (self::visibleWidth($text) <= $width) {
            return $text;
        }
        $ellipsisWidth = self::visibleWidth($ellipsis);
        $budget = max(0, $width - $ellipsisWidth);
        $head = (int) ceil($budget / 2);
        $tail = $budget - $head;
        return self::sliceVisible($text, $head) . $ellipsis . self::sliceVisibleFromEnd($text, $tail);
    }

    /**
     * ANSI-aware word wrap. Hard newlines become paragraph breaks; long words
     * are split. Active SGR styles are carried to continuation lines.
     *
     * @return list<string>
     */
    public static function wrap(string $text, int $width): array
    {
        $width = max(1, $width);
        $text = self::expandTabs($text);
        $out = [];
        foreach (explode("\n", $text) as $paragraph) {
            if ($paragraph === '') {
                $out[] = '';
                continue;
            }
            $wrapped = self::wrapClusters(self::clusters($paragraph), $width);
            foreach ($wrapped as $line) {
                $out[] = $line;
            }
        }
        return $out;
    }

    /** Pad a string to a visible width. */
    public static function pad(string $text, int $width, string $align = 'left'): string
    {
        $length = self::visibleWidth($text);
        if ($length >= $width) {
            return $text;
        }
        $space = $width - $length;
        return match ($align) {
            'right' => str_repeat(' ', $space) . $text,
            'center' => str_repeat(' ', intdiv($space, 2)) . $text . str_repeat(' ', $space - intdiv($space, 2)),
            default => $text . str_repeat(' ', $space),
        };
    }

    /**
     * Draw a box around lines. The width includes the borders. Content may
     * contain ANSI; every returned line has an equal visible width.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    public static function box(string $title, array $lines, int $width, bool $unicode = true): array
    {
        $width = max(6, $width);
        $inner = max(1, $width - 4);
        [$tl, $tr, $bl, $br, $horizontal, $vertical] = $unicode
            ? ['╭', '╮', '╰', '╯', '─', '│']
            : ['+', '+', '+', '+', '-', '|'];

        $title = self::truncate(self::sanitize($title), max(1, $width - 6));
        $titleWidth = self::visibleWidth($title);
        $fill = max(0, $width - $titleWidth - 5);
        $top = self::pad(self::truncate($tl . $horizontal . ' ' . $title . ' ' . str_repeat($horizontal, $fill) . $tr, $width), $width);

        $out = [$top];
        foreach ($lines as $line) {
            $line = self::truncate((string) $line, $inner);
            $out[] = $vertical . ' ' . self::pad($line, $inner) . ' ' . $vertical;
        }
        $out[] = $bl . str_repeat($horizontal, max(0, $width - 2)) . $br;

        foreach ($out as &$line) {
            $line = self::pad(self::truncate($line, $width), $width);
        }
        unset($line);
        return $out;
    }

    /**
     * Split into clusters: zero-width ANSI sequences attach to the next
     * visible character. Each cluster records its width and active SGR style.
     *
     * @return list<array{ansi: string, char: string, width: int, active: string}>
     */
    private static function clusters(string $text): array
    {
        $clusters = [];
        $pendingAnsi = '';
        $active = '';
        $afterJoiner = false;

        if (preg_match_all(self::TOKEN_PATTERN, $text, $matches) === false) {
            foreach (str_split($text) as $byte) {
                $clusters[] = ['ansi' => '', 'char' => $byte, 'width' => 1, 'active' => ''];
            }
            return $clusters;
        }

        foreach ($matches[0] as $token) {
            if ($token !== '' && $token[0] === "\x1B") {
                $pendingAnsi .= $token;
                $active = self::updateActive($active, $token);
                continue;
            }
            $width = self::charWidth($token);
            if ($token === "\u{200D}") {
                $width = 0;
                $afterJoiner = true;
            } elseif ($afterJoiner) {
                $width = 0;
                $afterJoiner = false;
            }
            $clusters[] = ['ansi' => $pendingAnsi, 'char' => $token, 'width' => $width, 'active' => $active];
            $pendingAnsi = '';
        }
        if ($pendingAnsi !== '') {
            $clusters[] = ['ansi' => $pendingAnsi, 'char' => '', 'width' => 0, 'active' => $active];
        }
        return $clusters;
    }

    /** @param list<array{ansi: string, char: string, width: int, active: string}> $clusters */
    private static function wrapClusters(array $clusters, int $width): array
    {
        $lines = [];
        $line = [];
        $lineWidth = 0;
        $lastSpace = null;

        foreach ($clusters as $cluster) {
            if ($cluster['char'] === ' ' && $lineWidth === 0) {
                continue;
            }
            if ($cluster['char'] !== '' && $lineWidth > 0 && $lineWidth + $cluster['width'] > $width) {
                if ($cluster['char'] === ' ') {
                    // The separator itself does not fit: end the line and drop it.
                    $lines[] = self::renderLine($line);
                    $line = [];
                    $lineWidth = 0;
                    $lastSpace = null;
                    continue;
                }
                if ($lastSpace !== null) {
                    $head = array_slice($line, 0, $lastSpace);
                    $tail = array_slice($line, $lastSpace + 1);
                    $lines[] = self::renderLine($head);
                    $line = [];
                    $lineWidth = 0;
                    while ($tail !== [] && $tail[0]['char'] === ' ' && $tail[0]['width'] > 0) {
                        array_shift($tail);
                    }
                    foreach ($tail as $tailCluster) {
                        $line[] = $tailCluster;
                        $lineWidth += $tailCluster['width'];
                    }
                    $lastSpace = self::lastSpaceIndex($line);
                } else {
                    $lines[] = self::renderLine($line);
                    $line = [];
                    $lineWidth = 0;
                    $lastSpace = null;
                }
            }
            if ($cluster['char'] === ' ' && $lineWidth === 0) {
                continue;
            }
            $line[] = $cluster;
            $lineWidth += $cluster['width'];
            if ($cluster['char'] === ' ') {
                $lastSpace = count($line) - 1;
            }
        }

        while ($line !== []) {
            $last = end($line);
            if (!is_array($last) || $last['char'] !== ' ' || $last['width'] === 0) {
                break;
            }
            array_pop($line);
        }
        if ($line !== []) {
            $lines[] = self::renderLine($line);
        }
        return $lines === [] ? [''] : $lines;
    }

    /** @param list<array{ansi: string, char: string, width: int, active: string}> $line */
    private static function renderLine(array $line): string
    {
        if ($line === []) {
            return '';
        }
        $active = $line[0]['active'];
        $firstAnsi = $line[0]['ansi'];
        // The first cluster usually already carries the sequence that made it
        // active; prepend only the part of the active state that it lacks.
        if ($firstAnsi !== '' && str_ends_with($active, $firstAnsi)) {
            $active = substr($active, 0, -strlen($firstAnsi));
        }
        $out = $active;
        foreach ($line as $cluster) {
            if ($cluster['ansi'] !== '') {
                $out .= $cluster['ansi'];
            }
            $out .= $cluster['char'];
        }
        if (str_contains($out, "\x1B")) {
            $out .= "\x1B[0m";
        }
        return $out;
    }

    /** @param list<array{ansi: string, char: string, width: int, active: string}> $line */
    private static function lastSpaceIndex(array $line): ?int
    {
        for ($index = count($line) - 1; $index >= 0; $index--) {
            if ($line[$index]['char'] === ' ') {
                return $index;
            }
        }
        return null;
    }

    private static function updateActive(string $active, string $sequence): string
    {
        if (preg_match('~\x1B\[([0-9;]*)m~', $sequence, $match) !== 1) {
            return $active;
        }
        $parameters = trim($match[1] ?? '', ';');
        $parts = $parameters === '' ? ['0'] : explode(';', $parameters);
        foreach ($parts as $part) {
            if ((int) $part !== 0) {
                return $active . $sequence;
            }
        }
        return '';
    }

    /** Keep only the first $width visible columns, preserving ANSI sequences. */
    private static function sliceVisible(string $text, int $width): string
    {
        if ($width <= 0) {
            return '';
        }
        $out = '';
        $used = 0;
        $sawAnsi = false;
        foreach (self::clusters($text) as $cluster) {
            if ($cluster['ansi'] !== '') {
                $out .= $cluster['ansi'];
                $sawAnsi = true;
            }
            if ($cluster['char'] === '') {
                continue;
            }
            if ($used + $cluster['width'] > $width) {
                break;
            }
            $out .= $cluster['char'];
            $used += $cluster['width'];
        }
        if ($sawAnsi) {
            $out .= "\x1B[0m";
        }
        return $out;
    }

    /** Keep the last $width visible columns of plain text. */
    private static function sliceVisibleFromEnd(string $text, int $width): string
    {
        if ($width <= 0) {
            return '';
        }
        $chars = preg_split('//u', self::strip($text), -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            return substr(self::strip($text), -$width);
        }
        $out = '';
        $used = 0;
        for ($index = count($chars) - 1; $index >= 0; $index--) {
            $charWidth = self::charWidth($chars[$index]);
            if ($used + $charWidth > $width) {
                break;
            }
            $out = $chars[$index] . $out;
            $used += $charWidth;
        }
        return $out;
    }

    private static function codepoint(string $char): ?int
    {
        if (function_exists('mb_ord')) {
            $codepoint = mb_ord($char, 'UTF-8');
            return $codepoint === false ? null : $codepoint;
        }
        $length = strlen($char);
        if ($length === 0) {
            return null;
        }
        $first = ord($char[0]);
        if ($first < 0x80) {
            return $first;
        }
        if (($first & 0xE0) === 0xC0 && $length >= 2) {
            return (($first & 0x1F) << 6) | (ord($char[1]) & 0x3F);
        }
        if (($first & 0xF0) === 0xE0 && $length >= 3) {
            return (($first & 0x0F) << 12) | ((ord($char[1]) & 0x3F) << 6) | (ord($char[2]) & 0x3F);
        }
        if (($first & 0xF8) === 0xF0 && $length >= 4) {
            return (($first & 0x07) << 18) | ((ord($char[1]) & 0x3F) << 12) | ((ord($char[2]) & 0x3F) << 6) | (ord($char[3]) & 0x3F);
        }
        return null;
    }
}
