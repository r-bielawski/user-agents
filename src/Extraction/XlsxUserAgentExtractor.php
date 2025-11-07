<?php

declare(strict_types=1);

namespace App\Extraction;

use Generator;
use RuntimeException;
use XMLReader;

/**
 * Streams user agents out of an XLSX worksheet without loading the entire file into memory.
 */
final class XlsxUserAgentExtractor implements UserAgentExtractor
{
    private const SAMPLE_ROW_LIMIT = 400;

    /**
     * @var array<int, string>
     */
    private array $sharedStrings = [];

    /**
     * @throws RuntimeException when the XLSX workbook cannot be parsed.
     *
     * @return Generator<int, string>
     */
    public function extract(string $filePath): Generator
    {
        $this->sharedStrings = $this->loadSharedStrings($filePath);
        $sheetPath = $this->resolveFirstSheetPath($filePath);
        $userAgentColumn = $this->detectUserAgentColumn($filePath, $sheetPath);

        $reader = $this->createSheetReader($filePath, $sheetPath);

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                continue;
            }

            $userAgent = null;

            $this->processRowCells(
                $reader,
                function (string $column, string $value) use (&$userAgent, $userAgentColumn): void {
                    if ($column === $userAgentColumn) {
                        $userAgent = $value;
                    }
                }
            );

            if ($userAgent !== null && $userAgent !== '') {
                yield $userAgent;
            }
        }

        $reader->close();
    }

    /**
     * @throws RuntimeException
     *
     * @return array<int, string>
     */
    private function loadSharedStrings(string $filePath): array
    {
        $sharedStrings = [];
        $reader = new XMLReader();

        if (!$reader->open("zip://{$filePath}#xl/sharedStrings.xml", null, LIBXML_NONET | LIBXML_COMPACT)) {
            return $sharedStrings;
        }

        $index = 0;

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
                $sharedStrings[$index++] = $this->readSharedStringItem($reader);
            }
        }

        $reader->close();

        return $sharedStrings;
    }

    /**
     * @throws RuntimeException
     */
    private function resolveFirstSheetPath(string $filePath): string
    {
        $sheetRelationshipId = null;
        $reader = new XMLReader();

        if ($reader->open("zip://{$filePath}#xl/workbook.xml", null, LIBXML_NONET | LIBXML_COMPACT)) {
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'sheet') {
                    $sheetRelationshipId = $reader->getAttribute('r:id');
                    break;
                }
            }
            $reader->close();
        }

        if ($sheetRelationshipId === null) {
            return 'xl/worksheets/sheet1.xml';
        }

        $relsReader = new XMLReader();

        if ($relsReader->open("zip://{$filePath}#xl/_rels/workbook.xml.rels", null, LIBXML_NONET | LIBXML_COMPACT)) {
            while ($relsReader->read()) {
                if ($relsReader->nodeType === XMLReader::ELEMENT && $relsReader->localName === 'Relationship') {
                    if ($relsReader->getAttribute('Id') === $sheetRelationshipId) {
                        $target = $relsReader->getAttribute('Target');
                        $relsReader->close();

                        if ($target === null) {
                            break;
                        }

                        // Absolute targets begin with /, relative targets should be resolved from xl/
                        if ($this->startsWith($target, '/')) {
                            return ltrim($target, '/');
                        }

                        return 'xl/' . ltrim($target, '/');
                    }
                }
            }
            $relsReader->close();
        }

        return 'xl/worksheets/sheet1.xml';
    }

    private function detectUserAgentColumn(string $filePath, string $sheetPath): string
    {
        $scores = [];
        $fallbackColumn = null;
        $rowsConsidered = 0;
        $reader = $this->createSheetReader($filePath, $sheetPath);

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                continue;
            }

            $lastColumnInRow = null;

            $this->processRowCells(
                $reader,
                function (string $column, string $value) use (&$scores, &$lastColumnInRow): void {
                    $lastColumnInRow = $column;
                    if ($this->looksLikeUserAgent($value)) {
                        $scores[$column] = ($scores[$column] ?? 0) + 1;
                    }
                }
            );

            if ($lastColumnInRow !== null) {
                $fallbackColumn = $lastColumnInRow;
            }

            $rowsConsidered++;

            if ($rowsConsidered >= self::SAMPLE_ROW_LIMIT) {
                break;
            }
        }

        $reader->close();

        if ($scores !== []) {
            arsort($scores, SORT_NUMERIC);
            return (string) array_key_first($scores);
        }

        return $fallbackColumn ?? 'G';
    }

    /**
     * @param callable(string, string):void $onCell
     */
    private function processRowCells(XMLReader $reader, callable $onCell): void
    {
        if ($reader->isEmptyElement) {
            return;
        }

        $rowDepth = $reader->depth;

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'c') {
                $cellReference = $reader->getAttribute('r') ?? '';
                $column = $this->extractColumnFromReference($cellReference);
                $value = $this->extractCellValue($reader);

                if ($column !== '' && $value !== null) {
                    $onCell($column, $value);
                }
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT
                && $reader->depth === $rowDepth
                && $reader->localName === 'row'
            ) {
                break;
            }
        }
    }

    private function extractCellValue(XMLReader $reader): ?string
    {
        $type = $reader->getAttribute('t') ?? '';

        if ($reader->isEmptyElement) {
            return null;
        }

        $cellDepth = $reader->depth;
        $rawValue = null;

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT) {
                if ($reader->localName === 'v') {
                    $rawValue = $this->readSimpleTextNode($reader);
                } elseif ($reader->localName === 'is') {
                    $rawValue = $this->readInlineString($reader);
                }
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT
                && $reader->depth === $cellDepth
                && $reader->localName === 'c'
            ) {
                break;
            }
        }

        if ($rawValue === null) {
            return null;
        }

        $rawValue = trim($rawValue);

        return match ($type) {
            's' => $this->sharedStrings[(int) $rawValue] ?? null,
            default => $rawValue,
        };
    }

    private function readInlineString(XMLReader $reader): string
    {
        if ($reader->isEmptyElement) {
            return '';
        }

        $inlineDepth = $reader->depth;
        $buffer = '';

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 't') {
                $buffer .= $this->readSimpleTextNode($reader);
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT
                && $reader->depth === $inlineDepth
                && $reader->localName === 'is'
            ) {
                break;
            }
        }

        return $buffer;
    }

    private function readSharedStringItem(XMLReader $reader): string
    {
        if ($reader->isEmptyElement) {
                return '';
        }

        $itemDepth = $reader->depth;
        $buffer = '';

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 't') {
                $buffer .= $this->readSimpleTextNode($reader);
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT
                && $reader->depth === $itemDepth
                && $reader->localName === 'si'
            ) {
                break;
            }
        }

        return $buffer;
    }

    private function readSimpleTextNode(XMLReader $reader): string
    {
        if ($reader->isEmptyElement) {
            return '';
        }

        $textDepth = $reader->depth;
        $buffer = '';

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::TEXT || $reader->nodeType === XMLReader::CDATA) {
                $buffer .= $reader->value;
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT
                && $reader->depth === $textDepth
                && $reader->localName === 'v'
            ) {
                break;
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT
                && $reader->depth === $textDepth
                && $reader->localName === 't'
            ) {
                break;
            }
        }

        return $buffer;
    }

    private function extractColumnFromReference(string $reference): string
    {
        if ($reference === '') {
            return '';
        }

        return preg_replace('/\d+/', '', $reference) ?? '';
    }

    private function looksLikeUserAgent(string $value): bool
    {
        $value = trim($value);

        if ($value === '' || strlen($value) < 12) {
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
            'crawler',
            'curl/',
            'wget/',
            'httpclient',
            'mediapartners',
            'postmanruntime',
            'cfnetwork',
        ];

        foreach ($keywords as $keyword) {
            if (str_contains($lower, $keyword)) {
                return true;
            }
        }

        // User agents typically contain parentheses and whitespace.
        if (str_contains($value, '(') && str_contains($value, ')') && str_contains($value, ' ')) {
            return true;
        }

        return false;
    }

    private function createSheetReader(string $filePath, string $sheetPath): XMLReader
    {
        $reader = new XMLReader();

        if (!$reader->open("zip://{$filePath}#{$sheetPath}", null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException("Cannot read worksheet {$sheetPath} in {$filePath}");
        }

        return $reader;
    }

    private function startsWith(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
