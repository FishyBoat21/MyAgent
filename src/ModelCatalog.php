<?php
declare(strict_types=1);

namespace MyAgent;

use RuntimeException;
use Throwable;

/**
 * Provider model catalog: one lazy HTTP GET, normalized into a stable shape
 * and cached under .agent/. The catalog also carries each model's declared
 * effort levels, which the selector uses to avoid offering unsupported
 * choices.
 *
 * Two provider shapes are understood:
 *  - OpenAI-compatible /models: data[].id/name/context_window/max_output_tokens/effort
 *  - Anthropic Models API:      data[].id/display_name/max_input_tokens/max_tokens
 *
 * Failures never escape models(): the caller gets a stale cache, then the
 * static config list, then an empty list, plus lastError() for one warning.
 */
final class ModelCatalog
{
    /** Canonical effort levels, ordered from cheapest to most thorough. */
    public const EFFORT_ORDER = ['low', 'medium', 'high', 'xhigh', 'max'];

    /** @var list<array<string, mixed>> */
    private array $loaded = [];
    private string $source = 'none';
    private ?string $lastError = null;

    /**
     * @param list<string|array<string, mixed>> $staticModels
     */
    public function __construct(
        private string $root,
        private string $modelsUrl = '',
        private string $auth = 'bearer',
        private string $apiKey = '',
        private string $apiKeyHeader = 'x-api-key',
        private string $apiKeyPrefix = '',
        private string $apiVersion = '2023-06-01',
        private int $cacheTtl = 86400,
        private array $staticModels = [],
    ) {
        if (!in_array($auth, ['bearer', 'header', 'none'], true)) {
            throw new RuntimeException('models_auth must be bearer, header, or none');
        }
        if ($cacheTtl < 0) {
            throw new RuntimeException('models_cache_ttl must be zero or greater');
        }
        if ($modelsUrl !== '') {
            $scheme = strtolower((string) parse_url($modelsUrl, PHP_URL_SCHEME));
            if (!filter_var($modelsUrl, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
                throw new RuntimeException('models_url must be an http or https URL');
            }
        }
        foreach ([$apiKey, $apiKeyHeader, $apiKeyPrefix, $apiVersion] as $value) {
            if (str_contains($value, "\r") || str_contains($value, "\n")) {
                throw new RuntimeException('Invalid model catalog header configuration');
            }
        }
    }

    /**
     * Load the catalog: fresh cache, live fetch, stale cache, then static.
     *
     * @return list<array<string, mixed>>
     */
    public function models(bool $refresh = false): array
    {
        $this->lastError = null;

        if (!$refresh && $this->cacheTtl > 0) {
            $cached = $this->readCache(true);
            if ($cached !== null) {
                $this->loaded = $cached;
                $this->source = 'cache';
                return $this->loaded;
            }
        }

        if ($this->modelsUrl !== '') {
            try {
                $models = self::normalize($this->fetch());
                if ($models !== []) {
                    try {
                        $this->writeCache($models);
                    } catch (Throwable) {
                        // A read-only project directory must not break selection.
                    }
                    $this->loaded = $models;
                    $this->source = 'live';
                    return $this->loaded;
                }
                $this->lastError = 'The provider returned no usable models';
            } catch (Throwable $e) {
                $this->lastError = $e->getMessage();
            }

            $cached = $this->readCache(false);
            if ($cached !== null) {
                $this->loaded = $cached;
                $this->source = 'cache';
                return $this->loaded;
            }
        }

        $static = self::normalizeStatic($this->staticModels);
        if ($static !== []) {
            $this->loaded = $static;
            $this->source = 'static';
            return $this->loaded;
        }

        $this->loaded = [];
        $this->source = 'none';
        return $this->loaded;
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        foreach ($this->loaded as $model) {
            if (($model['id'] ?? null) === $id) {
                return $model;
            }
        }
        return null;
    }

    /** @return list<string> Declared levels, or [] when the model is unknown. */
    public function effortLevels(string $id): array
    {
        $model = $this->find($id);
        return $model === null ? [] : (array) ($model['effort_levels'] ?? []);
    }

    public function defaultEffort(string $id): ?string
    {
        $model = $this->find($id);
        $default = $model['default_effort'] ?? null;
        return is_string($default) && in_array($default, self::EFFORT_ORDER, true) ? $default : null;
    }

    /**
     * Input modalities read from the on-disk cache only: never performs a request,
     * so startup capability detection stays off the network.
     *
     * @return list<string>
     */
    public function cachedInputModalities(string $id): array
    {
        foreach ($this->readCache(false) ?? [] as $model) {
            if (($model['id'] ?? null) === $id) {
                return (array) ($model['input_modalities'] ?? []);
            }
        }
        return [];
    }

    /**
     * Input modalities the provider declares for a model ([] when unknown).
     *
     * The catalog is a declaration, not a guarantee: callers fall back to
     * media.input_modalities when this is empty.
     *
     * @return list<string>
     */
    public function inputModalities(string $id): array
    {
        $model = $this->find($id);
        return $model === null ? [] : (array) ($model['input_modalities'] ?? []);
    }

    /** live|cache|static|none */
    public function source(): string
    {
        return $this->source;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Normalize an OpenAI-compatible or Anthropic model list payload.
     *
     * @param array<mixed> $payload
     * @return list<array<string, mixed>>
     */
    public static function normalize(array $payload): array
    {
        $entries = [];
        if (isset($payload['data']) && is_array($payload['data'])) {
            $entries = $payload['data'];
        } elseif (array_is_list($payload)) {
            $entries = $payload;
        }

        $models = [];
        $seen = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = trim((string) ($entry['id'] ?? ''));
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $name = trim((string) ($entry['name'] ?? $entry['display_name'] ?? $id));
            $effort = is_array($entry['effort'] ?? null) ? $entry['effort'] : [];
            $capabilities = is_array($entry['capabilities'] ?? null) ? $entry['capabilities'] : [];
            if ($effort === [] && is_array($capabilities['effort'] ?? null)) {
                $effort = $capabilities['effort'];
            }
            $modalities = self::sanitizeModalities(
                $entry['input_modalities'] ?? ($capabilities['input_modalities'] ?? null)
            );

            $levels = self::sanitizeLevels($effort['supported_levels'] ?? []);
            $default = self::normalizeEffortValue($effort['default_level'] ?? null);
            if ($default !== null && $levels !== [] && !in_array($default, $levels, true)) {
                $default = null;
            }

            $models[] = [
                'id' => $id,
                'name' => $name !== '' ? $name : $id,
                'context_window' => self::intOrNull($entry['context_window'] ?? $entry['max_input_tokens'] ?? null),
                'max_output_tokens' => self::intOrNull($entry['max_output_tokens'] ?? $entry['max_tokens'] ?? null),
                'effort_levels' => $levels,
                'default_effort' => $default,
                'input_modalities' => $modalities,
            ];
        }
        return $models;
    }

    /**
     * Normalize the static "models" config list (strings or objects).
     *
     * @param array<mixed> $entries
     * @return list<array<string, mixed>>
     */
    public static function normalizeStatic(array $entries): array
    {
        $models = [];
        $seen = [];
        foreach ($entries as $entry) {
            if (is_string($entry)) {
                $entry = ['id' => $entry];
            }
            if (!is_array($entry)) {
                continue;
            }
            $id = trim((string) ($entry['id'] ?? ''));
            if ($id === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $effort = is_array($entry['effort'] ?? null) ? $entry['effort'] : [];
            $levels = self::sanitizeLevels($entry['effort_levels'] ?? $effort['supported_levels'] ?? []);
            $default = self::normalizeEffortValue($entry['default_effort'] ?? $effort['default_level'] ?? null);
            if ($default !== null && $levels !== [] && !in_array($default, $levels, true)) {
                $default = null;
            }

            $models[] = [
                'id' => $id,
                'name' => trim((string) ($entry['name'] ?? $id)) ?: $id,
                'context_window' => self::intOrNull($entry['context_window'] ?? null),
                'max_output_tokens' => self::intOrNull($entry['max_output_tokens'] ?? null),
                'effort_levels' => $levels,
                'default_effort' => $default,
                'input_modalities' => self::sanitizeModalities($entry['input_modalities'] ?? null),
            ];
        }
        return $models;
    }

    /**
     * Clamp a requested level to the model's declared levels. Ties go to the
     * higher level (medium -> high on low/high/max; xhigh -> max).
     *
     * @param list<string> $levels
     * @return array{0: ?string, 1: bool} [level, clamped]
     */
    public static function clampEffort(?string $requested, array $levels, ?string $default = null): array
    {
        if ($requested === null || trim($requested) === '') {
            return [null, false];
        }
        $normalized = strtolower(trim($requested));
        if (!in_array($normalized, self::EFFORT_ORDER, true)) {
            throw new RuntimeException('Invalid effort level: ' . $requested);
        }

        $declared = self::sanitizeLevels($levels);
        if ($declared === [] || in_array($normalized, $declared, true)) {
            return [$normalized, false];
        }

        $target = (int) array_search($normalized, self::EFFORT_ORDER, true);
        $best = null;
        $bestRank = -1;
        $bestDistance = PHP_INT_MAX;
        foreach ($declared as $level) {
            $rank = (int) array_search($level, self::EFFORT_ORDER, true);
            $distance = abs($rank - $target);
            if ($best === null || $distance < $bestDistance || ($distance === $bestDistance && $rank > $bestRank)) {
                $best = $level;
                $bestRank = $rank;
                $bestDistance = $distance;
            }
        }

        if ($best === null) {
            $fallback = self::normalizeEffortValue($default);
            return [$fallback, $fallback !== $normalized];
        }
        return [$best, true];
    }

    public static function validEffort(?string $effort): bool
    {
        return $effort === null || $effort === '' || in_array(strtolower(trim($effort)), self::EFFORT_ORDER, true);
    }

    /** @return array<mixed> */
    private function fetch(): array
    {
        $ch = curl_init($this->modelsUrl);
        if ($ch === false) {
            throw new RuntimeException('curl could not initialize');
        }
        curl_setopt_array($ch, [
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => $this->headers(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_USERAGENT => 'MyAgent model-catalog',
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        unset($ch);

        if ($body === false) {
            throw new RuntimeException('Could not reach the model catalog: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            $detail = trim(substr(trim((string) $body), 0, 200));
            throw new RuntimeException("Model catalog returned HTTP {$status}" . ($detail === '' ? '' : ': ' . $detail));
        }
        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Model catalog returned invalid JSON');
        }
        return $decoded;
    }

    /** @return list<string> */
    private function headers(): array
    {
        $headers = ['Accept: application/json'];
        if ($this->apiKey !== '') {
            if ($this->auth === 'bearer') {
                $headers[] = 'Authorization: Bearer ' . $this->apiKey;
            } elseif ($this->auth === 'header' && $this->apiKeyHeader !== '') {
                $headers[] = $this->apiKeyHeader . ': ' . $this->apiKeyPrefix . $this->apiKey;
                $headers[] = 'anthropic-version: ' . $this->apiVersion;
            }
        }
        return $headers;
    }

    private function cachePath(): string
    {
        return $this->root . '/.agent/models-cache.json';
    }

    /** @return list<array<string, mixed>>|null */
    private function readCache(bool $requireFresh): ?array
    {
        $path = $this->cachePath();
        if (is_link($path) || !is_file($path)) {
            return null;
        }
        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
        if (!is_array($data) || ($data['version'] ?? null) !== 1
            || ($data['url'] ?? null) !== $this->modelsUrl
            || !is_array($data['models'] ?? null)) {
            return null;
        }
        if ($requireFresh) {
            $fetchedAt = (int) ($data['fetched_at'] ?? 0);
            if ($this->cacheTtl <= 0 || $fetchedAt <= 0 || (time() - $fetchedAt) >= $this->cacheTtl) {
                return null;
            }
        }
        $models = self::normalizeStatic($data['models']);
        return $models === [] ? null : $models;
    }

    /** @param list<array<string, mixed>> $models */
    private function writeCache(array $models): void
    {
        $directory = $this->root . '/.agent';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create the .agent directory');
        }
        $path = $this->cachePath();
        if (is_link($path)) {
            throw new RuntimeException('Model cache cannot be a symbolic link');
        }
        $json = json_encode(
            [
                'version' => 1,
                'url' => $this->modelsUrl,
                'fetched_at' => time(),
                'models' => $models,
            ],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        $temp = tempnam($directory, '.models-');
        if ($temp === false) {
            throw new RuntimeException('Could not create the model cache');
        }
        try {
            if (file_put_contents($temp, $json, LOCK_EX) !== strlen($json) || !rename($temp, $path)) {
                throw new RuntimeException('Could not write the model cache');
            }
            @chmod($path, 0600);
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    /**
     * Keep only known modality names, in canonical order.
     *
     * @return list<string>
     */
    private static function sanitizeModalities(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,|]+/', $value) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }
        $seen = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }
            $item = strtolower(trim($item));
            if (in_array($item, ['text', 'image', 'document', 'audio', 'video'], true)) {
                $seen[$item] = true;
            }
        }
        return array_values(array_filter(
            ['text', 'image', 'document', 'audio', 'video'],
            static fn(string $name): bool => isset($seen[$name])
        ));
    }

    /** @return list<string> */
    private static function sanitizeLevels(mixed $levels): array
    {
        if (!is_array($levels)) {
            return [];
        }
        $allowed = [];
        foreach ($levels as $level) {
            if (!is_string($level)) {
                continue;
            }
            $level = strtolower(trim($level));
            if (in_array($level, self::EFFORT_ORDER, true)) {
                $allowed[$level] = true;
            }
        }
        return array_values(array_filter(
            self::EFFORT_ORDER,
            static fn(string $level): bool => isset($allowed[$level])
        ));
    }

    private static function normalizeEffortValue(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));
        return in_array($value, self::EFFORT_ORDER, true) ? $value : null;
    }

    private static function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_float($value) && $value > 0) {
            return (int) $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            $int = (int) $value;
            return $int > 0 ? $int : null;
        }
        return null;
    }
}
