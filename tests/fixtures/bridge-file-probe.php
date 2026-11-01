<?php
declare(strict_types=1);

/**
 * Reproducer / regression fixture for the `--bridge` redirected-stdin defect.
 *
 * It mirrors the two startup pieces of agent.php that matter here, in the same
 * order:
 *
 *   1. Terminal::detect() — resolves the render width, which on Windows probes
 *      the console with `mode con`. Before the fix that child inherited our
 *      stdin and, with a redirected regular file, left the shared position at
 *      EOF, so the command file was already gone by step 2.
 *   2. Bridge::nextCommand() — the JSONL command channel itself.
 *
 * Prints one line: `width=<n> command=<type>` (or `command=none` when the
 * channel looked closed). No network, no provider, no MCP, no agent.
 */

$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Dependencies are not installed. Run: composer install\n");
    exit(1);
}
require $autoload;

$terminal = MyAgent\Terminal::detect([]);
$bridge = new MyAgent\Bridge();
$command = $bridge->nextCommand();

echo 'width=' . $terminal->width()
    . ' command=' . ($command === null ? 'none' : (string) ($command['type'] ?? '?'))
    . "\n";
