<?php

declare(strict_types=1);

error_reporting(E_ALL);

/**
 * User agent picker serving mobile/desktop samples without re-reading the full dataset.
 */

$type = detectRequestedType($argv ?? []);

if ($type === null) {
    respondWithError(400, 'Missing required query parameter: type=desktop|mobile');
}

$type = strtolower($type);
$map = [
    'desktop' => [
        'data' => __DIR__ . '/out/ua-desktop.txt',
        'index' => __DIR__ . '/out/ua-desktop.idx.php',
    ],
    'mobile' => [
        'data' => __DIR__ . '/out/ua-mobile.txt',
        'index' => __DIR__ . '/out/ua-mobile.idx.php',
    ],
];

if (!isset($map[$type])) {
    respondWithError(400, 'Unsupported type. Use type=desktop or type=mobile.');
}

$dataPath = $map[$type]['data'];
$indexPath = $map[$type]['index'];

if (!is_file($dataPath) || !is_readable($dataPath)) {
    respondWithError(500, 'Sample file unavailable.');
}

if (!is_file($indexPath) || !is_readable($indexPath)) {
    respondWithError(500, 'Sample index unavailable. Re-run process.php first.');
}

try {
    $offsets = loadOffsets($indexPath);
    $userAgent = pickRandomUserAgent($dataPath, $offsets);
} catch (Throwable $exception) {
    respondWithError(500, 'Failed to load user agent sample.');
}

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
}

echo $userAgent . PHP_EOL;
exit(0);

/**
 * @param string[] $argv
 */
function detectRequestedType(array $argv): ?string
{
    if (PHP_SAPI === 'cli') {
        if (isset($argv[1])) {
            return (string) $argv[1];
        }

        return null;
    }

    return isset($_GET['type']) ? (string) $_GET['type'] : null;
}

/**
 * @return int[]
 */
function loadOffsets(string $indexPath): array
{
    static $localCache = [];

    if (isset($localCache[$indexPath])) {
        return $localCache[$indexPath];
    }

    $offsets = null;
    $apcuKey = 'ua_idx_' . md5($indexPath);
    $supportsApcu = apcuIsEnabled();

    if ($supportsApcu) {
        $cached = apcu_fetch($apcuKey, $success);

        if ($success && is_array($cached) && isset($cached['mtime'], $cached['offsets'])) {
            $currentMtime = (int) @filemtime($indexPath);

            if ($currentMtime === (int) $cached['mtime'] && is_array($cached['offsets'])) {
                $offsets = $cached['offsets'];
            }
        }
    }

    if (!is_array($offsets)) {
        /** @var mixed $loaded */
        $loaded = require $indexPath;

        if (!is_array($loaded)) {
            throw new RuntimeException("Invalid index contents for {$indexPath}");
        }

        $offsets = array_map(
            static fn ($value): int => (int) $value,
            array_values($loaded)
        );

        if ($supportsApcu) {
            apcu_store(
                $apcuKey,
                [
                    'mtime' => (int) @filemtime($indexPath),
                    'offsets' => $offsets,
                ]
            );
        }
    }

    $localCache[$indexPath] = $offsets;

    return $offsets;
}

/**
 * @param int[] $offsets
 */
function pickRandomUserAgent(string $dataPath, array $offsets): string
{
    $count = count($offsets);

    if ($count === 0) {
        throw new RuntimeException('Sample set is empty.');
    }

    $randomIndex = random_int(0, $count - 1);
    $offset = $offsets[$randomIndex];

    $handle = fopen($dataPath, 'rb');

    if ($handle === false) {
        throw new RuntimeException("Cannot open {$dataPath} for reading.");
    }

    try {
        if (fseek($handle, $offset) !== 0) {
            if (fseek($handle, 0) !== 0) {
                throw new RuntimeException("Unable to seek within {$dataPath}");
            }
        }

        $line = fgets($handle);

        if ($line === false) {
            throw new RuntimeException("Unable to read data from {$dataPath}");
        }
    } finally {
        fclose($handle);
    }

    return rtrim($line, "\r\n");
}

function respondWithError(int $statusCode, string $message): void
{
    if (PHP_SAPI !== 'cli') {
        http_response_code($statusCode);
        header('Content-Type: text/plain; charset=utf-8');
    }

    echo $message . PHP_EOL;
    exit($statusCode);
}

function apcuIsEnabled(): bool
{
    if (!function_exists('apcu_fetch')) {
        return false;
    }

    if (PHP_SAPI === 'cli') {
        return iniFlag('apc.enable_cli');
    }

    return iniFlag('apc.enabled');
}

function iniFlag(string $option): bool
{
    $value = ini_get($option);

    if ($value === false) {
        return false;
    }

    $value = strtolower(trim((string) $value));

    return $value === '1' || $value === 'on' || $value === 'yes' || $value === 'true';
}
