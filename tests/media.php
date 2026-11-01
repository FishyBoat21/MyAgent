<?php
declare(strict_types=1);

/**
 * Image pipeline tests: MIME sniffing, normalisation, the content-addressed
 * store, and checkpoint dehydration. No API call, no binary fixtures — images
 * are generated with GD at runtime.
 */

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Dependencies are not installed. Run: composer install\n");
    exit(1);
}
require_once $autoload;

use MyAgent\Agent;
use MyAgent\Ansi;
use MyAgent\AnthropicClient;
use MyAgent\LocalTools;
use MyAgent\McpClient;
use MyAgent\MediaError;
use MyAgent\MediaLimits;
use MyAgent\MediaNormalizer;
use MyAgent\MediaStore;
use MyAgent\Mime;
use MyAgent\SecurityClassifier;
use MyAgent\SessionStore;
use MyAgent\Skills;
use MyAgent\Terminal;

if (!function_exists('check')) {
    function check(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }
}

if (!function_exists('errorOf')) {
    /** @param callable(): mixed $run */
    function errorOf(callable $run): string
    {
        try {
            $run();
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
        throw new RuntimeException('Expected a RuntimeException');
    }
}

/** @return array{0: string, 1: int, 2: int} [png bytes, width, height] */
$pngFixture = static function (int $width, int $height, bool $alpha = false): array {
    $image = imagecreatetruecolor($width, $height);
    if ($alpha) {
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
    } else {
        imagefill($image, 0, 0, imagecolorallocate($image, 20, 40, 80));
    }
    imagestring($image, 5, 2, 2, 'MyAgent', imagecolorallocate($image, 255, 255, 255));
    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);
    return [$bytes, $width, $height];
};

$jpegFixture = static function (int $width, int $height): string {
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 120, 40));
    ob_start();
    imagejpeg($image, null, 80);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);
    return $bytes;
};

$workspace = dirname(__DIR__) . '/tests/.media-' . getmypid();
$mediaDirectory = $workspace . '/.agent/media';
$limits = new MediaLimits(
    enabled: true,
    inputModalities: ['text', 'image'],
    maxBytes: 5_242_880,
    maxEdge: 1568,
    maxDimension: 8000,
    maxPixels: 40_000_000,
    maxImagesPerMessage: 20,
    maxImagesPerRequest: 20,
    maxImagesPerToolResult: 4,
    store: '.agent/media',
    retainHours: 168,
    mentions: 'images',
    resize: true,
);

try {
    // ---------------------------------------------------------------- T1 sniff
    [$png] = $pngFixture(4, 4);
    check(Mime::sniff($png) === 'image/png', 'T1 png not sniffed');
    check(Mime::sniff($jpegFixture(8, 8)) === 'image/jpeg', 'T1 jpeg not sniffed');

    $gif = imagecreatetruecolor(4, 4);
    ob_start();
    imagegif($gif);
    $gifBytes = (string) ob_get_clean();
    imagedestroy($gif);
    check(Mime::sniff($gifBytes) === 'image/gif', 'T1 gif not sniffed');

    $webp = imagecreatetruecolor(4, 4);
    ob_start();
    imagewebp($webp);
    $webpBytes = (string) ob_get_clean();
    imagedestroy($webp);
    check(Mime::sniff($webpBytes) === 'image/webp', 'T1 webp not sniffed');

    $bmp = imagecreatetruecolor(4, 4);
    ob_start();
    imagebmp($bmp);
    $bmpBytes = (string) ob_get_clean();
    imagedestroy($bmp);
    check(Mime::sniff($bmpBytes) === 'image/bmp', 'T1 bmp not sniffed');

    check(Mime::sniff('%PDF-1.7' . str_repeat(' ', 32)) === null, 'T1 a PDF was sniffed as an image');
    check(Mime::sniff('plain text, nothing binary here') === null, 'T1 plain text was sniffed as an image');
    check(Mime::sniff(random_bytes(64)) === null, 'T1 random bytes were sniffed as an image');

    // ------------------------------------------------------- T2 extension hints
    check(Mime::fromExtension('shot.PNG') === 'image/png', 'T2 uppercase extension lost');
    check(Mime::fromExtension('spec.pdf') === null, 'T2 a PDF extension produced an image type');
    check(Mime::looksLikeImage('a/b/shot.jpeg'), 'T2 .jpeg not treated as an image');
    check(!Mime::looksLikeImage('a/b/notes.md'), 'T2 .md treated as an image');
    check(Mime::isProviderImage('image/webp') && !Mime::isProviderImage('image/tiff'), 'T2 provider image set wrong');

    $store = new MediaStore($workspace, $limits);
    $normalizer = new MediaNormalizer($limits, $store);

    // ------------------------------------------------- T3 small png untouched
    $small = $normalizer->fromBytes($png, 'small.png', 'user');
    check($small['media_type'] === 'image/png', 'T3 small png was re-encoded');
    check($small['sha256'] === hash('sha256', $png), 'T3 small png bytes changed');
    check($small['width'] === 4 && $small['height'] === 4, 'T3 dimensions lost');
    check($small['bytes'] === strlen($png), 'T3 byte count wrong');
    check(is_file($small['path']), 'T3 the stored file is missing');

    // ------------------------------------------------------ T4 downscale 4000x100
    [$wide] = $pngFixture(4000, 100);
    $scaled = $normalizer->fromBytes($wide, 'wide.png', 'user');
    check($scaled['width'] === 1568, 'T4 long edge was not downscaled to max_edge');
    check($scaled['height'] < 100, 'T4 aspect ratio was not preserved');
    check(strlen($wide) > 1000 && $scaled['bytes'] < strlen($wide), 'T4 the downscaled image is not smaller');
    $decoded = @imagecreatefromstring((string) $store->bytes($scaled['sha256'], $scaled['media_type']));
    check($decoded !== false, 'T4 the downscaled image cannot be decoded');
    imagedestroy($decoded);

    // ------------------------------------------------ T5 non-native conversion
    $converted = $normalizer->fromBytes($bmpBytes, 'shot.bmp', 'user');
    check(in_array($converted['media_type'], Mime::PROVIDER_IMAGES, true), 'T5 bmp was not converted to a provider image');

    // ------------------------------------------------------------ T6 alpha kept
    [$alpha] = $pngFixture(2000, 200, true);
    $alphaRef = $normalizer->fromBytes($alpha, 'alpha.png', 'user');
    check($alphaRef['media_type'] === 'image/png', 'T6 alpha image lost its png type');
    check($alphaRef['width'] === 1568, 'T6 alpha image was not downscaled');

    // ------------------------------------------------------------ T7 too large
    // A well-formed PNG header (4x4, so the dimension guard stays quiet) with a
    // 6 MiB payload: the size cap must fire and name the limit.
    $bigValid = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', 4, 4)
        . "\x08\x06\x00\x00\x00" . random_bytes(4);
    $bigValid .= str_repeat("\x00", 6 * 1024 * 1024 - strlen($bigValid));
    $tooLarge = errorOf(static fn() => $normalizer->fromBytes($bigValid, 'huge.png', 'user'));
    check(str_contains($tooLarge, 'media.max_bytes'), 'T7 the size rejection does not name the limit');

    // ------------------------------------------------------- T8 pixel guard first
    // A PNG header that claims 9000x9000 but carries no pixel data: the size
    // guard must fire before GD is asked to decode anything.
    $hugeHeader = "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . pack('NN', 9000, 9000)
        . "\x08\x06\x00\x00\x00" . random_bytes(4);
    $tooBig = errorOf(static fn() => $normalizer->fromBytes($hugeHeader, 'huge-dim.png', 'user'));
    check(str_contains($tooBig, 'media.max_dimension'), 'T8 the dimension guard did not fire');

    // ------------------------------------------------------------ T9 undecodable
    $broken = "\x89PNG\r\n\x1a\n" . str_repeat("\x00", 64);
    $undecodable = errorOf(static fn() => $normalizer->fromBytes($broken, 'broken.png', 'user'));
    check(str_contains($undecodable, 'GD could not decode') || str_contains($undecodable, 'not an image'), 'T9 a corrupt png was accepted');

    // --------------------------------------------------------- T10 pdf rejected
    $pdfRejected = errorOf(static fn() => $normalizer->fromBytes("%PDF-1.7\n" . str_repeat('x', 200), 'spec.pdf', 'user'));
    check(str_contains($pdfRejected, 'is not an image'), 'T10 a PDF was not rejected with the image hint');

    // -------------------------------------------------------- T11 dedupe by sha
    $first = $normalizer->fromBytes($png, 'one.png', 'user');
    $second = $normalizer->fromBytes($png, 'two.png', 'user');
    check($first['sha256'] === $second['sha256'], 'T11 the same bytes produced different hashes');
    check($first['path'] === $second['path'], 'T11 the same bytes produced different paths');
    $stored = glob($mediaDirectory . '/' . substr($first['sha256'], 0, 2) . '/' . $first['sha256'] . '.*') ?: [];
    check(count($stored) === 1, 'T11 duplicate files were written');

    // ------------------------------------------------ T12 unwritable store fails
    $brokenRoot = $workspace . '/broken-root';
    @mkdir($brokenRoot, 0700, true);
    file_put_contents($brokenRoot . '/.agent', 'not a directory');
    $brokenStore = new MediaStore($brokenRoot, $limits);
    $brokenError = errorOf(static fn() => $brokenStore->put($png, 'image/png', 'x.png'));
    check(str_contains($brokenError, 'Could not store the image'), 'T12 an unwritable store did not report a clear error');
    @unlink($brokenRoot . '/.agent');
    @rmdir($brokenRoot);

    // ---------------------------------------------- T13 dehydrate/hydrate round trip
    $block = Mime::imageBlock((string) $store->bytes($small['sha256'], 'image/png'), 'image/png');
    $messages = [
        ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'see this'], $block]],
        ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'ok']]],
    ];
    $dehydrated = $store->dehydrate($messages);
    $marker = $dehydrated[0]['content'][1]['source'] ?? [];
    check(($marker['type'] ?? '') === 'myagent_media', 'T13 the checkpoint still carries base64');
    check(!str_contains((string) json_encode($dehydrated), '"base64"'), 'T13 base64 leaked into the dehydrated messages');
    check(MediaStore::hasMarkers($dehydrated), 'T13 markers were not detected for the checkpoint version');
    check(!MediaStore::hasMarkers($messages), 'T13 inline base64 was reported as a marker');

    $hydrated = $store->hydrate($dehydrated);
    $restored = $hydrated[0]['content'][1] ?? [];
    check(($restored['type'] ?? '') === 'image', 'T13 the image block was not restored');
    check(($restored['source']['type'] ?? '') === 'base64', 'T13 the restored block is not base64');
    check(base64_decode((string) $restored['source']['data'], true) === $png, 'T13 the restored bytes differ');
    check(!str_contains((string) json_encode($hydrated), 'myagent_media'), 'T13 a marker survived hydration');

    // ------------------------------------------------ T14 missing file degrades
    $orphan = $store->dehydrate([['role' => 'user', 'content' => [
        Mime::imageBlock((string) $store->bytes($first['sha256'], 'image/png'), 'image/png'),
    ]]]);
    $orphanSha = (string) ($orphan[0]['content'][0]['source']['sha256'] ?? '');
    check($orphanSha !== '', 'T14 no marker was produced');
    @unlink($store->path($orphanSha, 'image/png'));
    $orphanHydrated = $store->hydrate($orphan);
    check(($orphanHydrated[0]['content'][0]['type'] ?? '') === 'text', 'T14 a missing file did not degrade to text');
    check(str_contains((string) $orphanHydrated[0]['content'][0]['text'], 'image unavailable'), 'T14 the placeholder text is missing');
    // Restore it for the pruning tests that follow.
    $store->put($png, 'image/png', 'restored.png');

    // --------------------------------------------------- T15 prune keeps refs
    $keep = $normalizer->fromBytes($png, 'keep.png', 'user');
    $drop = $normalizer->fromBytes($jpegFixture(6, 6), 'drop.jpg', 'user');
    $old = time() - 40 * 24 * 3600;
    @touch($store->path($keep['sha256'], $keep['media_type']), $old);
    @touch($store->path($drop['sha256'], $drop['media_type']), $old);
    $pruned = $store->prune(168, [$keep['sha256']]);
    check($pruned['deleted'] >= 1, 'T15 nothing was pruned');
    check(is_file($store->path($keep['sha256'], $keep['media_type'])), 'T15 a referenced file was pruned');
    check(!is_file($store->path($drop['sha256'], $drop['media_type'])), 'T15 an unreferenced old file survived');

    // ---------------------------------------------------- T16 retain 0 = no prune
    $again = $normalizer->fromBytes($jpegFixture(6, 6), 'again.jpg', 'user');
    @touch($store->path($again['sha256'], $again['media_type']), $old);
    $none = $store->prune(0, []);
    check($none['deleted'] === 0, 'T16 retain_hours=0 still deleted files');
    check(is_file($store->path($again['sha256'], $again['media_type'])), 'T16 the file was deleted anyway');

    // --------------------------------------- T17 config validation (limits)
    $badLimit = errorOf(static fn() => MediaLimits::fromConfig(['max_edge' => 20000]));
    check(str_contains($badLimit, 'media.max_edge'), 'T17 an out-of-range max_edge was accepted');
    $badMentions = errorOf(static fn() => MediaLimits::fromConfig(['mentions' => 'everything']));
    check(str_contains($badMentions, 'media.mentions'), 'T17 an invalid mentions mode was accepted');
    $badStore = errorOf(static fn() => MediaLimits::fromConfig(['store' => '../outside']));
    check(str_contains($badStore, 'media.store'), 'T17 a store outside the project was accepted');
    $badModalities = errorOf(static fn() => MediaLimits::fromConfig(['input_modalities' => ['audio']]));
    check(str_contains($badModalities, 'input_modalities'), 'T17 a modality list without text was accepted');
    $defaults = MediaLimits::fromConfig([]);
    check($defaults->maxBytes === 5_242_880 && $defaults->maxEdge === 1568, 'T17 defaults changed');
    check($defaults->supportsImages(), 'T17 the default config should support images');
    check(!$defaults->withModalities(['text'])->supportsImages(), 'T17 text-only modalities still support images');

    // ------------------------------------- T18 sizes only grow the store by content
    $firstSize = 0;
    foreach (glob($mediaDirectory . '/*/*') ?: [] as $file) {
        $firstSize += (int) filesize($file);
    }
    $normalizer->fromBytes($png, 'one.png', 'user');
    $afterSize = 0;
    foreach (glob($mediaDirectory . '/*/*') ?: [] as $file) {
        $afterSize += (int) filesize($file);
    }
    check($firstSize === $afterSize, 'T18 re-attaching a stored image grew the store');

    // ------------------------------- T19-T21: read_image through LocalTools
    $projectRoot = $workspace . '/proj';
    @mkdir($projectRoot . '/docs', 0777, true);
    file_put_contents($projectRoot . '/docs/shot.png', $png);
    $toolLimits = new MediaLimits(store: '.agent/media');
    $toolStore = new MediaStore($projectRoot, $toolLimits);
    $toolNormalizer = new MediaNormalizer($toolLimits, $toolStore);
    $tools = new LocalTools($projectRoot, new Skills($projectRoot), 300, null, $toolNormalizer);

    $definitions = [];
    foreach ($tools->definitions() as $definition) {
        $definitions[(string) $definition['name']] = $definition;
    }
    check(isset($definitions['read_image']), 'T19 read_image is not advertised');
    check(($definitions['read_image']['input_schema']['required'] ?? []) === ['path'], 'T19 read_image requires a path');

    $rich = $tools->call('read_image', ['path' => 'docs/shot.png', 'note' => 'look at the corner']);
    check(is_array($rich), 'T19 read_image did not return content blocks');
    check(($rich[0]['type'] ?? '') === 'text', 'T19 the summary text block is missing');
    check(str_contains((string) $rich[0]['text'], '4×4'), 'T19 the summary lost the dimensions');
    check(str_contains((string) $rich[0]['text'], 'look at the corner'), 'T19 the caller note was dropped');
    check(($rich[1]['type'] ?? '') === 'image', 'T19 the image block is missing');
    check(($rich[1]['source']['type'] ?? '') === 'base64', 'T19 the image block is not base64');
    check(base64_decode((string) $rich[1]['source']['data'], true) === $png, 'T19 the attached bytes differ');

    // A real file outside the project root: containment must refuse it by name.
    file_put_contents($workspace . '/outside.png', $png);
    $traversal = errorOf(static fn() => $tools->call('read_image', ['path' => '../outside.png']));
    check(str_contains($traversal, 'Path outside project'), 'T20 traversal was not blocked: ' . $traversal);
    $missing = errorOf(static fn() => $tools->call('read_image', ['path' => 'docs/nope.png']));
    check(str_contains($missing, 'Not found'), 'T20 a missing image did not report Not found');

    $tools->setMediaEnabled(false);
    $disabledDefinitions = [];
    foreach ($tools->definitions() as $definition) {
        $disabledDefinitions[] = (string) $definition['name'];
    }
    check(!in_array('read_image', $disabledDefinitions, true), 'T21 read_image is still advertised when disabled');
    check(
        str_contains(errorOf(static fn() => $tools->call('read_image', ['path' => 'docs/shot.png'])), 'unavailable'),
        'T21 a disabled read_image call did not fail clearly'
    );
    $tools->setMediaEnabled(true);

    // ------------------------- T22: Agent returns image blocks in a tool_result
    $agentProbe = new class('unused') extends AnthropicClient {
        /** @var list<array<string, mixed>> */
        public array $toolResults = [];
        public int $calls = 0;
        public function message(string $model, int $maxTokens, string $system, array $messages, array $tools): array
        {
            $this->calls++;
            if ($this->calls === 1) {
                return ['content' => [[
                    'type' => 'tool_use',
                    'id' => 'img-1',
                    'name' => 'read_image',
                    'input' => ['path' => 'docs/shot.png'],
                ]], 'stop_reason' => 'tool_use'];
            }
            $this->toolResults[] = (array) ($messages[2]['content'][0] ?? []);
            return ['content' => [['type' => 'text', 'text' => 'done']], 'stop_reason' => 'end_turn'];
        }
    };
    $terminalOut = '';
    $terminalErr = '';
    $testTerminal = new Terminal(
        true,
        true,
        ['color' => 'never', 'unicode' => 'never', 'markdown' => false, 'usage' => false, 'spinner' => false, 'width' => 100],
        static function (string $text) use (&$terminalOut): void {
            $terminalOut .= $text;
        },
        static function (string $text) use (&$terminalErr): void {
            $terminalErr .= $text;
        }
    );
    $imageAgent = new Agent(
        $agentProbe,
        $tools,
        [],
        'test-model',
        100,
        3,
        null,
        24000,
        2048,
        null,
        $testTerminal,
        $toolStore
    );
    $imageAgent->setInputModalities(['text', 'image']);
    $imageAgent->run('Look at the screenshot');
    $toolResult = $agentProbe->toolResults[0] ?? [];
    check(is_array($toolResult['content'] ?? null), 'T22 tool_result content is not a block list');
    $imageBlocks = array_values(array_filter(
        (array) $toolResult['content'],
        static fn($block): bool => is_array($block) && ($block['type'] ?? '') === 'image'
    ));
    check(count($imageBlocks) === 1, 'T22 the image block did not survive into the tool_result');
    check(base64_decode((string) $imageBlocks[0]['source']['data'], true) === $png, 'T22 the tool_result image bytes differ');
    check(str_contains(Ansi::strip($terminalErr), 'image'), 'T22 the terminal summary does not mention the image');
    check(!str_contains($terminalErr, 'base64'), 'T22 base64 reached the terminal');

    // ------------------------------------- T23: gating and byte-identical bodies
    $bodyProbe = new class('unused') extends AnthropicClient {
        /** @param array<int, array<string, mixed>> $messages @param array<int, array<string, mixed>> $tools */
        public function body(array $messages, array $tools = []): array
        {
            return $this->requestBody('probe-model', 16, 'sys', $messages, $tools);
        }
    };
    $imageBlock = Mime::imageBlock($png, 'image/png');
    $messagesWithImage = [['role' => 'user', 'content' => [['type' => 'text', 'text' => 'look'], $imageBlock]]];
    $ungated = $bodyProbe->body($messagesWithImage);
    check(substr_count((string) json_encode($ungated), '"type":"image"') === 1, 'T23 an ungated body lost the image');
    $bodyProbe->setInputModalities(['text']);
    $gated = $bodyProbe->body($messagesWithImage);
    $gatedJson = (string) json_encode($gated);
    check(!str_contains($gatedJson, '"type":"image"'), 'T23 a text-only model still received an image block');
    check(str_contains($gatedJson, 'image omitted'), 'T23 the placeholder text is missing');
    check(!$bodyProbe->supportsImages(), 'T23 supportsImages() is wrong for a text-only model');
    $bodyProbe->setInputModalities(['text', 'image']);
    check($bodyProbe->supportsImages(), 'T23 supportsImages() is wrong for an image model');

    // --------------------------- T24: prompt block ingestion and string compat
    $promptProbe = new class('unused') extends AnthropicClient {
        /** @var list<array<int, array<string, mixed>>> */
        public array $contents = [];
        public function message(string $model, int $maxTokens, string $system, array $messages, array $tools): array
        {
            $this->contents[] = $messages[array_key_last($messages)]['content'];
            return ['content' => [['type' => 'text', 'text' => 'ok']], 'stop_reason' => 'end_turn'];
        }
    };
    $promptAgent = new Agent($promptProbe, $tools, [], 'test-model', 100, 2);
    $promptAgent->run('plain text');
    $promptAgent->run([['type' => 'text', 'text' => 'see'], $imageBlock]);
    check(is_string($promptProbe->contents[0]), 'T24 a string prompt stopped being a string');
    check(is_array($promptProbe->contents[1]) && count($promptProbe->contents[1]) === 2, 'T24 a block prompt was not stored as blocks');
    check(
        str_contains(errorOf(static fn() => $promptAgent->run([['type' => 'audio']])), 'Unsupported prompt block type'),
        'T24 an unsupported block type was accepted'
    );

    // -------------------------------------- T25/T26: checkpoint dehydration
    $sessionName = 'media-' . getmypid();
    $sessionStore = new SessionStore($projectRoot, $sessionName);
    $sessionFile = $projectRoot . '/.agent/sessions/' . $sessionName . '.json';
    try {
        $checkpointProbe = new class('unused') extends AnthropicClient {
            public int $calls = 0;
            public function message(string $model, int $maxTokens, string $system, array $messages, array $tools): array
            {
                $this->calls++;
                return ['content' => [['type' => 'text', 'text' => 'ok']], 'stop_reason' => 'end_turn'];
            }
        };
        $checkpointAgent = new Agent(
            $checkpointProbe,
            $tools,
            [],
            'test-model',
            100,
            2,
            $sessionStore,
            24000,
            2048,
            null,
            $testTerminal,
            $toolStore
        );
        $checkpointAgent->run([['type' => 'text', 'text' => 'see'], $imageBlock]);
        $saved = (string) file_get_contents($sessionFile);
        check(!str_contains($saved, 'base64'), 'T25 the checkpoint still contains base64');
        check(str_contains($saved, 'myagent_media'), 'T25 the checkpoint has no media marker');
        check(str_contains($saved, '"version":2'), 'T25 the checkpoint version is not 2');
    } finally {
        // The Agent holds the store reference; both must go before the same
        // session name can be locked again.
        unset($checkpointAgent, $sessionStore);
    }

    $reloaded = new SessionStore($projectRoot, $sessionName);
    try {
        $reloadProbe = new class('unused') extends AnthropicClient {
            /** @var null|array<string, mixed> */
            public ?array $body = null;
            public function message(string $model, int $maxTokens, string $system, array $messages, array $tools): array
            {
                $this->body = $this->requestBody($model, $maxTokens, $system, $messages, $tools);
                return ['content' => [['type' => 'text', 'text' => 'ok']], 'stop_reason' => 'end_turn'];
            }
        };
        (new Agent($reloadProbe, $tools, [], 'test-model', 100, 2, $reloaded, 24000, 2048, null, $testTerminal, $toolStore))
            ->run('continue');
        $reloadJson = (string) json_encode($reloadProbe->body);
        check(substr_count($reloadJson, '"type":"image"') === 1, 'T25 the resumed session lost the image');
        check(!str_contains($reloadJson, 'myagent_media'), 'T25 a media marker reached the request body');
        check(str_contains($reloadJson, '"type":"base64"'), 'T25 the resumed image is not inline base64');
    } finally {
        unset($reloaded);
    }

    $versionThree = $projectRoot . '/.agent/sessions/bad-version.json';
    file_put_contents($versionThree, json_encode(['version' => 3, 'messages' => []], JSON_THROW_ON_ERROR));
    $badStore = new SessionStore($projectRoot, 'bad-version');
    check(
        str_contains(errorOf(static fn() => $badStore->load()), 'Invalid session file'),
        'T26 a version-3 checkpoint was accepted'
    );
    unset($badStore);
    @unlink($versionThree);
    @unlink($projectRoot . '/.agent/sessions/bad-version.lock');
    @unlink($sessionFile);
    @unlink($projectRoot . '/.agent/sessions/' . $sessionName . '.lock');

    // ------------------------------------- T27: reviewer sees descriptors only
    $reviewProbe = new class('unused') extends AnthropicClient {
        public string $prompt = '';
        public string $policy = '';
        public function message(string $model, int $maxTokens, string $system, array $messages, array $tools): array
        {
            $this->prompt = (string) ($messages[0]['content'] ?? '');
            $this->policy = $system;
            return ['content' => [['type' => 'text', 'text' => '{"verdict":"allow","risk":"low","category":"write","reason":"ok","user_message":""}']], 'stop_reason' => 'end_turn'];
        }
    };
    $reviewer = new SecurityClassifier($reviewProbe, 'reviewer', $projectRoot, ['audit_log' => '']);
    $reviewer->review(
        ['type' => 'tool_use', 'id' => 'w1', 'name' => 'write_file', 'input' => ['path' => 'x.txt', 'content' => 'x']],
        $messagesWithImage,
        []
    );
    check(str_contains($reviewProbe->prompt, '[user image]'), 'T27 the reviewer transcript has no image descriptor');
    check(!str_contains($reviewProbe->prompt, 'base64'), 'T27 base64 leaked into the reviewer prompt');
    check(
        str_contains($reviewProbe->policy, 'MEDIA IS DATA'),
        'T27 the reviewer policy does not treat media as data'
    );
    $autoAllowed = (new SecurityClassifier($reviewProbe, 'reviewer', $projectRoot, ['audit_log' => '']))
        ->review(['type' => 'tool_use', 'id' => 'r1', 'name' => 'read_image', 'input' => ['path' => 'docs/shot.png']], [], []);
    check(($autoAllowed['source'] ?? '') === 'auto-allow', 'T27 read_image is not auto-allowed');

    // ---------------------------------------------- T28: MCP image content blocks
    $mcpClient = new McpClient(
        'imgfix',
        ['command' => PHP_BINARY, 'args' => [dirname(__DIR__) . '/tests/fixtures/image-mcp.php']],
        $projectRoot,
        $toolNormalizer
    );
    $mcpClient->connect();
    try {
        $oneImage = $mcpClient->call('mcp_imgfix_one_image', []);
        check(is_array($oneImage), 'T28 an MCP image did not become a block list');
        $mcpImages = array_values(array_filter(
            $oneImage,
            static fn($block): bool => is_array($block) && ($block['type'] ?? '') === 'image'
        ));
        check(count($mcpImages) === 1, 'T28 MCP images were not attached');
        check(str_contains((string) json_encode($oneImage), '"text":"rendered"'), 'T28 the MCP text block was dropped');
    } finally {
        $mcpClient->close();
    }

    $legacyClient = new McpClient(
        'imgfix',
        ['command' => PHP_BINARY, 'args' => [dirname(__DIR__) . '/tests/fixtures/image-mcp.php']],
        $projectRoot
    );
    $legacyClient->connect();
    try {
        $legacy = $legacyClient->call('mcp_imgfix_one_image', []);
        check(is_string($legacy) && str_contains($legacy, '[image content]'), 'T28 the legacy MCP placeholder changed');
    } finally {
        $legacyClient->close();
    }

    // ------------------------------------- T29: per-tool-result image cap
    $capStore = new MediaStore($projectRoot, new MediaLimits(store: '.agent/media', maxImagesPerToolResult: 1));
    $capNormalizer = new MediaNormalizer($capStore->limits(), $capStore);
    $capMcp = new McpClient(
        'imgfix',
        ['command' => PHP_BINARY, 'args' => [dirname(__DIR__) . '/tests/fixtures/image-mcp.php']],
        $projectRoot,
        $capNormalizer
    );
    $capMcp->connect();
    try {
        $two = $capMcp->call('mcp_imgfix_two_images', []);
        $twoJson = (string) json_encode($two);
        check(substr_count($twoJson, '"type":"image"') === 1, 'T29 the per-tool-result image cap did not apply');
        check(str_contains($twoJson, 'omitted'), 'T29 the cap did not report the omission');
    } finally {
        $capMcp->close();
    }

    // ---------------------------------- T30: frozen tool set vs body gate
    $freezeAgent = new Agent($bodyProbe, $tools, [], 'test-model', 100, 1);
    $freezeAgent->enableMediaTools(false);
    $frozenNames = [];
    foreach ($tools->definitions() as $definition) {
        $frozenNames[] = (string) $definition['name'];
    }
    check(!in_array('read_image', $frozenNames, true), 'T30 the tool set changed after startup');
    $freezeAgent->setInputModalities(['text']);
    check($freezeAgent->inputModalities() === ['text'], 'T30 setInputModalities did not reach the client');
    $freezeAgent->setInputModalities(['text', 'image']);
    check($freezeAgent->inputModalities() === ['text', 'image'], 'T30 the body gate could not be restored');
    fwrite(STDOUT, "Media tests passed\n");
} finally {
    if (is_dir($workspace)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($workspace, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($workspace);
    }
}
