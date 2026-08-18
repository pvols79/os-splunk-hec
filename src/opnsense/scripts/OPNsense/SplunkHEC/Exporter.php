#!/usr/local/bin/php
<?php

/**
 * OPNsense Splunk HEC Plugin – Exporter Daemon
 *
 * Reads configured log files, detects new lines (surviving log rotation via
 * inode tracking), and forwards each line as a JSON event to a Splunk HTTP
 * Event Collector endpoint.  Failed payloads are cached on disk and retried
 * on subsequent runs.
 *
 * Invoked by configd on a periodic timer (default: every 60 seconds).
 *
 * @license BSD-2-Clause
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Paths
// ---------------------------------------------------------------------------
define('CONF_PATH',  '/var/etc/splunk_hec.conf');
define('STATE_PATH', '/var/run/splunk_hec_state.json');
define('CACHE_PATH', '/var/run/splunk_hec_cache.log');
define('LOG_PATH',   '/var/log/splunk_hec.log');

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Append a timestamped message to the daemon's own log file.
 */
function hec_log(string $message): void
{
    $ts = date('Y-m-d\TH:i:sP');
    @file_put_contents(LOG_PATH, "[{$ts}] {$message}\n", FILE_APPEND | LOCK_EX);
}

/**
 * Load the INI configuration written by the API controller.
 *
 * @return array<string,string>|null  Section [splunk_hec] or null on failure.
 */
function load_config(): ?array
{
    if (!is_readable(CONF_PATH)) {
        hec_log('WARN  Configuration file not found: ' . CONF_PATH);
        return null;
    }
    $ini = parse_ini_file(CONF_PATH, true);
    return is_array($ini) && isset($ini['splunk_hec']) ? $ini['splunk_hec'] : null;
}

/**
 * Load persisted file-tracking state (inode + offset per path).
 *
 * @return array<string,array{inode:int,offset:int}>
 */
function load_state(): array
{
    if (!is_readable(STATE_PATH)) {
        return [];
    }
    $json = @file_get_contents(STATE_PATH);
    $data = $json !== false ? json_decode($json, true) : null;
    return is_array($data) ? $data : [];
}

/**
 * Persist file-tracking state.
 *
 * @param array<string,array{inode:int,offset:int}> $state
 */
function save_state(array $state): void
{
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    @file_put_contents(STATE_PATH, $json, LOCK_EX);
}

/**
 * POST a JSON event payload to the Splunk HEC endpoint.
 *
 * @param  string $endpoint  Full URL (e.g. https://host:8088/services/collector).
 * @param  string $token     HEC token.
 * @param  string $payload   JSON body.
 * @return int               HTTP response code (0 on total failure).
 */
function hec_post(string $endpoint, string $token, string $payload): int
{
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Splunk ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($code === 0) {
        hec_log('ERROR cURL failure: ' . curl_error($ch));
    }
    curl_close($ch);
    return $code;
}

/**
 * Append a failed payload line to the on-disk cache.
 */
function cache_payload(string $payload): void
{
    @file_put_contents(CACHE_PATH, $payload . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * Attempt to flush previously cached payloads.
 *
 * @param  string $endpoint
 * @param  string $token
 * @param  int    $maxSizeMB   Maximum cache file size in megabytes.
 * @param  int    $maxAgeHours Maximum cache retention in hours.
 * @return int                 Number of successfully flushed lines.
 */
function flush_cache(string $endpoint, string $token, int $maxSizeMB, int $maxAgeHours): int
{
    if (!is_file(CACHE_PATH) || filesize(CACHE_PATH) === 0) {
        return 0;
    }

    // Enforce retention policy: drop the cache if it is too old.
    $mtime = filemtime(CACHE_PATH);
    if ($mtime !== false && (time() - $mtime) > ($maxAgeHours * 3600)) {
        hec_log('INFO  Cache expired (>' . $maxAgeHours . ' h) — purging.');
        @unlink(CACHE_PATH);
        return 0;
    }

    // Enforce size policy.
    $sizeBytes = filesize(CACHE_PATH);
    if ($sizeBytes > $maxSizeMB * 1024 * 1024) {
        hec_log('WARN  Cache exceeds ' . $maxSizeMB . ' MB — purging.');
        @unlink(CACHE_PATH);
        return 0;
    }

    $lines  = file(CACHE_PATH, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $failed = [];
    $ok     = 0;

    foreach ($lines as $line) {
        $code = hec_post($endpoint, $token, $line);
        if ($code === 200) {
            $ok++;
        } else {
            $failed[] = $line;
        }
    }

    // Re-write the cache with any remaining failures.
    if (count($failed) > 0) {
        file_put_contents(CACHE_PATH, implode("\n", $failed) . "\n", LOCK_EX);
    } else {
        @unlink(CACHE_PATH);
    }

    if ($ok > 0) {
        hec_log('INFO  Flushed ' . $ok . ' cached payload(s).');
    }
    return $ok;
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

hec_log('INFO  Exporter invoked.');

$cfg = load_config();
if ($cfg === null) {
    hec_log('WARN  No valid configuration — exiting.');
    exit(0);
}

if (($cfg['enabled'] ?? '0') !== '1') {
    hec_log('INFO  Service disabled — exiting.');
    exit(0);
}

$token    = $cfg['token']    ?? '';
$endpoint = $cfg['endpoint'] ?? '';

if ($token === '' || $endpoint === '') {
    hec_log('WARN  Token or endpoint not configured — exiting.');
    exit(0);
}

$logFiles   = array_filter(array_map('trim', explode(',', $cfg['logs'] ?? '')));
$maxSizeMB  = max(1, (int)($cfg['cache_size'] ?? 100));
$maxAgeHrs  = max(1, (int)($cfg['cache_time'] ?? 24));

if (count($logFiles) === 0) {
    hec_log('WARN  No log files configured — exiting.');
    exit(0);
}

// --- Flush any cached payloads first -----------------------------------------
flush_cache($endpoint, $token, $maxSizeMB, $maxAgeHrs);

// --- Process each log file ---------------------------------------------------
$state = load_state();

foreach ($logFiles as $logFile) {
    if (!is_readable($logFile)) {
        hec_log('WARN  Cannot read log file: ' . $logFile);
        continue;
    }

    $currentInode = fileinode($logFile);
    $prev         = $state[$logFile] ?? null;

    // Detect log rotation (inode changed) → start from the beginning.
    if ($prev !== null && (int)$prev['inode'] !== $currentInode) {
        hec_log('INFO  Log rotated: ' . $logFile . ' (inode ' . $prev['inode'] . ' → ' . $currentInode . ')');
        $prev = null;
    }

    $offset = ($prev !== null) ? (int)$prev['offset'] : 0;
    $fh = fopen($logFile, 'rb');
    if ($fh === false) {
        hec_log('ERROR Cannot open: ' . $logFile);
        continue;
    }

    // If the file shrank (e.g., truncated), restart from zero.
    $fileSize = filesize($logFile);
    if ($offset > $fileSize) {
        hec_log('INFO  File truncated: ' . $logFile . ' — resetting offset.');
        $offset = 0;
    }

    fseek($fh, $offset);
    $lineCount = 0;

    while (($line = fgets($fh)) !== false) {
        $line = rtrim($line, "\r\n");
        if ($line === '') {
            continue;
        }

        $payload = json_encode([
            'time'   => time(),
            'host'   => gethostname(),
            'source' => $logFile,
            'event'  => $line,
        ], JSON_UNESCAPED_SLASHES);

        $code = hec_post($endpoint, $token, $payload);

        if ($code === 200) {
            $lineCount++;
        } else {
            hec_log('WARN  POST ' . $endpoint . ' → HTTP ' . $code . ' — caching payload.');
            cache_payload($payload);
        }
    }

    $newOffset = ftell($fh);
    fclose($fh);

    $state[$logFile] = [
        'inode'  => $currentInode,
        'offset' => $newOffset,
    ];

    if ($lineCount > 0) {
        hec_log('INFO  ' . $logFile . ': forwarded ' . $lineCount . ' line(s).');
    }
}

save_state($state);
hec_log('INFO  Exporter run complete.');
exit(0);
