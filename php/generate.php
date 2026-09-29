<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function respond(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function getSetting(string $settingName): string
{
    $setting = getenv($settingName);
    if ($setting !== false && trim($setting) !== '') {
        return trim($setting);
    }

    $envPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
    if (!is_readable($envPath)) {
        return '';
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return '';
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);
        if (trim($name) !== $settingName) {
            continue;
        }

        $value = trim($value);
        if (strlen($value) >= 2 && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))) {
            $value = substr($value, 1, -1);
        }
        return trim($value);
    }

    return '';
}

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? '';
if ($requestMethod !== 'POST') {
    header('Allow: POST');
    respond(405, ['error' => 'Only POST requests are supported.']);
}

if (!extension_loaded('curl')) {
    error_log('Image generation requires the PHP cURL extension.');
    respond(500, ['error' => 'The server is missing the cURL extension.']);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    respond(400, ['error' => 'Request body must be valid JSON.']);
}

$prompt = trim((string) ($input['prompt'] ?? ''));
$size = (string) ($input['size'] ?? '1024x1024');
$provider = (string) ($input['provider'] ?? 'gemini');
$allowedSizes = ['1024x1024', '1024x1536', '1536x1024', 'auto'];

if ($prompt === '') {
    respond(400, ['error' => 'Please provide a prompt.']);
}
if (strlen($prompt) > 10000) {
    respond(400, ['error' => 'Prompt must be 10,000 bytes or fewer.']);
}
if (!in_array($size, $allowedSizes, true)) {
    respond(400, ['error' => 'The selected image size is not supported.']);
}
if (!in_array($provider, ['gemini', 'openai'], true)) {
    respond(400, ['error' => 'The selected image provider is not supported.']);
}

$apiKeySetting = $provider === 'gemini' ? 'GEMINI_API_KEY' : 'OPENAI_API_KEY';
$apiKey = getSetting($apiKeySetting);
if ($apiKey === '') {
    error_log($apiKeySetting . ' is not configured.');
    respond(500, ['error' => $provider === 'gemini'
        ? 'Gemini image generation is not configured on the server.'
        : 'OpenAI image generation is not configured on the server.']);
}

$aspectRatios = [
    '1024x1024' => '1:1',
    '1024x1536' => '2:3',
    '1536x1024' => '3:2',
];

if ($provider === 'gemini') {
    $model = getSetting('GEMINI_IMAGE_MODEL');
    if ($model === '') {
        $model = 'gemini-2.5-flash-image';
    }
    if (!preg_match('/^[a-zA-Z0-9._-]+$/', $model)) {
        respond(500, ['error' => 'The configured Gemini image model name is invalid.']);
    }

    $generationConfig = ['responseModalities' => ['IMAGE']];
    if ($size !== 'auto') {
        $generationConfig['imageConfig'] = ['aspectRatio' => $aspectRatios[$size]];
    }
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
    $payload = [
        'contents' => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => $generationConfig,
    ];
    $headers = [
        'Content-Type: application/json',
        'x-goog-api-key: ' . $apiKey,
    ];
} else {
    $url = 'https://api.openai.com/v1/images/generations';
    $payload = [
        'model' => 'gpt-image-1',
        'prompt' => $prompt,
        'size' => $size,
        'output_format' => 'png',
    ];
    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ];
}

$ch = curl_init($url);
if ($ch === false) {
    error_log('Unable to initialize cURL for image generation.');
    respond(500, ['error' => 'Unable to start the image generation request.']);
}

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 120,
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false) {
    error_log('Image generation request failed: ' . $curlError);
    respond(502, ['error' => 'Could not reach the image generation service.']);
}

$data = json_decode($response, true);
if ($httpCode < 200 || $httpCode >= 300) {
    $message = is_array($data) ? ($data['error']['message'] ?? 'Unknown upstream error') : 'Invalid upstream response';
    error_log(sprintf('%s image API returned HTTP %d: %s', ucfirst($provider), $httpCode, $message));
    respond(502, ['error' => ucfirst($provider) . ' image generation failed. Check the server log for details.']);
}

if (is_array($data)) {
    if ($provider === 'gemini') {
        $parts = $data['candidates'][0]['content']['parts'] ?? [];
        foreach ($parts as $part) {
            $image = $part['inlineData'] ?? null;
            if (!is_array($image) || !isset($image['data'], $image['mimeType'])) {
                continue;
            }
            $mimeType = (string) $image['mimeType'];
            if (str_starts_with($mimeType, 'image/') && base64_decode($image['data'], true) !== false) {
                respond(200, ['image' => 'data:' . $mimeType . ';base64,' . $image['data']]);
            }
        }
    } else {
        $imageData = $data['data'][0] ?? null;
        if (is_array($imageData) && isset($imageData['b64_json']) && base64_decode($imageData['b64_json'], true) !== false) {
            respond(200, ['image' => 'data:image/png;base64,' . $imageData['b64_json']]);
        }
        if (is_array($imageData) && isset($imageData['url']) && filter_var($imageData['url'], FILTER_VALIDATE_URL)) {
            respond(200, ['image' => $imageData['url']]);
        }
    }
}

error_log(ucfirst($provider) . ' image API response did not contain a usable image.');
respond(502, ['error' => ucfirst($provider) . ' returned no usable image. Make sure the configured model supports image generation.']);
