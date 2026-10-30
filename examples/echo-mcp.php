<?php
declare(strict_types=1);

// Minimal stdio MCP server for a local smoke test.
while (($line = fgets(STDIN)) !== false) {
    $request = json_decode($line, true);
    if (!is_array($request) || !isset($request['id'])) {
        continue;
    }
    $result = match ($request['method'] ?? '') {
        'initialize' => [
            'protocolVersion' => '2025-11-25',
            'capabilities' => ['tools' => (object) []],
            'serverInfo' => ['name' => 'echo-example', 'version' => '1.0'],
        ],
        'tools/list' => ['tools' => [[
            'name' => 'echo',
            'description' => 'Return the supplied text',
            'inputSchema' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']],
        ]]],
        'tools/call' => ['content' => [['type' => 'text', 'text' => (string) ($request['params']['arguments']['text'] ?? '')]]],
        default => null,
    };
    $response = $result === null
        ? ['jsonrpc' => '2.0', 'id' => $request['id'], 'error' => ['code' => -32601, 'message' => 'Unknown method']]
        : ['jsonrpc' => '2.0', 'id' => $request['id'], 'result' => $result];
    fwrite(STDOUT, json_encode($response, JSON_UNESCAPED_SLASHES) . "\n");
    fflush(STDOUT);
}
