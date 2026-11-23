<div align="center" id="head">

<table>
<tr>
<td><img src="readme-media/logo_desiderata.png" alt="Desiderata" width="140" /></td>
<td align="left">
<h1 style="margin:0">Desiderata</h1>
<p style="margin:0.25em 0 0"><strong>Wishlist colaborativa de libros y documentos</strong><br/>Marcadores · WordPress · PWA · chat efímero</p>
</td>
</tr>
</table>

<a href="https://atelier.atechnologie.fr/" title="l'Atelier – asociación de fabricación de libros y herramientas de investigación"><img src="https://raw.githubusercontent.com/Atelier-Recherche/.github/main/profile/logo.jpg" alt="l'Atelier" width="200" /></a>  
<sub>Desarrollado por <a href="https://atelier.atechnologie.fr/">l'Atelier</a> — fabricación de libros y herramientas de investigación (EHESS)</sub>

<p>
🇫🇷 <a href="README.md">Français</a> ·
🇬🇧 <a href="README.en.md">English</a> ·
🇩🇪 <a href="README.de.md">Deutsch</a> ·
🇪🇸 <a href="README.es.md"><b>Español</b></a>
</p>

<p>
<a href="https://atelier.atechnologie.fr/"><img src="https://img.shields.io/badge/🌐_l'Atelier-atelier.atechnologie.fr-2d5016?style=for-the-badge" alt="Sitio l'Atelier" /></a>
<a href="https://github.com/Atelier-Recherche/ATDesiderata"><img src="https://img.shields.io/badge/📦_Repositorio-GitHub-181717?style=for-the-badge&logo=github" alt="Repositorio GitHub" /></a>
</p>

</div>

---

## 📸 Vista previa

| Arquitectura | Wishlist pública |
| :---: | :---: |
| <img src="readme-media/screen1.png" alt="Arquitectura" width="400" /> | <img src="readme-media/screen2.png" alt="Wishlist" width="400" /> |

---

## 📖 Acerca de

**Desiderata** convierte una carpeta de marcadores (libros, artículos, fichas) en una **wishlist interactiva** compartible: sin cuenta para consultar, acción «Me lo quedo», enriquecimiento ISBN / Open Library, import-export Zotero y chat efímero opcional.

Tres piezas: **extensión de navegador**, **plugin WordPress** y, opcionalmente, **API autónoma** con el mismo contrato REST.

> Estado: **MVP usable**. La lista más completa está en [README.md](README.md) / [README.en.md](README.en.md).

---

## ✨ Funciones actuales (resumen)

- Sincronización de marcadores con debounce  
- PWA pública con reserva / liberación  
- ISBN + Open Library, import/export Zotero  
- Chat efímero (polling servidor + WebRTC opcional)  
- Scripts de build/deploy O2switch  

---

## 🦊 Instalar la extensión Firefox

```powershell
.\build.ps1
# → dist/desiderata-firefox.xpi
```

**Carga temporal (desarrollo):** `about:debugging#/runtime/this-firefox` → **Cargar complemento temporal…** → `packages/extension/dist-firefox/manifest.json` (o el `.xpi`).

Firefox estable **no instala de forma permanente** un `.xpi` sin firmar con doble clic. Hace falta firma AMO o Nightly/ESR con comprobación de firmas desactivada.

**Chrome:** cargar sin empaquetar `packages/extension/dist-chrome`.

---

## 📦 Plugin WordPress

Subir `dist/desiderata.zip` o ejecutar `.\deploy.ps1`, activar, guardar enlaces permanentes, crear lista + token API en **Desiderata**, luego conectar la extensión.

---

## 🗺 Backend autónomo (opcional)

```powershell
.\deploy.ps1 -Standalone
```

Crear `config.php` desde `config.example.php`. Base API: `…/desiderata-api/v1`.

---

## 💻 Desarrollo

```powershell
.\build.ps1
```

---

## 🌐 Enlaces

- 🌐 [l’Atelier](https://atelier.atechnologie.fr/)
- 📦 [GitHub](https://github.com/Atelier-Recherche/ATDesiderata)
