#!/usr/local/bin/php
<?php

/**
 * OPNsense Splunk HEC Plugin – Exporter Daemon
 *
 * Reads configured log files, detects new lines (surviving log rotation via
 * inode tracking), and forwards each line as a JSON event to a Splunk HTTP
 * Event Collector endpoint.
 *
 * @license BSD-2-Clause
 */

declare(strict_types=1);

define('CONF_PATH',  '/var/etc/splunk_hec.conf');
define('STATE_PATH', '/var/run/splunk_hec_state.json');
define('CACHE_PATH', '/var/run/splunk_hec_cache.log');
define('LOG_PATH',   '/var/log/splunk_hec.log');

function hec_log(string $message): void
{
    $ts = date('Y-m-d\TH:i:sP');
    @file_put_contents(LOG_PATH, "[{$ts}] {$message}\n", FILE_APPEND | LOCK_EX);
}

function load_state(): array
{
    if (!is_readable(STATE_PATH)) return [];
    $json = @file_get_contents(STATE_PATH);
    $data = $json !== false ? json_decode($json, true) : null;
    return is_array($data) ? $data : [];
}

function save_state(array $state): void
{
    $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    @file_put_contents(STATE_PATH, $json, LOCK_EX);
}

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
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $response = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($code === 0) {
        $err = curl_error($ch);
        echo "ERROR cURL failure: {$err}\n";
        hec_log("ERROR cURL failure: {$err}");
    } elseif ($code !== 200) {
        echo "ERROR Splunk API returned HTTP {$code}. Response: {$response}\n";
        hec_log("ERROR Splunk API returned HTTP {$code}. Response: {$response}");
    }
    
    curl_close($ch);
    return $code;
}

function cache_payload(string $payload): void
{
    @file_put_contents(CACHE_PATH, $payload . "\n", FILE_APPEND | LOCK_EX);
}

function flush_cache(string $endpoint, string $token, int $maxSizeMB, int $maxAgeHours): int
{
    if (!is_file(CACHE_PATH) || filesize(CACHE_PATH) === 0) return 0;

    $mtime = filemtime(CACHE_PATH);
    if ($mtime !== false && (time() - $mtime) > ($maxAgeHours * 3600)) {
        hec_log('INFO  Cache expired (>' . $maxAgeHours . ' h) — purging.');
        @unlink(CACHE_PATH);
        return 0;
    }

    if (filesize(CACHE_PATH) > $maxSizeMB * 1024 * 1024) {
        hec_log('WARN  Cache exceeds ' . $maxSizeMB . ' MB — purging.');
        @unlink(CACHE_PATH);
        return 0;
    }

    $lines  = file(CACHE_PATH, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $failed = [];
    $ok     = 0;

    foreach ($lines as $line) {
        $code = hec_post($endpoint, $token, $line);
        if ($code === 200) $ok++;
        else $failed[] = $line;
    }

    if (count($failed) > 0) {
        file_put_contents(CACHE_PATH, implode("\n", $failed) . "\n", LOCK_EX);
    } else {
        @unlink(CACHE_PATH);
    }

    if ($ok > 0) hec_log('INFO  Flushed ' . $ok . ' cached payload(s).');
    return $ok;
}

// ---------------------------------------------------------------------------
// Daemon Loop
// ---------------------------------------------------------------------------

echo "INFO  Exporter daemon started.\n";
hec_log('INFO  Exporter daemon started.');

while (true) {
    echo "DEBUG: Reading INI file...\n";
    $ini = @parse_ini_file(CONF_PATH, true);
    if (!$ini) {
        echo "DEBUG: Failed to read INI. Sleeping 10s...\n";
        sleep(10);
        continue;
    }

    $cfg = $ini['splunk_hec'] ?? [];
    if (($cfg['enabled'] ?? '0') !== '1') {
        echo "INFO  Service disabled — exiting.\n";
        hec_log('INFO  Service disabled — exiting.');
        exit(0);
    }

    $token    = $cfg['token']    ?? '';
    $endpoint = $cfg['endpoint'] ?? '';

    if ($token === '' || $endpoint === '') {
        echo "DEBUG: Token or Endpoint missing. Sleeping 10s...\n";
        sleep(10);
        continue;
    }

    $maxSizeMB = max(1, (int)($cfg['cache_size'] ?? 100));
    $maxAgeHrs = max(1, (int)($cfg['cache_time'] ?? 24));

    // Map the boolean settings from the UI to log paths and Splunk sourcetypes
    $logsCfg = $ini['logs'] ?? [];
    $sources = [];
    
    if (($logsCfg['system'] ?? '0') === '1') {
        $sources['/var/log/system/latest.log'] = 'opnsense:syslog';
    }
    if (($logsCfg['filter'] ?? '0') === '1') {
        $sources['/var/log/filter/latest.log'] = 'opnsense:filterlog';
    }

    if (empty($sources)) {
        echo "DEBUG: No log sources enabled. Sleeping 10s...\n";
        sleep(10);
        continue;
    }

    echo "DEBUG: Flushing cache if any...\n";
    flush_cache($endpoint, $token, $maxSizeMB, $maxAgeHrs);

    echo "DEBUG: Loading state...\n";
    $state = load_state();

    foreach ($sources as $logFile => $sourcetype) {
        if (!is_readable($logFile)) {
            echo "DEBUG: Log file not readable: {$logFile}\n";
            continue;
        }

        $currentInode = fileinode($logFile);
        $prev         = $state[$logFile] ?? null;

        if ($prev !== null && (int)$prev['inode'] !== $currentInode) {
            echo "INFO  Log rotated: {$logFile}\n";
            hec_log('INFO  Log rotated: ' . $logFile);
            $prev = null;
        }

        $offset   = ($prev !== null) ? (int)$prev['offset'] : 0;
        $fileSize = filesize($logFile);

        if ($offset > $fileSize) {
            echo "INFO  File truncated: {$logFile}\n";
            hec_log('INFO  File truncated: ' . $logFile);
            $offset = 0;
        }

        if ($offset < $fileSize) {
            echo "DEBUG: Reading new lines from {$logFile}...\n";
            $fh = fopen($logFile, 'rb');
            if ($fh !== false) {
                fseek($fh, $offset);
                $lineCount = 0;
                $batchCount = 0;
                $payloadBatch = '';

                while (($line = fgets($fh)) !== false) {
                    $line = rtrim($line, "\r\n");
                    if ($line === '') continue;

                    $payloadBatch .= json_encode([
                        'time'       => time(),
                        'host'       => gethostname(),
                        'source'     => $logFile,
                        'sourcetype' => $sourcetype,
                        'event'      => $line,
                    ], JSON_UNESCAPED_SLASHES) . "\n";

                    $batchCount++;

                    // Send every 500 lines to avoid massive memory use or timeouts
                    if ($batchCount >= 500) {
                        $code = hec_post($endpoint, $token, $payloadBatch);
                        if ($code === 200) {
                            $lineCount += $batchCount;
                        } else {
                            cache_payload($payloadBatch);
                        }
                        $payloadBatch = '';
                        $batchCount = 0;
                    }
                }

                // Send remaining batch
                if ($batchCount > 0) {
                    $code = hec_post($endpoint, $token, $payloadBatch);
                    if ($code === 200) {
                        $lineCount += $batchCount;
                    } else {
                        cache_payload($payloadBatch);
                    }
                }

                $newOffset = ftell($fh);
                fclose($fh);

                $state[$logFile] = [
                    'inode'  => $currentInode,
                    'offset' => $newOffset,
                ];

                if ($lineCount > 0) {
                    $msg = "INFO  {$logFile}: forwarded {$lineCount} line(s).";
                    echo $msg . "\n";
                    hec_log($msg);
                }
            }
        } else {
            echo "DEBUG: No new lines in {$logFile}.\n";
        }
    }

    save_state($state);
    
    echo "DEBUG: Sleeping for 10 seconds...\n";
    sleep(10);
}
