<?php

declare(strict_types=1);

namespace App\Shared;

final readonly class AppOptions
{
    public function __construct(
        public string $profileCreateUrl,
        public string $publicBaseUrl,
    ) {
    }
}
