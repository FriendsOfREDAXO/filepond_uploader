# FilePond Uploader für REDAXO

Datei-Uploader für den REDAXO-Medienpool auf Basis von [FilePond](https://pqina.nl/filepond/): Chunk-Upload für große Dateien, Metadaten-Dialog mit MetaInfo-Feldern, eigener Bildeditor, KI-Alt-Texte über [AI Platform](https://github.com/FriendsOfREDAXO/ai_platform), YForm-Feldtyp, Frontend-Einsatz und MediaPlace-Anbindung.

![Screenshot](https://github.com/FriendsOfREDAXO/filepond_uploader/blob/assets/screenshot.png?raw=true)

Alternative: [uppy](https://github.com/FriendsOfREDAXO/uppy)

## Inhalt

- [Funktionen](#funktionen)
- [Voraussetzungen und Installation](#voraussetzungen-und-installation)
- [Update von 2.x](#update-von-2x)
- [Einstellungen](#einstellungen)
- [Im Backend](#im-backend)
- [YForm-Feldtyp](#yform-feldtyp)
- [In Modulen](#in-modulen)
- [Im Frontend](#im-frontend)
- [Bildeditor](#bildeditor)
- [KI-Alt-Texte](#ki-alt-texte)
- [Alt-Text-Checker](#alt-text-checker)
- [Metadaten](#metadaten)
- [Data-Attribute](#data-attribute)
- [Sicherheit](#sicherheit)
- [PHP-API und Klassen](#php-api-und-klassen)
- [JavaScript](#javascript)
- [Darstellung anpassen](#darstellung-anpassen)
- [Wartung](#wartung)
- [Credits](#credits)

## Funktionen

- **Chunk-Upload:** große Dateien in Teilen (Standard 5 MB), mit Fortschrittsanzeige und Abbruch; Reste abgebrochener Uploads werden automatisch entfernt.
- **Metadaten-Dialog:** Titel, Alt-Text, Copyright und alle weiteren MetaInfo-Felder des Medienpools, auch mehrsprachig (MetaInfo Lang Fields). Pflichtfelder und dekorative Bilder.
- **Bildeditor:** Zuschneiden mit freiem oder festem Seitenverhältnis, Drehen in 90°-Schritten, Spiegeln – vor dem Upload.
- **Bildoptimierung:** Verkleinern im Browser oder auf dem Server (ImageMagick oder GD), EXIF-Orientierung, Farbprofile bleiben erhalten.
- **KI-Alt-Texte** über ein Bildverständnis-Profil des AddOns AI Platform, ein- und mehrsprachig.
- **Alt-Text-Checker** im Medienpool: fehlende Alt-Texte finden, direkt bearbeiten, per KI erzeugen.
- **YForm-Feldtyp** `filepond` inkl. automatischem Löschen nicht mehr verwendeter Dateien und Aktion `filepond2email`.
- **Medienpool:** eigene Upload-Seite, optional als Ersatz der Medienpool-Upload-Seite, Datei ersetzen auf der Detailseite, Upload-Anbieter für MediaPlace.
- **Frontend:** für YCom-User oder Gäste (API-Token in der Session), mit signierter Zielkategorie und Feldgrenzen.

## Voraussetzungen und Installation

- REDAXO ab 5.17.1, PHP ab 8.4
- AddOns: `mediapool`, `metainfo`, `yform` (ab 4.0)
- Optional: `ai_platform` (KI-Alt-Texte), `mediaplace`, `ycom`, `metainfo_lang_fields`

Installation über den REDAXO-Installer. Dabei werden die MetaInfo-Felder `med_alt` (Alternativtext) und `med_copyright` angelegt, falls sie fehlen, und ein API-Token erzeugt.

## Update von 2.x

3.0.0 bringt Änderungen, die eigene Erweiterungen betreffen können:

- **Namespace** `FriendsOfRedaxo\FilePondUploader`. Die bisherigen Klassennamen (`filepond_helper`, `rex_api_filepond_*`, `filepond_alt_text_checker`, `filepond_ai_alt_generator`, `FriendsOfRedaxo\FilePond\FilePondMediaCleanup`, …) funktionieren als Aliase weiter.
- **KI nur noch über AI Platform.** Die eigenen Anbindungen für Gemini, Cloudflare und Open WebUI sind entfernt, ebenso deren Einstellungen. In AI Platform ein Profil vom Typ „Bildverständnis“ anlegen und in den FilePond-Einstellungen auswählen.
- **Eigene Widgets brauchen Sicherheitsattribute:** Jede Anfrage braucht einen CSRF-Token; Frontend-Uploads zusätzlich eine signierte Kategorie. Für eigene Widgets `Helper::widgetSecurityAttributes()` ausgeben (siehe [Im Frontend](#im-frontend)). Backend-Seiten erhalten den Token automatisch.
- **Entfernt:** Info-Center-Widget, „Bulk Resize“, die API-Funktionen `load`, `restore`, `cancel-upload` und `save_metadata`, die Session-Schalter `filepond_no_meta` und `filepond_title_required` (stattdessen `data-filepond-skip-meta` bzw. `data-filepond-title-required`).
- **Einstellungen** sind auf Reiter verteilt. Alte Werte bleiben erhalten; nicht mehr verwendete Schlüssel entfernt das Update.

Alle Änderungen stehen im [CHANGELOG](CHANGELOG.md).

## Einstellungen

**FilePond Uploader → Einstellungen** (nur Admins):

| Reiter | Inhalt |
| --- | --- |
| Upload | Anzahl und Größe, verzögerter Upload, Standardkategorie, Sprache, Pflichtfelder, Chunk-Upload, erlaubte Dateitypen |
| Bilder | maximale Pixelgröße, Qualität, EXIF-Orientierung, Verkleinern im Browser oder auf dem Server, Bildeditor |
| Metadaten | Dialog immer anzeigen bzw. überspringen, zusätzliche Pflichtfelder, ausgeblendete Felder |
| Medienpool | Löschen ungenutzter Dateien aus YForm-Feldern, Medienpool-Upload ersetzen, Datei ersetzen, Multiupload-Unterseite, Alt-Text-Checker, YCom-Medienschutz |
| KI | AI-Platform-Profil, Einsatzorte, Zielfeld, Sprachen, Prompt, Ergebnis-Cache |
| System | API-Token, Debug-Log, temporäre Dateien aufräumen |

## Im Backend

- **FilePond Uploader → Upload:** Upload in eine wählbare Kategorie (nur Kategorien mit Rechten).
- **Medienpool:** wahlweise ersetzt FilePond die Upload-Seite des Medienpools oder erscheint als zusätzliche Unterseite „Multiupload“.
- **Datei ersetzen:** Auf der Detailseite einer Mediendatei lässt sich die Datei austauschen; Dateiname und Metadaten bleiben.
- **Medien-Widgets:** Aus `REX_MEDIA`/`REX_MEDIALIST` heraus geöffnet, lassen sich hochgeladene Dateien direkt übernehmen.
- **MediaPlace:** In den MediaPlace-Einstellungen „FilePond“ als Upload-Anbieter wählen; dann laufen Upload-Button, Drag & Drop und Einfügen über den FilePond-Dialog (inkl. Chunk-Upload, Bildeditor, KI-Vorschlag). Recht für Nicht-Admins: `filepond_uploader[mediaplace_upload]`.

Rechte: `filepond_uploader[upload]` (Upload-Seite), `filepond_uploader[alt_checker]`, `filepond_uploader[mediaplace_upload]`, `filepond_uploader[ycom_media_auth]`. Hochgeladen werden darf nur in Medienkategorien, für die der User Rechte hat.

## YForm-Feldtyp

```php
$yform->setValueField('filepond', [
    'name' => 'bilder',
    'label' => 'Bildergalerie',
    'category' => 1,
    'allowed_types' => 'image/*,application/pdf',
    'allowed_filesize' => 10,
    'allowed_max_files' => 5,
    'required' => 1,
    'empty_value' => 'Bitte mindestens eine Datei auswählen.',
    'delayed_upload' => 0,
]);
```

Pipe-Notation: `filepond|name|label|category|allowed_types|allowed_filesize|allowed_max_files|required|notice|empty_value|skip_meta|chunk_enabled|chunk_size|delayed_upload|title_required|alt_required|max_pixel|image_quality|client_resize|ai_enabled|ai_target_field`

Leere Optionen übernehmen die globalen Einstellungen. Gespeichert werden die Dateinamen kommagetrennt.

| `delayed_upload` | Verhalten |
| --- | --- |
| `0` | Upload sofort nach der Auswahl |
| `1` | Upload über einen eigenen Button |
| `2` | Upload beim Absenden des Formulars, danach wird das Formular gesendet |

Abgelehnte Dateien (Typ, Größe) blockieren das Absenden mit einem Hinweis am Feld, bis sie entfernt sind.

**Typen und Größe** werden auch serverseitig geprüft: Das Feld signiert seine Grenzen, der Upload-Endpunkt lehnt abweichende Dateien ab.

**Ungenutzte Dateien löschen:** Ist „Automatisches Löschen“ aktiv, werden Dateien, die beim Speichern aus dem Feld entfernt wurden, aus dem Medienpool gelöscht – sofern sie nirgends sonst verwendet werden.

**Dateien per E-Mail versenden:** Die Aktion hängt die Dateien eines Feldes an die YForm-E-Mail an.

```php
$yform->setActionField('filepond2email', ['bilder']);
// Pipe: action|filepond2email|bilder
```

## In Modulen

Eingabe:

```php
<?php use FriendsOfRedaxo\FilePondUploader\Helper; ?>
<input type="hidden" name="REX_INPUT_VALUE[1]" value="REX_VALUE[1]"
    data-widget="filepond"
    data-filepond-cat="1"
    <?= Helper::configAttributes(['types' => 'image/*', 'maxfiles' => 5]) ?>
>
```

`Helper::configAttributes()` liefert alle `data-filepond-*`-Attribute aus den Einstellungen; einzelne Werte lassen sich überschreiben. Im Backend werden Skripte, Styles und CSRF-Token automatisch geladen.

Ausgabe:

```php
<?php
foreach (array_filter(explode(',', 'REX_VALUE[1]')) as $filename) {
    $media = rex_media::get($filename);
    if (null !== $media) {
        echo '<img src="' . $media->getUrl() . '" alt="' . rex_escape((string) $media->getValue('med_alt')) . '">';
    }
}
```

## Im Frontend

Uploads aus dem Frontend sind erlaubt für eingeloggte YCom-User oder – für Gäste – wenn der API-Token in der Session liegt. Der Token wird nie im HTML ausgegeben.

```php
<?php
use FriendsOfRedaxo\FilePondUploader\Config;
use FriendsOfRedaxo\FilePondUploader\Helper;

rex_login::startSession();
rex_set_session('filepond_token', Config::string('api_token')); // nur für Gäste nötig

$categoryId = 3;
$types = 'image/*,application/pdf';
$maxSizeMb = 10;

echo Helper::getStyles();
?>
<form method="post">
    <input type="hidden" name="dateien" value=""
        data-widget="filepond"
        data-filepond-cat="<?= $categoryId ?>"
        <?= Helper::configAttributes(['types' => $types, 'maxsize' => $maxSizeMb]) ?>
        <?= Helper::widgetSecurityAttributes($categoryId, $types, $maxSizeMb) ?>
    >
</form>
<?= Helper::getScripts() ?>
```

`widgetSecurityAttributes()` gibt den CSRF-Token und die Signatur über Kategorie, Typen und Größe aus. Die Werte müssen zu `data-filepond-cat`, `types` und `maxsize` passen; der Server übernimmt nur signierte Werte. Ein vollständiges Beispiel liegt in `demo/frontend_demo.php`.

In YForm-Formularen im Frontend setzt der Feldtyp `filepond` (Template `bootstrap`) alle Attribute selbst; `getStyles()`/`getScripts()` und – für Gäste – den Token in der Session wie oben ergänzen. KI-Vorschläge stehen im Frontend nicht zur Verfügung.

Die Upload-Anfragen gehen an die aktuelle Seiten-URL, REDAXO verarbeitet `rex-api-call` dort.

## Bildeditor

Für JPEG, PNG und WebP: Zuschneiden (frei, Original, 1:1, 4:3, 3:2, 16:9 und Hochformate), Drehen um 90°, horizontal und vertikal spiegeln, Zurücksetzen. Bedienbar per Maus, Touch und Tastatur (Pfeiltasten verschieben, Umschalt + Pfeiltasten ändern die Größe, Enter übernimmt, Escape bricht ab).

- Im **Metadaten-Dialog** über „Bild bearbeiten“ unter der Vorschau – in allen Upload-Modi.
- Bei **verzögertem Upload** zusätzlich direkt am Dateieintrag (über `filepond-plugin-image-edit`); Vorschau und Upload berücksichtigen den Zuschnitt.

Abschalten: Einstellungen → Bilder → Bildeditor, oder pro Widget `data-filepond-image-editor="false"`.

## KI-Alt-Texte

1. AddOn [AI Platform](https://github.com/FriendsOfREDAXO/ai_platform) installieren und ein Profil vom Typ **Bildverständnis** anlegen (z. B. ein lokales Ollama-Modell oder ein Cloud-Anbieter).
2. **Einstellungen → KI:** KI-Alt-Texte aktivieren und das Profil wählen (ohne Auswahl gilt das Standardprofil von AI Platform).
3. Einsatzorte wählen: Metadaten-Dialog, Medienpool-Detailseite, Alt-Text-Checker.

Für mehrsprachige Alt-Felder erzeugt ein Klick alle Sprachen. Sprachen, die das Modell schlecht beherrscht, lassen sich sperren; sie erhalten den Text der Fallback-Sprache. Ergebnisse werden je Bild und Profil zwischengespeichert, „neu erzeugen“ umgeht den Cache.

Eigener Prompt mit Platzhaltern:

| Platzhalter | Ersetzt durch |
| --- | --- |
| `{language}` | Sprachname, z. B. „Deutsch“ |
| `{lang}` | Sprachcode, z. B. `de` |
| `{filename}` | ursprünglicher Dateiname |

Ohne eigenen Prompt gilt das gewählte Prompt-Profil (barrierefrei, neutral, SEO). Die KI-Funktionen stehen nur Backend-Usern zur Verfügung.

## Alt-Text-Checker

**Medienpool → Alt-Text-Checker** (Admins und Recht `filepond_uploader[alt_checker]`):

- Statistik über Bilder mit und ohne Alt-Text
- Alt-Texte direkt in der Liste eingeben, auch mehrsprachig; Enter speichert, „Alle speichern“ speichert alle Änderungen
- Bilder als dekorativ markieren (zählen als erledigt)
- Filter nach Dateiname und Kategorie, große Vorschau
- KI-Vorschläge je Bild oder für alle sichtbaren Bilder

Voraussetzung ist das MetaInfo-Feld `med_alt`.

## Metadaten

Der Dialog zeigt die MetaInfo-Felder des Medienpools, sortiert nach `title`, `med_title_lang`, `med_alt`, `med_copyright`, `med_description`, dann alle weiteren. Mehrsprachige Felder (MetaInfo Lang Fields) erscheinen mit Sprach-Reitern.

- `title`: optional Pflichtfeld (Einstellung oder `data-filepond-title-required`)
- `med_title_lang`: immer Pflichtfeld
- `med_alt`: bei Bildern standardmäßig Pflichtfeld, alternativ „dekoratives Bild“

Weitere Pflicht- und ausgeblendete Felder lassen sich unter Einstellungen → Metadaten festlegen.

## Data-Attribute

| Attribut | Bedeutung | Standard |
| --- | --- | --- |
| `data-widget="filepond"` | macht ein `<input type="hidden">` zum Upload-Feld | – |
| `data-filepond-cat` | Zielkategorie | `0` |
| `data-filepond-types` | erlaubte Typen, MIME oder Endung, z. B. `image/*,.pdf` | Einstellung |
| `data-filepond-maxfiles` | maximale Anzahl | Einstellung |
| `data-filepond-maxsize` | maximale Größe in MB | Einstellung |
| `data-filepond-lang` | `de_de` oder `en_gb` | Sprache des Users |
| `data-filepond-skip-meta` | Metadaten-Dialog überspringen | `false` |
| `data-filepond-title-required` | Titel ist Pflicht | Einstellung |
| `data-filepond-alt-required` | Alt-Text bei Bildern ist Pflicht | Einstellung |
| `data-filepond-chunk-enabled` | Chunk-Upload | Einstellung |
| `data-filepond-chunk-size` | Chunk-Größe in Byte (Werte bis 1024 gelten als MB) | 5 MB |
| `data-filepond-delayed-upload` | verzögerter Upload | `false` |
| `data-filepond-delayed-type` | `1` Button, `2` beim Absenden | `1` |
| `data-filepond-client-resize` | im Browser verkleinern | Einstellung |
| `data-filepond-max-pixel` | maximale Kantenlänge | Einstellung |
| `data-filepond-image-quality` | JPEG/WebP-Qualität 10–100 | Einstellung |
| `data-filepond-image-editor` | Bildeditor | Einstellung |
| `data-filepond-ai-enabled` | KI-Vorschlag im Dialog (nur Backend) | Einstellung |
| `data-filepond-ai-target-field` | Zielfeld des KI-Vorschlags | `med_alt` |
| `data-filepond-media-url` | URL des Medienordners | `/media/` |

Sicherheitsattribute (`data-filepond-csrf`, `data-filepond-cat-sig`, `data-filepond-policy-*`) erzeugt `Helper::widgetSecurityAttributes()`.

## Sicherheit

- Jede Anfrage braucht den CSRF-Token des AddOns (Backend automatisch, Frontend über `widgetSecurityAttributes()`).
- **Backend:** Upload nur in Kategorien mit Rechten, Löschen nur eigener Uploads oder mit Kategorierecht.
- **Frontend:** nur YCom-User oder Session mit API-Token; Kategorie, Typen und Größe sind signiert und werden serverseitig geprüft; löschen lassen sich nur die eigenen Uploads der Session.
- Dateityp wird am Inhalt geprüft (nicht an Endung oder Browserangabe), dazu die Medienpool-Regeln für erlaubte Endungen.
- Chunks und Metadaten eines Uploads gehören der Session, die ihn vorbereitet hat.
- **Externe Clients** ohne Session authentifizieren sich mit dem Parameter `api_token` (Einstellungen → System). Den Token wie ein Passwort behandeln.

## PHP-API und Klassen

```php
use FriendsOfRedaxo\FilePondUploader\Helper;

Helper::getStyles();                 // Frontend: <link>-Tags, Backend: per rex_view
Helper::getScripts();                // Frontend: <script>-Tags, Backend: per rex_view
Helper::configAttributes([...]);     // data-filepond-* aus den Einstellungen
Helper::widgetSecurityAttributes($categoryId, $types, $maxSizeMb);
```

| Klasse | Zweck | Alias bis 2.x |
| --- | --- | --- |
| `FriendsOfRedaxo\FilePondUploader\Helper` | Assets und Widget-Attribute | `filepond_helper` |
| `…\Config` | Einstellungen lesen | – |
| `…\MediaCleanup` | Verwendungsprüfung, Löschen ungenutzter Dateien | `FriendsOfRedaxo\FilePond\FilePondMediaCleanup` |
| `…\AltTextChecker` | Alt-Text-Auswertung | `filepond_alt_text_checker` |
| `…\Ai\AltTextGenerator` | KI-Alt-Texte über AI Platform | `filepond_ai_alt_generator` |
| `…\Api\Upload` | API `filepond_uploader` | `rex_api_filepond_uploader` |
| `…\Api\AutoMetainfo`, `…\Api\AiGenerate`, `…\Api\AltChecker`, `…\Api\YcomAuth` | weitere APIs | `rex_api_filepond_*` |

Die APIs sind nach [REDAXO-Doku](https://redaxo.org/doku/5.x/api#namespace-registrierung) registriert (`rex-api-call=filepond_uploader` usw.).

Extension Point `MEDIA_IS_IN_USE`: Dateien, die in einem FilePond-YForm-Feld stecken, gelten als verwendet und lassen sich im Medienpool nicht löschen.

## JavaScript

```js
// Nach jedem erfolgreichen Upload, am Input-Element
document.querySelector('#mein-feld').addEventListener('filepond:uploaded', (event) => {
    console.log(event.detail.filename);
});

// FilePond-Instanz eines Feldes
const pond = document.querySelector('#mein-feld').pondInstance;
pond.on('processfile', (error, file) => { /* … */ });
```

Widgets werden beim Laden der Seite initialisiert, im Backend zusätzlich bei `rex:ready`. Für nachträglich eingefügte Widgets: `document.dispatchEvent(new Event('filepond:init'))`.

## Darstellung anpassen

Die Farben laufen über CSS-Variablen in `assets/filepond_widget.css` (`--fp-primary`, `--fp-border`, `--fp-background`, …) und passen sich dem Dark Mode des Backends an. Eigene Werte im Projekt-CSS überschreiben:

```css
:root {
    --fp-primary: #0b6e4f;
    --fp-primary-hover: #095c42;
}
.filepond-upload-btn { border-radius: 0; }
```

## Wartung

Reste abgebrochener Uploads (Chunks, Metadaten) werden automatisch nach sechs Stunden gelöscht. Unter **Einstellungen → System** lassen sie sich sofort aufräumen; dort lässt sich auch der API-Token neu erzeugen und ein Debug-Log aktivieren (zusätzlich zum Debug-Modus von REDAXO).

## Credits

- **Friends Of REDAXO** – [github.com/FriendsOfREDAXO](https://github.com/FriendsOfREDAXO)
- **KLXM Crossmedia GmbH** – [klxm.de](https://klxm.de)
- **Thomas Skerbis** – [github.com/skerbis](https://github.com/skerbis)
- FilePond von [PQINA](https://pqina.nl/filepond/), MIT-Lizenz

Lizenz: MIT. Fehler und Wünsche bitte als [Issue](https://github.com/FriendsOfREDAXO/filepond_uploader/issues).
