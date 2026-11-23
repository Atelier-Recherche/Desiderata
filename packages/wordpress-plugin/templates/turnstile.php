<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

$site_key = Desiderata_Auth::turnstile_site_key();
?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Desiderata — vérification</title>
  <style>
    body { font-family: system-ui, sans-serif; margin: 0; padding: 1.25rem; background: #0f1419; color: #e8eef4; }
    p { margin: 0 0 1rem; font-size: 0.95rem; opacity: 0.9; }
    #widget { min-height: 65px; }
    .err { color: #f5a8a8; }
  </style>
  <?php if ($site_key !== '') : ?>
  <script src="https://challenges.cloudflare.com/turnstile/v0/api.js?onload=desiderataTurnstileReady" async defer></script>
  <?php endif; ?>
</head>
<body>
  <?php if ($site_key === '') : ?>
    <p class="err">Turnstile n’est pas configuré sur ce site.</p>
  <?php else : ?>
    <p>Vérification anti-bot (Cloudflare Turnstile)…</p>
    <div id="widget"></div>
    <script>
      (function () {
        var sitekey = <?php echo wp_json_encode($site_key); ?>;
        var allowedOrigin = <?php echo wp_json_encode(
            isset($_GET['origin']) ? esc_url_raw(wp_unslash((string) $_GET['origin'])) : ''
        ); ?>;

        function notify(payload) {
          var msg = Object.assign({ type: 'desiderata-turnstile' }, payload);
          if (window.opener) {
            try { window.opener.postMessage(msg, allowedOrigin || '*'); } catch (e) {}
          }
          if (window.parent && window.parent !== window) {
            try { window.parent.postMessage(msg, allowedOrigin || '*'); } catch (e) {}
          }
        }

        window.desiderataTurnstileReady = function () {
          if (!window.turnstile) {
            notify({ ok: false, error: 'turnstile_missing' });
            return;
          }
          window.turnstile.render('#widget', {
            sitekey: sitekey,
            theme: 'dark',
            callback: function (token) {
              notify({ ok: true, token: token });
            },
            'error-callback': function () {
              notify({ ok: false, error: 'challenge_failed' });
            },
            'expired-callback': function () {
              notify({ ok: false, error: 'expired' });
            }
          });
        };
      })();
    </script>
  <?php endif; ?>
</body>
</html>
