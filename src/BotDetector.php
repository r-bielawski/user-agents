<?php

declare(strict_types=1);

namespace App;

final class BotDetector
{
    /**
     * @var string[]
     */
    private array $knownMarkers;

    /**
     * @param string[]|null $knownMarkers
     */
    public function __construct(?array $knownMarkers = null)
    {
        $this->knownMarkers = $knownMarkers ?? $this->defaultMarkers();
    }

    public function isBot(string $userAgent): bool
    {
        $normalized = strtolower($userAgent);

        foreach ($this->knownMarkers as $marker) {
            if (str_contains($normalized, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    private function defaultMarkers(): array
    {
        return [
            ' bot',
            'bot/',
            'bot;',
            'crawler',
            'crawl',
            'spider',
            'spider-',
            'spider/',
            'curl/',
            'wget/',
            'httpclient',
            'python-requests',
            'libwww-perl',
            'java/',
            'feedfetcher',
            'mediapartners',
            'adsbot',
            'headless',
            'phantomjs',
            'electron',
            'uptimerobot',
            'pingdom',
            'monitor',
            'axios/',
            'node-fetch',
            'guzzlehttp',
            'postmanruntime',
            'insomnia/',
            'okhttp',
            'urlpreview',
            'previewbot',
            'owler',
            'ias-',
            'slurp',
            'discordbot',
            'slackbot',
            'twitterbot',
            'facebookbot',
            'facebookexternalhit',
            'skypeuripreview',
            'vkshare',
            'linkedinbot',
            'whatsapp',
            'telegrambot',
            'petalbot',
            'bytespider',
            'cloudflare-healthcheck',
            'yandexbot',
            'bingbot',
            'bingpreview',
            'duckduckbot',
            'baiduspider',
            'applebot',
            'semrush',
            'ahrefs',
            'mj12bot',
            'dotbot',
            'dataminr',
            'nimbusscreencapture',
            'validator',
            'reindeer',
        ];
    }
}
