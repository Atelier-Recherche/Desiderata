<?php
declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$slug = get_query_var('desiderata_wishlist');
$slug = is_string($slug) ? preg_replace('/[^a-z0-9]/', '', $slug) : '';
$api_base = esc_url_raw(rest_url('desiderata/v1'));
$asset_url = DESIDERATA_URL . 'public/';
$manifest_url = $asset_url . 'manifest.webmanifest';
$title = 'Desiderata';
?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="theme-color" content="#1a3a2f" />
  <meta name="description" content="Wishlist collaborative — Desiderata par L'atelier" />
  <link rel="manifest" href="<?php echo esc_url($manifest_url); ?>" />
  <link rel="stylesheet" href="<?php echo esc_url($asset_url . 'css/app.css?v=' . rawurlencode(DESIDERATA_VERSION)); ?>" />
  <title><?php echo esc_html($title); ?></title>
  <script>
    window.DESIDERATA = {
      slug: <?php echo wp_json_encode($slug); ?>,
      apiBase: <?php echo wp_json_encode($api_base); ?>,
      assetBase: <?php echo wp_json_encode($asset_url); ?>,
      version: <?php echo wp_json_encode(DESIDERATA_VERSION); ?>
    };
  </script>
</head>
<body>
  <div class="atmosphere" aria-hidden="true"></div>
  <header class="site-header">
    <p class="brand">Desiderata <span class="by">par L'atelier</span></p>
  </header>
  <main id="app" class="app">
    <p class="loading">Chargement de la liste…</p>
  </main>
  <script type="module" src="<?php echo esc_url($asset_url . 'js/app.js?v=' . rawurlencode(DESIDERATA_VERSION)); ?>"></script>
</body>
</html>
