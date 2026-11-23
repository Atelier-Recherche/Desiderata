<?php

declare(strict_types=1);

namespace Desiderata\Standalone;

use PDO;

final class Schema
{
    public static function migrate(PDO $pdo, string $prefix): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}users (
            id INTEGER PRIMARY KEY AUTO_INCREMENT,
            email VARCHAR(190) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            display_name VARCHAR(120) NOT NULL DEFAULT '',
            api_token_hash VARCHAR(255) NULL,
            api_token_prefix VARCHAR(32) NULL,
            api_token_hint VARCHAR(64) NULL,
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}wishlists (
            id INTEGER PRIMARY KEY AUTO_INCREMENT,
            user_id INTEGER NOT NULL,
            slug VARCHAR(32) NOT NULL UNIQUE,
            title VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}wishlist_items (
            id INTEGER PRIMARY KEY AUTO_INCREMENT,
            wishlist_id INTEGER NOT NULL,
            bookmark_guid VARCHAR(64) NOT NULL DEFAULT '',
            url TEXT NOT NULL,
            title VARCHAR(512) NOT NULL DEFAULT '',
            meta LONGTEXT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            claimed_by VARCHAR(120) NULL,
            claimed_at DATETIME NULL,
            claim_hash VARCHAR(255) NULL,
            updated_at DATETIME NOT NULL,
            INDEX (wishlist_id),
            INDEX (bookmark_guid)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}chat_rooms (
            id INTEGER PRIMARY KEY AUTO_INCREMENT,
            wishlist_id INTEGER NOT NULL,
            room_token VARCHAR(64) NOT NULL UNIQUE,
            status VARCHAR(16) NOT NULL DEFAULT 'open',
            created_at DATETIME NOT NULL,
            closed_at DATETIME NULL,
            INDEX (wishlist_id),
            INDEX (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}chat_participants (
            id INTEGER PRIMARY KEY AUTO_INCREMENT,
            room_id INTEGER NOT NULL,
            participant_id VARCHAR(64) NOT NULL,
            display_name VARCHAR(120) NOT NULL DEFAULT '',
            last_seen_at DATETIME NOT NULL,
            wants_close TINYINT(1) NOT NULL DEFAULT 0,
            UNIQUE KEY room_participant (room_id, participant_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}chat_messages (
            id INTEGER PRIMARY KEY AUTO_INCREMENT,
            room_id INTEGER NOT NULL,
            participant_id VARCHAR(64) NOT NULL,
            body VARCHAR(500) NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX (room_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}rtc_signals (
            id INTEGER PRIMARY KEY AUTO_INCREMENT,
            room_id INTEGER NOT NULL,
            from_id VARCHAR(64) NOT NULL,
            to_id VARCHAR(64) NULL,
            type VARCHAR(16) NOT NULL,
            payload LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX (room_id),
            INDEX (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS {$prefix}rate_limits (
            bucket_key VARCHAR(64) PRIMARY KEY,
            count INT NOT NULL,
            window_start INT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}
