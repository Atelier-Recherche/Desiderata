<?php

declare(strict_types=1);

namespace Desiderata\Standalone;

use Desiderata_Meta;
use Desiderata_OpenLibrary;
use Desiderata_Zotero;
use PDO;

final class Router
{
    private array $config;
    private PDO $pdo;
    private string $prefix;
    private string $salt;

    public function __construct(array $config, PDO $pdo)
    {
        $this->config = $config;
        $this->pdo = $pdo;
        $this->prefix = (string) ($config['db']['prefix'] ?? 'desiderata_');
        $this->salt = hash('sha256', ($config['public_base'] ?? 'desiderata') . '|standalone');
    }

    public function dispatch(string $method, string $path): void
    {
        $this->cors();
        if (strtoupper($method) === 'OPTIONS') {
            http_response_code(204);
            exit;
        }

        $path = trim($path, '/');
        $parts = $path === '' ? [] : explode('/', $path);
        $json = $this->readJson();

        try {
            if ($method === 'GET' && ($parts[0] ?? '') === 'auth' && ($parts[1] ?? '') === 'status') {
                $this->authStatus();
                return;
            }
            if ($method === 'POST' && ($parts[0] ?? '') === 'auth' && ($parts[1] ?? '') === 'register') {
                $this->registerAccount($json);
                return;
            }
            if ($method === 'POST' && ($parts[0] ?? '') === 'auth' && (($parts[1] ?? '') === 'token' || ($parts[1] ?? '') === 'login')) {
                $this->issueToken($json);
                return;
            }
            if ($method === 'GET' && ($parts[0] ?? '') === 'me' && ($parts[1] ?? '') === 'wishlists') {
                $this->listMyWishlists();
                return;
            }
            if ($method === 'POST' && ($parts[0] ?? '') === 'wishlists' && count($parts) === 1) {
                $this->createWishlist($json);
                return;
            }
            if ($method === 'PUT' && ($parts[0] ?? '') === 'wishlists' && isset($parts[1]) && ($parts[2] ?? '') === 'sync') {
                $this->syncWishlist((int) $parts[1], $json);
                return;
            }
            if ($method === 'GET' && ($parts[0] ?? '') === 'wishlists' && isset($parts[1]) && count($parts) === 2) {
                $this->getPublicWishlist($parts[1]);
                return;
            }
            if (($parts[0] ?? '') === 'wishlists' && isset($parts[1], $parts[2], $parts[3]) && $parts[2] === 'items' && ($parts[4] ?? '') === 'claim') {
                if ($method === 'POST') {
                    $this->claimItem($parts[1], (int) $parts[3], $json);
                    return;
                }
                if ($method === 'DELETE') {
                    $this->releaseItem($parts[1], (int) $parts[3], $json);
                    return;
                }
            }
            if ($method === 'PATCH' && ($parts[0] ?? '') === 'wishlists' && isset($parts[1], $parts[2], $parts[3]) && $parts[2] === 'items') {
                $this->patchItem((int) $parts[1], (int) $parts[3], $json);
                return;
            }
            if ($method === 'POST' && ($parts[0] ?? '') === 'wishlists' && isset($parts[1], $parts[2], $parts[3], $parts[4]) && $parts[2] === 'items' && $parts[4] === 'enrich') {
                $this->enrichItem((int) $parts[1], (int) $parts[3], $json);
                return;
            }
            if ($method === 'POST' && ($parts[0] ?? '') === 'wishlists' && isset($parts[1], $parts[2], $parts[3]) && $parts[2] === 'import' && $parts[3] === 'zotero') {
                $this->importZotero((int) $parts[1], $json);
                return;
            }
            if ($method === 'GET' && ($parts[0] ?? '') === 'wishlists' && isset($parts[1], $parts[2], $parts[3]) && $parts[2] === 'export' && $parts[3] === 'zotero') {
                $this->exportZotero($parts[1]);
                return;
            }
            if ($method === 'GET' && ($parts[0] ?? '') === 'meta' && ($parts[1] ?? '') === 'isbn' && isset($parts[2])) {
                $this->lookupIsbn($parts[2]);
                return;
            }
            if (($parts[0] ?? '') === 'wishlists' && isset($parts[1], $parts[2]) && $parts[2] === 'chat') {
                $action = $parts[3] ?? '';
                if ($method === 'POST' && $action === 'join') {
                    $this->chatJoin($parts[1], $json);
                    return;
                }
                if ($action === 'messages') {
                    if ($method === 'GET') {
                        $this->chatList($parts[1]);
                        return;
                    }
                    if ($method === 'POST') {
                        $this->chatPost($parts[1], $json);
                        return;
                    }
                }
                if ($method === 'POST' && $action === 'close') {
                    $this->chatClose($parts[1], $json);
                    return;
                }
            }
            if (($parts[0] ?? '') === 'wishlists' && isset($parts[1], $parts[2], $parts[3]) && $parts[2] === 'rtc' && $parts[3] === 'signals') {
                if ($method === 'GET') {
                    $this->rtcList($parts[1]);
                    return;
                }
                if ($method === 'POST') {
                    $this->rtcPost($parts[1], $json);
                    return;
                }
            }

            $this->json(['code' => 'not_found', 'message' => 'Route inconnue.'], 404);
        } catch (\Throwable $e) {
            $this->json(['code' => 'server_error', 'message' => $e->getMessage()], 500);
        }
    }

    private function cors(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowed = $this->config['cors_origins'] ?? [];
        $ok = false;
        if ($origin && is_array($allowed) && in_array($origin, $allowed, true)) {
            $ok = true;
        }
        if ($origin && preg_match('#^(chrome|moz)-extension://#', $origin)) {
            $ok = true;
        }
        if ($ok) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
            header('Access-Control-Allow-Headers: Authorization, Content-Type');
            header('Access-Control-Allow-Credentials: true');
        }
        header('Content-Type: application/json; charset=utf-8');
    }

    /** @return array<string,mixed> */
    private function readJson(): array
    {
        $raw = file_get_contents('php://input');
        if (!$raw) {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    private function json($data, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function requireUser(): array
    {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null);
        $user = Auth::userFromBearer($this->pdo, $this->prefix, is_string($auth) ? $auth : null);
        if (!$user) {
            $this->json(['code' => 'unauthorized', 'message' => 'Non autorisé.'], 401);
        }
        return $user;
    }

    private function authStatus(): void
    {
        $open = !array_key_exists('registration_open', $this->config)
            || (bool) $this->config['registration_open'];
        $this->json([
            'registration_open' => $open,
            'version' => trim((string) @file_get_contents(dirname(__DIR__) . '/VERSION')) ?: '0.2.0',
        ]);
    }

    private function registerAccount(array $json): void
    {
        $open = !array_key_exists('registration_open', $this->config)
            || (bool) $this->config['registration_open'];
        if (!$open) {
            $this->json(['code' => 'registration_closed', 'message' => 'Création de compte désactivée.'], 403);
        }
        if (!RateLimit::check($this->pdo, $this->prefix, 'auth_register', RateLimit::ip(), 8, 3600)) {
            $this->json(['code' => 'rate_limited', 'message' => 'Trop de tentatives.'], 429);
        }
        $email = trim((string) ($json['email'] ?? ''));
        $password = (string) ($json['password'] ?? '');
        $display = trim((string) ($json['display_name'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->json(['code' => 'invalid', 'message' => 'E-mail invalide.'], 400);
        }
        if (strlen($password) < 8) {
            $this->json(['code' => 'invalid', 'message' => 'Mot de passe trop court (8 caractères min.).'], 400);
        }
        $exists = $this->pdo->prepare("SELECT id FROM {$this->prefix}users WHERE email = ? LIMIT 1");
        $exists->execute([$email]);
        if ($exists->fetch()) {
            $this->json(['code' => 'exists', 'message' => 'Un compte existe déjà avec cet e-mail.'], 409);
        }
        $this->pdo->prepare(
            "INSERT INTO {$this->prefix}users (email, password_hash, display_name, created_at) VALUES (?,?,?,?)"
        )->execute([
            $email,
            password_hash($password, PASSWORD_DEFAULT),
            $display !== '' ? mb_substr($display, 0, 120) : $email,
            gmdate('Y-m-d H:i:s'),
        ]);
        $userId = (int) $this->pdo->lastInsertId();
        $token = Auth::issueToken($this->pdo, $this->prefix, $userId);
        $this->json([
            'ok' => true,
            'user_id' => $userId,
            'email' => $email,
            'token' => $token,
            'hint' => substr($token, 0, 12) . '…',
        ], 201);
    }

    private function issueToken(array $json): void
    {
        if (!RateLimit::check($this->pdo, $this->prefix, 'auth_token', RateLimit::ip(), 20, 3600)) {
            $this->json(['code' => 'rate_limited', 'message' => 'Trop de tentatives.'], 429);
        }
        $email = (string) ($json['email'] ?? $json['login'] ?? '');
        $password = (string) ($json['password'] ?? '');
        $user = Auth::login($this->pdo, $this->prefix, $email, $password);
        if (!$user) {
            $this->json(['code' => 'unauthorized', 'message' => 'Identifiants invalides.'], 401);
        }
        $token = Auth::issueToken($this->pdo, $this->prefix, (int) $user['id']);
        $this->json([
            'ok' => true,
            'user_id' => (int) $user['id'],
            'email' => $user['email'],
            'token' => $token,
            'hint' => substr($token, 0, 12) . '…',
        ]);
    }

    private function listMyWishlists(): void
    {
        $user = $this->requireUser();
        $stmt = $this->pdo->prepare(
            "SELECT id, slug, title, updated_at FROM {$this->prefix}wishlists WHERE user_id = ? ORDER BY id ASC"
        );
        $stmt->execute([(int) $user['id']]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'slug' => $row['slug'],
                'title' => $row['title'],
                'updated_at' => $row['updated_at'],
                'public_url' => $this->publicWishlistUrl($row['slug']),
            ];
        }
        $this->json(['wishlists' => $out]);
    }

    private function createWishlist(array $json): void
    {
        $user = $this->requireUser();
        $max = (int) ($this->config['limits']['wishlists_per_user'] ?? 5);
        $count = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM {$this->prefix}wishlists WHERE user_id = " . (int) $user['id']
        )->fetchColumn();
        if ($count >= $max) {
            $this->json(['code' => 'limit', 'message' => 'Limite de listes atteinte.'], 403);
        }
        $title = trim((string) ($json['title'] ?? 'Wishlist')) ?: 'Wishlist';
        $slug = $this->uniqueSlug();
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            "INSERT INTO {$this->prefix}wishlists (user_id, slug, title, created_at, updated_at) VALUES (?,?,?,?,?)"
        );
        $stmt->execute([(int) $user['id'], $slug, $title, $now, $now]);
        $id = (int) $this->pdo->lastInsertId();
        $this->json([
            'id' => $id,
            'slug' => $slug,
            'title' => $title,
            'public_url' => $this->publicWishlistUrl($slug),
        ], 201);
    }

    private function syncWishlist(int $id, array $json): void
    {
        $user = $this->requireUser();
        $wishlist = $this->wishlistById($id);
        if (!$wishlist || (int) $wishlist['user_id'] !== (int) $user['id']) {
            $this->json(['code' => 'not_found', 'message' => 'Liste introuvable.'], 404);
        }
        $items = $json['items'] ?? null;
        if (!is_array($items)) {
            $this->json(['code' => 'invalid', 'message' => 'items doit être un tableau.'], 400);
        }
        $max = (int) ($this->config['limits']['items_per_wishlist'] ?? 500);
        if (count($items) > $max) {
            $this->json(['code' => 'limit', 'message' => 'Trop d’items.'], 400);
        }

        $title = isset($json['title']) ? trim((string) $json['title']) : (string) $wishlist['title'];
        $now = gmdate('Y-m-d H:i:s');
        $existing = $this->pdo->prepare(
            "SELECT id, bookmark_guid FROM {$this->prefix}wishlist_items WHERE wishlist_id = ?"
        );
        $existing->execute([$id]);
        $byGuid = [];
        foreach ($existing->fetchAll() as $row) {
            $byGuid[$row['bookmark_guid']] = (int) $row['id'];
        }

        $seen = [];
        $order = 0;
        $upd = $this->pdo->prepare(
            "UPDATE {$this->prefix}wishlist_items
             SET url=?, title=?, meta=?, sort_order=?, updated_at=? WHERE id=?"
        );
        $ins = $this->pdo->prepare(
            "INSERT INTO {$this->prefix}wishlist_items
             (wishlist_id, bookmark_guid, url, title, meta, sort_order, updated_at)
             VALUES (?,?,?,?,?,?,?)"
        );

        foreach ($items as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $url = $this->sanitizeUrl((string) ($raw['url'] ?? ''));
            if (!$url) {
                continue;
            }
            $guid = trim((string) ($raw['bookmark_guid'] ?? ''));
            if ($guid === '') {
                $guid = 'u' . md5($url);
            }
            $itemTitle = trim((string) ($raw['title'] ?? '')) ?: $url;
            $meta = null;
            if (isset($raw['meta']) && is_array($raw['meta'])) {
                $meta = Desiderata_Meta::encode($raw['meta']);
            } elseif (!empty($raw['isbn'])) {
                $meta = Desiderata_Meta::encode(['isbn' => (string) $raw['isbn']]);
            }
            $seen[$guid] = true;
            if (isset($byGuid[$guid])) {
                $upd->execute([$url, mb_substr($itemTitle, 0, 512), $meta, $order++, $now, $byGuid[$guid]]);
            } else {
                $ins->execute([$id, $guid, $url, mb_substr($itemTitle, 0, 512), $meta, $order++, $now]);
            }
        }

        $del = $this->pdo->prepare("DELETE FROM {$this->prefix}wishlist_items WHERE id = ?");
        foreach ($byGuid as $guid => $itemId) {
            if (!isset($seen[$guid])) {
                $del->execute([$itemId]);
            }
        }

        $this->pdo->prepare(
            "UPDATE {$this->prefix}wishlists SET title=?, updated_at=? WHERE id=?"
        )->execute([$title ?: $wishlist['title'], $now, $id]);

        $this->json([
            'ok' => true,
            'id' => $id,
            'slug' => $wishlist['slug'],
            'item_count' => count($seen),
            'updated_at' => $now,
            'public_url' => $this->publicWishlistUrl($wishlist['slug']),
        ]);
    }

    private function getPublicWishlist(string $slug): void
    {
        $wishlist = $this->wishlistBySlug($slug);
        if (!$wishlist) {
            $this->json(['code' => 'not_found', 'message' => 'Liste introuvable.'], 404);
        }
        $stmt = $this->pdo->prepare(
            "SELECT id, bookmark_guid, url, title, meta, sort_order, claimed_by, claimed_at, updated_at
             FROM {$this->prefix}wishlist_items WHERE wishlist_id = ? ORDER BY sort_order ASC, id ASC"
        );
        $stmt->execute([(int) $wishlist['id']]);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'bookmark_guid' => $row['bookmark_guid'],
                'url' => $row['url'],
                'title' => $row['title'],
                'meta' => Desiderata_Meta::decode($row['meta'] ?? null),
                'sort_order' => (int) $row['sort_order'],
                'claimed_by' => $row['claimed_by'],
                'claimed_at' => $row['claimed_at'],
                'available' => $row['claimed_by'] === null || $row['claimed_by'] === '',
            ];
        }
        $this->json([
            'id' => (int) $wishlist['id'],
            'slug' => $wishlist['slug'],
            'title' => $wishlist['title'],
            'updated_at' => $wishlist['updated_at'],
            'items' => $items,
        ]);
    }

    private function claimItem(string $slug, int $itemId, array $json): void
    {
        if (!RateLimit::check($this->pdo, $this->prefix, 'claim_' . $slug, RateLimit::ip(), 30, 3600)) {
            $this->json(['code' => 'rate_limited', 'message' => 'Trop de réservations.'], 429);
        }
        $wishlist = $this->wishlistBySlug($slug);
        if (!$wishlist) {
            $this->json(['code' => 'not_found', 'message' => 'Liste introuvable.'], 404);
        }
        $item = $this->itemById($itemId, (int) $wishlist['id']);
        if (!$item) {
            $this->json(['code' => 'not_found', 'message' => 'Item introuvable.'], 404);
        }
        if (!empty($item['claimed_by'])) {
            $this->json(['code' => 'already_claimed', 'message' => 'Déjà réservé.'], 409);
        }
        $name = trim((string) ($json['name'] ?? '')) ?: 'Anonyme';
        $secret = bin2hex(random_bytes(16));
        $hash = Auth::hashClaim($secret, $this->salt);
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            "UPDATE {$this->prefix}wishlist_items
             SET claimed_by=?, claimed_at=?, claim_hash=?, updated_at=?
             WHERE id=? AND wishlist_id=? AND (claimed_by IS NULL OR claimed_by='')"
        );
        $stmt->execute([mb_substr($name, 0, 120), $now, $hash, $now, $itemId, (int) $wishlist['id']]);
        if ($stmt->rowCount() < 1) {
            $this->json(['code' => 'already_claimed', 'message' => 'Déjà réservé.'], 409);
        }
        $this->json([
            'ok' => true,
            'item_id' => $itemId,
            'claimed_by' => $name,
            'claimed_at' => $now,
            'claim_secret' => $secret,
        ], 201);
    }

    private function releaseItem(string $slug, int $itemId, array $json): void
    {
        $wishlist = $this->wishlistBySlug($slug);
        if (!$wishlist) {
            $this->json(['code' => 'not_found', 'message' => 'Liste introuvable.'], 404);
        }
        $item = $this->itemById($itemId, (int) $wishlist['id']);
        if (!$item) {
            $this->json(['code' => 'not_found', 'message' => 'Item introuvable.'], 404);
        }
        if (empty($item['claimed_by'])) {
            $this->json(['ok' => true, 'released' => false]);
        }
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        $user = Auth::userFromBearer($this->pdo, $this->prefix, is_string($auth) ? $auth : null);
        $isOwner = $user && (int) $user['id'] === (int) $wishlist['user_id'];
        $secret = (string) ($json['claim_secret'] ?? '');
        $ok = $isOwner || (
            $secret !== ''
            && !empty($item['claim_hash'])
            && Auth::verifyClaim($secret, (string) $item['claim_hash'], $this->salt)
        );
        if (!$ok) {
            $this->json(['code' => 'forbidden', 'message' => 'Secret invalide.'], 403);
        }
        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->prepare(
            "UPDATE {$this->prefix}wishlist_items
             SET claimed_by=NULL, claimed_at=NULL, claim_hash=NULL, updated_at=? WHERE id=?"
        )->execute([$now, $itemId]);
        $this->json(['ok' => true, 'released' => true]);
    }

    private function patchItem(int $wishlistId, int $itemId, array $json): void
    {
        $user = $this->requireUser();
        $wishlist = $this->wishlistById($wishlistId);
        if (!$wishlist || (int) $wishlist['user_id'] !== (int) $user['id']) {
            $this->json(['code' => 'not_found', 'message' => 'Liste introuvable.'], 404);
        }
        $item = $this->itemById($itemId, $wishlistId);
        if (!$item) {
            $this->json(['code' => 'not_found', 'message' => 'Item introuvable.'], 404);
        }
        $current = Desiderata_Meta::decode($item['meta'] ?? null) ?? [];
        if (isset($json['meta']) && is_array($json['meta'])) {
            $current = array_merge($current, $json['meta']);
        }
        if (isset($json['isbn'])) {
            $current['isbn'] = (string) $json['isbn'];
        }
        $title = isset($json['title']) ? trim((string) $json['title']) : (string) $item['title'];
        $url = (string) $item['url'];
        if (isset($json['url'])) {
            $candidate = $this->sanitizeUrl((string) $json['url']);
            if ($candidate) {
                $url = $candidate;
            }
        }
        $encoded = Desiderata_Meta::encode($current);
        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->prepare(
            "UPDATE {$this->prefix}wishlist_items SET title=?, url=?, meta=?, updated_at=? WHERE id=?"
        )->execute([mb_substr($title, 0, 512), $url, $encoded, $now, $itemId]);
        $this->json([
            'id' => $itemId,
            'title' => $title,
            'url' => $url,
            'meta' => Desiderata_Meta::decode($encoded),
            'updated_at' => $now,
        ]);
    }

    private function enrichItem(int $wishlistId, int $itemId, array $json): void
    {
        $user = $this->requireUser();
        if (!RateLimit::check($this->pdo, $this->prefix, 'enrich', RateLimit::ip(), 40, 3600)) {
            $this->json(['code' => 'rate_limited', 'message' => 'Trop de requêtes.'], 429);
        }
        $wishlist = $this->wishlistById($wishlistId);
        if (!$wishlist || (int) $wishlist['user_id'] !== (int) $user['id']) {
            $this->json(['code' => 'not_found', 'message' => 'Liste introuvable.'], 404);
        }
        $item = $this->itemById($itemId, $wishlistId);
        if (!$item) {
            $this->json(['code' => 'not_found', 'message' => 'Item introuvable.'], 404);
        }
        $current = Desiderata_Meta::decode($item['meta'] ?? null) ?? [];
        $isbn = Desiderata_Meta::normalize_isbn((string) ($json['isbn'] ?? ($current['isbn'] ?? '')));
        if (!$isbn) {
            $this->json(['code' => 'invalid', 'message' => 'ISBN requis.'], 400);
        }
        $found = Desiderata_OpenLibrary::lookup_isbn($isbn);
        if (!$found) {
            $this->json(['code' => 'not_found', 'message' => 'Aucun résultat Open Library.'], 404);
        }
        $merged = array_merge($current, $found['meta']);
        $title = $found['title'] !== '' ? $found['title'] : (string) $item['title'];
        $encoded = Desiderata_Meta::encode($merged);
        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->prepare(
            "UPDATE {$this->prefix}wishlist_items SET title=?, meta=?, updated_at=? WHERE id=?"
        )->execute([mb_substr($title, 0, 512), $encoded, $now, $itemId]);
        $this->json(['id' => $itemId, 'title' => $title, 'meta' => Desiderata_Meta::decode($encoded)]);
    }

    private function importZotero(int $wishlistId, array $json): void
    {
        $user = $this->requireUser();
        $wishlist = $this->wishlistById($wishlistId);
        if (!$wishlist || (int) $wishlist['user_id'] !== (int) $user['id']) {
            $this->json(['code' => 'not_found', 'message' => 'Liste introuvable.'], 404);
        }
        $payload = $json['items'] ?? $json;
        $mapped = Desiderata_Zotero::import_items($payload);
        if (!$mapped) {
            $this->json(['code' => 'invalid', 'message' => 'Aucune référence Zotero valide.'], 400);
        }
        $mode = (string) ($json['mode'] ?? 'merge');
        $now = gmdate('Y-m-d H:i:s');
        if ($mode === 'replace') {
            $this->pdo->prepare("DELETE FROM {$this->prefix}wishlist_items WHERE wishlist_id = ?")->execute([$wishlistId]);
        }
        $existing = $this->pdo->prepare(
            "SELECT id, bookmark_guid FROM {$this->prefix}wishlist_items WHERE wishlist_id = ?"
        );
        $existing->execute([$wishlistId]);
        $byGuid = [];
        foreach ($existing->fetchAll() as $row) {
            $byGuid[$row['bookmark_guid']] = (int) $row['id'];
        }
        $order = (int) $this->pdo->query(
            "SELECT COALESCE(MAX(sort_order), -1) FROM {$this->prefix}wishlist_items WHERE wishlist_id = " . (int) $wishlistId
        )->fetchColumn() + 1;

        $imported = 0;
        $updated = 0;
        $upd = $this->pdo->prepare(
            "UPDATE {$this->prefix}wishlist_items SET url=?, title=?, meta=?, updated_at=? WHERE id=?"
        );
        $ins = $this->pdo->prepare(
            "INSERT INTO {$this->prefix}wishlist_items
             (wishlist_id, bookmark_guid, url, title, meta, sort_order, updated_at) VALUES (?,?,?,?,?,?,?)"
        );
        foreach ($mapped as $row) {
            $meta = Desiderata_Meta::encode($row['meta']);
            if (isset($byGuid[$row['bookmark_guid']])) {
                $upd->execute([$row['url'], $row['title'], $meta, $now, $byGuid[$row['bookmark_guid']]]);
                $updated++;
            } else {
                $ins->execute([$wishlistId, $row['bookmark_guid'], $row['url'], $row['title'], $meta, $order++, $now]);
                $imported++;
            }
        }
        $this->pdo->prepare("UPDATE {$this->prefix}wishlists SET updated_at=? WHERE id=?")->execute([$now, $wishlistId]);
        $this->json(['ok' => true, 'imported' => $imported, 'updated' => $updated, 'total_payload' => count($mapped)], 201);
    }

    private function exportZotero(string $slug): void
    {
        $wishlist = $this->wishlistBySlug($slug);
        if (!$wishlist) {
            $this->json(['code' => 'not_found', 'message' => 'Liste introuvable.'], 404);
        }
        $stmt = $this->pdo->prepare(
            "SELECT id, title, url, meta FROM {$this->prefix}wishlist_items WHERE wishlist_id = ? ORDER BY sort_order ASC, id ASC"
        );
        $stmt->execute([(int) $wishlist['id']]);
        $csl = Desiderata_Zotero::export_csl($stmt->fetchAll());
        header('Content-Disposition: attachment; filename="desiderata-' . $slug . '.json"');
        $this->json($csl);
    }

    private function lookupIsbn(string $isbn): void
    {
        if (!RateLimit::check($this->pdo, $this->prefix, 'isbn', RateLimit::ip(), 60, 3600)) {
            $this->json(['code' => 'rate_limited', 'message' => 'Trop de requêtes.'], 429);
        }
        $norm = Desiderata_Meta::normalize_isbn($isbn);
        if (!$norm) {
            $this->json(['code' => 'invalid', 'message' => 'ISBN invalide.'], 400);
        }
        $found = Desiderata_OpenLibrary::lookup_isbn($norm);
        if (!$found) {
            $this->json(['code' => 'not_found', 'message' => 'Aucun résultat Open Library.'], 404);
        }
        $this->json([
            'isbn' => $norm,
            'meta' => $found['meta'],
            'title' => $found['title'],
            'suggested_url' => 'https://openlibrary.org/isbn/' . $norm,
        ]);
    }

    private function chatJoin(string $slug, array $json): void
    {
        $wishlist = $this->wishlistBySlug($slug);
        if (!$wishlist) {
            $this->json(['code' => 'not_found', 'message' => 'Liste introuvable.'], 404);
        }
        $participantId = trim((string) ($json['participant_id'] ?? ''));
        if ($participantId === '' || strlen($participantId) > 64) {
            $participantId = bin2hex(random_bytes(16));
        }
        $display = trim((string) ($json['display_name'] ?? '')) ?: 'Anonyme';
        $now = gmdate('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->prefix}chat_rooms
             WHERE wishlist_id = ? AND status IN ('open','closing') ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([(int) $wishlist['id']]);
        $room = $stmt->fetch();
        if (!$room) {
            $token = bin2hex(random_bytes(16));
            $this->pdo->prepare(
                "INSERT INTO {$this->prefix}chat_rooms (wishlist_id, room_token, status, created_at) VALUES (?,?,?,?)"
            )->execute([(int) $wishlist['id'], $token, 'open', $now]);
            $room = [
                'id' => (int) $this->pdo->lastInsertId(),
                'room_token' => $token,
                'status' => 'open',
            ];
        }

        $check = $this->pdo->prepare(
            "SELECT id FROM {$this->prefix}chat_participants WHERE room_id=? AND participant_id=?"
        );
        $check->execute([(int) $room['id'], $participantId]);
        $existing = $check->fetch();
        if ($existing) {
            $this->pdo->prepare(
                "UPDATE {$this->prefix}chat_participants SET display_name=?, last_seen_at=?, wants_close=0 WHERE id=?"
            )->execute([mb_substr($display, 0, 120), $now, (int) $existing['id']]);
        } else {
            $this->pdo->prepare(
                "INSERT INTO {$this->prefix}chat_participants (room_id, participant_id, display_name, last_seen_at, wants_close)
                 VALUES (?,?,?,?,0)"
            )->execute([(int) $room['id'], $participantId, mb_substr($display, 0, 120), $now]);
        }

        $peerCount = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM {$this->prefix}chat_participants WHERE room_id = " . (int) $room['id']
        )->fetchColumn();

        $this->json([
            'room_id' => (int) $room['id'],
            'room_token' => $room['room_token'],
            'status' => $room['status'],
            'participant_id' => $participantId,
            'display_name' => $display,
            'peer_count' => $peerCount,
            'messages' => $this->fetchMessages((int) $room['id'], 0),
        ]);
    }

    private function chatList(string $slug): void
    {
        $wishlist = $this->wishlistBySlug($slug);
        if (!$wishlist) {
            $this->json(['code' => 'not_found', 'message' => 'Liste introuvable.'], 404);
        }
        $room = $this->openRoom((int) $wishlist['id']);
        if (!$room) {
            $this->json(['messages' => [], 'status' => 'none', 'peer_count' => 0]);
        }
        $since = (int) ($_GET['since'] ?? 0);
        $participantId = (string) ($_GET['participant_id'] ?? '');
        if ($participantId !== '') {
            $this->pdo->prepare(
                "UPDATE {$this->prefix}chat_participants SET last_seen_at=? WHERE room_id=? AND participant_id=?"
            )->execute([gmdate('Y-m-d H:i:s'), (int) $room['id'], $participantId]);
        }
        $cutoff = gmdate('Y-m-d H:i:s', time() - 120);
        $peer = $this->pdo->prepare(
            "SELECT COUNT(*) FROM {$this->prefix}chat_participants WHERE room_id=? AND last_seen_at > ?"
        );
        $peer->execute([(int) $room['id'], $cutoff]);
        $this->json([
            'room_id' => (int) $room['id'],
            'status' => $room['status'],
            'peer_count' => (int) $peer->fetchColumn(),
            'messages' => $this->fetchMessages((int) $room['id'], $since),
        ]);
    }

    private function chatPost(string $slug, array $json): void
    {
        if (!RateLimit::check($this->pdo, $this->prefix, 'chat_' . $slug, RateLimit::ip(), 120, 3600)) {
            $this->json(['code' => 'rate_limited', 'message' => 'Trop de messages.'], 429);
        }
        $wishlist = $this->wishlistBySlug($slug);
        if (!$wishlist) {
            $this->json(['code' => 'not_found', 'message' => 'Liste introuvable.'], 404);
        }
        $roomToken = (string) ($json['room_token'] ?? '');
        $participantId = (string) ($json['participant_id'] ?? '');
        $body = mb_substr(trim((string) ($json['body'] ?? '')), 0, 500);
        if ($roomToken === '' || $participantId === '' || $body === '') {
            $this->json(['code' => 'invalid', 'message' => 'Paramètres manquants.'], 400);
        }
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->prefix}chat_rooms WHERE wishlist_id=? AND room_token=? AND status IN ('open','closing')"
        );
        $stmt->execute([(int) $wishlist['id'], $roomToken]);
        $room = $stmt->fetch();
        if (!$room) {
            $this->json(['code' => 'not_found', 'message' => 'Salon introuvable.'], 404);
        }
        $part = $this->pdo->prepare(
            "SELECT * FROM {$this->prefix}chat_participants WHERE room_id=? AND participant_id=?"
        );
        $part->execute([(int) $room['id'], $participantId]);
        $participant = $part->fetch();
        if (!$participant) {
            $this->json(['code' => 'forbidden', 'message' => 'Participant inconnu.'], 403);
        }

        $max = (int) ($this->config['limits']['chat_messages'] ?? 50);
        $count = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM {$this->prefix}chat_messages WHERE room_id = " . (int) $room['id']
        )->fetchColumn();
        if ($count >= $max) {
            $oldest = (int) $this->pdo->query(
                "SELECT id FROM {$this->prefix}chat_messages WHERE room_id = " . (int) $room['id'] . " ORDER BY id ASC LIMIT 1"
            )->fetchColumn();
            if ($oldest) {
                $this->pdo->prepare("DELETE FROM {$this->prefix}chat_messages WHERE id=?")->execute([$oldest]);
            }
        }

        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->prepare(
            "INSERT INTO {$this->prefix}chat_messages (room_id, participant_id, body, created_at) VALUES (?,?,?,?)"
        )->execute([(int) $room['id'], $participantId, $body, $now]);
        $id = (int) $this->pdo->lastInsertId();
        $this->json([
            'id' => $id,
            'room_id' => (int) $room['id'],
            'participant_id' => $participantId,
            'display_name' => $participant['display_name'],
            'body' => $body,
            'created_at' => $now,
        ], 201);
    }

    private function chatClose(string $slug, array $json): void
    {
        $wishlist = $this->wishlistBySlug($slug);
        if (!$wishlist) {
            $this->json(['code' => 'not_found', 'message' => 'Liste introuvable.'], 404);
        }
        $roomToken = (string) ($json['room_token'] ?? '');
        $participantId = (string) ($json['participant_id'] ?? '');
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->prefix}chat_rooms WHERE wishlist_id=? AND room_token=? AND status IN ('open','closing')"
        );
        $stmt->execute([(int) $wishlist['id'], $roomToken]);
        $room = $stmt->fetch();
        if (!$room) {
            $this->json(['code' => 'not_found', 'message' => 'Salon introuvable.'], 404);
        }
        $this->pdo->prepare(
            "UPDATE {$this->prefix}chat_participants SET wants_close=1, last_seen_at=? WHERE room_id=? AND participant_id=?"
        )->execute([gmdate('Y-m-d H:i:s'), (int) $room['id'], $participantId]);

        $cutoff = gmdate('Y-m-d H:i:s', time() - 180);
        $active = $this->pdo->prepare(
            "SELECT wants_close FROM {$this->prefix}chat_participants WHERE room_id=? AND last_seen_at > ?"
        );
        $active->execute([(int) $room['id'], $cutoff]);
        $rows = $active->fetchAll();
        $allWant = !empty($rows);
        foreach ($rows as $row) {
            if (!(int) $row['wants_close']) {
                $allWant = false;
                break;
            }
        }
        if (count($rows) <= 1) {
            $allWant = true;
        }
        if ($allWant) {
            $this->purgeRoom((int) $room['id']);
            $this->json(['ok' => true, 'closed' => true, 'status' => 'closed']);
        }
        $this->pdo->prepare("UPDATE {$this->prefix}chat_rooms SET status='closing' WHERE id=?")->execute([(int) $room['id']]);
        $this->json(['ok' => true, 'closed' => false, 'status' => 'closing']);
    }

    private function rtcPost(string $slug, array $json): void
    {
        $wishlist = $this->wishlistBySlug($slug);
        if (!$wishlist) {
            $this->json(['code' => 'not_found', 'message' => 'Liste introuvable.'], 404);
        }
        $roomToken = (string) ($json['room_token'] ?? '');
        $from = (string) ($json['from_id'] ?? '');
        $to = isset($json['to_id']) ? (string) $json['to_id'] : null;
        $type = (string) ($json['type'] ?? '');
        $payload = $json['payload'] ?? null;
        if (!in_array($type, ['offer', 'answer', 'ice'], true) || $roomToken === '' || $from === '' || $payload === null) {
            $this->json(['code' => 'invalid', 'message' => 'Paramètres manquants.'], 400);
        }
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->prefix}chat_rooms WHERE wishlist_id=? AND room_token=? AND status IN ('open','closing')"
        );
        $stmt->execute([(int) $wishlist['id'], $roomToken]);
        $room = $stmt->fetch();
        if (!$room) {
            $this->json(['code' => 'not_found', 'message' => 'Salon introuvable.'], 404);
        }
        $encoded = is_string($payload) ? $payload : json_encode($payload);
        $this->pdo->prepare(
            "INSERT INTO {$this->prefix}rtc_signals (room_id, from_id, to_id, type, payload, created_at)
             VALUES (?,?,?,?,?,?)"
        )->execute([(int) $room['id'], $from, $to, $type, $encoded, gmdate('Y-m-d H:i:s')]);
        $this->json(['ok' => true, 'id' => (int) $this->pdo->lastInsertId()], 201);
    }

    private function rtcList(string $slug): void
    {
        $wishlist = $this->wishlistBySlug($slug);
        if (!$wishlist) {
            $this->json(['code' => 'not_found', 'message' => 'Liste introuvable.'], 404);
        }
        $roomToken = (string) ($_GET['room_token'] ?? '');
        $participantId = (string) ($_GET['participant_id'] ?? '');
        $since = (int) ($_GET['since'] ?? 0);
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->prefix}chat_rooms WHERE wishlist_id=? AND room_token=? AND status IN ('open','closing')"
        );
        $stmt->execute([(int) $wishlist['id'], $roomToken]);
        $room = $stmt->fetch();
        if (!$room) {
            $this->json(['signals' => []]);
        }
        $q = $this->pdo->prepare(
            "SELECT id, from_id, to_id, type, payload, created_at FROM {$this->prefix}rtc_signals
             WHERE room_id=? AND id>? AND from_id<>?
               AND (to_id IS NULL OR to_id='' OR to_id=?)
             ORDER BY id ASC LIMIT 100"
        );
        $q->execute([(int) $room['id'], $since, $participantId, $participantId]);
        $out = [];
        foreach ($q->fetchAll() as $row) {
            $decoded = json_decode((string) $row['payload'], true);
            $out[] = [
                'id' => (int) $row['id'],
                'from_id' => $row['from_id'],
                'to_id' => $row['to_id'],
                'type' => $row['type'],
                'payload' => $decoded !== null ? $decoded : $row['payload'],
                'created_at' => $row['created_at'],
            ];
        }
        $this->json(['signals' => $out]);
    }

    private function purgeRoom(int $roomId): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->prepare("DELETE FROM {$this->prefix}chat_messages WHERE room_id=?")->execute([$roomId]);
        $this->pdo->prepare("DELETE FROM {$this->prefix}rtc_signals WHERE room_id=?")->execute([$roomId]);
        $this->pdo->prepare("DELETE FROM {$this->prefix}chat_participants WHERE room_id=?")->execute([$roomId]);
        $this->pdo->prepare(
            "UPDATE {$this->prefix}chat_rooms SET status='closed', closed_at=? WHERE id=?"
        )->execute([$now, $roomId]);
    }

    /** @return list<array<string,mixed>> */
    private function fetchMessages(int $roomId, int $since): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT m.id, m.participant_id, m.body, m.created_at, p.display_name
             FROM {$this->prefix}chat_messages m
             LEFT JOIN {$this->prefix}chat_participants p
               ON p.room_id = m.room_id AND p.participant_id = m.participant_id
             WHERE m.room_id = ? AND m.id > ?
             ORDER BY m.id ASC LIMIT 100"
        );
        $stmt->execute([$roomId, $since]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'participant_id' => $row['participant_id'],
                'display_name' => $row['display_name'] ?: 'Anonyme',
                'body' => $row['body'],
                'created_at' => $row['created_at'],
            ];
        }
        return $out;
    }

    private function wishlistById(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->prefix}wishlists WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function wishlistBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->prefix}wishlists WHERE slug = ?");
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function itemById(int $itemId, int $wishlistId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->prefix}wishlist_items WHERE id=? AND wishlist_id=?"
        );
        $stmt->execute([$itemId, $wishlistId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function openRoom(int $wishlistId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM {$this->prefix}chat_rooms
             WHERE wishlist_id=? AND status IN ('open','closing') ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$wishlistId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function uniqueSlug(): string
    {
        $alphabet = 'abcdefghijkmnopqrstuvwxyz23456789';
        for ($t = 0; $t < 8; $t++) {
            $slug = '';
            for ($i = 0; $i < 10; $i++) {
                $slug .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->prefix}wishlists WHERE slug=?");
            $stmt->execute([$slug]);
            if ((int) $stmt->fetchColumn() === 0) {
                return $slug;
            }
        }
        return bin2hex(random_bytes(8));
    }

    private function sanitizeUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return null;
        }
        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    private function publicWishlistUrl(string $slug): string
    {
        // Prefer WP public page on same site when configured; else API-only hint.
        $site = (string) ($this->config['site_public_wishlist_base'] ?? '');
        if ($site !== '') {
            return rtrim($site, '/') . '/' . $slug . '/';
        }
        return rtrim((string) ($this->config['public_base'] ?? ''), '/') . '/wishlists/' . $slug;
    }
}
