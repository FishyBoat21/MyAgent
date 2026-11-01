<?php
declare(strict_types=1);

/**
 * Test double: a stream that lies about EOF.
 *
 * The first read returns '' and reports end-of-file — exactly what a Windows
 * regular file looks like after another process advanced the shared file position
 * (see docs/plans/IMPLEMENTATION_PLAN-bridge-file-stdin.md §1.2). The second read
 * returns the buffered payload. Bridge must not give up on the first empty read.
 *
 * Register with `stream_wrapper_register('flakystream', FlakyStream::class)` and
 * set `FlakyStream::$payload` before opening `flakystream://channel`.
 */
final class FlakyStream
{
    public static string $payload = '';

    /** @var resource|null Set by PHP for wrapper context. */
    public $context;

    private string $data = '';
    private int $offset = 0;
    private int $served = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->data = self::$payload;
        $this->offset = 0;
        $this->served = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        $this->served++;
        if ($this->served === 1) {
            return '';
        }
        $chunk = substr($this->data, $this->offset, $count);
        $this->offset += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        // Claims EOF right after the first (empty) read, like the real defect.
        return $this->served >= 1;
    }

    /** @return array<string, int> */
    public function stream_stat(): array
    {
        // A regular file that still holds bytes we have not seen.
        return ['mode' => 0100666, 'size' => strlen($this->data)];
    }

    public function stream_tell(): int
    {
        // Our own cursor never advanced; the position was moved behind our back.
        return 0;
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return false;
    }

    public function stream_write(string $data): int
    {
        return 0;
    }

    public function stream_close(): void
    {
    }
}
