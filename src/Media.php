<?php
declare(strict_types=1);

namespace MyAgent;

use RuntimeException;

/**
 * Image type detection from magic bytes.
 *
 * ext-fileinfo is not part of the guaranteed extension set (it is absent on the
 * development machine), so types are sniffed here. The extension is only a hint:
 * it drives @mention filtering and error text, never the type that reaches the
 * provider.
 */
final class Mime
{
    /** Media types the Messages API accepts as image blocks. */
    public const PROVIDER_IMAGES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /** Extension hint => media type. */
    public const IMAGE_EXT = [
        '.png' => 'image/png',
        '.jpg' => 'image/jpeg',
        '.jpeg' => 'image/jpeg',
        '.gif' => 'image/gif',
        '.webp' => 'image/webp',
        '.bmp' => 'image/bmp',
        '.avif' => 'image/avif',
        '.tif' => 'image/tiff',
        '.tiff' => 'image/tiff',
        '.tga' => 'image/tga',
    ];

    /** Human-readable list used in error messages. */
    public const ACCEPTED_HINT = 'png, jpeg, gif, webp; bmp, tiff, avif and tga are converted';

    /** Detect an image type from the bytes; null when it is not a recognised image. */
    public static function sniff(string $bytes): ?string
    {
        $length = strlen($bytes);
        if ($length < 4) {
            return null;
        }
        $head = substr($bytes, 0, 16);
        if (str_starts_with($head, "\x89PNG\r\n\x1a\n")) {
            return 'image/png';
        }
        if (str_starts_with($head, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a')) {
            return 'image/gif';
        }
        if ($length >= 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        if ($length > 18 && str_starts_with($head, 'BM')) {
            // Offset 2 holds the file size; the DIB header size sits at offset 14.
            $dibSize = (int) (unpack('V', substr($bytes, 14, 4))[1] ?? 0);
            if (in_array($dibSize, [12, 16, 40, 52, 56, 64, 108, 124], true)) {
                return 'image/bmp';
            }
        }
        if ($length >= 12 && substr($bytes, 4, 4) === 'ftyp') {
            $brand = substr($bytes, 8, 4);
            if ($brand === 'avif' || $brand === 'avis') {
                return 'image/avif';
            }
        }
        if (str_starts_with($head, "II*\x00") || str_starts_with($head, "MM\x00*")) {
            return 'image/tiff';
        }
        // TGA has no leading magic; the 26-byte footer is the only reliable marker.
        if ($length > 26 && substr($bytes, -18) === "TRUEVISION-XFILE.\x00") {
            return 'image/tga';
        }
        return null;
    }

    /** Media type implied by the file extension, or null. */
    public static function fromExtension(string $path): ?string
    {
        $extension = strtolower(strrchr($path, '.') === false ? '' : (string) strrchr($path, '.'));
        return self::IMAGE_EXT[$extension] ?? null;
    }

    /** True when the path carries an image extension (used by @mentions). */
    public static function looksLikeImage(string $path): bool
    {
        return self::fromExtension($path) !== null;
    }

    public static function isProviderImage(string $mediaType): bool
    {
        return in_array($mediaType, self::PROVIDER_IMAGES, true);
    }

    /** File extension for a stored media type (no leading dot). */
    public static function extensionFor(string $mediaType): string
    {
        return match ($mediaType) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/bmp' => 'bmp',
            'image/avif' => 'avif',
            'image/tiff' => 'tif',
            'image/tga' => 'tga',
            default => 'bin',
        };
    }

    /** One Anthropic image content block. */
    public static function imageBlock(string $bytes, string $mediaType): array
    {
        return [
            'type' => 'image',
            'source' => [
                'type' => 'base64',
                'media_type' => $mediaType,
                'data' => base64_encode($bytes),
            ],
        ];
    }

    /** One-line descriptor for terminal, bridge, reviewer and placeholders. */
    public static function describe(array $ref): string
    {
        $parts = ['image', self::subtype((string) ($ref['media_type'] ?? ''))];
        $width = (int) ($ref['width'] ?? 0);
        $height = (int) ($ref['height'] ?? 0);
        if ($width > 0 && $height > 0) {
            $parts[] = $width . '×' . $height;
        }
        $parts[] = self::formatBytes((int) ($ref['bytes'] ?? 0));
        $sha = (string) ($ref['sha256'] ?? '');
        if ($sha !== '') {
            $parts[] = 'sha ' . substr($sha, 0, 8);
        }
        return '[' . implode(' ', $parts) . ']';
    }

    /** Descriptor for an image block that is already on the wire (no MediaRef available). */
    public static function describeBlock(array $block): string
    {
        $source = is_array($block['source'] ?? null) ? $block['source'] : [];
        $mediaType = (string) ($source['media_type'] ?? 'image');
        $parts = ['image', self::subtype($mediaType)];
        $data = $source['data'] ?? null;
        if (is_string($data) && $data !== '') {
            // base64 is 4 chars per 3 bytes.
            $parts[] = self::formatBytes((int) round(strlen($data) * 3 / 4));
        }
        return '[' . implode(' ', $parts) . ']';
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1_048_576) {
            $value = number_format($bytes / 1_048_576, 1, '.', '');
            return rtrim(rtrim($value, '0'), '.') . ' MB';
        }
        if ($bytes >= 1024) {
            return (string) (int) round($bytes / 1024) . ' kB';
        }
        return $bytes . ' B';
    }

    /** The part after the slash in a media type ("image/png" => "png"). */
    public static function subtype(string $mediaType): string
    {
        $slash = strpos($mediaType, '/');
        return $slash === false ? $mediaType : substr($mediaType, $slash + 1);
    }
}

/**
 * A media failure that should be reported to the user (and, for tools, to the
 * model) instead of crashing the run.
 */
final class MediaError extends RuntimeException
{
    public const UNSUPPORTED = 'unsupported';
    public const TOO_LARGE = 'too-large';
    public const TOO_BIG_PX = 'too-many-pixels';
    public const UNDECODABLE = 'undecodable';
    public const DISABLED = 'disabled';
    public const OUTSIDE = 'outside-project';
    public const MISSING = 'missing';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}

/** Limits and switches for image handling; built once from the config "media" block. */
final class MediaLimits
{
    /** @param list<string> $inputModalities */
    public function __construct(
        public bool $enabled = true,
        public array $inputModalities = ['text', 'image'],
        public int $maxBytes = 5_242_880,
        public int $maxEdge = 1568,
        public int $maxDimension = 8000,
        public int $maxPixels = 40_000_000,
        public int $maxImagesPerMessage = 20,
        public int $maxImagesPerRequest = 20,
        public int $maxImagesPerToolResult = 4,
        public string $store = '.agent/media',
        public int $retainHours = 168,
        public string $mentions = 'images',
        public bool $resize = true,
    ) {
    }

    /**
     * Build from the config block; every invalid value throws one actionable sentence.
     *
     * @param array<string, mixed> $media
     */
    public static function fromConfig(array $media): self
    {
        $defaults = new self();
        $limits = new self();

        $limits->enabled = self::boolOf($media['enabled'] ?? null, $defaults->enabled);
        $limits->resize = self::boolOf($media['resize'] ?? null, $defaults->resize);

        if (array_key_exists('input_modalities', $media)) {
            $raw = $media['input_modalities'];
            if (!is_array($raw)) {
                throw new RuntimeException('media.input_modalities must be an array of modality names');
            }
            $modalities = [];
            foreach ($raw as $entry) {
                $entry = strtolower(trim((string) $entry));
                if ($entry === 'text' || $entry === 'image') {
                    $modalities[$entry] = true;
                }
            }
            $limits->inputModalities = array_values(array_filter(
                ['text', 'image'],
                static fn(string $name): bool => isset($modalities[$name])
            ));
            if ($limits->inputModalities === []) {
                throw new RuntimeException('media.input_modalities must contain at least "text"');
            }
        }

        $limits->maxBytes = self::intOf($media, 'max_bytes', $defaults->maxBytes, 1024, 100_000_000);
        $limits->maxEdge = self::intOf($media, 'max_edge', $defaults->maxEdge, 0, 8000);
        $limits->maxDimension = self::intOf($media, 'max_dimension', $defaults->maxDimension, 1, 100_000);
        $limits->maxPixels = self::intOf($media, 'max_pixels', $defaults->maxPixels, 1, 2_000_000_000);
        $limits->maxImagesPerMessage = self::intOf($media, 'max_images_per_message', $defaults->maxImagesPerMessage, 1, 100);
        $limits->maxImagesPerRequest = self::intOf($media, 'max_images_per_request', $defaults->maxImagesPerRequest, 1, 100);
        $limits->maxImagesPerToolResult = self::intOf($media, 'max_images_per_tool_result', $defaults->maxImagesPerToolResult, 0, 100);
        $limits->retainHours = self::intOf($media, 'retain_hours', $defaults->retainHours, 0, 8760);

        if ($limits->maxEdge > $limits->maxDimension) {
            throw new RuntimeException('media.max_edge must not be greater than media.max_dimension');
        }

        $store = trim(str_replace('\\', '/', (string) ($media['store'] ?? $defaults->store)));
        if ($store === '' || preg_match('#\A(?:[A-Za-z]:|/)#', $store) === 1 || preg_match('#(^|/)\.\.(/|$)#', $store) === 1) {
            throw new RuntimeException('media.store must be a relative path inside the project');
        }
        $limits->store = $store;

        $mentions = strtolower(trim((string) ($media['mentions'] ?? $defaults->mentions)));
        if (!in_array($mentions, ['images', 'all', 'off'], true)) {
            throw new RuntimeException('media.mentions must be images, all, or off');
        }
        $limits->mentions = $mentions;

        return $limits;
    }

    public function withImagesEnabled(bool $enabled): self
    {
        $copy = clone $this;
        $copy->enabled = $enabled;
        return $copy;
    }

    /** @throws RuntimeException when the override is outside the valid range. */
    public function withMaxEdge(int $maxEdge): self
    {
        if ($maxEdge < 0 || $maxEdge > $this->maxDimension) {
            throw new RuntimeException('media.max_edge must be between 0 and ' . $this->maxDimension);
        }
        $copy = clone $this;
        $copy->maxEdge = $maxEdge;
        return $copy;
    }

    /** @param list<string> $modalities */
    public function withModalities(array $modalities): self
    {
        $copy = clone $this;
        $copy->inputModalities = array_values($modalities);
        return $copy;
    }

    public function supportsImages(): bool
    {
        return $this->enabled && in_array('image', $this->inputModalities, true);
    }

    /** @param mixed $value @param bool $default */
    private static function boolOf(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            $value = strtolower(trim($value));
            if ($value === '') {
                return $default;
            }
            if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }
        return $default;
    }

    /** @param array<string, mixed> $media */
    private static function intOf(array $media, string $key, int $default, int $min, int $max): int
    {
        if (!array_key_exists($key, $media) || $media[$key] === null || $media[$key] === '') {
            return $default;
        }
        $value = $media[$key];
        if (is_string($value) && !is_numeric(trim($value))) {
            throw new RuntimeException('media.' . $key . ' must be a number');
        }
        if (!is_numeric($value)) {
            throw new RuntimeException('media.' . $key . ' must be a number');
        }
        $int = (int) $value;
        if ($int < $min || $int > $max) {
            throw new RuntimeException("media.{$key} must be between {$min} and {$max}");
        }
        return $int;
    }
}

/**
 * Read → sniff → validate → normalise → store.
 *
 * Every check runs before the step it protects: size before reading, dimensions
 * before decoding, memory before GD allocates.
 */
final class MediaNormalizer
{
    public function __construct(private MediaLimits $limits, private MediaStore $store)
    {
    }

    public function limits(): MediaLimits
    {
        return $this->limits;
    }

    /**
     * @return array<string, mixed> MediaRef
     * @throws MediaError
     */
    public function fromFile(string $absolutePath, string $displayName, string $source): array
    {
        if (!is_file($absolutePath)) {
            throw new MediaError(MediaError::MISSING, "media: not a file: {$displayName}");
        }
        $size = @filesize($absolutePath);
        if ($size === false) {
            throw new MediaError(MediaError::MISSING, "media: could not read {$displayName}");
        }
        if ($size <= 0) {
            throw new MediaError(MediaError::UNSUPPORTED, "media: {$displayName} is empty");
        }
        if ($size > $this->limits->maxBytes * 2) {
            throw new MediaError(MediaError::TOO_LARGE, sprintf(
                'media: %s is %s; media.max_bytes is %s.',
                $displayName,
                Mime::formatBytes($size),
                Mime::formatBytes($this->limits->maxBytes)
            ));
        }
        $bytes = @file_get_contents($absolutePath);
        if ($bytes === false) {
            throw new MediaError(MediaError::MISSING, "media: could not read {$displayName}");
        }
        return $this->fromBytes($bytes, $displayName, $source);
    }

    /**
     * @return array<string, mixed> MediaRef
     * @throws MediaError
     */
    public function fromBytes(string $bytes, string $displayName, string $source = 'user'): array
    {
        if (!$this->limits->enabled) {
            throw new MediaError(MediaError::DISABLED, 'media: image support is disabled (media.enabled)');
        }
        if (strlen($bytes) > $this->limits->maxBytes * 2) {
            throw new MediaError(MediaError::TOO_LARGE, sprintf(
                'media: %s is %s after reading; media.max_bytes is %s.',
                $displayName,
                Mime::formatBytes(strlen($bytes)),
                Mime::formatBytes($this->limits->maxBytes)
            ));
        }
        $mediaType = Mime::sniff($bytes);
        if ($mediaType === null) {
            throw new MediaError(MediaError::UNSUPPORTED, sprintf(
                'media: %s is not an image. Accepted: %s.',
                $displayName,
                Mime::ACCEPTED_HINT
            ));
        }

        [$width, $height] = $this->dimensions($bytes, $displayName);
        if ($width > 0 && $height > 0) {
            if (max($width, $height) > $this->limits->maxDimension) {
                throw new MediaError(MediaError::TOO_BIG_PX, sprintf(
                    'media: %s is %d×%d; media.max_dimension is %d.',
                    $displayName,
                    $width,
                    $height,
                    $this->limits->maxDimension
                ));
            }
            if ($width * $height > $this->limits->maxPixels) {
                throw new MediaError(MediaError::TOO_BIG_PX, sprintf(
                    'media: %s is %d×%d (%d MP); media.max_pixels is %d MP.',
                    $displayName,
                    $width,
                    $height,
                    (int) round($width * $height / 1_000_000),
                    (int) round($this->limits->maxPixels / 1_000_000)
                ));
            }
        }

        if ($this->needsNormalisation($mediaType, $width, $height)) {
            [$bytes, $mediaType, $width, $height] = $this->normalise($bytes, $mediaType, $displayName);
        }
        if ($width <= 0 || $height <= 0) {
            throw new MediaError(MediaError::UNDECODABLE, sprintf(
                'media: %s could not be read as a %s image.',
                $displayName,
                Mime::subtype($mediaType)
            ));
        }

        $size = strlen($bytes);
        if ($size > $this->limits->maxBytes) {
            throw new MediaError(MediaError::TOO_LARGE, sprintf(
                'media: %s is %s after normalisation; media.max_bytes is %s.',
                $displayName,
                Mime::formatBytes($size),
                Mime::formatBytes($this->limits->maxBytes)
            ));
        }

        $ref = $this->store->put($bytes, $mediaType, $displayName);
        $ref['width'] = $width > 0 ? $width : null;
        $ref['height'] = $height > 0 ? $height : null;
        $ref['bytes'] = $size;
        $ref['media_type'] = $mediaType;
        $ref['source'] = $source;
        return $ref;
    }

    /** The wire block for a stored reference. */
    public function block(array $ref): array
    {
        $bytes = $this->store->bytes((string) $ref['sha256'], (string) $ref['media_type']);
        if ($bytes === null) {
            throw new MediaError(MediaError::MISSING, 'media: the stored image is missing (sha ' . substr((string) $ref['sha256'], 0, 8) . ')');
        }
        return Mime::imageBlock($bytes, (string) $ref['media_type']);
    }

    private function needsNormalisation(string $mediaType, int $width, int $height): bool
    {
        if ($width <= 0 || $height <= 0) {
            // Unsizeable header: let GD decide whether this is an image at all.
            return true;
        }
        if (!Mime::isProviderImage($mediaType)) {
            return true;
        }
        if (!$this->limits->resize || $this->limits->maxEdge <= 0) {
            return false;
        }
        return max($width, $height) > $this->limits->maxEdge;
    }

    /** @return array{0: int, 1: int} */
    private function dimensions(string $bytes, string $displayName): array
    {
        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            // GD can still decode some formats getimagesize() cannot size; defer.
            return [0, 0];
        }
        return [(int) $info[0], (int) $info[1]];
    }

    /**
     * @return array{0: string, 1: string, 2: int, 3: int} [bytes, mediaType, width, height]
     * @throws MediaError
     */
    private function normalise(string $bytes, string $mediaType, string $displayName): array
    {
        if (!function_exists('imagecreatefromstring')) {
            throw new MediaError(MediaError::TOO_LARGE, sprintf(
                'media: ext-gd is not installed, so images cannot be downscaled; %s was refused (%s limit).',
                $displayName,
                Mime::formatBytes($this->limits->maxBytes)
            ));
        }
        $info = @getimagesizefromstring($bytes);
        if ($info !== false) {
            $this->guardMemory((int) $info[0], (int) $info[1]);
        }

        $previous = ini_get('memory_limit');
        $this->raiseMemory((int) ($info[0] ?? 0), (int) ($info[1] ?? 0));
        try {
            $image = @imagecreatefromstring($bytes);
            if ($image === false) {
                throw new MediaError(MediaError::UNDECODABLE, sprintf(
                    'media: %s looks like a %s but GD could not decode it.',
                    $displayName,
                    Mime::subtype($mediaType)
                ));
            }
            $width = imagesx($image);
            $height = imagesy($image);
            $edge = $this->limits->maxEdge;
            if ($this->limits->resize && $edge > 0 && max($width, $height) > $edge) {
                if ($width >= $height) {
                    $targetWidth = $edge;
                    $targetHeight = max(1, (int) round($height * ($edge / $width)));
                } else {
                    $targetHeight = $edge;
                    $targetWidth = max(1, (int) round($width * ($edge / $height)));
                }
                $scaled = @imagescale($image, $targetWidth, $targetHeight, IMG_BICUBIC);
                if ($scaled !== false) {
                    imagedestroy($image);
                    $image = $scaled;
                    $width = imagesx($image);
                    $height = imagesy($image);
                }
            }

            $keepAlpha = $this->hasAlpha($image, $mediaType);
            $result = null;
            if ($keepAlpha) {
                ob_start();
                imagepng($image);
                $result = (string) ob_get_clean();
                $outputType = 'image/png';
            } else {
                foreach ([85, 70, 60] as $quality) {
                    ob_start();
                    imagejpeg($image, null, $quality);
                    $result = (string) ob_get_clean();
                    $outputType = 'image/jpeg';
                    if (strlen($result) <= $this->limits->maxBytes) {
                        break;
                    }
                }
            }
            imagedestroy($image);
            if ($result === null || $result === '') {
                throw new MediaError(MediaError::UNDECODABLE, sprintf(
                    'media: %s could not be re-encoded.',
                    $displayName
                ));
            }
            return [$result, $outputType, $width, $height];
        } finally {
            if (is_string($previous) && $previous !== '') {
                @ini_set('memory_limit', $previous);
            }
        }
    }

    /** PNG/WebP/GIF/BMP sources may carry alpha; keep them lossless. */
    private function hasAlpha($image, string $mediaType): bool
    {
        if (in_array($mediaType, ['image/png', 'image/webp', 'image/bmp'], true)) {
            return true;
        }
        if ($mediaType !== 'image/gif') {
            return false;
        }
        // A sampled translucent pixel means the GIF needs an alpha-capable output.
        $width = imagesx($image);
        $height = imagesy($image);
        $stepX = max(1, (int) ($width / 16));
        $stepY = max(1, (int) ($height / 16));
        for ($x = 0; $x < $width; $x += $stepX) {
            for ($y = 0; $y < $height; $y += $stepY) {
                $color = imagecolorat($image, $x, $y);
                if (($color & 0x7F000000) !== 0) {
                    return true;
                }
            }
        }
        return false;
    }

    private function raiseMemory(int $width, int $height): bool
    {
        if ($width <= 0 || $height <= 0) {
            return false;
        }
        $limit = self::parseMemoryLimit((string) ini_get('memory_limit'));
        if ($limit <= 0) {
            return false;
        }
        $need = $width * $height * 5 + 64 * 1024 * 1024;
        if ($need <= $limit) {
            return false;
        }
        $target = min($need, 512 * 1024 * 1024);
        return @ini_set('memory_limit', (string) (int) $target) !== false;
    }

    private function guardMemory(int $width, int $height): void
    {
        if ($width <= 0 || $height <= 0) {
            return;
        }
        $limit = self::parseMemoryLimit((string) ini_get('memory_limit'));
        if ($limit <= 0) {
            return;
        }
        if ($width * $height * 5 > $limit * 0.9) {
            // raiseMemory() may still rescue this; only reject when it cannot.
            $need = $width * $height * 5 + 64 * 1024 * 1024;
            if ($need > 512 * 1024 * 1024) {
                throw new MediaError(MediaError::TOO_BIG_PX, sprintf(
                    'media: %d×%d needs about %s of memory; raise memory_limit or downscale it first.',
                    $width,
                    $height,
                    Mime::formatBytes($need)
                ));
            }
        }
    }

    private static function parseMemoryLimit(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return 0;
        }
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;
        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

}
