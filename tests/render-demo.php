<?php
declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Dependencies are not installed. Run: composer install\n");
    exit(1);
}
require_once $autoload;

use MyAgent\AskUser;
use MyAgent\Terminal;

/**
 * Visual gallery for the MyAgent terminal renderer.
 *
 * Usage:
 *   php tests/render-demo.php [--color=auto|always|never] [--width=N] [--ascii] [--force]
 *
 * --force renders as if both streams were a TTY, which is useful for snapshot
 * review in CI or when piping the demo to a pager.
 */
$options = getopt('', ['color:', 'width:', 'ascii', 'force']);
$uiOptions = [];
if (isset($options['color'])) {
    $uiOptions['color'] = (string) $options['color'];
}
if (isset($options['width'])) {
    $uiOptions['width'] = (int) $options['width'];
}
if (isset($options['ascii'])) {
    $uiOptions['unicode'] = 'never';
}
$uiOptions['spinner'] = false;
if (isset($options['force'])) {
    $uiOptions['width'] = (int) ($options['width'] ?? 100);
    $ui = new Terminal(true, true, $uiOptions);
} else {
    $ui = Terminal::detect($uiOptions, false);
}

$ui->heading('MyAgent terminal preview');
$ui->banner([
    'model' => 'deepseek-flash',
    'workspace' => getcwd() ?: '.',
    'session' => 'demo',
    'security' => 'reviewer deepseek-flash · fail-closed',
    'tools' => '5 local',
    'mcp' => '1 server',
]);

$ui->prompt();
$ui->out("Summarize this repo.\n\n");

$sample = <<<'MARKDOWN'
## Architecture

The project has **three** layers:

- `agent.php` — entry point
- `src/Agent.php` — API client, tools, and the loop
- `src/SecurityClassifier.php` — per-call safety gate

> Rendering is local only; prompts and token usage are unchanged.

| Tool | Risk | Verdict |
| --- | --- | --- |
| read_file | low | allow |
| git push | high | ask |

```php
$agent->run('Summarize this repo');
```
MARKDOWN;

$ui->assistant($sample);

$ui->toolStart('read_file', ['path' => 'src/Agent.php']);
$ui->toolResult('read_file', ['path' => 'src/Agent.php'], str_repeat("line\n", 120), false, 0.004);
$ui->toolStart('run_command', ['command' => 'php tests/smoke.php']);
$ui->toolResult('run_command', ['command' => 'php tests/smoke.php'], "PHP Warning: example\n[exit code: 1]", false, 1.2);
$ui->usage(['input_tokens' => 1800, 'output_tokens' => 412, 'cache_read_input_tokens' => 900], 4.1, 'end_turn');

$ui->securityDenied([
    'verdict' => 'deny',
    'risk' => 'high',
    'category' => 'destructive',
    'reason' => 'The command force-deletes the project root.',
]);
$ui->securityApproval(
    ['name' => 'run_command', 'input' => ['command' => 'git push origin main']],
    ['risk' => 'high', 'category' => 'shared-state', 'reason' => 'Pushes to a shared branch.']
);
$ui->err("\n");
$ui->securityResolved(true);

$ui->info('Asking the user; the answer is returned to the model.');
$ask = new AskUser(static fn(): ?string => "2\n", null, $ui);
$answer = $ask->ask('Which environment should I deploy to?', ['staging', 'production']);
$ui->err("\n");
$ui->info('ask_user answered: ' . $answer);

$plainOut = '';
$plainErr = '';
$plain = new Terminal(false, false, [
    'plain' => true,
    'color' => 'never',
    'unicode' => 'never',
    'markdown' => false,
    'spinner' => false,
    'banner' => false,
    'usage' => false,
], static function (string $text) use (&$plainOut): void {
    $plainOut .= $text;
}, static function (string $text) use (&$plainErr): void {
    $plainErr .= $text;
});
$plain->assistant("**plain** text");
$plain->toolStart('read_file', ['path' => 'README.md']);
$hasAnsi = str_contains($plainOut . $plainErr, "\x1B");
$ui->info('Plain-mode ANSI check: ' . ($hasAnsi ? 'FAILED' : 'OK'));
