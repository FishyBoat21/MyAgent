<?php
declare(strict_types=1);

namespace MyAgent;

/**
 * Line-oriented Markdown renderer for a terminal.
 *
 * Supports headings, paragraphs, ordered/unordered/task lists, blockquotes,
 * rules, fenced code with basic highlighting, GFM tables, links, and inline
 * emphasis. Input is sanitized; output is always bounded by the constructor
 * width. When the Theme has color disabled the structure is preserved with
 * plain markers and no ANSI bytes.
 */
final class Markdown
{
    public function __construct(
        private int $width,
        private Theme $theme,
    ) {
        $this->width = max(20, $width);
    }

    /** Render a Markdown document. Always ends with "\n" unless empty. */
    public function render(string $markdown): string
    {
        $markdown = Ansi::sanitize($markdown);
        $markdown = Ansi::expandTabs($markdown);
        if (trim($markdown) === '') {
            return '';
        }
        $lines = explode("\n", $markdown);
        $count = count($lines);
        $out = [];
        $index = 0;

        while ($index < $count) {
            $line = $lines[$index];
            if (trim($line) === '') {
                $index++;
                continue;
            }

            if (preg_match('/^\s*(`{3,}|~{3,})\s*([A-Za-z0-9_+.-]*)(?:\s+.*)?$/', $line, $match) === 1) {
                $fenceCharacter = $match[1][0];
                $fenceLength = strlen($match[1]);
                $language = $match[2];
                $code = [];
                $index++;
                $closing = '/^\s*' . preg_quote($fenceCharacter, '/') . '{' . $fenceLength . ',}\s*$/';
                while ($index < $count) {
                    if (preg_match($closing, $lines[$index]) === 1) {
                        $index++;
                        break;
                    }
                    $code[] = $lines[$index];
                    $index++;
                }
                $this->addBlock($out, $this->renderCode(implode("\n", $code), $language));
                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.*?)\s*#*\s*$/', $line, $match) === 1) {
                $this->addBlock($out, $this->renderHeading(strlen($match[1]), $match[2]));
                $index++;
                continue;
            }

            if (preg_match('/^\s*(?:-{3,}|\*{3,}|_{3,})\s*$/', $line) === 1) {
                $this->addBlock($out, [$this->rule()]);
                $index++;
                continue;
            }

            if (str_contains($line, '|') && $index + 1 < $count && $this->isTableDelimiter($lines[$index + 1])) {
                $header = $this->splitRow($line);
                $delimiters = $this->splitRow($lines[$index + 1]);
                $index += 2;
                $rows = [];
                while ($index < $count && trim($lines[$index]) !== '' && str_contains($lines[$index], '|')) {
                    $rows[] = $this->splitRow($lines[$index]);
                    $index++;
                }
                $this->addBlock($out, $this->renderTable($header, $delimiters, $rows));
                continue;
            }

            if (preg_match('/^\s*>\s?(.*)$/', $line, $match) === 1) {
                $quote = [];
                while ($index < $count && preg_match('/^\s*>\s?(.*)$/', $lines[$index], $quoteMatch) === 1) {
                    $quote[] = $quoteMatch[1];
                    $index++;
                }
                $this->addBlock($out, $this->renderQuote($quote));
                continue;
            }

            if (preg_match('/^(\s*)([-*+]|\d+[.)])\s+(.*)$/', $line, $match) === 1) {
                $items = [];
                while ($index < $count) {
                    if (preg_match('/^(\s*)([-*+]|\d+[.)])\s+(.*)$/', $lines[$index], $itemMatch) === 1) {
                        $items[] = [
                            'indent' => strlen(str_replace("\t", '    ', $itemMatch[1])),
                            'marker' => $itemMatch[2],
                            'text' => $itemMatch[3],
                        ];
                        $index++;
                    } elseif ($items !== [] && trim($lines[$index]) !== '' && preg_match('/^\s+/', $lines[$index]) === 1) {
                        $items[count($items) - 1]['text'] .= ' ' . trim($lines[$index]);
                        $index++;
                    } else {
                        break;
                    }
                }
                $this->addBlock($out, $this->renderList($items));
                continue;
            }

            $paragraph = [];
            while ($index < $count && trim($lines[$index]) !== '' && !$this->startsBlock($lines, $index)) {
                $paragraph[] = $lines[$index];
                $index++;
            }
            if ($paragraph === []) {
                $paragraph[] = $line;
                $index++;
            }
            $text = $this->joinParagraph($paragraph);
            $this->addBlock($out, Ansi::wrap($this->inline($text), $this->width));
        }

        while ($out !== [] && end($out) === '') {
            array_pop($out);
        }
        return $out === [] ? '' : implode("\n", $out) . "\n";
    }

    /** @param list<string> $lines */
    private function addBlock(array &$out, array $lines): void
    {
        if ($lines === []) {
            return;
        }
        if ($out !== [] && end($out) !== '') {
            $out[] = '';
        }
        foreach ($lines as $line) {
            $out[] = $line;
        }
    }

    /** @param list<string> $lines */
    private function startsBlock(array $lines, int $index): bool
    {
        $line = $lines[$index];
        if (preg_match('/^\s*(`{3,}|~{3,})/', $line) === 1) {
            return true;
        }
        if (preg_match('/^#{1,6}\s+/', $line) === 1) {
            return true;
        }
        if (preg_match('/^\s*(?:-{3,}|\*{3,}|_{3,})\s*$/', $line) === 1) {
            return true;
        }
        if (preg_match('/^\s*>\s?/', $line) === 1) {
            return true;
        }
        if (preg_match('/^(\s*)([-*+]|\d+[.)])\s+/', $line) === 1) {
            return true;
        }
        return str_contains($line, '|')
            && $index + 1 < count($lines)
            && $this->isTableDelimiter($lines[$index + 1]);
    }

    /** @param list<string> $lines */
    private function joinParagraph(array $lines): string
    {
        $out = '';
        $previousHardBreak = false;
        foreach ($lines as $index => $line) {
            $hardBreak = str_ends_with($line, '  ') || str_ends_with(rtrim($line), '\\');
            $line = rtrim($line);
            if (str_ends_with($line, '\\')) {
                $line = substr($line, 0, -1);
            }
            if ($index > 0) {
                $out .= $previousHardBreak ? "\n" : ' ';
            }
            $out .= ltrim($line);
            $previousHardBreak = $hardBreak;
        }
        return $out;
    }

    /** @return list<string> */
    private function renderHeading(int $level, string $text): array
    {
        $inline = $this->inline($text);

        if (!$this->theme->color()) {
            return Ansi::wrap(str_repeat('#', $level) . ' ' . Ansi::strip($inline), $this->width);
        }

        $symbol = match ($level) {
            1 => $this->theme->symbol('h1'),
            2 => $this->theme->symbol('h2'),
            3 => $this->theme->symbol('h3'),
            default => '',
        };
        $styles = $level <= 2 ? ['bold', 'accent'] : ($level === 3 ? ['bold'] : ['bold', 'muted']);
        $prefix = $symbol !== '' ? $this->theme->style($symbol, 'accent') . ' ' : '';
        $prefixWidth = $symbol !== '' ? Ansi::visibleWidth($symbol) + 1 : 0;
        $wrapped = Ansi::wrap($inline, max(10, $this->width - $prefixWidth));

        $out = [];
        foreach ($wrapped as $index => $line) {
            $plain = Ansi::strip($line);
            $prefixLine = $index === 0 ? $prefix : str_repeat(' ', $prefixWidth);
            $out[] = $prefixLine . $this->theme->style($plain, ...$styles);
        }
        return $out;
    }

    /** @param list<string> $lines @return list<string> */
    private function renderQuote(array $lines): array
    {
        $prefix = $this->theme->style($this->theme->unicode() ? '│ ' : '| ', 'muted');
        $out = [];
        foreach ($lines as $line) {
            $rendered = $this->inline($line);
            if (trim(Ansi::strip($rendered)) === '') {
                $out[] = rtrim($prefix);
                continue;
            }
            foreach (Ansi::wrap($rendered, max(10, $this->width - 2)) as $wrapped) {
                $out[] = $prefix . $wrapped;
            }
        }
        return $out;
    }

    /**
     * @param list<array{indent: int, marker: string, text: string}> $items
     * @return list<string>
     */
    private function renderList(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $depth = min(3, intdiv($item['indent'], 2));
            $indent = $depth * 2;
            $text = $item['text'];
            $marker = $item['marker'];

            $task = null;
            if (preg_match('/^\[( |x|X)\]\s+(.*)$/', $text, $taskMatch) === 1) {
                $task = strtolower($taskMatch[1]) === 'x';
                $text = $taskMatch[2];
            }

            if ($task !== null) {
                $symbol = $this->theme->symbol($task ? 'task_done' : 'task_todo');
                $markerText = $this->theme->style($symbol, $task ? 'success' : 'muted');
            } elseif (preg_match('/^\d+[.)]$/', $marker) === 1) {
                $markerText = $this->theme->style(rtrim($marker, '.)') . '.', 'accent');
            } else {
                $symbolName = match ($depth) {
                    0 => 'bullet',
                    1 => 'bullet_nested',
                    default => 'bullet_deep',
                };
                $markerText = $this->theme->style($this->theme->symbol($symbolName), 'accent');
            }

            $markerWidth = Ansi::visibleWidth($markerText);
            $contentWidth = max(6, $this->width - $indent - $markerWidth - 1);
            $wrapped = Ansi::wrap($this->inline($text), $contentWidth);
            $pad = str_repeat(' ', $indent);
            foreach ($wrapped as $index => $line) {
                if ($index === 0) {
                    $out[] = $pad . $markerText . ' ' . $line;
                } else {
                    $out[] = $pad . str_repeat(' ', $markerWidth + 1) . $line;
                }
            }
        }
        return $out;
    }

    /** @return list<string> */
    private function renderCode(string $code, string $language): array
    {
        $language = strtolower((string) preg_replace('/[^A-Za-z0-9_+.-]/', '', $language));
        $inner = max(10, $this->width - 4);
        $unicode = $this->theme->unicode();
        [$tl, $tr, $bl, $br, $horizontal, $vertical] = $unicode
            ? ['┌', '┐', '└', '┘', '─', '│']
            : ['+', '+', '+', '+', '-', '|'];

        $label = $language === '' ? '' : ' ' . Ansi::truncate($language, max(1, $this->width - 8)) . ' ';
        $labelWidth = Ansi::visibleWidth($label);
        $fill = max(0, $this->width - 3 - $labelWidth);
        $top = $this->theme->style($tl . $horizontal, 'code_border')
            . $this->theme->style($label, 'muted')
            . $this->theme->style(str_repeat($horizontal, $fill) . $tr, 'code_border');

        $out = [$top];
        $border = $this->theme->style($vertical, 'code_border');
        foreach (explode("\n", $code) as $line) {
            $highlighted = $this->highlight($line, $language);
            $wrapped = Ansi::wrap($highlighted, $inner);
            if ($wrapped === []) {
                $wrapped = [''];
            }
            foreach ($wrapped as $wrappedLine) {
                $out[] = $border . ' ' . Ansi::pad($wrappedLine, $inner) . ' ' . $border;
            }
        }
        $out[] = $this->theme->style($bl . str_repeat($horizontal, max(0, $this->width - 2)) . $br, 'code_border');
        return $out;
    }

    /**
     * @param list<string> $header
     * @param list<string> $delimiters
     * @param list<list<string>> $rows
     * @return list<string>
     */
    private function renderTable(array $header, array $delimiters, array $rows): array
    {
        $rowCounts = array_map('count', $rows);
        $columns = max(count($header), count($delimiters), $rowCounts === [] ? 0 : max($rowCounts));
        if ($columns === 0) {
            return [];
        }

        $normalize = function (array $row) use ($columns): array {
            $row = array_slice($row, 0, $columns);
            while (count($row) < $columns) {
                $row[] = '';
            }
            return array_map(fn(string $cell): string => $this->inline(trim($cell)), $row);
        };
        $header = $normalize($header);
        $rows = array_map($normalize, $rows);

        $alignments = [];
        for ($column = 0; $column < $columns; $column++) {
            $delimiter = trim($delimiters[$column] ?? '');
            $left = str_starts_with($delimiter, ':');
            $right = str_ends_with($delimiter, ':');
            $alignments[$column] = $left && $right ? 'center' : ($right ? 'right' : 'left');
        }

        $widths = [];
        for ($column = 0; $column < $columns; $column++) {
            $cellWidth = 3;
            foreach (array_merge([$header], $rows) as $row) {
                $cellWidth = max($cellWidth, Ansi::visibleWidth($row[$column]));
            }
            $widths[$column] = $cellWidth;
        }

        $overhead = 1 + 3 * $columns;
        $available = max($columns * 3, $this->width - $overhead);
        while (array_sum($widths) > $available) {
            $widest = 0;
            for ($column = 1; $column < $columns; $column++) {
                if ($widths[$column] > $widths[$widest]) {
                    $widest = $column;
                }
            }
            if ($widths[$widest] <= 3) {
                break;
            }
            $widths[$widest]--;
        }
        if (array_sum($widths) > $available) {
            return $this->renderTableFallback($header, $rows);
        }

        $wrapRow = function (array $row) use ($widths): array {
            $cells = [];
            foreach ($widths as $column => $width) {
                $wrapped = Ansi::wrap($row[$column], max(1, $width));
                $cells[$column] = $wrapped === [] ? [''] : $wrapped;
            }
            return $cells;
        };

        $headerCells = $wrapRow($header);
        foreach ($headerCells as $column => $lines) {
            foreach ($lines as $lineIndex => $line) {
                $headerCells[$column][$lineIndex] = $this->theme->style(Ansi::strip($line), 'bold');
            }
        }

        $unicode = $this->theme->unicode();
        [$tl, $tm, $tr, $ml, $mm, $mr, $bl, $bm, $br, $horizontal, $vertical] = $unicode
            ? ['┌', '┬', '┐', '├', '┼', '┤', '└', '┴', '┘', '─', '│']
            : ['+', '+', '+', '+', '+', '+', '+', '+', '+', '-', '|'];

        $borderLine = function (string $left, string $middle, string $right) use ($widths, $horizontal): string {
            $parts = [];
            foreach ($widths as $width) {
                $parts[] = str_repeat($horizontal, $width + 2);
            }
            return $this->theme->style($left . implode($middle, $parts) . $right, 'muted');
        };

        $buildRow = function (array $cells) use ($widths, $alignments, $vertical): array {
            $height = 0;
            foreach ($cells as $lines) {
                $height = max($height, count($lines));
            }
            $out = [];
            for ($rowIndex = 0; $rowIndex < $height; $rowIndex++) {
                $line = $this->theme->style($vertical, 'muted');
                foreach ($widths as $column => $width) {
                    $text = $cells[$column][$rowIndex] ?? '';
                    $line .= ' ' . Ansi::pad($text, $width, $alignments[$column]) . ' ' . $this->theme->style($vertical, 'muted');
                }
                $out[] = $line;
            }
            return $out;
        };

        $out = [$borderLine($tl, $tm, $tr)];
        foreach ($buildRow($headerCells) as $line) {
            $out[] = $line;
        }
        $out[] = $borderLine($ml, $mm, $mr);
        foreach ($rows as $row) {
            foreach ($buildRow($wrapRow($row)) as $line) {
                $out[] = $line;
            }
        }
        $out[] = $borderLine($bl, $bm, $br);
        return $out;
    }

    /**
     * Narrow fallback: one "header: value · header: value" line per row.
     *
     * @param list<list<string>> $header
     * @param list<list<string>> $rows
     * @return list<string>
     */
    private function renderTableFallback(array $header, array $rows): array
    {
        $labels = array_map(static fn(string $cell): string => Ansi::strip($cell), $header);
        $out = [];
        foreach ($rows as $row) {
            $parts = [];
            foreach ($row as $column => $cell) {
                $label = $labels[$column] ?? '';
                $parts[] = ($label !== '' ? $label . ': ' : '') . $cell;
            }
            foreach (Ansi::wrap(implode(' · ', $parts), $this->width) as $line) {
                $out[] = $line;
            }
            $out[] = '';
        }
        while ($out !== [] && end($out) === '') {
            array_pop($out);
        }
        return $out;
    }

    private function isTableDelimiter(string $line): bool
    {
        if (!str_contains($line, '-')) {
            return false;
        }
        $cells = $this->splitRow($line);
        if ($cells === []) {
            return false;
        }
        foreach ($cells as $cell) {
            if (preg_match('/^:?-{3,}:?$/', trim($cell)) !== 1) {
                return false;
            }
        }
        return true;
    }

    /** @return list<string> */
    private function splitRow(string $line): array
    {
        $line = trim($line);
        $line = (string) preg_replace('/^\|/', '', $line);
        $line = (string) preg_replace('/\|$/', '', $line);
        $line = str_replace('\\|', "\x00PIPE\x00", $line);
        return array_map(
            static fn(string $cell): string => trim(str_replace("\x00PIPE\x00", '|', $cell)),
            explode('|', $line)
        );
    }

    private function rule(): string
    {
        $character = $this->theme->unicode() ? '─' : '-';
        return $this->theme->style(str_repeat($character, $this->width), 'muted');
    }

    private function inline(string $text): string
    {
        $stash = [];
        $store = function (string $rendered) use (&$stash): string {
            $key = "\x00" . sprintf('%06d', count($stash)) . "\x00";
            $stash[$key] = $rendered;
            return $key;
        };

        $text = preg_replace_callback('/(`+)(.+?)\1/us', function (array $match) use ($store): string {
            $code = trim($match[2]);
            $rendered = $this->theme->color()
                ? $this->theme->style($code, 'code')
                : '`' . $code . '`';
            return $store($rendered);
        }, $text) ?? $text;

        $text = preg_replace_callback('/\\\\([\\\\`*_{}\[\]()#+\-.!>~|])/u', function (array $match) use ($store): string {
            return $store($match[1]);
        }, $text) ?? $text;

        $text = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)(?:\s+"[^"]*")?\)/u', function (array $match) use ($store): string {
            $alt = $this->inline($match[1]);
            return $store(($alt !== '' ? $alt . ' ' : '') . '(' . $match[2] . ')');
        }, $text) ?? $text;

        $text = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)(?:\s+"[^"]*")?\)/u', function (array $match) use ($store): string {
            return $store($this->link($this->inline($match[1]), $match[2]));
        }, $text) ?? $text;

        $text = preg_replace_callback('/<((?:https?|mailto):[^>\s]+)>/u', function (array $match) use ($store): string {
            return $store($this->link($match[1], $match[1]));
        }, $text) ?? $text;

        $text = preg_replace_callback('/(\*\*\*|___)(.+?)\1/us', function (array $match): string {
            return $this->theme->style($match[2], 'bold', 'italic');
        }, $text) ?? $text;

        $text = preg_replace_callback('/(\*\*|__)(.+?)\1/us', function (array $match): string {
            return $this->theme->style($match[2], 'bold');
        }, $text) ?? $text;

        $text = preg_replace_callback('/~~(.+?)~~/us', function (array $match): string {
            return $this->theme->style($match[1], 'strike');
        }, $text) ?? $text;

        $text = preg_replace_callback('/(?<![\p{L}\p{N}])_([^_]+?)_(?![\p{L}\p{N}])/us', function (array $match): string {
            return $this->theme->style($match[1], 'italic');
        }, $text) ?? $text;

        $text = preg_replace_callback('/\*([^*]+?)\*/us', function (array $match): string {
            return $this->theme->style($match[1], 'italic');
        }, $text) ?? $text;

        return strtr($text, $stash);
    }

    private function link(string $text, string $url): string
    {
        $url = trim($url);
        if (preg_match('~^(?:https?://|mailto:)~i', $url) !== 1) {
            return $text;
        }
        $url = str_replace(["\x07", "\x1B"], '', $url);
        if (!$this->theme->color()) {
            return $text === $url ? $url : $text . ' (' . $url . ')';
        }
        $styled = $this->theme->style($text, 'accent');
        $suffix = $text === $url ? '' : $this->theme->style(' (' . $url . ')', 'muted');
        return $styled . $suffix;
    }

    /** Basic, dependency-free syntax highlighting. */
    private function highlight(string $code, string $language): string
    {
        if (!$this->theme->color() || $code === '') {
            return $code;
        }

        if ($language === 'diff') {
            $out = [];
            foreach (explode("\n", $code) as $line) {
                $style = null;
                if (str_starts_with($line, '+')) {
                    $style = 'success';
                } elseif (str_starts_with($line, '-')) {
                    $style = 'error';
                } elseif (str_starts_with($line, '@@')) {
                    $style = 'accent';
                }
                $out[] = $style === null ? $line : $this->theme->style($line, $style);
            }
            return implode("\n", $out);
        }

        if ($language === 'html' || $language === 'xml') {
            $pattern = '~(?P<comment><!--[\s\S]*?-->)|(?P<tag></?[A-Za-z][^>]*>)|(?P<string>"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\')~u';
            return $this->applyHighlightTokens($code, $pattern, ['comment', 'tag', 'string']);
        }

        $keywords = match ($language) {
            'php' => 'abstract|and|array|as|break|callable|case|catch|class|clone|const|continue|declare|default|do|echo|else|elseif|empty|enddeclare|endfor|endforeach|endif|endswitch|endwhile|enum|extends|final|finally|fn|for|foreach|function|global|goto|if|implements|include|include_once|instanceof|insteadof|interface|isset|list|match|namespace|new|or|print|private|protected|public|readonly|require|require_once|return|static|switch|throw|trait|try|unset|use|var|while|xor|yield|true|false|null',
            'js', 'javascript', 'ts', 'typescript' => 'const|let|var|function|return|if|else|for|while|do|switch|case|break|continue|class|extends|new|this|super|import|export|from|default|async|await|try|catch|finally|throw|typeof|instanceof|in|of|void|null|undefined|true|false|interface|type|enum|implements|public|private|protected|readonly|static|get|set',
            'python', 'py' => 'def|class|return|if|elif|else|for|while|break|continue|import|from|as|try|except|finally|raise|with|pass|lambda|yield|global|nonlocal|assert|del|in|is|not|and|or|None|True|False|async|await',
            'bash', 'sh', 'shell', 'powershell', 'ps1' => 'if|then|else|elif|fi|for|while|do|done|case|esac|function|return|exit|export|local|readonly|set|unset|source|alias|echo|printf|read|true|false|param|foreach|try|catch|finally|throw',
            'sql' => 'select|from|where|insert|into|values|update|set|delete|create|table|alter|drop|join|left|right|inner|outer|on|group|by|order|having|limit|offset|as|and|or|not|null|is|in|exists|distinct|union|all|case|when|then|else|end|primary|key|foreign|references|index|view|begin|commit|rollback|transaction',
            default => '',
        };

        $known = ['php', 'js', 'javascript', 'ts', 'typescript', 'json', 'bash', 'sh', 'shell', 'powershell', 'ps1', 'python', 'py', 'sql'];
        if (!in_array($language, $known, true)) {
            return $code;
        }

        $stringPattern = '"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'';
        if (in_array($language, ['js', 'javascript', 'ts', 'typescript'], true)) {
            $stringPattern .= '|`(?:\\\\.|[^`\\\\])*`';
        }
        $commentPattern = '(?://[^\n]*|#[^\n]*|/\*[\s\S]*?\*/)';
        if ($language === 'sql') {
            $commentPattern = '(?:--[^\n]*|/\*[\s\S]*?\*/)';
        }

        $parts = [
            '(?P<comment>' . $commentPattern . ')',
            '(?P<string>' . $stringPattern . ')',
            '(?P<number>\b\d+(?:\.\d+)?\b)',
            '(?P<variable>\$[A-Za-z_]\w*|%[A-Za-z_][A-Za-z0-9_]*%|@[A-Za-z_]\w*)',
        ];
        if ($keywords !== '') {
            $parts[] = '(?P<keyword>\b(?:' . $keywords . ')\b)';
        }
        $parts[] = '(?P<function>\b[A-Za-z_]\w*(?=\s*\())';

        return $this->applyHighlightTokens(
            $code,
            '~' . implode('|', $parts) . '~iu',
            ['comment', 'string', 'number', 'variable', 'keyword', 'function']
        );
    }

    /**
     * @param list<string> $groups
     */
    private function applyHighlightTokens(string $code, string $pattern, array $groups): string
    {
        if (preg_match_all($pattern, $code, $matches, PREG_OFFSET_CAPTURE) === false) {
            return $code;
        }
        $styles = [
            'comment' => 'muted',
            'string' => 'success',
            'number' => 'warn',
            'variable' => 'user',
            'keyword' => 'tool',
            'function' => 'accent',
            'tag' => 'tool',
        ];
        $out = '';
        $cursor = 0;
        foreach ($matches[0] as $index => $match) {
            $token = (string) $match[0];
            $offset = (int) $match[1];
            if ($offset > $cursor) {
                $out .= substr($code, $cursor, $offset - $cursor);
            }
            $type = '';
            foreach ($groups as $group) {
                if (isset($matches[$group][$index]) && (int) $matches[$group][$index][1] !== -1) {
                    $type = $group;
                    break;
                }
            }
            $out .= isset($styles[$type]) ? $this->theme->style($token, $styles[$type]) : $token;
            $cursor = $offset + strlen($token);
        }
        if ($cursor < strlen($code)) {
            $out .= substr($code, $cursor);
        }
        return $out;
    }
}
