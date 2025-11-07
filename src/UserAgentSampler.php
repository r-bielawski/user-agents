<?php

declare(strict_types=1);

namespace App;

final class UserAgentSampler
{
    /**
     * Calculates how many times each user agent should appear in a fixed-size sample.
     *
     * @param array<string, int> $counts
     * @return array<string, int>
     */
    public function buildSample(array $counts, int $sampleSize): array
    {
        if ($sampleSize <= 0 || $counts === []) {
            return [];
        }

        $total = array_sum($counts);

        if ($total <= 0) {
            return [];
        }

        arsort($counts, SORT_NUMERIC);

        $baseAllocation = [];
        $remainders = [];
        $assigned = 0;

        foreach ($counts as $userAgent => $count) {
            $exact = ($count / $total) * $sampleSize;
            $base = (int) floor($exact);
            $remainder = $exact - $base;

            $baseAllocation[$userAgent] = $base;
            $assigned += $base;

            $remainders[] = [
                'ua' => $userAgent,
                'remainder' => $remainder,
                'count' => $count,
            ];
        }

        $slotsRemaining = max(0, $sampleSize - $assigned);

        if ($slotsRemaining > 0) {
            usort(
                $remainders,
                static function (array $a, array $b): int {
                    $remainderComparison = $b['remainder'] <=> $a['remainder'];

                    if ($remainderComparison !== 0) {
                        return $remainderComparison;
                    }

                    $countComparison = $b['count'] <=> $a['count'];

                    if ($countComparison !== 0) {
                        return $countComparison;
                    }

                    return strcmp($a['ua'], $b['ua']);
                }
            );

            for ($i = 0; $i < $slotsRemaining && $i < count($remainders); $i++) {
                $userAgent = $remainders[$i]['ua'];
                $baseAllocation[$userAgent]++;
            }
        }

        // Remove zero allocations to avoid empty lines when writing the sample.
        return array_filter(
            $baseAllocation,
            static fn (int $occurrences): bool => $occurrences > 0
        );
    }
}

