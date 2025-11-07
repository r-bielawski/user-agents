<?php

declare(strict_types=1);

namespace App\Extraction;

use Generator;

interface UserAgentExtractor
{
    /**
     * @return Generator<int, string>
     */
    public function extract(string $filePath): Generator;
}
