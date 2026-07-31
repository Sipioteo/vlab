<?php

declare(strict_types=1);

namespace App\Support;

final class Str
{
    /**
     * Kebab-case, accent-folded slug generation.
     */
    public static function slug(string $value): string
    {
        $value = trim($value);
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($converted !== false) {
                $value = $converted;
            }
        }
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        $value = trim($value, '-');
        return $value !== '' ? $value : 'n-a';
    }

    /**
     * Make a slug unique against an existence check, appending -2, -3, ...
     *
     * @param callable(string):bool $exists
     */
    public static function uniqueSlug(string $base, callable $exists): string
    {
        $slug = $base;
        $i = 2;
        while ($exists($slug)) {
            $slug = $base . '-' . $i;
            $i++;
        }
        return $slug;
    }

    public static function randomHex(int $chars): string
    {
        return bin2hex(random_bytes(intdiv($chars, 2)));
    }

    public static function uuid4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
