<?php
// ====== CORS & headers ======
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header("Access-Control-Allow-Origin: $origin");
header("Vary: Origin");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Access-Control-Max-Age: 86400");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

// ====== Helpers ======
function send_json($statusCode, $payload) {
  http_response_code($statusCode);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function clean_string($s) {
  if (!is_string($s)) return '';
  // basic cleanup
  return trim(preg_replace('/\s+/u', ' ', $s));
}

// ====== Input ======
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
  send_json(400, ['error' => 'Invalid JSON body']);
}

$text = clean_string($data['text'] ?? '');
$imgDesc = clean_string($data['imgDesc'] ?? '');

// ====== API key ======
$apiKey = getenv('OPENAI_API_KEY');  // <-- recommended
// $apiKey = 'sk-REPLACE_WITH_YOUR_KEY'; // <-- for local testing only (remove later)

if (!$apiKey) {
  // Provide a helpful error + a temporary mock so frontend stays usable
  send_json(500, [
    'error'  => 'Missing OPENAI_API_KEY',
    'detail' => 'Set environment variable OPENAI_API_KEY on your server.'
  ]);
}$apiKey = getenv('OPENAI_API_KEY');
if (!$apiKey) {
  $keyFile = __DIR__ . '/.openai_key';
  if (is_file($keyFile)) {
    $apiKey = trim(file_get_contents($keyFile));
  }
}
if (!$apiKey) {
  send_json(500, [
    'error'  => 'Missing OPENAI_API_KEY',
    'detail' => 'Set env var or create api/.openai_key with the key'
  ]);
}


// ====== Prompt ======
$system = <<<SYS
Doel: Corrigeer spelling/grammatica en herschrijf naar een algoritme-vriendelijke social-post.
Eisen:
- Korte krachtige hook (<=80 tekens) op 1e regel.
- Verbeter spelling en toon (spreektaal, duidelijk, actief).
- Voeg 1 call-to-action toe (bijv. "Volg voor meer", "Reageer hieronder").
- 3–7 relevante hashtags (geen spam).
- Als imgDesc bestaat: geef alt-tekst (<=120 tekens) die de inhoud beschrijft.
- Output in JSON exact: {"hook":"","caption":"","alt":"","hashtags":["#..."]}
- Wees feitelijk; geen ongepaste claims of copyrighted lyrics >10 woorden.
SYS;

$user = "Tekst: \"\"\"{$text}\"\"\"\nAfbeelding: \"\"\"{$imgDesc}\"\"\"";

// ====== OpenAI call (Chat Completions JSON mode) ======
$payload = [
  'model' => 'gpt-4o-mini',
  'temperature' => 0.5,
  'messages' => [
    ['role' => 'system', 'content' => $system],
    ['role' => 'user', 'content' => $user]
  ],
  'response_format' => ['type' => 'json_object']
];

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST => true,
  CURLOPT_HTTPHEADER => [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey
  ],
  CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
  CURLOPT_TIMEOUT => 60
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($response === false) {
  send_json(500, ['error' => 'Rewrite failed', 'detail' => "cURL error: $curlErr"]);
}

if ($httpCode < 200 || $httpCode >= 300) {
  // Return upstream error payload for debugging
  send_json($httpCode, [
    'error'  => 'OpenAI error',
    'detail' => json_decode($response, true) ?: $response
  ]);
}

$resp = json_decode($response, true);
$content = $resp['choices'][0]['message']['content'] ?? '{}';
$parsed  = json_decode($content, true);

// Guard rails & fallbacks
$hook = clean_string($parsed['hook'] ?? mb_substr($text, 0, 80));
$caption = clean_string($parsed['caption'] ?? $text);
$alt = clean_string($parsed['alt'] ?? ($imgDesc ? mb_substr($imgDesc, 0, 120) : 'AI-geoptimaliseerde post'));
$hashtags = $parsed['hashtags'] ?? [];
if (!is_array($hashtags)) $hashtags = [];

send_json(200, [
  'hook' => $hook,
  'caption' => $caption,
  'alt' => $alt,
  'hashtags' => $hashtags
]);

const r = await fetch('http://127.0.0.1:8000/api/rewrite.php', { ... })
