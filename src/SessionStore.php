<?php
declare(strict_types=1);

namespace MyAgent;

use RuntimeException;

final class SessionStore
{
    private string $path;
    private mixed $lock;

    public function __construct(string $root, string $name)
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $name)) {
            throw new RuntimeException('Session name must use letters, numbers, underscores or hyphens');
        }
        $directory = $root . '/.agent/sessions';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create session directory');
        }
        $base = realpath($root);
        $resolved = realpath($directory);
        if ($base === false || $resolved === false || !str_starts_with(strtolower($resolved), strtolower(rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('Session directory escapes project');
        }
        $this->path = $directory . '/' . $name . '.json';
        $this->lock = fopen($directory . '/' . $name . '.lock', 'c');
        if ($this->lock === false || !flock($this->lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($this->lock)) {
                fclose($this->lock);
            }
            throw new RuntimeException('Session is already open in another process');
        }
    }

    public function load(): ?array
    {
        if (is_link($this->path)) {
            throw new RuntimeException('Session file cannot be a symbolic link');
        }
        if (!is_file($this->path)) {
            return null;
        }
        $state = json_decode((string) file_get_contents($this->path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($state) || ($state['version'] ?? null) !== 1 || !is_array($state['messages'] ?? null)) {
            throw new RuntimeException('Invalid session file: ' . $this->path);
        }
        return $state;
    }

    public function save(array $state): void
    {
        if (is_link($this->path)) {
            throw new RuntimeException('Session file cannot be a symbolic link');
        }
        $directory = dirname($this->path);
        $temp = tempnam($directory, '.session-');
        if ($temp === false) {
            throw new RuntimeException('Could not create session checkpoint');
        }
        try {
            $json = json_encode(
                $state,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
            );
            if (file_put_contents($temp, $json, LOCK_EX) !== strlen($json) || !rename($temp, $this->path)) {
                throw new RuntimeException('Could not save session checkpoint');
            }
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    public function __destruct()
    {
        if (is_resource($this->lock)) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
        }
    }
}
