<div align="center" id="head">

<table>
<tr>
<td><img src="readme-media/logo_desiderata.png" alt="Desiderata" width="140" /></td>
<td align="left">
<h1 style="margin:0">Desiderata</h1>
<p style="margin:0.25em 0 0"><strong>Wishlist collaborative de livres &amp; documents</strong><br/>Favoris navigateur · WordPress · PWA · chat éphémère</p>
</td>
</tr>
</table>

<a href="https://atelier.atechnologie.fr/" title="l'Atelier – Association de fabrication de livres et d'outils de recherche"><img src="https://raw.githubusercontent.com/Atelier-Recherche/.github/main/profile/logo.jpg" alt="l'Atelier" width="200" /></a>  
<sub>Développé par <a href="https://atelier.atechnologie.fr/">l'Atelier</a> — fabrication de livres et outils de recherche (EHESS)</sub>

<p>
🇫🇷 <a href="README.md"><b>Français</b></a> ·
🇬🇧 <a href="README.en.md">English</a> ·
🇩🇪 <a href="README.de.md">Deutsch</a> ·
🇪🇸 <a href="README.es.md">Español</a>
</p>

<p>
<a href="https://atelier.atechnologie.fr/"><img src="https://img.shields.io/badge/🌐_l'Atelier-atelier.atechnologie.fr-2d5016?style=for-the-badge" alt="Site l'Atelier" /></a>
<a href="https://github.com/Atelier-Recherche/ATDesiderata"><img src="https://img.shields.io/badge/📦_Dépôt-GitHub-181717?style=for-the-badge&logo=github" alt="Dépôt GitHub" /></a>
</p>

</div>

---

## 📸 Aperçu

| Architecture | Wishlist publique |
| :---: | :---: |
| <img src="readme-media/screen1.png" alt="Architecture" width="400" /> | <img src="readme-media/screen2.png" alt="Wishlist" width="400" /> |

---

## 📖 À propos

**Desiderata** permet de partager un dossier de favoris (livres, articles, notices) sous forme de **wishlist interactive** : consultation sans compte, action « Moi je l’ai », enrichissement ISBN / Open Library, import-export Zotero, et chat optionnel éphémère.

Trois briques complémentaires :

1. **Extension navigateur** (Firefox & Chrome) — sélection du dossier de favoris et sync automatique  
2. **Plugin WordPress** — comptes, stockage, pages publiques `/wishlist/{slug}`  
3. **Backend autonome** (optionnel) — même contrat REST, sans WordPress  

> État : **MVP utilisable**. Sync, claims, chat serveur + WebRTC optionnel, ISBN/Zotero et scripts de deploy O2switch sont en place.

---

## ✨ Fonctionnalités actuelles

- **Sync favoris** — écoute `onCreated` / `onRemoved` / `onChanged` / `onMoved` avec debounce  
- **Page publique PWA** — installable, claim / libération avec secret local  
- **ISBN + Open Library** — lookup, enrichissement meta (auteurs, couverture…)  
- **Zotero** — import JSON / CSL-JSON, export CSL-JSON public  
- **Chat éphémère** — polling serveur (autorité) + RTCDataChannel optionnel ; purge à la clôture  
- **Deploy** — `build.ps1` / `deploy.ps1` / `release.ps1` (même site FTP que les autres projets Atelier)

---

## 🧩 Stack

| Couche | Choix |
| --- | --- |
| Extension | WebExtensions Manifest V3 (Chrome service worker · Firefox event page) |
| Backend WP | PHP 8 · `$wpdb` · REST `/wp-json/desiderata/v1` |
| Backend autonome | PHP 8 · PDO MySQL · `/desiderata-api/v1` |
| Front public | HTML / CSS / JS modules · Web App Manifest · Service Worker |
| Chat | REST + polling · WebRTC DataChannel (STUN) |

---

## 📁 Organisation du dépôt

```text
ATDesiderata/
  README.md / README.en.md / README.de.md / README.es.md
  readme-media/
  build.ps1 · deploy.ps1.example · release.ps1
  packages/
    shared-php/          # Meta, Open Library, Zotero
    wordpress-plugin/    # Plugin WP « Desiderata »
    extension/           # Sources + dist-chrome / dist-firefox
    standalone/          # API autonome
  dist/                  # artefacts après build
```

---

## 🦊 Installer l’extension Firefox

Un fichier **`.xpi`** est produit par le build :

```powershell
.\build.ps1
# → dist/desiderata-firefox.xpi
# → packages/extension/dist-pack/desiderata-firefox.xpi
```

### Chargement temporaire (dev — recommandé)

Firefox **ne charge pas** un `.xpi` non signé en double-clic (contrairement à un add-on AMO). Pour développer :

1. Ouvrir `about:debugging#/runtime/this-firefox`  
2. **Charger un module complémentaire temporaire…**  
3. Sélectionner `packages/extension/dist-firefox/manifest.json`  
   (préférer le dossier plutôt que le `.xpi` en dev)

Après un rebuild : **Recharger** l’extension dans `about:debugging` (bouton Recharger).

> Note : un ancien empaquetage Windows mettait des `\` dans le `.xpi` → options en 404 sous `moz-extension://`. Le build actuel utilise des `/`.

L’extension reste active jusqu’au redémarrage de Firefox.

### Installation permanente

- Publier / signer sur [addons.mozilla.org](https://addons.mozilla.org/) (ou signature non listée), **ou**  
- Utiliser Firefox Nightly / ESR avec `xpinstall.signatures.required = false` (usage avancé uniquement).

### Chrome / Edge

1. `chrome://extensions` → Mode développeur  
2. **Charger l’extension non empaquetée** → `packages/extension/dist-chrome`  
3. Ou installer depuis `dist/desiderata-chrome.zip` (dézipper puis charger le dossier)

---

## 📦 Installer le plugin WordPress

1. `.\build.ps1` → `dist/desiderata.zip`  
2. WP Admin → Extensions → Ajouter → Téléverser, **ou**  
3. `.\deploy.ps1` (FTP vers `wp-content/plugins/desiderata`)  
4. Activer le plugin, **Réglages → Permaliens → Enregistrer**  
5. Menu **Desiderata** : inscription, Turnstile (optionnel), utilisateurs, listes  
6. Après mise à jour : **Réglages → Permaliens → Enregistrer** (route `/desiderata/turnstile/`)

Puis dans l’extension : URL du site → créer un compte / se connecter → dossier de favoris → Synchroniser.

Les comptes créés via l’extension sont des **abonnés WordPress** (pas d’admin) + capacité `desiderata_manage` uniquement.

---

## 🗺 Backend autonome (optionnel)

```powershell
.\deploy.ps1 -Standalone
# → /desiderata-api sur le même hébergement
```

Créer `config.php` à partir de `packages/standalone/config.example.php`.  
L’extension accepte une base API du type `https://votresite.fr/desiderata-api/v1`.

---

## 💻 Développement

```powershell
# Build complet (plugin + API + xpi/zip extensions)
.\build.ps1

# Extension seule
cd packages\extension
.\build.ps1
```

Critère produit : moins de 2 minutes entre l’install de l’extension et le premier lien partageable ; zéro compte pour consulter / réserver / chatter.

---

## 🤝 Contribution

Issues et PR bienvenues. Préférer des changements petits et relisibles. Le cercle de confiance (listes partagées entre proches) guide le modèle de claim.

---

## 📄 Licence

À définir par l’Atelier (dépôt privé / usage association pour l’instant).

---

## 🌐 Liens

- 🌐 [l’Atelier](https://atelier.atechnologie.fr/)
- 📦 [Dépôt GitHub](https://github.com/Atelier-Recherche/ATDesiderata)
