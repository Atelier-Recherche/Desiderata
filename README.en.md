<div align="center" id="head">

<table>
<tr>
<td><img src="readme-media/logo_desiderata.png" alt="Desiderata" width="140" /></td>
<td align="left">
<h1 style="margin:0">Desiderata</h1>
<p style="margin:0.25em 0 0"><strong>Collaborative wishlist for books &amp; documents</strong><br/>Browser bookmarks · WordPress · PWA · ephemeral chat</p>
</td>
</tr>
</table>

<a href="https://atelier.atechnologie.fr/" title="l'Atelier – book-making and research tools association"><img src="https://raw.githubusercontent.com/Atelier-Recherche/.github/main/profile/logo.jpg" alt="l'Atelier" width="200" /></a>  
<sub>Developed by <a href="https://atelier.atechnologie.fr/">l'Atelier</a> — book-making and research tools (EHESS)</sub>

<p>
🇫🇷 <a href="README.md">Français</a> ·
🇬🇧 <a href="README.en.md"><b>English</b></a> ·
🇩🇪 <a href="README.de.md">Deutsch</a> ·
🇪🇸 <a href="README.es.md">Español</a>
</p>

<p>
<a href="https://atelier.atechnologie.fr/"><img src="https://img.shields.io/badge/🌐_l'Atelier-atelier.atechnologie.fr-2d5016?style=for-the-badge" alt="l'Atelier website" /></a>
<a href="https://github.com/Atelier-Recherche/ATDesiderata"><img src="https://img.shields.io/badge/📦_Repository-GitHub-181717?style=for-the-badge&logo=github" alt="GitHub repository" /></a>
</p>

</div>

---

## 📸 Preview

| Architecture | Public wishlist |
| :---: | :---: |
| <img src="readme-media/screen1.png" alt="Architecture" width="400" /> | <img src="readme-media/screen2.png" alt="Wishlist" width="400" /> |

---

## 📖 About

**Desiderata** turns a bookmarks folder (books, articles, notices) into a shareable **interactive wishlist**: no account required to browse, “I’ll take it” claims, ISBN / Open Library enrichment, Zotero import-export, and optional ephemeral chat.

Three complementary pieces:

1. **Browser extension** (Firefox & Chrome) — pick a bookmarks folder and auto-sync  
2. **WordPress plugin** — accounts, storage, public pages `/wishlist/{slug}`  
3. **Standalone backend** (optional) — same REST contract without WordPress  

> Status: **usable MVP**. Sync, claims, server chat + optional WebRTC, ISBN/Zotero, and O2switch deploy scripts are in place.

---

## ✨ Current features

- **Bookmark sync** — `onCreated` / `onRemoved` / `onChanged` / `onMoved` with debounce  
- **Public PWA page** — installable; claim / release with a local secret  
- **ISBN + Open Library** — lookup and meta enrichment (authors, cover…)  
- **Zotero** — JSON / CSL-JSON import, public CSL-JSON export  
- **Ephemeral chat** — server polling (source of truth) + optional RTCDataChannel; purged on close  
- **Deploy** — `build.ps1` / `deploy.ps1` / `release.ps1`

---

## 🧩 Stack

| Layer | Choice |
| --- | --- |
| Extension | WebExtensions Manifest V3 (Chrome service worker · Firefox event page) |
| WP backend | PHP 8 · `$wpdb` · REST `/wp-json/desiderata/v1` |
| Standalone | PHP 8 · PDO MySQL · `/desiderata-api/v1` |
| Public front | HTML / CSS / JS modules · Web App Manifest · Service Worker |
| Chat | REST + polling · WebRTC DataChannel (STUN) |

---

## 📁 Repository layout

```text
ATDesiderata/
  README.md / README.en.md / README.de.md / README.es.md
  readme-media/
  build.ps1 · deploy.ps1.example · release.ps1
  packages/
    shared-php/
    wordpress-plugin/
    extension/
    standalone/
  dist/
```

---

## 🦊 Install the Firefox extension

Build produces a **`.xpi`**:

```powershell
.\build.ps1
# → dist/desiderata-firefox.xpi
```

### Temporary load (dev — recommended)

Release Firefox **will not** permanently install an unsigned `.xpi` via double-click. For development:

1. Open `about:debugging#/runtime/this-firefox`  
2. **Load Temporary Add-on…**  
3. Select `packages/extension/dist-firefox/manifest.json` (or the built `.xpi`)

The add-on lasts until Firefox restarts.

### Permanent install

Sign via [addons.mozilla.org](https://addons.mozilla.org/) (listed or unlisted), or use Nightly/ESR with signature checks disabled (advanced only).

### Chrome / Edge

Load unpacked from `packages/extension/dist-chrome`, or unzip `dist/desiderata-chrome.zip` first.

---

## 📦 Install the WordPress plugin

1. `.\build.ps1` → `dist/desiderata.zip`  
2. Upload in WP Admin, or run `.\deploy.ps1`  
3. Activate, flush permalinks, create a list + API token under **Desiderata**  
4. In the extension: site URL + token + bookmarks folder → Sync  

---

## 🗺 Standalone backend (optional)

```powershell
.\deploy.ps1 -Standalone
```

Create `config.php` from `packages/standalone/config.example.php`.  
Point the extension at `https://yoursite/desiderata-api/v1`.

---

## 💻 Development

```powershell
.\build.ps1
cd packages\extension
.\build.ps1
```

Product goal: under 2 minutes from extension install to first shareable link; zero account to view / claim / chat.

---

## 🤝 Contributing

Issues and PRs welcome. Prefer small, reviewable changes. Trust-circle sharing guides the claim model.

---

## 📄 License

To be set by l’Atelier.

---

## 🌐 Links

- 🌐 [l’Atelier](https://atelier.atechnologie.fr/)
- 📦 [GitHub repository](https://github.com/Atelier-Recherche/ATDesiderata)
