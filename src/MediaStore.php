<?php
declare(strict_types=1);

namespace MyAgent;

use RuntimeException;
use Throwable;

/**
 * Content-addressed store for normalised images.
 *
 * Media lives in <root>/.agent/media/<sha256[0:2]>/<sha256>.<ext> (0700 dirs,
 * 0600 files). Two jobs:
 *
 *  1. put()/bytes() — keep the bytes on disk so a conversation can carry the
 *     same image many times, and across sessions, without duplicating them.
 *  2. dehydrate()/hydrate() — session checkpoints must never contain base64.
 *     A checkpoint stores `myagent_media` source markers and the process
 *     restores the real image blocks when the session is loaded.
 *
 * Invariant: a `myagent_media` marker must never reach the provider. It only
 * exists inside a checkpoint file.
 */
final class MediaStore
{
    /** @var array<string, string> hash('xxh128', base64) => sha256, so each image is hashed once per process */
    private array $memo = [];

    public function __construct(private string $root, private MediaLimits $limits)
    {
    }

    public function limits(): MediaLimits
    {
        return $this->limits;
    }

    /** Absolute directory holding the store. */
    public function directory(): string
    {
        return rtrim(str_replace('\\', '/', $this->root), '/') . '/' . trim($this->limits->store, '/');
    }

    /**
     * Store the bytes and return a MediaRef.
     *
     * @return array<string, mixed>
     * @throws RuntimeException when the store cannot be written.
     */
    public function put(string $bytes, string $mediaType, string $name): array
    {
        $sha = hash('sha256', $bytes);
        $path = $this->path($sha, $mediaType);
        $directory = dirname($path);
        if (!is_dir($directory)) {
            if (!@mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new RuntimeException('Could not store the image under ' . $this->limits->store);
            }
        }
        if (!is_file($path)) {
            $temp = @tempnam($directory, '.media-');
            if ($temp === false) {
                throw new RuntimeException('Could not store the image under ' . $this->limits->store);
            }
            try {
                if (@file_put_contents($temp, $bytes, LOCK_EX) !== strlen($bytes) || !@rename($temp, $path)) {
                    throw new RuntimeException('Could not store the image under ' . $this->limits->store);
                }
                @chmod($path, 0600);
            } finally {
                if (is_file($temp)) {
                    @unlink($temp);
                }
            }
            if (@hash_file('sha256', $path) !== $sha) {
                @unlink($path);
                throw new RuntimeException('Could not store the image under ' . $this->limits->store);
            }
        }
        return [
            'sha256' => $sha,
            'media_type' => $mediaType,
            'bytes' => strlen($bytes),
            'width' => null,
            'height' => null,
            'name' => $name,
            'path' => $path,
            'source' => 'user',
        ];
    }

    public function path(string $sha256, string $mediaType): string
    {
        $sha256 = preg_replace('/[^0-9a-f]/', '', strtolower($sha256)) ?? '';
        if (strlen($sha256) !== 64) {
            throw new RuntimeException('Invalid media hash');
        }
        return $this->directory() . '/' . substr($sha256, 0, 2) . '/' . $sha256 . '.' . Mime::extensionFor($mediaType);
    }

    public function has(string $sha256): bool
    {
        try {
            return is_file($this->path($sha256, 'image/png'));
        } catch (Throwable) {
            return false;
        }
    }

    /** The stored bytes, or null when they are gone. */
    public function bytes(string $sha256, string $mediaType): ?string
    {
        try {
            $path = $this->path($sha256, $mediaType);
        } catch (Throwable) {
            return null;
        }
        if (!is_file($path)) {
            // The extension may differ from the marker's (re-encoded image).
            foreach (Mime::PROVIDER_IMAGES as $candidate) {
                $other = $this->path($sha256, $candidate);
                if (is_file($other)) {
                    $path = $other;
                    break;
                }
            }
        }
        if (!is_file($path)) {
            return null;
        }
        $bytes = @file_get_contents($path);
        return $bytes === false ? null : $bytes;
    }

    /**
     * Replace inline base64 with store markers so a checkpoint stays small.
     *
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    public function dehydrate(array $messages): array
    {
        foreach ($messages as $index => $message) {
            $content = $message['content'] ?? null;
            if (!is_array($content)) {
                continue;
            }
            $changed = false;
            foreach ($content as $blockIndex => $block) {
                if (!is_array($block) || ($block['type'] ?? '') !== 'image') {
                    continue;
                }
                $source = is_array($block['source'] ?? null) ? $block['source'] : [];
                if (($source['type'] ?? '') !== 'base64' || !is_string($source['data'] ?? null)) {
                    continue;
                }
                $raw = base64_decode($source['data'], true);
                if ($raw === false) {
                    continue;
                }
                $mediaType = (string) ($source['media_type'] ?? 'image/png');
                $sha = $this->shaOf($source['data'], $raw);
                try {
                    $this->put($raw, $mediaType, (string) ($source['name'] ?? 'image'));
                } catch (Throwable) {
                    // A read-only store must not break the run: keep the base64 inline.
                    continue;
                }
                $info = @getimagesizefromstring($raw);
                $marker = [
                    'type' => 'image',
                    'source' => [
                        'type' => 'myagent_media',
                        'sha256' => $sha,
                        'media_type' => $mediaType,
                        'bytes' => strlen($raw),
                    ],
                ];
                if ($info !== false) {
                    $marker['source']['width'] = (int) $info[0];
                    $marker['source']['height'] = (int) $info[1];
                }
                $content[$blockIndex] = $marker;
                $changed = true;
            }
            if ($changed) {
                $messages[$index]['content'] = $content;
            }
        }
        return $messages;
    }

    /**
     * Restore inline base64 from store markers; a missing file becomes a text
     * placeholder so the request body stays valid.
     *
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    public function hydrate(array $messages): array
    {
        foreach ($messages as $index => $message) {
            $content = $message['content'] ?? null;
            if (!is_array($content)) {
                continue;
            }
            $changed = false;
            foreach ($content as $blockIndex => $block) {
                if (!is_array($block) || ($block['type'] ?? '') !== 'image') {
                    continue;
                }
                $source = is_array($block['source'] ?? null) ? $block['source'] : [];
                if (($source['type'] ?? '') !== 'myagent_media') {
                    continue;
                }
                $sha = (string) ($source['sha256'] ?? '');
                $mediaType = (string) ($source['media_type'] ?? 'image/png');
                $bytes = $sha === '' ? null : $this->bytes($sha, $mediaType);
                if ($bytes === null) {
                    $content[$blockIndex] = [
                        'type' => 'text',
                        'text' => '[image unavailable: the local copy for sha ' . substr($sha, 0, 8)
                            . ' is missing; ask the user to attach it again]',
                    ];
                } else {
                    $content[$blockIndex] = Mime::imageBlock($bytes, $mediaType);
                }
                $changed = true;
            }
            if ($changed) {
                $messages[$index]['content'] = $content;
            }
        }
        return $messages;
    }

    /** True when any message carries a store marker (=> checkpoint version 2). */
    public static function hasMarkers(array $messages): bool
    {
        foreach ($messages as $message) {
            $content = $message['content'] ?? null;
            if (!is_array($content)) {
                continue;
            }
            foreach ($content as $block) {
                if (!is_array($block) || ($block['type'] ?? '') !== 'image') {
                    continue;
                }
                if (is_array($block['source'] ?? null) && ($block['source']['type'] ?? '') === 'myagent_media') {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Delete stored images older than the retention window that no session references.
     *
     * @param list<string> $referencedSha
     * @return array{deleted: int, kept: int, bytes: int}
     */
    public function prune(int $retainHours, array $referencedSha = []): array
    {
        $result = ['deleted' => 0, 'kept' => 0, 'bytes' => 0];
        if ($retainHours <= 0) {
            return $result;
        }
        $directory = $this->directory();
        if (!is_dir($directory)) {
            return $result;
        }
        $referenced = array_fill_keys($referencedSha, true);
        $cutoff = time() - $retainHours * 3600;
        $budget = 500;
        foreach (glob($directory . '/*', GLOB_ONLYDIR) ?: [] as $shard) {
            foreach (glob($shard . '/*') ?: [] as $file) {
                if ($budget <= 0) {
                    return $result;
                }
                if (!is_file($file)) {
                    continue;
                }
                $sha = strtolower((string) pathinfo($file, PATHINFO_FILENAME));
                if (isset($referenced[$sha])) {
                    $result['kept']++;
                    continue;
                }
                $mtime = @filemtime($file);
                if ($mtime !== false && $mtime > $cutoff) {
                    $result['kept']++;
                    continue;
                }
                $size = (int) @filesize($file);
                if (@unlink($file)) {
                    $result['deleted']++;
                    $result['bytes'] += $size;
                    $budget--;
                }
            }
        }
        return $result;
    }

    /**
     * Shas referenced by session checkpoints (bounded scan).
     *
     * @return list<string>
     */
    public static function referencedBy(string $root, int $maxFiles = 64): array
    {
        $directory = rtrim(str_replace('\\', '/', $root), '/') . '/.agent/sessions';
        if (!is_dir($directory)) {
            return [];
        }
        $files = glob($directory . '/*.json') ?: [];
        $files = array_slice($files, 0, max(1, $maxFiles));
        $shas = [];
        foreach ($files as $file) {
            $text = @file_get_contents($file, false, null, 0, 4_000_000);
            if (!is_string($text) || $text === '') {
                continue;
            }
            if (preg_match_all('/"sha256"\s*:\s*"([0-9a-f]{64})"/', $text, $matches) > 0) {
                foreach ($matches[1] as $sha) {
                    $shas[$sha] = true;
                }
            }
        }
        return array_keys($shas);
    }

    /**
     * sha256 of the decoded bytes, memoised on the base64 text so a checkpoint
     * rehashes each image once per process instead of once per write.
     */
    private function shaOf(string $base64, string $raw): string
    {
        $key = hash('sha256', $base64);
        if (!isset($this->memo[$key])) {
            $this->memo[$key] = hash('sha256', $raw);
        }
        return $this->memo[$key];
    }
}
