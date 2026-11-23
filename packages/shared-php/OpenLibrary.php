<?php

declare(strict_types=1);

final class Desiderata_OpenLibrary
{
    private const API = 'https://openlibrary.org';

    /**
     * @return array{title:string,meta:array<string,mixed>}|null
     */
    public static function lookup_isbn(string $isbn): ?array
    {
        $isbn = Desiderata_Meta::normalize_isbn($isbn);
        if ($isbn === null) {
            return null;
        }

        $url = self::API . '/isbn/' . rawurlencode($isbn) . '.json';
        $book = self::http_get_json($url);
        if (!$book) {
            $search = self::http_get_json(self::API . '/search.json?isbn=' . rawurlencode($isbn) . '&limit=1');
            if (!$search || empty($search['docs'][0])) {
                return null;
            }
            $doc = $search['docs'][0];
            $title = isset($doc['title']) ? (string) $doc['title'] : '';
            return [
                'title' => $title,
                'meta' => Desiderata_Meta::normalize([
                    'isbn' => $isbn,
                    'authors' => $doc['author_name'] ?? [],
                    'publisher' => isset($doc['publisher'][0]) ? (string) $doc['publisher'][0] : '',
                    'published_date' => isset($doc['first_publish_year']) ? (string) $doc['first_publish_year'] : '',
                    'cover_url' => isset($doc['cover_i'])
                        ? 'https://covers.openlibrary.org/b/id/' . $doc['cover_i'] . '-M.jpg'
                        : self::cover_url($isbn),
                    'description' => '',
                    'item_type' => 'book',
                ]),
            ];
        }

        $authors = [];
        if (!empty($book['authors']) && is_array($book['authors'])) {
            foreach ($book['authors'] as $ref) {
                if (!is_array($ref) || empty($ref['key'])) {
                    continue;
                }
                $author = self::http_get_json(self::API . $ref['key'] . '.json');
                if ($author && !empty($author['name'])) {
                    $authors[] = (string) $author['name'];
                }
            }
        }

        $description = '';
        if (isset($book['description'])) {
            if (is_string($book['description'])) {
                $description = $book['description'];
            } elseif (is_array($book['description']) && isset($book['description']['value'])) {
                $description = (string) $book['description']['value'];
            }
        }

        $title = isset($book['title']) ? (string) $book['title'] : '';
        $publishers = [];
        if (!empty($book['publishers']) && is_array($book['publishers'])) {
            foreach ($book['publishers'] as $p) {
                if (is_string($p)) {
                    $publishers[] = $p;
                }
            }
        }

        return [
            'title' => $title,
            'meta' => Desiderata_Meta::normalize([
                'isbn' => $isbn,
                'authors' => $authors,
                'publisher' => $publishers[0] ?? '',
                'published_date' => isset($book['publish_date']) ? (string) $book['publish_date'] : '',
                'cover_url' => self::cover_url($isbn),
                'description' => $description,
                'item_type' => 'book',
            ]),
        ];
    }

    public static function cover_url(string $isbn): string
    {
        return 'https://covers.openlibrary.org/b/isbn/' . rawurlencode($isbn) . '-M.jpg';
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function http_get_json(string $url): ?array
    {
        if (function_exists('wp_remote_get')) {
            $response = wp_remote_get($url, [
                'timeout' => 8,
                'headers' => ['Accept' => 'application/json'],
            ]);
            if (is_wp_error($response)) {
                return null;
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code < 200 || $code >= 300) {
                return null;
            }
            $body = wp_remote_retrieve_body($response);
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 8,
                    'header' => "Accept: application/json\r\nUser-Agent: Desiderata/0.2\r\n",
                ],
            ]);
            $body = @file_get_contents($url, false, $ctx);
            if ($body === false) {
                return null;
            }
        }

        $data = json_decode((string) $body, true);
        return is_array($data) ? $data : null;
    }
}
