<?php

declare(strict_types=1);

// Captura de webhooks REALES de LiveKit (fase 3B): guarda encabezado Authorization y cuerpo ORIGINAL.
$raw = file_get_contents('php://input');
$event = json_decode((string) $raw, true);
$name = sprintf('%s/captures/%.6f-%s.json', __DIR__, microtime(true), preg_replace('/[^a-z_]/', '', (string) ($event['event'] ?? 'x')));
file_put_contents($name, json_encode([
    'authorization' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,
    'raw' => $raw,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
http_response_code(200);
header('Content-Type: application/json');
echo '{"ok":true}';
