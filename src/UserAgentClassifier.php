<?php

declare(strict_types=1);

namespace App;

final class UserAgentClassifier
{
    /**
     * @var string[]
     */
    private const MOBILE_KEYWORDS = [
        'android',
        'iphone',
        'ipad',
        'ipod',
        'windows phone',
        'mobile',
        'mobi',
        'blackberry',
        'opera mini',
        'opera mobi',
        'kindle',
        'silk/',
        'fennec',
        'iemobile',
        'puffin',
        'samsung',
        'huawei',
        'honor',
        'xiaomi',
        'redmi',
        'oneplus',
        'nokia',
    ];

    public function classify(string $userAgent): string
    {
        $normalized = strtolower($userAgent);

        foreach (self::MOBILE_KEYWORDS as $keyword) {
            if (str_contains($normalized, $keyword)) {
                return 'mobile';
            }
        }

        return 'desktop';
    }
}

