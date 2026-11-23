<?php

declare(strict_types=1);

/**
 * Shared meta helpers for Desiderata (WordPress plugin + standalone).
 */
final class Desiderata_Meta
{
    /**
     * @param mixed $meta
     * @return array<string, mixed>
     */
    public static function normalize($meta): array
    {
        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);
            $meta = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($meta)) {
            $meta = [];
        }

        $out = [];
        if (isset($meta['isbn'])) {
            $isbn = self::normalize_isbn((string) $meta['isbn']);
            if ($isbn !== null) {
                $out['isbn'] = $isbn;
            }
        }
        if (isset($meta['authors'])) {
            if (is_array($meta['authors'])) {
                $authors = array_values(array_filter(array_map(
                    static fn($a) => is_string($a) ? trim($a) : '',
                    $meta['authors']
                )));
            } elseif (is_string($meta['authors'])) {
                $authors = array_values(array_filter(array_map('trim', explode(',', $meta['authors']))));
            } else {
                $authors = [];
            }
            if ($authors) {
                $out['authors'] = array_slice($authors, 0, 20);
            }
        }
        foreach (['publisher', 'published_date', 'cover_url', 'description', 'zotero_key', 'item_type', 'doi'] as $key) {
            if (!isset($meta[$key]) || !is_scalar($meta[$key])) {
                continue;
            }
            $val = trim((string) $meta[$key]);
            if ($val === '') {
                continue;
            }
            if ($key === 'cover_url' || $key === 'doi') {
                // keep as-is after trim; URL validated elsewhere if needed
            }
            if ($key === 'description') {
                $val = mb_substr($val, 0, 2000);
            } else {
                $val = mb_substr($val, 0, 512);
            }
            $out[$key] = $val;
        }
        if (isset($meta['tags']) && is_array($meta['tags'])) {
            $tags = [];
            foreach ($meta['tags'] as $tag) {
                if (is_string($tag) && trim($tag) !== '') {
                    $tags[] = mb_substr(trim($tag), 0, 64);
                }
            }
            if ($tags) {
                $out['tags'] = array_slice(array_values(array_unique($tags)), 0, 30);
            }
        }
        return $out;
    }

    public static function normalize_isbn(string $raw): ?string
    {
        $clean = strtoupper(preg_replace('/[\s-]+/', '', $raw) ?? '');
        if ($clean === '') {
            return null;
        }
        if (preg_match('/^\d{9}[\dX]$/', $clean)) {
            return self::isbn10_to_13($clean);
        }
        if (preg_match('/^\d{13}$/', $clean)) {
            return $clean;
        }
        return null;
    }

    public static function isbn10_to_13(string $isbn10): string
    {
        $core = '978' . substr($isbn10, 0, 9);
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $digit = (int) $core[$i];
            $sum += ($i % 2 === 0) ? $digit : $digit * 3;
        }
        $check = (10 - ($sum % 10)) % 10;
        return $core . (string) $check;
    }

    /**
     * @param array<string, mixed> $meta
     */
    public static function encode(array $meta): ?string
    {
        $normalized = self::normalize($meta);
        if (!$normalized) {
            return null;
        }
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : null;
    }

    /**
     * @param mixed $raw
     * @return array<string, mixed>|null
     */
    public static function decode($raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (is_array($raw)) {
            $n = self::normalize($raw);
            return $n ?: null;
        }
        if (!is_string($raw)) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        $n = self::normalize($decoded);
        return $n ?: null;
    }
}
