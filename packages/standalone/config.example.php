<?php

declare(strict_types=1);

/**
 * Copier vers config.php (non versionné) et adapter.
 * Sur O2switch : même MySQL que WordPress ou base dédiée.
 */
return [
    'db' => [
        'dsn' => 'mysql:host=localhost;dbname=VOTRE_BASE;charset=utf8mb4',
        'user' => 'VOTRE_USER',
        'pass' => 'VOTRE_PASS',
        'prefix' => 'desiderata_',
    ],
    // URL publique de cette API, sans slash final — ex: https://votresite.fr/desiderata-api/v1
    'public_base' => 'https://votresite.fr/desiderata-api/v1',
    // Pages wishlist WP sur le même site (optionnel)
    'site_public_wishlist_base' => 'https://votresite.fr/wishlist',
    // Origine du site pour CORS (PWA / pages)
    'cors_origins' => [
        'https://votresite.fr',
    ],
    // Inscription libre depuis l’extension (false pour la couper)
    'registration_open' => true,
    // Compte propriétaire initial (créé au premier install si absent)
    'bootstrap_user' => [
        'email' => 'admin@example.com',
        'password' => 'change-me-now',
        'display_name' => 'Admin Desiderata',
    ],
    'limits' => [
        'wishlists_per_user' => 5,
        'items_per_wishlist' => 500,
        'chat_messages' => 50,
    ],
];
