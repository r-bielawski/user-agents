#!/usr/bin/env php
<?php

declare(strict_types=1);

error_reporting(E_ALL);

if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool
    {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}

if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }

        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

spl_autoload_register(
    static function (string $class): void {
        $prefix = 'App\\';

        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';

        if (is_file($path)) {
            require $path;
        }
    }
);

use App\BotDetector;
use App\UserAgentAggregator;
use App\UserAgentSampler;
use App\UserAgentClassifier;
use App\Extraction\UserAgentExtractor;
use App\Extraction\XlsxUserAgentExtractor;
use App\Extraction\CsvUserAgentExtractor;

final class Application
{
    private const INPUT_DIR = 'in';
    private const OUTPUT_DIR = 'out';
    private const SAMPLE_SIZE = 10000;

    /**
     * @var array<string, UserAgentExtractor>
     */
    private array $extractors;

    public function __construct()
    {
        $this->extractors = [
            'xlsx' => new XlsxUserAgentExtractor(),
            'csv' => new CsvUserAgentExtractor(),
        ];
    }

    public function run(): int
    {
        $inputDirectory = $this->resolvePath(self::INPUT_DIR);

        if (!is_dir($inputDirectory)) {
            fwrite(STDERR, "Input directory '{$inputDirectory}' does not exist." . PHP_EOL);
            return 1;
        }

        $aggregator = new UserAgentAggregator(new BotDetector());
        $processedFiles = 0;

        foreach ($this->discoverFiles($inputDirectory) as $filePath => $filename) {
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            if (!isset($this->extractors[$extension])) {
                fwrite(STDERR, "Skipping unsupported file: {$filename}" . PHP_EOL);
                continue;
            }

            $extractor = $this->extractors[$extension];

            try {
                foreach ($extractor->extract($filePath) as $userAgent) {
                    $aggregator->add($userAgent);
                }
                $processedFiles++;
            } catch (Throwable $exception) {
                fwrite(
                    STDERR,
                    "Failed to process {$filename}: {$exception->getMessage()}" . PHP_EOL
                );
            }
        }

        $counts = $aggregator->counts();
        $classifier = new UserAgentClassifier();
        $partitions = [
            'mobile' => [],
            'desktop' => [],
        ];

        foreach ($counts as $userAgent => $count) {
            $category = $classifier->classify($userAgent);

            if (!isset($partitions[$category])) {
                $category = 'desktop';
            }

            $partitions[$category][$userAgent] = $count;
        }

        $this->prepareOutputDirectory();
        $this->writeMiddleReport($counts);
        $this->writeMiddleReport($partitions['mobile'], 'mobile');
        $this->writeMiddleReport($partitions['desktop'], 'desktop');
        $this->writeSampleFile($counts);
        $this->writeSampleFile($partitions['mobile'], 'mobile');
        $this->writeSampleFile($partitions['desktop'], 'desktop');

        $uniqueAgents = count($counts);
        $totalAgents = array_sum($counts);

        fwrite(
            STDOUT,
            "Processed {$processedFiles} file(s); {$uniqueAgents} unique user agents found across {$totalAgents} entries." . PHP_EOL
        );

        return 0;
    }

    /**
     * @return iterable<string, string> map of absolute path => filename
     */
    private function discoverFiles(string $directory): iterable
    {
        $entries = scandir($directory);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (str_starts_with($entry, '~$')) {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;

            if (!is_file($path)) {
                continue;
            }

            yield $path => $entry;
        }
    }

    /**
     * @param array<string, int> $counts
     */
    private function writeMiddleReport(array $counts, ?string $suffix = null): void
    {
        $filename = $suffix === null ? 'middle.csv' : "middle-{$suffix}.csv";
        $outputPath = $this->resolvePath(self::OUTPUT_DIR . '/' . $filename);
        $handle = fopen($outputPath, 'w');

        if ($handle === false) {
            fwrite(STDERR, "Unable to open {$outputPath} for writing." . PHP_EOL);
            return;
        }

        fputcsv($handle, ['user_agent', 'count'], ',', '"', '\\');

        if ($counts !== []) {
            arsort($counts, SORT_NUMERIC);

            foreach ($counts as $userAgent => $count) {
                fputcsv($handle, [$userAgent, (string) $count], ',', '"', '\\');
            }
        }

        fclose($handle);
    }

    /**
     * @param array<string, int> $counts
     */
    private function writeSampleFile(array $counts, ?string $suffix = null): void
    {
        $filename = $suffix === null ? 'ua.txt' : "ua-{$suffix}.txt";
        $outputPath = $this->resolvePath(self::OUTPUT_DIR . '/' . $filename);
        $handle = fopen($outputPath, 'w');

        if ($handle === false) {
            fwrite(STDERR, "Unable to open {$outputPath} for writing." . PHP_EOL);
            return;
        }

        $sampler = new UserAgentSampler();
        $distribution = $sampler->buildSample($counts, self::SAMPLE_SIZE);
        $linesWritten = 0;
        $offsets = [];
        $currentOffset = 0;

        if ($distribution === []) {
            fclose($handle);
            $this->removeSampleIndex($suffix);
            return;
        }

        foreach ($distribution as $userAgent => $occurrences) {
            for ($i = 0; $i < $occurrences; $i++) {
                $offsets[] = $currentOffset;
                $line = $userAgent . PHP_EOL;

                $bytesWritten = fwrite($handle, $line);

                if ($bytesWritten === false) {
                    fclose($handle);
                    $this->removeSampleIndex($suffix);
                    fwrite(STDERR, "Unable to write to {$outputPath}." . PHP_EOL);
                    return;
                }

                $currentOffset += strlen($line);
                $linesWritten++;
            }
        }

        fclose($handle);
        $this->writeSampleIndex($offsets, $suffix);

        if ($linesWritten !== self::SAMPLE_SIZE && $counts !== []) {
            $label = $suffix === null ? '' : " ({$suffix})";
            fwrite(
                STDERR,
                "Warning{$label}: expected to write " . self::SAMPLE_SIZE . " entries, wrote {$linesWritten}." . PHP_EOL
            );
        }
    }

    /**
     * @param int[] $offsets
     */
    private function writeSampleIndex(array $offsets, ?string $suffix = null): void
    {
        $filename = $suffix === null ? 'ua.idx.php' : "ua-{$suffix}.idx.php";
        $path = $this->resolvePath(self::OUTPUT_DIR . '/' . $filename);
        $content = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export(array_values($offsets), true) . ";\n";

        if (file_put_contents($path, $content) === false) {
            fwrite(STDERR, "Unable to write {$path}." . PHP_EOL);
        }
    }

    private function removeSampleIndex(?string $suffix = null): void
    {
        $filename = $suffix === null ? 'ua.idx.php' : "ua-{$suffix}.idx.php";
        $path = $this->resolvePath(self::OUTPUT_DIR . '/' . $filename);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function prepareOutputDirectory(): void
    {
        $path = $this->resolvePath(self::OUTPUT_DIR);

        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
    }

    private function resolvePath(string $relativePath): string
    {
        return __DIR__ . DIRECTORY_SEPARATOR . $relativePath;
    }
}

$app = new Application();
exit($app->run());
