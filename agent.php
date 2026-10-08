<?php
// WordPress site auditor agent.
// Usage:  export ANTHROPIC_API_KEY="your-key"
//         php agent.php https://example.com

const MODEL = 'claude-sonnet-5-5';   // check the Anthropic docs for the current model name
const MAX_STEPS = 8;                 // guardrail: stop the loop after this many rounds

$apiKey = getenv('ANTHROPIC_API_KEY');
if (!$apiKey) {
    exit("Set the ANTHROPIC_API_KEY environment variable first.\n");
}
$siteUrl = $argv[1] ?? exit("Usage: php agent.php https://example.com\n");

// ---------------------------------------------------------------
// PART 1: Tool descriptions (what the model is told it can use)
// ---------------------------------------------------------------
$urlProp = [
    'type' => 'object',
    'properties' => [
        'url' => ['type' => 'string', 'description' => 'The full URL, e.g. https://example.com'],
    ],
    'required' => ['url'],
];

$tools = [
    [
        'name' => 'fetch_page',
        'description' => 'Fetches a web page and returns the HTTP status code and response time in seconds. Use this to check if a site is reachable and how fast it is.',
        'input_schema' => $urlProp,
    ],
    [
        'name' => 'check_wp_version',
        'description' => 'Fetches a site\'s homepage and reads the WordPress version from the generator meta tag or readme.html. Returns the version string, or null if the site hides it. Use this to find out which WordPress version a site runs. It does not say whether that version is the latest.',
        'input_schema' => $urlProp,
    ],
    [
        'name' => 'get_latest_wp_version',
        'description' => 'Returns the latest stable WordPress version number from the official WordPress.org API. Use this to compare against a site\'s version. Takes no input.',
        'input_schema' => ['type' => 'object', 'properties' => new stdClass()],
    ],
    [
        'name' => 'check_rest_api',
        'description' => 'Requests the site\'s /wp-json endpoint and returns the HTTP status code and whether the response was valid JSON. A 200 with valid JSON means the REST API is working and publicly reachable. A 404 usually means it is disabled or permalinks are broken. A 401 or 403 usually means a security plugin or firewall is blocking it. Use this to check REST API health and exposure.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'url' => ['type' => 'string', 'description' => 'The site\'s base URL, e.g. https://example.com (without /wp-json)'],
            ],
            'required' => ['url'],
        ],
    ],
];

// ---------------------------------------------------------------
// PART 2: The real PHP functions that run when the model asks
// ---------------------------------------------------------------

// Only allow plain http/https URLs (guardrail).
function valid_url(string $url): bool
{
    $scheme = parse_url($url, PHP_URL_SCHEME);
    return filter_var($url, FILTER_VALIDATE_URL) !== false && in_array($scheme, ['http', 'https'], true);
}

// Shared GET helper: returns status code, body and time taken.
function http_get(string $url): array
{
    $start = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'WPAuditAgent/0.1',
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [
        'status' => $status,
        'body' => $body === false ? '' : $body,
        'seconds' => round(microtime(true) - $start, 2),
    ];
}

function tool_fetch_page(string $url): array
{
    $r = http_get($url);
    return ['status' => $r['status'], 'seconds' => $r['seconds']];
}

function tool_check_wp_version(string $url): array
{
    $home = http_get($url);
    if (preg_match('/<meta name="generator" content="WordPress ([\d.]+)"/i', $home['body'], $m)) {
        return ['version' => $m[1]];
    }
    $readme = http_get(rtrim($url, '/') . '/readme.html');
    if (preg_match('/Version ([\d.]+)/', $readme['body'], $m)) {
        return ['version' => $m[1]];
    }
    return ['version' => null];
}

function tool_get_latest_wp_version(): array
{
    $r = http_get('https://api.wordpress.org/core/version-check/1.7/');
    $data = json_decode($r['body'], true);
    return ['latest_version' => $data['offers'][0]['current'] ?? null];
}

function tool_check_rest_api(string $url): array
{
    $r = http_get(rtrim($url, '/') . '/wp-json');
    json_decode($r['body']);
    return [
        'status' => $r['status'],
        'valid_json' => json_last_error() === JSON_ERROR_NONE && $r['body'] !== '',
    ];
}

// The dispatcher: matches the tool name the model asked for to a PHP function.
function run_tool(string $name, array $input): array
{
    $url = $input['url'] ?? '';
    if ($name !== 'get_latest_wp_version' && !valid_url($url)) {
        return ['error' => 'Invalid URL. Only http and https URLs are allowed.'];
    }
    return match ($name) {
        'fetch_page' => tool_fetch_page($url),
        'check_wp_version' => tool_check_wp_version($url),
        'get_latest_wp_version' => tool_get_latest_wp_version(),
        'check_rest_api' => tool_check_rest_api($url),
        default => ['error' => "Unknown tool: $name"],
    };
}

// ---------------------------------------------------------------
// PART 3: Calling the API
// ---------------------------------------------------------------
function call_claude(array $messages, array $tools, string $apiKey): array
{
    $payload = [
        'model' => MODEL,
        'max_tokens' => 1024,
        'system' => 'You are a WordPress site auditor. Use the tools to gather facts, then write a short report. Never guess: if a tool returns null or an error, say the value is unknown.',
        'tools' => $tools,
        'messages' => $messages,
    ];
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => [
            'content-type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $data = json_decode((string) $raw, true);
    if (!isset($data['content'])) {
        exit("API error: " . $raw . "\n");
    }
    return $data;
}

// ---------------------------------------------------------------
// PART 4: The agent loop
// ---------------------------------------------------------------
$messages = [
    ['role' => 'user', 'content' => "Audit this WordPress site: $siteUrl. Check that it is reachable and how fast it is, which WordPress version it runs and whether that is the latest, and whether the REST API is reachable."],
];

for ($step = 1; $step <= MAX_STEPS; $step++) {
    $response = call_claude($messages, $tools, $apiKey);

    // PHP turns an empty JSON object {} into [] when decoding. The API needs {}
    // when we send the history back, so fix empty tool inputs here.
    foreach ($response['content'] as &$block) {
        if ($block['type'] === 'tool_use' && empty($block['input'])) {
            $block['input'] = new stdClass();
        }
    }
    unset($block);

    // Add the model's reply to the history.
    $messages[] = ['role' => 'assistant', 'content' => $response['content']];

    // No tool requested: the model has finished, so print its report.
    if ($response['stop_reason'] !== 'tool_use') {
        foreach ($response['content'] as $block) {
            if ($block['type'] === 'text') {
                echo $block['text'] . "\n";
            }
        }
        exit;
    }

    // Run every tool the model asked for and collect the results.
    $results = [];
    foreach ($response['content'] as $block) {
        if ($block['type'] !== 'tool_use') {
            continue;
        }
        $input = (array) $block['input'];
        $output = run_tool($block['name'], $input);

        // Log each tool call to a file.
        file_put_contents(
            __DIR__ . '/agent.log',
            date('c') . " step=$step tool={$block['name']} input=" . json_encode($input) . " output=" . json_encode($output) . "\n",
            FILE_APPEND
        );

        $results[] = [
            'type' => 'tool_result',
            'tool_use_id' => $block['id'],
            'content' => json_encode($output),
        ];
    }

    // Send the results back as a user message, then the loop repeats.
    $messages[] = ['role' => 'user', 'content' => $results];
}

echo "Stopped: reached the maximum of " . MAX_STEPS . " steps without a final answer.\n";
