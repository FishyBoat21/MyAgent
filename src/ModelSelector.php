<?php
declare(strict_types=1);

namespace MyAgent;

use Closure;
use RuntimeException;

/**
 * Line-based model and effort pickers for the REPL.
 *
 * Rendering goes to STDERR through Terminal so the model's prose on STDOUT
 * stays clean, matching AskUser and the security [y/N] prompt. Input is a
 * single line: a number, a model id or unique prefix, Enter to keep the
 * current choice, "r" to refresh the catalog, or "q" to cancel.
 */
final class ModelSelector
{
    private const MAX_ATTEMPTS = 3;
    private const MAX_ROWS = 50;

    /** @var null|Closure(): ?string */
    private ?Closure $readLine;

    /** @var Closure(string): void */
    private Closure $write;

    private ?Terminal $terminal;

    /**
     * @param null|Closure(): ?string $readLine Reads one line; null means no interactive user.
     * @param null|Closure(string): void $write Writes prompt text; defaults to STDERR.
     * @param null|Terminal $terminal Optional renderer for styled prompts.
     */
    public function __construct(?Closure $readLine = null, ?Closure $write = null, ?Terminal $terminal = null)
    {
        $this->readLine = $readLine;
        $this->terminal = $terminal;
        if ($write !== null) {
            $this->write = $write;
        } elseif ($terminal !== null) {
            $this->write = static function (string $text) use ($terminal): void {
                $terminal->err($text);
            };
        } else {
            $this->write = static function (string $text): void {
                fwrite(STDERR, $text);
            };
        }
    }

    public function available(): bool
    {
        return $this->readLine !== null;
    }

    /**
     * Render the catalog and return the user's choice.
     *
     * @param list<array<string, mixed>> $models
     * @param null|callable(bool): list<array<string, mixed>> $refresh
     * @return array{action: 'select'|'keep'|'cancel', model: ?string}
     */
    public function pickModel(array $models, ?string $current, ?string $defaultModel, ?callable $refresh = null): array
    {
        if ($this->readLine === null) {
            throw new RuntimeException('Model selection requires an interactive terminal. Use /model <id> instead.');
        }

        $refreshed = false;
        $attempts = 0;
        while (true) {
            $this->renderModels($models, $current, $defaultModel);
            $this->prompt($models === []
                ? 'Type a model id, Enter to keep the current model, r to refresh, q to cancel: '
                : 'Select 1-' . count($models)
                    . ', type a model id, Enter to keep the current model, r to refresh, q to cancel: ');
            $line = ($this->readLine)();
            if ($line === null) {
                return ['action' => 'cancel', 'model' => null];
            }
            $input = trim($line);

            if ($input === '') {
                return ['action' => 'keep', 'model' => null];
            }
            $lower = strtolower($input);
            if (in_array($lower, ['q', 'quit', 'cancel'], true)) {
                return ['action' => 'cancel', 'model' => null];
            }
            if (in_array($lower, ['r', 'refresh'], true)) {
                if ($refresh === null) {
                    $this->out($this->muted('Model refresh is unavailable.') . "\n");
                    continue;
                }
                if ($refreshed) {
                    $this->out($this->muted('Already refreshed; using the current list.') . "\n");
                    continue;
                }
                $refreshed = true;
                $this->out($this->muted('Refreshing the provider catalog...') . "\n");
                $models = (array) $refresh(true);
                continue;
            }

            $resolution = self::resolve($input, $models);
            if ($resolution['status'] === 'match') {
                return ['action' => 'select', 'model' => $resolution['model']];
            }
            if ($resolution['status'] === 'ambiguous') {
                $this->out($this->warning('Ambiguous model prefix. Matches: '
                    . implode(', ', $resolution['candidates'])) . "\n");
                if (++$attempts >= self::MAX_ATTEMPTS) {
                    throw new RuntimeException('No valid model was selected.');
                }
                continue;
            }
            if ($resolution['status'] === 'invalid') {
                $this->out($this->warning('Pick a number between 1 and ' . count($models) . '.') . "\n");
                if (++$attempts >= self::MAX_ATTEMPTS) {
                    throw new RuntimeException('No valid model was selected.');
                }
                continue;
            }

            // Unknown id: catalogs can be stale, so allow it after one confirmation.
            $this->prompt('"' . Ansi::sanitize($input) . '" is not in the provider list. Use it anyway? [y/N]: ');
            $answer = ($this->readLine)();
            $yes = $answer !== null && in_array(strtolower(trim($answer)), ['y', 'yes'], true);
            if ($yes) {
                return ['action' => 'select', 'model' => $input];
            }
            if (++$attempts >= self::MAX_ATTEMPTS) {
                throw new RuntimeException('No valid model was selected.');
            }
        }
    }

    /**
     * Render the effort choices and return the user's choice.
     *
     * @param list<string> $levels Declared levels; [] means the canonical five.
     * @return array{action: 'select'|'keep'|'cancel', effort: ?string, clamped: bool, requested: ?string}
     */
    public function pickEffort(array $levels, ?string $current, ?string $defaultLevel, ?string $modelId = null): array
    {
        if ($this->readLine === null) {
            throw new RuntimeException('Effort selection requires an interactive terminal. Use /effort <level> instead.');
        }

        $levels = self::orderedLevels($levels);
        $attempts = 0;
        while (true) {
            $this->renderEfforts($levels, $current, $defaultLevel, $modelId);
            $this->prompt('Select 0-' . count($levels)
                . ', Enter to keep the current effort, q to cancel: ');
            $line = ($this->readLine)();
            if ($line === null) {
                return ['action' => 'cancel', 'effort' => null, 'clamped' => false, 'requested' => null];
            }
            $input = trim($line);
            if ($input === '') {
                return ['action' => 'keep', 'effort' => null, 'clamped' => false, 'requested' => null];
            }
            $lower = strtolower($input);
            if (in_array($lower, ['q', 'quit', 'cancel'], true)) {
                return ['action' => 'cancel', 'effort' => null, 'clamped' => false, 'requested' => null];
            }
            if (in_array($lower, ['0', 'default', 'none', 'off'], true)) {
                return ['action' => 'select', 'effort' => null, 'clamped' => false, 'requested' => $input];
            }
            if (preg_match('/\A\d+\z/', $input) === 1) {
                $choice = (int) $input;
                if ($choice >= 1 && $choice <= count($levels)) {
                    return ['action' => 'select', 'effort' => $levels[$choice - 1], 'clamped' => false, 'requested' => $input];
                }
                $this->out($this->warning('Pick a number between 0 and ' . count($levels) . '.') . "\n");
                if (++$attempts >= self::MAX_ATTEMPTS) {
                    throw new RuntimeException('No valid effort was selected.');
                }
                continue;
            }
            if (!ModelCatalog::validEffort($lower)) {
                $this->out($this->warning('Unknown effort level "' . Ansi::sanitize($input)
                    . '". Use one of: ' . implode(', ', $levels) . '.') . "\n");
                if (++$attempts >= self::MAX_ATTEMPTS) {
                    throw new RuntimeException('No valid effort was selected.');
                }
                continue;
            }

            [$effort, $clamped] = ModelCatalog::clampEffort($lower, $levels, $defaultLevel);
            return ['action' => 'select', 'effort' => $effort, 'clamped' => $clamped, 'requested' => $lower];
        }
    }

    /**
     * Resolve a /model argument: a 1-based index, an exact id, or a unique
     * case-insensitive prefix.
     *
     * @param list<array<string, mixed>> $models
     * @return array{status: 'match'|'ambiguous'|'unknown'|'invalid', model: ?string, candidates: list<string>}
     */
    public static function resolve(string $arg, array $models): array
    {
        $arg = trim($arg);
        if ($arg === '') {
            return ['status' => 'unknown', 'model' => null, 'candidates' => []];
        }
        if (preg_match('/\A\d+\z/', $arg) === 1) {
            $index = (int) $arg;
            if ($index >= 1 && $index <= count($models)) {
                return ['status' => 'match', 'model' => (string) ($models[$index - 1]['id'] ?? ''), 'candidates' => []];
            }
            return ['status' => 'invalid', 'model' => null, 'candidates' => []];
        }

        $ids = [];
        foreach ($models as $model) {
            $id = (string) ($model['id'] ?? '');
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        if (in_array($arg, $ids, true)) {
            return ['status' => 'match', 'model' => $arg, 'candidates' => []];
        }

        $lower = strtolower($arg);
        $matches = array_values(array_filter($ids, static fn(string $id): bool => str_starts_with(strtolower($id), $lower)));
        if (count($matches) === 1) {
            return ['status' => 'match', 'model' => $matches[0], 'candidates' => []];
        }
        if (count($matches) > 1) {
            return ['status' => 'ambiguous', 'model' => null, 'candidates' => $matches];
        }
        return ['status' => 'unknown', 'model' => null, 'candidates' => []];
    }

    /** @param list<array<string, mixed>> $models */
    private function renderModels(array $models, ?string $current, ?string $defaultModel): void
    {
        $width = $this->terminal !== null ? $this->terminal->width() : 0;

        $this->out("\n" . $this->wrap($this->accent('Select a model')
            . $this->muted('  (provider catalog: ' . count($models) . ' model' . (count($models) === 1 ? '' : 's') . ')')) . "\n");

        if ($models === []) {
            $this->out($this->wrap($this->muted('No models are available from the catalog. Type an id to use it anyway.')) . "\n");
        }

        $shown = array_slice($models, 0, self::MAX_ROWS);
        foreach ($shown as $index => $model) {
            $id = (string) ($model['id'] ?? '');
            $name = (string) ($model['name'] ?? $id);
            $isCurrent = $id === $current;
            $row = ($isCurrent ? $this->accent('*') : ' ') . ' ' . ($index + 1) . ') '
                . Ansi::sanitize($id);
            $details = trim(Ansi::sanitize($name));
            if ($details !== '' && $details !== $id) {
                $row .= '  ' . $this->muted($details);
            }
            $meta = [];
            $context = $model['context_window'] ?? null;
            if (is_int($context)) {
                $meta[] = self::formatTokens($context) . ' ctx';
            }
            $output = $model['max_output_tokens'] ?? null;
            if (is_int($output)) {
                $meta[] = self::formatTokens($output) . ' out';
            }
            $levels = $model['effort_levels'] ?? [];
            if (is_array($levels) && $levels !== []) {
                $meta[] = 'effort ' . implode('/', $levels);
            }
            if ($meta !== []) {
                $row .= '  ' . $this->muted('· ' . implode(' · ', $meta));
            }
            if ($width > 0) {
                $row = Ansi::truncate($row, $width);
            }
            $this->out($row . "\n");
        }
        if (count($models) > self::MAX_ROWS) {
            $this->out($this->muted('  … and ' . (count($models) - self::MAX_ROWS) . ' more; type an id.') . "\n");
        }

        $facts = [];
        if ($defaultModel !== null && $defaultModel !== '') {
            $facts[] = 'default ' . Ansi::sanitize($defaultModel);
        }
        $facts[] = 'current ' . Ansi::sanitize($current ?? '(none)');
        $this->out($this->muted('  ' . implode(' · ', $facts)) . "\n\n");
    }

    /** @param list<string> $levels */
    private function renderEfforts(array $levels, ?string $current, ?string $defaultLevel, ?string $modelId = null): void
    {
        $title = 'Select effort' . ($modelId !== null && $modelId !== ''
            ? ' for ' . Ansi::sanitize($modelId)
            : '');
        $this->out("\n" . $this->wrap($this->accent($title)) . "\n");
        $this->out($this->muted('  declared: ' . implode('/', $levels)
            . ' · default ' . ($defaultLevel ?? 'provider default')) . "\n");

        $defaultRow = (0) . ') provider default (omit output_config)';
        $this->out(($current === null ? $this->accent('*') : ' ') . ' ' . $defaultRow
            . ($current === null ? $this->muted('  current') : '') . "\n");

        foreach ($levels as $index => $level) {
            $isCurrent = $current === $level;
            $suffix = [];
            if ($isCurrent) {
                $suffix[] = 'current';
            }
            if ($defaultLevel === $level) {
                $suffix[] = 'model default';
            }
            $row = ($isCurrent ? $this->accent('*') : ' ') . ' ' . ($index + 1) . ') ' . $level;
            if ($suffix !== []) {
                $row .= $this->muted('  ' . implode(' · ', $suffix));
            }
            $this->out($row . "\n");
        }
        $this->out("\n");
    }

    /** @param list<string> $levels @return list<string> */
    private static function orderedLevels(array $levels): array
    {
        $set = [];
        foreach ($levels as $level) {
            if (is_string($level) && in_array(strtolower(trim($level)), ModelCatalog::EFFORT_ORDER, true)) {
                $set[strtolower(trim($level))] = true;
            }
        }
        if ($set === []) {
            return ModelCatalog::EFFORT_ORDER;
        }
        return array_values(array_filter(
            ModelCatalog::EFFORT_ORDER,
            static fn(string $level): bool => isset($set[$level])
        ));
    }

    private function prompt(string $text): void
    {
        $this->out($this->accent($text));
    }

    private function out(string $text): void
    {
        ($this->write)($text);
    }

    private function wrap(string $text): string
    {
        if ($this->terminal === null) {
            return $text;
        }
        return implode("\n", Ansi::wrap($text, max(20, $this->terminal->width())));
    }

    private function accent(string $text): string
    {
        return $this->terminal !== null && $this->terminal->colorErr()
            ? $this->terminal->styleErr($text, 'accent', 'bold')
            : $text;
    }

    private function muted(string $text): string
    {
        return $this->terminal !== null && $this->terminal->colorErr()
            ? $this->terminal->styleErr($text, 'muted')
            : $text;
    }

    private function warning(string $text): string
    {
        return $this->terminal !== null && $this->terminal->colorErr()
            ? $this->terminal->styleErr($text, 'warning')
            : $text;
    }

    private static function formatTokens(int $tokens): string
    {
        if ($tokens >= 1_000_000) {
            return self::trimDecimal($tokens / 1_000_000) . 'M';
        }
        if ($tokens >= 1000) {
            return (string) (int) round($tokens / 1000) . 'K';
        }
        return (string) $tokens;
    }

    /** Format a short scale (1.0 -> "1", 1.5 -> "1.5", 100.0 -> "100"). */
    private static function trimDecimal(float $value): string
    {
        $text = number_format($value, 1, '.', '');
        return rtrim(rtrim($text, '0'), '.');
    }
}
