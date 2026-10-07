<?php

declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

function fail(int $status, string $message): never
{
    http_response_code($status);
    exit($message);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    fail(405, 'METHOD_NOT_ALLOWED');
}
if (strlen($_SERVER['QUERY_STRING'] ?? '') > 2048) {
    fail(414, 'REQUEST_TOO_LARGE');
}

$data = [];
foreach (['i', 'r', 'c', 'a', 'p', 'lat', 'lon'] as $key) {
    $value = $_GET[$key] ?? '';
    if (!is_string($value) || strlen($value) > 32) {
        if (in_array($key, ['lat', 'lon'], true)) {
            $value = '';
        } else {
            fail(422, 'INVALID_PARAMETER');
        }
    }
    $data[$key] = $value;
}
if (!preg_match('/^[0-9]{15}$/D', $data['i'])
    || !preg_match('/^[0-9]{1,5}$/D', $data['r']) || (int) $data['r'] > 65535
    || !in_array($data['p'], ['0', '1'], true)) {
    fail(422, 'INVALID_PARAMETER');
}
if (($data['c'] === '') !== ($data['a'] === '')
    || ($data['c'] !== '' && (!preg_match('/^[0-9]{1,5}$/D', $data['c'])
        || (int) $data['c'] < 1 || (int) $data['c'] > 65535
        || !in_array($data['a'], ['0', '1', '2'], true)))) {
    fail(422, 'INVALID_REPORT');
}
$validLocation = is_numeric($data['lat']) && is_numeric($data['lon'])
    && is_finite((float) $data['lat']) && is_finite((float) $data['lon'])
    && abs((float) $data['lat']) <= 90 && abs((float) $data['lon']) <= 180;
if (!$validLocation) {
    $data['lat'] = '';
    $data['lon'] = '';
}

$url = getenv('TRACKER_API_URL') ?: '';
$token = getenv('TRACKER_BRIDGE_TOKEN') ?: '';
if (!filter_var($url, FILTER_VALIDATE_URL) || parse_url($url, PHP_URL_SCHEME) !== 'https'
    || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null
    || strlen($token) < 32 || preg_match('/[\r\n]/', $token) || !extension_loaded('curl')) {
    fail(503, 'BRIDGE_NOT_CONFIGURED');
}

$logDirectory = dirname(__DIR__).'/storage/logs';
umask(0077);
if (!is_dir($logDirectory) && !@mkdir($logDirectory, 0700, true) && !is_dir($logDirectory)) {
    fail(503, 'LOG_UNAVAILABLE');
}
$line = json_encode(['time' => gmdate('c'), 'ip' => $_SERVER['REMOTE_ADDR'] ?? null, 'data' => $data], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
if (@file_put_contents($logDirectory.'/tracker-'.gmdate('Y-m-d').'.jsonl', $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
    fail(503, 'LOG_UNAVAILABLE');
}

$body = '';
$curl = curl_init($url);
curl_setopt_array($curl, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($data, JSON_THROW_ON_ERROR),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: text/plain', 'Authorization: Bearer '.$token],
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
        if (strlen($body) + strlen($chunk) > 63) {
            return 0;
        }
        $body .= $chunk;
        return strlen($chunk);
    },
]);
$ok = curl_exec($curl);
$status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
curl_close($curl);

if ($ok === false || $status !== 200) {
    // A fabricated success would discard an unrecorded device acknowledgement.
    fail(503, 'UPSTREAM_UNAVAILABLE');
}
$fields = explode(',', $body);
if (count($fields) !== 12 || $fields[0] !== '1'
    || !preg_match('/^[0-9]{1,5}$/D', $fields[1]) || (int) $fields[1] > 65535
    || !in_array($fields[2], ['', '0', '1', '2'], true)
    || !in_array($fields[3], ['', '0', '1', '2'], true)
    || !in_array($fields[4], ['', '1', '2', '3', '4', '5', '6', '7', '8'], true)
    || !in_array($fields[5], ['', '0', '1'], true)
    || ($fields[6] !== '' && !preg_match('/^\+98[0-9]{10}$/D', $fields[6]))
    || ($fields[7] !== 'off' && $fields[7] !== '' && !preg_match('/^\+98[0-9]{10}$/D', $fields[7]))
    || !in_array($fields[8], ['', '0', '1', '2'], true)
    || !in_array($fields[9], ['', '0', '1', '2'], true)
    || (($fields[10] === '') !== ($fields[11] === ''))
    || ($fields[10] !== '' && (!preg_match('/^[0-9]{1,5}$/D', $fields[10])
        || (int) $fields[10] < 1 || (int) $fields[10] > 65535 || !in_array($fields[11], ['L', 'U'], true)))) {
    fail(503, 'INVALID_UPSTREAM_RESPONSE');
}
echo $body;
