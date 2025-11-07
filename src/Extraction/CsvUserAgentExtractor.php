<?php

declare(strict_types=1);

namespace App\Extraction;

use Generator;
use RuntimeException;

final class CsvUserAgentExtractor implements UserAgentExtractor
{
    /**
     * @return Generator<int, string>
     */
    public function extract(string $filePath): Generator
    {
        $handle = fopen($filePath, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Cannot read {$filePath}");
        }

        try {
            $header = $this->readCsvRow($handle);

            if ($header === null) {
                return;
            }

            $userAgentIndex = $this->resolveUserAgentIndex($header);

            if ($userAgentIndex === null) {
                // No matching header; fall back to last column with UA-like data.
                $userAgentIndex = $this->fallbackIndex($header);
            }

            while (($row = $this->readCsvRow($handle)) !== null) {
                if (!array_key_exists($userAgentIndex, $row)) {
                    continue;
                }

                $value = trim((string) $row[$userAgentIndex]);

                if ($value === '') {
                    continue;
                }

                if ($this->looksLikeUserAgent($value)) {
                    yield $value;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return string[]|null
     */
    private function readCsvRow($handle): ?array
    {
        $row = fgetcsv($handle, 0, ',', '"', '\\');

        if ($row === false) {
            return null;
        }

        if (isset($row[0])) {
            $row[0] = $this->stripBom($row[0]);
        }

        return $row;
    }

    /**
     * @param string[] $header
     */
    private function resolveUserAgentIndex(array $header): ?int
    {
        $targets = ['user_agent', 'useragent', 'ua', 'user-agent'];

        foreach ($header as $index => $column) {
            $normalized = strtolower(trim($column));

            if ($normalized === '') {
                continue;
            }

            if (in_array($normalized, $targets, true)) {
                return (int) $index;
            }
        }

        return null;
    }

    /**
     * @param string[] $header
     */
    private function fallbackIndex(array $header): int
    {
        $lastIndex = max(array_keys($header));
        $bestIndex = $lastIndex;
        $bestScore = -1;

        foreach ($header as $index => $column) {
            $score = 0;
            $lower = strtolower(trim($column));

            if (str_contains($lower, 'agent')) {
                $score += 2;
            }

            if (str_contains($lower, 'ua')) {
                $score += 1;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIndex = (int) $index;
            }
        }

        return $bestIndex;
    }

    private function stripBom(string $value): string
    {
        if (str_starts_with($value, "\xEF\xBB\xBF")) {
            return substr($value, 3);
        }

        return $value;
    }

    private function looksLikeUserAgent(string $value): bool
    {
        if (strlen($value) < 12) {
            return false;
        }

        $lower = strtolower($value);
        $keywords = [
            'mozilla/',
            'chrome/',
            'safari/',
            'opera/',
            'edge/',
            'android',
            'iphone',
            'bot',
            'spider',
            'curl/',
            'wget/',
            'httpclient',
        ];

        foreach ($keywords as $keyword) {
            if (str_contains($lower, $keyword)) {
                return true;
            }
        }

        return str_contains($value, '(') && str_contains($value, ')');
    }
}
