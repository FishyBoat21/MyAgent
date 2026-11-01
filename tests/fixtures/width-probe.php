<?php
declare(strict_types=1);

/**
 * Width-probe fixture: run Terminal::detect() (which may probe the console for a
 * width) and then read STDIN, reporting both.
 *
 * Used by tests/ui.php with stdin redirected from a file and stdout on a pipe, to
 * prove the probe cannot consume our stdin (see
 * docs/plans/IMPLEMENTATION_PLAN-bridge-file-stdin.md §1.2).
 *
 * Prints: `width=<n> len=<bytes read from stdin>`
 */

$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Dependencies are not installed. Run: composer install\n");
    exit(1);
}
require $autoload;

$terminal = MyAgent\Terminal::detect([]);
$chunk = @fread(STDIN, 65536);

echo 'width=' . $terminal->width() . ' len=' . strlen((string) $chunk) . "\n";
