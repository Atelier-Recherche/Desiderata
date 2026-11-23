<div align="center" id="head">

<table>
<tr>
<td><img src="readme-media/logo_desiderata.png" alt="Desiderata" width="140" /></td>
<td align="left">
<h1 style="margin:0">Desiderata</h1>
<p style="margin:0.25em 0 0"><strong>Kollaborative Wunschliste für Bücher &amp; Dokumente</strong><br/>Lesezeichen · WordPress · PWA · ephemerer Chat</p>
</td>
</tr>
</table>

<a href="https://atelier.atechnologie.fr/" title="l'Atelier – Verein für Buchherstellung und Forschungswerkzeuge"><img src="https://raw.githubusercontent.com/Atelier-Recherche/.github/main/profile/logo.jpg" alt="l'Atelier" width="200" /></a>  
<sub>Entwickelt von <a href="https://atelier.atechnologie.fr/">l'Atelier</a> — Buchherstellung und Forschungswerkzeuge (EHESS)</sub>

<p>
🇫🇷 <a href="README.md">Français</a> ·
🇬🇧 <a href="README.en.md">English</a> ·
🇩🇪 <a href="README.de.md"><b>Deutsch</b></a> ·
🇪🇸 <a href="README.es.md">Español</a>
</p>

<p>
<a href="https://atelier.atechnologie.fr/"><img src="https://img.shields.io/badge/🌐_l'Atelier-atelier.atechnologie.fr-2d5016?style=for-the-badge" alt="Website l'Atelier" /></a>
<a href="https://github.com/Atelier-Recherche/ATDesiderata"><img src="https://img.shields.io/badge/📦_Repository-GitHub-181717?style=for-the-badge&logo=github" alt="GitHub-Repository" /></a>
</p>

</div>

---

## 📸 Vorschau

| Architektur | Öffentliche Wishlist |
| :---: | :---: |
| <img src="readme-media/screen1.png" alt="Architektur" width="400" /> | <img src="readme-media/screen2.png" alt="Wishlist" width="400" /> |

---

## 📖 Über

**Desiderata** macht einen Lesezeichen-Ordner zu einer teilbaren **interaktiven Wishlist**: ohne Konto ansehen, „Ich habe es / ich nehme es“, ISBN / Open Library, Zotero Import-Export und optionaler ephemerer Chat.

Drei Bausteine: **Browser-Erweiterung**, **WordPress-Plugin**, optional **Standalone-API** mit demselben REST-Vertrag.

> Status: **nutzbarer MVP**. Details und die ausführlichste Liste stehen in [README.md](README.md) / [README.en.md](README.en.md).

---

## ✨ Aktuelle Funktionen (Kurz)

- Sync von Lesezeichen mit Debounce  
- Öffentliche PWA mit Claim/Release  
- ISBN + Open Library, Zotero Import/Export  
- Ephemerer Chat (Server-Polling + optionales WebRTC)  
- Build/Deploy-Skripte für O2switch  

---

## 🦊 Firefox-Erweiterung installieren

```powershell
.\build.ps1
# → dist/desiderata-firefox.xpi
```

**Temporär (Entwicklung):** `about:debugging#/runtime/this-firefox` → **Temporäres Add-on laden…** → `packages/extension/dist-firefox/manifest.json` (oder die `.xpi`).

Ein unsigniertes `.xpi` lässt sich in Release-Firefox **nicht** dauerhaft per Doppelklick installieren — Signatur über AMO oder Nightly/ESR mit deaktivierter Signaturprüfung nötig.

**Chrome:** entpackt aus `packages/extension/dist-chrome` laden.

---

## 📦 WordPress-Plugin

`dist/desiderata.zip` hochladen oder `.\deploy.ps1` ausführen, aktivieren, Permalinks speichern, Liste + API-Token unter **Desiderata** erzeugen, dann in der Erweiterung verbinden.

---

## 🗺 Standalone (optional)

```powershell
.\deploy.ps1 -Standalone
```

`config.php` aus `config.example.php` anlegen. API-Basis: `…/desiderata-api/v1`.

---

## 💻 Entwicklung

```powershell
.\build.ps1
```

---

## 🌐 Links

- 🌐 [l’Atelier](https://atelier.atechnologie.fr/)
- 📦 [GitHub](https://github.com/Atelier-Recherche/ATDesiderata)
