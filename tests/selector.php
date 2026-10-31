<?php
declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Dependencies are not installed. Run: composer install\n");
    exit(1);
}
require_once $autoload;

use MyAgent\ModelSelector;

if (!function_exists('check')) {
    function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}

if (!function_exists('errorOf')) {
    /** @param callable(): mixed $run */
    function errorOf(callable $run): string
    {
        try {
            $run();
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
        throw new RuntimeException('Expected a RuntimeException');
    }
}

/**
 * @param list<string> $lines
 * @return Closure(): ?string
 */
function scripted(array $lines): Closure
{
    $index = 0;
    return static function () use (&$index, $lines): ?string {
        if ($index >= count($lines)) {
            return null;
        }
        return $lines[$index++] . "\n";
    };
}

/** @return list<array<string, mixed>> */
function modelFixture(): array
{
    return [
        [
            'id' => 'deepseek-flash',
            'name' => 'DeepSeek-V4.1-Flash',
            'context_window' => 1048576,
            'max_output_tokens' => 393216,
            'effort_levels' => ['low', 'high', 'max'],
            'default_effort' => 'high',
        ],
        [
            'id' => 'deepseek-v4-pro',
            'name' => 'DeepSeek-V4-Pro',
            'context_window' => 1048576,
            'max_output_tokens' => 393216,
            'effort_levels' => ['low', 'high', 'max'],
            'default_effort' => 'high',
        ],
    ];
}

/** @param list<string> $lines Build a selector reading scripted lines into $output. */
function selectorFor(array $lines, string &$output): ModelSelector
{
    return new ModelSelector(
        scripted($lines),
        static function (string $text) use (&$output): void {
            $output .= $text;
        }
    );
}

$models = modelFixture();

// --- resolve -----------------------------------------------------------------

$resolution = ModelSelector::resolve('2', $models);
check($resolution['status'] === 'match' && $resolution['model'] === 'deepseek-v4-pro', 'numeric resolve failed');

$resolution = ModelSelector::resolve('deepseek-v4-pro', $models);
check($resolution['status'] === 'match' && $resolution['model'] === 'deepseek-v4-pro', 'exact resolve failed');

$resolution = ModelSelector::resolve('deepseek-v4-p', $models);
check($resolution['status'] === 'match' && $resolution['model'] === 'deepseek-v4-pro', 'prefix resolve failed');

$resolution = ModelSelector::resolve('DEEPSEEK-V4', $models);
check($resolution['status'] === 'match' && $resolution['model'] === 'deepseek-v4-pro', 'case-insensitive prefix resolve failed');

$resolution = ModelSelector::resolve('deepseek-', $models);
check($resolution['status'] === 'ambiguous' && count($resolution['candidates']) === 2, 'ambiguous resolve failed');

$resolution = ModelSelector::resolve('unknown-model', $models);
check($resolution['status'] === 'unknown' && $resolution['model'] === null, 'unknown resolve failed');

$resolution = ModelSelector::resolve('99', $models);
check($resolution['status'] === 'invalid', 'out-of-range number was not invalid');

// --- pickModel ---------------------------------------------------------------

$output = '';
$roundModel = [[
    'id' => 'round-model', 'name' => 'Round', 'context_window' => 100000, 'max_output_tokens' => 1000,
    'effort_levels' => [], 'default_effort' => null,
]];
selectorFor(['1'], $output)->pickModel($roundModel, 'round-model', null);
check(str_contains($output, '100K ctx'), '100K context window was misformatted');
check(str_contains($output, '1K out'), '1000 output tokens were misformatted');

$output = '';
$choice = selectorFor(['1'], $output)->pickModel($models, 'deepseek-v4-pro', 'deepseek-flash');
check($choice['action'] === 'select' && $choice['model'] === 'deepseek-flash', 'number selection failed');
check(str_contains($output, 'deepseek-flash'), 'model list missing from output');
check(str_contains($output, '1M ctx'), 'context window missing from output');
check(str_contains($output, 'effort low/high/max'), 'effort levels missing from output');

$output = '';
$choice = selectorFor([''], $output)->pickModel($models, 'deepseek-flash', 'deepseek-flash');
check($choice['action'] === 'keep' && $choice['model'] === null, 'empty input did not keep the model');

$output = '';
$choice = selectorFor(['q'], $output)->pickModel($models, 'deepseek-flash', 'deepseek-flash');
check($choice['action'] === 'cancel', 'q did not cancel');

$output = '';
$choice = selectorFor(["\n"], $output)->pickModel($models, 'deepseek-flash', 'deepseek-flash');
check($choice['action'] === 'keep', 'blank line did not keep the model');

$output = '';
$choice = selectorFor(['deepseek-v4-p'], $output)->pickModel($models, 'deepseek-flash', 'deepseek-flash');
check($choice['action'] === 'select' && $choice['model'] === 'deepseek-v4-pro', 'prefix selection failed');

// Ambiguous prefix prints candidates, then accepts a number.
$output = '';
$choice = selectorFor(['deepseek-', '2'], $output)->pickModel($models, 'deepseek-flash', 'deepseek-flash');
check($choice['action'] === 'select' && $choice['model'] === 'deepseek-v4-pro', 'ambiguous retry failed');
check(str_contains($output, 'Ambiguous model prefix'), 'ambiguous candidates were not printed');

// Out-of-range number, then a valid one.
$output = '';
$choice = selectorFor(['9', '1'], $output)->pickModel($models, 'deepseek-flash', 'deepseek-flash');
check($choice['action'] === 'select' && $choice['model'] === 'deepseek-flash', 'invalid number retry failed');

// Unknown id is accepted only after confirmation.
$output = '';
$choice = selectorFor(['future-model', 'y'], $output)->pickModel($models, 'deepseek-flash', 'deepseek-flash');
check($choice['action'] === 'select' && $choice['model'] === 'future-model', 'unknown id confirmation failed');

$output = '';
$choice = selectorFor(['future-model', 'n', '1'], $output)->pickModel($models, 'deepseek-flash', 'deepseek-flash');
check($choice['action'] === 'select' && $choice['model'] === 'deepseek-flash', 'declined unknown id did not retry');

// Refresh calls the callback once, then continues with the new list.
$refreshes = 0;
$output = '';
$refreshing = new ModelSelector(
    scripted(['r', '2']),
    static function (string $text) use (&$output): void {
        $output .= $text;
    }
);
$choice = $refreshing->pickModel($models, 'deepseek-flash', 'deepseek-flash', static function (bool $refresh) use (&$refreshes, $models): array {
    $refreshes++;
    check($refresh === true, 'refresh callback received false');
    return $models;
});
check($choice['action'] === 'select' && $choice['model'] === 'deepseek-v4-pro', 'selection after refresh failed');
check($refreshes === 1, 'refresh was not requested exactly once');
check(str_contains($output, 'Refreshing the provider catalog'), 'refresh notice missing');

// EOF cancels instead of hanging.
$output = '';
$choice = selectorFor([], $output)->pickModel($models, 'deepseek-flash', 'deepseek-flash');
check($choice['action'] === 'cancel', 'EOF did not cancel');

// Too many invalid answers.
$retryOut = '';
$error = errorOf(static function () use ($models, &$retryOut): array {
    return selectorFor(['9', '9', '9'], $retryOut)->pickModel($models, 'deepseek-flash', 'deepseek-flash');
});
check(str_contains($error, 'No valid model'), 'selector did not give up after retries');

// Terminal escapes in provider text are stripped.
$output = '';
$evil = $models;
$evil[0]['name'] = "Evil\e]52;c;Zm9v\x07Name";
selectorFor(['1'], $output)->pickModel($evil, 'deepseek-flash', 'deepseek-flash');
check(!str_contains($output, "\e]52"), 'selector leaked a terminal escape');

// Without an input stream the picker is unavailable.
check(!(new ModelSelector())->available(), 'selector should be unavailable without a reader');
$error = errorOf(static fn(): array => (new ModelSelector())->pickModel($models, null, null));
check(str_contains($error, 'interactive terminal'), 'unavailable picker did not explain itself');

// --- pickEffort --------------------------------------------------------------

$output = '';
$choice = selectorFor(['2'], $output)->pickEffort(['low', 'high', 'max'], 'low', 'high', 'deepseek-flash');
check($choice['action'] === 'select' && $choice['effort'] === 'high', 'effort number selection failed');
check(str_contains($output, 'Select effort for deepseek-flash'), 'effort picker title missing');
check(str_contains($output, 'provider default'), 'provider-default row missing');

$output = '';
$choice = selectorFor(['0'], $output)->pickEffort(['low', 'high', 'max'], 'high', 'high');
check($choice['action'] === 'select' && $choice['effort'] === null, 'effort 0 did not clear the effort');

$output = '';
$choice = selectorFor([''], $output)->pickEffort(['low', 'high', 'max'], 'high', 'high');
check($choice['action'] === 'keep', 'empty effort input did not keep the current level');

$output = '';
$choice = selectorFor(['q'], $output)->pickEffort(['low', 'high', 'max'], 'high', 'high');
check($choice['action'] === 'cancel', 'q did not cancel the effort picker');

// A valid but undeclared level clamps (medium -> high on low/high/max).
$output = '';
$choice = selectorFor(['medium'], $output)->pickEffort(['low', 'high', 'max'], null, 'high');
check($choice['action'] === 'select' && $choice['effort'] === 'high' && $choice['clamped'] === true, 'medium did not clamp to high');

// No declared levels falls back to the canonical five.
$output = '';
$choice = selectorFor(['5'], $output)->pickEffort([], null, null);
check($choice['action'] === 'select' && $choice['effort'] === 'max', 'canonical fallback failed');

// Invalid level, then a valid one.
$output = '';
$choice = selectorFor(['turbo', '1'], $output)->pickEffort(['low', 'high', 'max'], null, 'high');
check($choice['action'] === 'select' && $choice['effort'] === 'low', 'invalid effort retry failed');
check(str_contains($output, 'Unknown effort level'), 'invalid effort warning missing');

$effortRetryOut = '';
$error = errorOf(static function () use (&$effortRetryOut): array {
    return selectorFor(['turbo', 'turbo', 'turbo'], $effortRetryOut)->pickEffort(['low'], null, null);
});
check(str_contains($error, 'No valid effort'), 'effort picker did not give up after retries');

fwrite(STDOUT, "Selector tests passed\n");
