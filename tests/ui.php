<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Ansi.php';
require_once dirname(__DIR__) . '/src/Theme.php';
require_once dirname(__DIR__) . '/src/Markdown.php';
require_once dirname(__DIR__) . '/src/Terminal.php';
require_once dirname(__DIR__) . '/src/Spinner.php';
require_once dirname(__DIR__) . '/src/AskUser.php';

use MyAgent\Ansi;
use MyAgent\AskUser;
use MyAgent\Markdown;
use MyAgent\Terminal;
use MyAgent\Theme;

if (!function_exists('check')) {
    function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}

/** @param list<string> $lines */
$maxWidth = static function (array $lines): int {
    $max = 0;
    foreach ($lines as $line) {
        $max = max($max, Ansi::visibleWidth($line));
    }
    return $max;
};

// --- ANSI primitives ---------------------------------------------------------

check(Ansi::strip("\033[31mred\033[0m") === 'red', 'ANSI strip failed');
check(Ansi::sanitize("\e]52;c;Zm9v\x07hello") === 'hello', 'OSC injection not removed');
check(Ansi::sanitize("a\e[31mb\e[0m") === 'ab', 'CSI injection not removed');
check(Ansi::sanitize("a\r\nb\rc") === "a\nb\nc", 'line ending normalization failed');
check(Ansi::visibleWidth('中a') === 3, 'CJK width wrong');
check(Ansi::visibleWidth("\033[1mabc\033[0m") === 3, 'ANSI width wrong');
check(Ansi::visibleWidth('👨‍👩‍👧') === 2, 'ZWJ emoji width wrong');
check(Ansi::wrap('one two three', 7) === ['one two', 'three'], 'word wrap failed');
check(Ansi::wrap(str_repeat('x', 25), 10) === ['xxxxxxxxxx', 'xxxxxxxxxx', 'xxxxx'], 'hard wrap failed');
check(Ansi::wrap("first\nsecond", 20) === ['first', 'second'], 'newline wrap failed');

$box = Ansi::box('T', ['hello', '中'], 20);
check(count($box) === 4, 'box height wrong');
foreach ($box as $line) {
    check(Ansi::visibleWidth($line) === 20, 'box width wrong');
}
$asciiBox = Ansi::box('T', ['hello'], 20, false);
foreach ($asciiBox as $line) {
    check(!preg_match('/[╭╮╰╯─│]/u', $line), 'ascii box contains unicode glyphs');
    check(Ansi::visibleWidth($line) === 20, 'ascii box width wrong');
}

check(Ansi::truncate('abcdefgh', 5) === 'abcd…', 'truncate failed');
check(Ansi::visibleWidth(Ansi::truncateMiddle('D:/very/long/path/file.php', 16)) === 16, 'middle truncate width wrong');
check(str_contains(Ansi::truncateMiddle('D:/very/long/path/file.php', 16), '…'), 'middle truncate marker missing');
check(Ansi::pad('ab', 5) === 'ab   ', 'left pad failed');
check(Ansi::pad('ab', 5, 'right') === '   ab', 'right pad failed');
check(Ansi::visibleWidth(Ansi::pad("\033[1mab\033[0m", 5)) === 5, 'ANSI pad width wrong');

foreach ([40, 60, 80, 120] as $width) {
    $corpus = "CJK 中文测试 and emoji 👨‍👩‍👧 with ANSI \033[1mbold\033[0m words " . str_repeat('longtoken ', 20);
    check($maxWidth(Ansi::wrap($corpus, $width)) <= $width, "wrap exceeded width {$width}");
}

// --- Theme -------------------------------------------------------------------

$mono = new Theme(false, false, 0);
check($mono->style('text', 'bold', 'accent') === 'text', 'mono theme styled text');
check($mono->symbol('assistant') === '*', 'theme ascii symbol wrong');

$colored = new Theme(true, true, 256);
$styled = $colored->style('text', 'bold', 'accent');
check(str_contains($styled, "\033[1;38;5;45m"), 'theme SGR sequence missing');
check(str_contains($styled, "\033[0m"), 'theme reset missing');
check($colored->symbol('assistant') === '⏺', 'theme unicode symbol wrong');

$sixteen = new Theme(true, false, 16);
check(str_contains($sixteen->style('x', 'accent'), "\033[36m"), '16-color mapping wrong');

// TERM=dumb disables color in auto mode, unless color was forced.
$previousTerm = getenv('TERM');
$previousNoColor = getenv('NO_COLOR');
putenv('NO_COLOR');
putenv('TERM=dumb');
$dumb = new Terminal(true, true, ['color' => 'auto'],
    static function (string $text): void {},
    static function (string $text): void {}
);
check(!$dumb->colorOut(), 'TERM=dumb should disable automatic color');
putenv($previousTerm === false ? 'TERM' : 'TERM=' . $previousTerm);
putenv($previousNoColor === false ? 'NO_COLOR' : 'NO_COLOR=' . $previousNoColor);

// --- Markdown ----------------------------------------------------------------

$markdownTheme = new Theme(true, true, 256);
$renderer = new Markdown(60, $markdownTheme);
$rendered = $renderer->render(
    "# Title\n\n**bold** and `code`\n\n- one\n- two\n\n> quote\n\n"
    . "| A | B |\n| --- | ---: |\n| 1 | 2 |\n\n"
    . "```php\necho \"hi\";\n```\n"
);
check(!str_contains($rendered, '**'), 'bold markers survived');
check(str_contains($rendered, 'bold'), 'bold text missing');
check(str_contains($rendered, "\033["), 'markdown not styled');
foreach (explode("\n", rtrim($rendered, "\n")) as $line) {
    check(Ansi::visibleWidth($line) <= 60, 'markdown line exceeds width');
}
$stripped = Ansi::strip($rendered);
check(str_contains($stripped, 'Title'), 'heading text missing');
check(str_contains($stripped, 'quote'), 'blockquote text missing');
check(str_contains($stripped, 'echo "hi";'), 'code block content missing');
check(str_contains($stripped, 'A') && str_contains($stripped, '1'), 'table content missing');

$plainRenderer = new Markdown(50, new Theme(false, false, 0));
$plainTable = $plainRenderer->render("| A | B |\n| --- | --- |\n| 1 | 2 |\n");
check(!str_contains($plainTable, "\033["), 'monochrome markdown contains ANSI');
check(str_contains($plainTable, 'A') && str_contains($plainTable, '1'), 'monochrome table content missing');

$long = $renderer->render(str_repeat('word ', 40));
foreach (explode("\n", rtrim($long, "\n")) as $line) {
    check(Ansi::visibleWidth($line) <= 60, 'long paragraph exceeds width');
}

$narrow = new Markdown(40, $markdownTheme);
$narrowTable = $narrow->render("| Identifier | Description |\n| --- | --- |\n| alpha | " . str_repeat('long ', 20) . "|\n");
foreach (explode("\n", rtrim($narrowTable, "\n")) as $line) {
    check(Ansi::visibleWidth($line) <= 40, 'narrow table exceeds width');
}

$cjk = $renderer->render(str_repeat('中文测试 ', 30));
foreach (explode("\n", rtrim($cjk, "\n")) as $line) {
    check(Ansi::visibleWidth($line) <= 60, 'CJK paragraph exceeds width');
}

check($renderer->render('') === '', 'empty markdown should render empty');

// Model-supplied ANSI must not survive.
$injected = $renderer->render("safe \e]52;c;Zm9v\x07 text");
check(!str_contains($injected, "\e]52"), 'markdown leaked OSC injection');

// --- Terminal ----------------------------------------------------------------

$out = '';
$err = '';
$captureOut = static function (string $text) use (&$out): void {
    $out .= $text;
};
$captureErr = static function (string $text) use (&$err): void {
    $err .= $text;
};

$terminal = new Terminal(true, true, [
    'color' => 'always',
    'unicode' => 'always',
    'width' => 60,
    'banner' => true,
    'usage' => true,
], $captureOut, $captureErr);

check($terminal->width() === 60, 'terminal width wrong');
check($terminal->banner([
    'model' => 'test-model',
    'workspace' => 'D:/Project/MyAgent',
    'session' => null,
    'security' => 'off',
    'tools' => '5 local',
    'mcp' => '0 servers',
]), 'banner not shown');
check(str_contains($err, 'MyAgent'), 'banner title missing');
check(str_contains($err, 'test-model'), 'banner model missing');
check(str_contains($err, "\033["), 'banner not styled');
foreach (explode("\n", rtrim($err, "\n")) as $line) {
    check(Ansi::visibleWidth($line) <= 60, 'banner exceeds width');
}

$out = '';
$terminal->prompt();
check(str_contains($out, '❯'), 'styled prompt missing');
check(str_contains($out, "\033["), 'styled prompt not colored');

$out = '';
$err = '';
$plainTerminal = new Terminal(false, false, [
    'color' => 'never',
    'unicode' => 'never',
    'markdown' => false,
    'spinner' => false,
    'banner' => false,
    'usage' => false,
    'width' => 60,
], $captureOut, $captureErr);
$plainTerminal->prompt();
check($out === 'you> ', 'plain prompt changed');
check(!str_contains($out, "\033["), 'plain prompt contains ANSI');
check($plainTerminal->spinner('Thinking') === null, 'plain terminal returned a spinner');

$err = '';
$terminal->toolStart('read_file', ['path' => 'README.md']);
$terminal->toolResult('read_file', ['path' => 'README.md'], "line1\nline2", false, 0.003);
check(str_contains($err, 'read_file'), 'tool name missing');
check(str_contains($err, '2 lines'), 'tool line count missing');

$err = '';
$terminal->toolResult('run_command', ['command' => 'false'], "output\n[exit code: 1]", false, 1.2);
check(str_contains($err, 'exit 1'), 'exit code missing');

$err = '';
$terminal->toolResult('read_file', ['path' => 'x'], 'Error: boom', true, 0.1);
check(str_contains($err, 'boom'), 'tool error missing');

$err = '';
$terminal->securityDenied([
    'verdict' => 'deny',
    'risk' => 'high',
    'category' => 'destructive',
    'reason' => 'Nope.',
]);
check(str_contains(Ansi::strip($err), 'security deny'), 'security deny text missing');

$err = '';
$terminal->securityApproval(
    ['name' => 'run_command', 'input' => ['command' => 'git push origin main']],
    ['risk' => 'high', 'category' => 'shared-state', 'reason' => 'Pushes to a shared branch.']
);
check(str_contains(Ansi::strip($err), 'Security approval required'), 'approval title missing');
check(str_contains(Ansi::strip($err), 'Approve? [y/N]'), 'approval prompt missing');

$err = '';
$terminal->usage(['input_tokens' => 1200, 'output_tokens' => 348, 'cache_read_input_tokens' => 900], 2.4, 'end_turn');
check(str_contains(Ansi::strip($err), '1.2k in'), 'usage input missing');
check(str_contains(Ansi::strip($err), '348 out'), 'usage output missing');
check(str_contains(Ansi::strip($err), 'end_turn'), 'usage stop reason missing');

$err = '';
$spinner = $terminal->spinner('Thinking');
check($spinner !== null, 'styled terminal returned no spinner');
$spinner->start();
$spinner->tick(true);
$spinner->stop();
check(str_contains($err, "\r"), 'spinner did not redraw');
check(str_contains($err, 'Thinking'), 'spinner label missing');

// Narrow terminal: status, usage, tool, security and ask_user lines must fit.
$out = '';
$err = '';
$narrow = new Terminal(true, true, [
    'color' => 'always',
    'unicode' => 'always',
    'width' => 40,
    'usage' => true,
    'spinner' => false,
], $captureOut, $captureErr);
$narrow->warning(str_repeat('long warning ', 10));
$narrow->usage(['input_tokens' => 12345, 'output_tokens' => 6789], 1.2, 'end_turn');
$narrow->toolStart('run_command', ['command' => str_repeat('x', 100)]);
$narrow->toolResult('read_file', ['path' => 'README.md'], str_repeat("line\n", 10), false, 0.1);
$narrow->securityDenied([
    'verdict' => 'deny',
    'risk' => 'high',
    'category' => 'destructive',
    'reason' => str_repeat('long reason ', 8),
]);
$narrow->securityApproval(
    ['name' => 'run_command', 'input' => ['command' => 'git push origin main']],
    ['risk' => 'high', 'category' => 'shared-state', 'reason' => str_repeat('long reason ', 8)]
);
foreach (explode("\n", rtrim($err, "\n")) as $line) {
    check(Ansi::visibleWidth($line) <= 40, 'narrow terminal line exceeds width');
}

$narrowAskOutput = '';
$narrowAskWrite = static function (string $text) use (&$narrowAskOutput): void {
    $narrowAskOutput .= $text;
};
$narrowAskTerminal = new Terminal(true, true, ['color' => 'always', 'unicode' => 'always', 'width' => 40], null, $narrowAskWrite);
$narrowAsk = new AskUser(static fn(): ?string => "1\n", $narrowAskWrite, $narrowAskTerminal);
$narrowAsk->ask(str_repeat('long question ', 6), [str_repeat('long option ', 6)]);
foreach (explode("\n", rtrim($narrowAskOutput, "\n")) as $line) {
    check(Ansi::visibleWidth($line) <= 40, 'narrow ask line exceeds width');
}

// --- AskUser -----------------------------------------------------------------

$askOutput = '';
$askWrite = static function (string $text) use (&$askOutput): void {
    $askOutput .= $text;
};
$askTerminal = new Terminal(true, true, ['color' => 'always', 'unicode' => 'always'], null, $askWrite);
$askStyled = new AskUser(static fn(): ?string => "2\n", $askWrite, $askTerminal);
check($askStyled->ask('Pick', ['staging', 'production']) === 'production', 'styled ask selection failed');
check(str_contains($askOutput, "\033["), 'styled ask has no ANSI');
$askPlainText = Ansi::strip($askOutput);
check(str_contains($askPlainText, '1) staging'), 'styled ask lost plain option text');
check(str_contains($askPlainText, '3) Other - type your own answer'), 'styled ask lost Other line');

$askOutput = '';
$askPlain = new AskUser(static fn(): ?string => "1\n", $askWrite);
check($askPlain->ask('Pick', ['A']) === 'A', 'plain ask selection failed');
check(!str_contains($askOutput, "\033["), 'plain ask contains ANSI');
check(str_contains($askOutput, '[?] Pick'), 'plain ask question changed');
check(str_contains($askOutput, '2) Other - type your own answer'), 'plain ask Other line changed');

fwrite(STDOUT, "UI tests passed\n");
