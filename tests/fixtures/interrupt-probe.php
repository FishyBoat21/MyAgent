<?php
declare(strict_types=1);

/**
 * Fixture: exercise the real Ctrl+C handler the way agent.php wires it.
 *
 * Driven by tests/cancellation.php, which gives this process its own console
 * process group and sends it CTRL_BREAK events.
 *
 * Modes ($argv[1]):
 *  - stop (default): a turn is running, so the event stops it and the process
 *    must stay alive;
 *  - idle: no turn is running, so no handler is installed and the OS default
 *    must end the process;
 *  - twice: a turn is running, the first event stops it and the second, with a
 *    stop already pending, must end the process from the main thread.
 */

$autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Dependencies are not installed. Run: composer install\n");
    exit(1);
}
require $autoload;

use MyAgent\Cancellation;
use MyAgent\Interrupt;

$mode = $argv[1] ?? 'stop';

$cancel = new Cancellation();
$cancel->setInterruptible(true);
if ($mode !== 'idle') {
    $cancel->beginTurn(); // arms the handler, exactly like Agent::run()
}
echo 'armed=' . (Interrupt::armed() ? '1' : '0') . ' mode=' . $mode . "\n";
fflush(STDOUT);

// Block like a request in flight. The console handler runs on its own thread,
// so the main thread does not have to cooperate for the event to arrive; poll()
// is where a forced exit is honoured, again like the agent's wait loops.
$deadline = microtime(true) + 5.0;
while (microtime(true) < $deadline) {
    $cancel->poll();
    if ($mode === 'stop' && $cancel->requested()) {
        break;
    }
    usleep(50000);
}

echo 'cancelled=' . ($cancel->requested() ? '1' : '0')
    . ' reason=' . ($cancel->reason() === '' ? 'none' : $cancel->reason()) . "\n";
echo "alive\n";
fflush(STDOUT);
