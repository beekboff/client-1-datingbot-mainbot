<?php

declare(strict_types=1);

namespace App\Infrastructure\I18n;

final class Localizer
{
    public function __construct(
        private readonly string $path,
        private readonly string $default,
        private readonly array $supported,
    ) {
    }

    public function isSupported(string $lang): bool
    {
        return in_array($lang, $this->supported, true);
    }

    public function normalize(string $lang): string
    {
        $lang = strtolower(substr($lang, 0, 2));
        return $this->isSupported($lang) ? $lang : $this->default;
    }

    public function t(string $key, ?string $lang = null): string
    {
        $lang = $lang ? $this->normalize($lang) : $this->default;
        $messages = $this->load($lang);
        if ($this->hasKey($messages, $key)) {
            return $this->getByKey($messages, $key);
        }
        if ($lang !== $this->default) {
            $fallback = $this->load($this->default);
            if ($this->hasKey($fallback, $key)) {
                return $this->getByKey($fallback, $key);
            }
        }
        return $key;
    }

    private function load(string $lang): array
    {
        $file = rtrim($this->path, '/')."/{$lang}.php";
        if (is_file($file)) {
            /** @psalm-suppress UnresolvableInclude */
            $data = require $file;
            return is_array($data) ? $data : [];
        }
        return [];
    }

    private function hasKey(array $arr, string $dotted): bool
    {
        $ref = $arr;
        foreach (explode('.', $dotted) as $part) {
            if (!is_array($ref) || !array_key_exists($part, $ref)) {
                return false;
            }
            $ref = $ref[$part];
        }
        return is_string($ref);
    }

    private function getByKey(array $arr, string $dotted): string
    {
        $ref = $arr;
        foreach (explode('.', $dotted) as $part) {
            $ref = $ref[$part] ?? null;
        }
        return is_string($ref) ? $ref : $dotted;
    }
}
