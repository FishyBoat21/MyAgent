<?php
declare(strict_types=1);

// Minimal stdio MCP server that returns image content blocks, so the media
// pipeline can be tested end to end without a network or a real server.

$png = static function (int $width, int $height, int $r, int $g, int $b): string {
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, $r, $g, $b));
    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);
    return $bytes;
};

while (($line = fgets(STDIN)) !== false) {
    $request = json_decode($line, true);
    if (!is_array($request) || !isset($request['id'])) {
        continue;
    }
    $method = (string) ($request['method'] ?? '');
    $tool = (string) ($request['params']['name'] ?? '');
    $result = match ($method) {
        'initialize' => [
            'protocolVersion' => '2025-11-25',
            'capabilities' => ['tools' => (object) []],
            'serverInfo' => ['name' => 'image-fixture', 'version' => '1.0'],
        ],
        'tools/list' => ['tools' => [
            [
                'name' => 'one_image',
                'description' => 'Return one image content block',
                'inputSchema' => ['type' => 'object', 'properties' => []],
            ],
            [
                'name' => 'two_images',
                'description' => 'Return two image content blocks',
                'inputSchema' => ['type' => 'object', 'properties' => []],
            ],
        ]],
        'tools/call' => ['content' => array_merge(
            [['type' => 'text', 'text' => 'rendered']],
            [['type' => 'image', 'mimeType' => 'image/png', 'data' => base64_encode($png(6, 6, 10, 120, 200))]],
            $tool === 'two_images'
                ? [['type' => 'image', 'mimeType' => 'image/png', 'data' => base64_encode($png(8, 8, 200, 40, 40))]]
                : []
        )],
        default => null,
    };
    $response = $result === null
        ? ['jsonrpc' => '2.0', 'id' => $request['id'], 'error' => ['code' => -32601, 'message' => 'Unknown method']]
        : ['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => $result];
    fwrite(STDOUT, json_encode($response, JSON_UNESCAPED_SLASHES) . "\n");
    fflush(STDOUT);
}
