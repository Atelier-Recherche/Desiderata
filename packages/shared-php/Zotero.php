<?php

declare(strict_types=1);

final class Desiderata_Zotero
{
    /**
     * Import Zotero JSON (native export) or CSL-JSON array/object.
     *
     * @param mixed $payload
     * @return list<array{title:string,url:string,bookmark_guid:string,meta:array<string,mixed>}>
     */
    public static function import_items($payload): array
    {
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            $payload = $decoded;
        }
        if (!is_array($payload)) {
            return [];
        }

        // Single object → wrap
        $isList = array_keys($payload) === range(0, count($payload) - 1);
        $rows = $isList ? $payload : [$payload];

        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $mapped = self::map_row($row);
            if ($mapped !== null) {
                $items[] = $mapped;
            }
        }
        return $items;
    }

    /**
     * @param list<array<string,mixed>> $items Public or internal item rows with title/url/meta
     * @return list<array<string,mixed>>
     */
    public static function export_csl(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $meta = Desiderata_Meta::decode($item['meta'] ?? null) ?? [];
            $type = isset($meta['item_type']) ? self::to_csl_type((string) $meta['item_type']) : 'document';
            $entry = [
                'id' => (string) ($meta['zotero_key'] ?? ('item-' . ($item['id'] ?? uniqid('d', true)))),
                'type' => $type,
                'title' => (string) ($item['title'] ?? ''),
                'URL' => (string) ($item['url'] ?? ''),
            ];
            if (!empty($meta['isbn'])) {
                $entry['ISBN'] = (string) $meta['isbn'];
            }
            if (!empty($meta['doi'])) {
                $entry['DOI'] = (string) $meta['doi'];
            }
            if (!empty($meta['publisher'])) {
                $entry['publisher'] = (string) $meta['publisher'];
            }
            if (!empty($meta['published_date'])) {
                $entry['issued'] = ['raw' => (string) $meta['published_date']];
            }
            if (!empty($meta['authors']) && is_array($meta['authors'])) {
                $entry['author'] = [];
                foreach ($meta['authors'] as $name) {
                    if (!is_string($name) || $name === '') {
                        continue;
                    }
                    $entry['author'][] = ['literal' => $name];
                }
            }
            if (!empty($meta['tags']) && is_array($meta['tags'])) {
                $entry['keyword'] = implode(', ', $meta['tags']);
            }
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{title:string,url:string,bookmark_guid:string,meta:array<string,mixed>}|null
     */
    private static function map_row(array $row): ?array
    {
        // Zotero native library item
        if (isset($row['data']) && is_array($row['data'])) {
            $row = array_merge($row, $row['data']);
        }

        $title = '';
        if (isset($row['title']) && is_string($row['title'])) {
            $title = trim($row['title']);
        } elseif (isset($row['shortTitle']) && is_string($row['shortTitle'])) {
            $title = trim($row['shortTitle']);
        }

        $url = '';
        if (isset($row['url']) && is_string($row['url'])) {
            $url = trim($row['url']);
        } elseif (isset($row['URL']) && is_string($row['URL'])) {
            $url = trim($row['URL']);
        }

        $key = '';
        if (isset($row['key']) && is_string($row['key'])) {
            $key = $row['key'];
        } elseif (isset($row['id']) && is_scalar($row['id'])) {
            $key = (string) $row['id'];
        }

        if ($title === '' && $url === '') {
            return null;
        }
        if ($url === '') {
            // Placeholder URL so sync validation accepts http(s) — use Open Library or about page
            if (!empty($row['ISBN']) || !empty($row['isbn'])) {
                $isbn = Desiderata_Meta::normalize_isbn((string) ($row['ISBN'] ?? $row['isbn']));
                $url = $isbn ? ('https://openlibrary.org/isbn/' . $isbn) : 'https://www.zotero.org/';
            } else {
                $url = 'https://www.zotero.org/';
            }
        }
        if (!preg_match('#^https?://#i', $url)) {
            return null;
        }
        if ($title === '') {
            $title = $url;
        }

        $authors = [];
        if (!empty($row['creators']) && is_array($row['creators'])) {
            foreach ($row['creators'] as $c) {
                if (!is_array($c)) {
                    continue;
                }
                if (!empty($c['name'])) {
                    $authors[] = (string) $c['name'];
                } else {
                    $parts = trim(((string) ($c['firstName'] ?? '')) . ' ' . ((string) ($c['lastName'] ?? '')));
                    if ($parts !== '') {
                        $authors[] = $parts;
                    }
                }
            }
        } elseif (!empty($row['author']) && is_array($row['author'])) {
            foreach ($row['author'] as $c) {
                if (is_array($c) && !empty($c['literal'])) {
                    $authors[] = (string) $c['literal'];
                } elseif (is_array($c)) {
                    $parts = trim(((string) ($c['given'] ?? '')) . ' ' . ((string) ($c['family'] ?? '')));
                    if ($parts !== '') {
                        $authors[] = $parts;
                    }
                }
            }
        }

        $isbn = null;
        foreach (['ISBN', 'isbn'] as $k) {
            if (!empty($row[$k])) {
                $isbn = Desiderata_Meta::normalize_isbn((string) $row[$k]);
                if ($isbn) {
                    break;
                }
            }
        }

        $tags = [];
        if (!empty($row['tags']) && is_array($row['tags'])) {
            foreach ($row['tags'] as $t) {
                if (is_string($t)) {
                    $tags[] = $t;
                } elseif (is_array($t) && isset($t['tag'])) {
                    $tags[] = (string) $t['tag'];
                }
            }
        }

        $meta = Desiderata_Meta::normalize([
            'isbn' => $isbn,
            'authors' => $authors,
            'publisher' => (string) ($row['publisher'] ?? ''),
            'published_date' => (string) ($row['date'] ?? ($row['issued']['raw'] ?? '')),
            'doi' => (string) ($row['DOI'] ?? $row['doi'] ?? ''),
            'zotero_key' => $key,
            'item_type' => (string) ($row['itemType'] ?? $row['type'] ?? 'document'),
            'tags' => $tags,
            'cover_url' => $isbn ? Desiderata_OpenLibrary::cover_url($isbn) : '',
        ]);

        $guid = $key !== '' ? ('zotero:' . $key) : ('zotero:' . md5($url . '|' . $title));

        return [
            'title' => mb_substr($title, 0, 512),
            'url' => $url,
            'bookmark_guid' => $guid,
            'meta' => $meta,
        ];
    }

    private static function to_csl_type(string $type): string
    {
        $map = [
            'book' => 'book',
            'journalArticle' => 'article-journal',
            'newspaperArticle' => 'article-newspaper',
            'magazineArticle' => 'article-magazine',
            'thesis' => 'thesis',
            'webpage' => 'webpage',
            'blogPost' => 'post-weblog',
            'videoRecording' => 'motion_picture',
            'podcast' => 'song',
            'document' => 'document',
        ];
        return $map[$type] ?? 'document';
    }
}
