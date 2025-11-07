<?php

declare(strict_types=1);

namespace App;

final class UserAgentAggregator
{
    public function __construct(
        private readonly BotDetector $botDetector
    ) {
    }

    /**
     * @var array<string, int>
     */
    private array $counts = [];

    public function add(string $userAgent): void
    {
        $normalized = $this->normalize($userAgent);

        if ($normalized === ''
            || stripos($normalized, 'mozilla/') !== 0
            || $this->botDetector->isBot($normalized)
            || strcasecmp($normalized, 'Google') === 0
        ) {
            return;
        }

        $this->counts[$normalized] = ($this->counts[$normalized] ?? 0) + 1;
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        return $this->counts;
    }

    private function normalize(string $userAgent): string
    {
        $trimmed = trim($userAgent);

        if ($trimmed === '') {
            return '';
        }

        // Collapse any excessive internal whitespace to a single space.
        $collapsed = preg_replace('/\s+/', ' ', $trimmed) ?? '';

        return $collapsed;
    }
}
