# Changelog

## 3.0.0 (unveröffentlicht)

Das AddOn zieht zu [Friends Of REDAXO](https://github.com/FriendsOfREDAXO/filepond_uploader). Autoren: Friends Of REDAXO, KLXM Crossmedia GmbH, Thomas Skerbis.

### ⚠️ Breaking Changes
- **PHP 8.4** ist Mindestvoraussetzung.
- **Namespace `FriendsOfRedaxo\FilePondUploader`** für alle Klassen, APIs nach [REDAXO-Doku](https://redaxo.org/doku/5.x/api#namespace-registrierung) registriert. Die bisherigen Klassennamen (`filepond_helper`, `rex_api_filepond_*`, `filepond_alt_text_checker`, `filepond_ai_alt_generator`, `FriendsOfRedaxo\FilePond\FilePondMediaCleanup`, `FriendsOfRedaxo\FilePond\YcomAuthSettings`) bleiben als Aliase nutzbar.
- **KI nur noch über [AI Platform](https://github.com/FriendsOfREDAXO/ai_platform)**: Die eigenen Anbindungen an Gemini, Cloudflare Workers AI und Open WebUI samt Einstellungen sind entfernt. Benötigt wird ein AI-Platform-Profil vom Typ „Bildverständnis“, auswählbar unter Einstellungen → KI.
- **Eigene Upload-Widgets brauchen Sicherheitsattribute**: Alle APIs verlangen einen CSRF-Token, Frontend-Uploads zusätzlich eine signierte Kategorie. `Helper::widgetSecurityAttributes()` liefert beides; Backend-Seiten erhalten den Token automatisch, das YForm-Template setzt die Attribute selbst.
- **Entfernt**: Info-Center-Widget, „Bulk Resize“, die API-Funktionen `load`, `restore`, `cancel-upload` und `save_metadata`, die Session-Schalter `filepond_no_meta` und `filepond_title_required` (stattdessen `data-filepond-skip-meta` / `data-filepond-title-required`).

### 🔒 Sicherheit
- **Löschen fremder Medien**: Jeder berechtigte Aufrufer – auch Frontend-Gäste mit Token in der Session – konnte beliebige Mediendateien löschen, sofern sie in keinem FilePond-Feld steckten. Gelöscht werden dürfen jetzt nur eigene Uploads der Session, im Backend zusätzlich Dateien in Kategorien mit Rechten.
- **Pfade aus Client-Daten**: Die `fileId` von Chunk-Uploads ging ungeprüft in Dateipfade ein. Sie wird jetzt gegen das erzeugte Format geprüft und gehört der Session, die den Upload vorbereitet hat.
- **`load` lieferte beliebige Mediendateien** aus (am YCom-Medienschutz vorbei); die Funktion ist entfernt.
- **CSRF-Schutz** für Upload, Metadaten, KI und Alt-Text-Checker.
- **Kategorie und Feldgrenzen**: Backend-Uploads nur in Kategorien mit Rechten; im Frontend sind Kategorie, Dateitypen und Maximalgröße signiert und werden serverseitig durchgesetzt (vorher nur im Browser geprüft).
- **XSS** im Metadaten-Dialog (vorhandene Werte, Feldbezeichnungen) und im Alt-Text-Checker; Attribute im YForm-Template werden escaped.
- Dateityp wird immer am Inhalt bestimmt; YForm-Feldwerte und `filepond2email` akzeptieren nur vorhandene Mediendateien.
- KI-Funktionen nur für Backend-User.

### 🎉 Neue Features
- **Eigener Bildeditor**: Zuschneiden (frei oder mit festem Seitenverhältnis), Drehen um 90°, Spiegeln – im Metadaten-Dialog und bei verzögertem Upload direkt am Dateieintrag (über `filepond-plugin-image-edit`). Tastatur- und Touch-bedienbar, abschaltbar.
- **Einstellungen auf Reitern**: Upload, Bilder, Metadaten, Medienpool, KI, System.
- **`Helper::configAttributes()`** erzeugt alle `data-filepond-*`-Attribute aus den Einstellungen, einzelne Werte überschreibbar.
- **Abbrechen räumt auf**: Bereits übertragene Chunks werden sofort verworfen; Reste abgebrochener Uploads werden zusätzlich automatisch gelöscht.
- Ereignis `filepond:uploaded` am Input-Element nach jedem erfolgreichen Upload.
- MediaPlace-Upload mit KI-Vorschlag, Bildeditor und den Chunk-Einstellungen.
- Englische Übersetzung vervollständigt, alle sichtbaren Texte übersetzbar.

### 🐛 Bugfixes
- **Upload beim Absenden (YForm `delayed_upload` 2)**: Abgelehnte Dateien blockierten das Absenden kommentarlos, der Feldwert fehlte teils beim Speichern, und YForm konnte „speichern“ und „übernehmen“ nicht unterscheiden.
- **Alt-Text-Checker**: Speichern und KI schlugen fehl (CSRF-Token kam durch eine HTML-escapte URL nie an); das Recht `filepond_uploader[alt_checker]` fehlte in der Rollenverwaltung.
- **Frontend**: Widgets wurden auf Seiten mit jQuery nie initialisiert; APIs antworteten auf Seiten mit gesetztem 404-Status (YRewrite/URL-AddOn) mit 404.
- **Serverseitige Bildverarbeitung**: ImageMagick entfernte das Farbprofil und – ohne EXIF-Korrektur – auch die Orientierung (Bilder lagen danach gedreht); GD verlor die WebP-Transparenz und komprimierte JPEGs doppelt; ImageMagick wurde bei jedem Upload neu gesucht.
- Abbruch eines Uploads funktionierte nicht zuverlässig, die Chunk-Größe wurde je nach Quelle als MB oder Byte gelesen.
- Jeder normale Upload übertrug die Datei zweimal.
- `install.php` lief nur mit eingeloggtem Admin im Backend (nicht per Konsole/Deployment).
- YForm: Automatisches Löschen nutzte den Wert vor dem Speichern, Listensuche fand Dateien in Feldern mit mehreren Dateien nicht.
- Erwartbare Ablehnungen (z. B. falscher Token) landen nicht mehr als Fehler im Systemlog.

### 🔧 Technik
- FilePond 4.32.12, Plugins aktualisiert, `filepond-plugin-image-edit` 1.6.3 neu.
- rexstan Level 8 ohne Befunde.
- Nicht mehr benötigte Dateien, Assets und Sprachschlüssel entfernt.

## 2.11.0 (2026-10-02)

### 🎉 Neue Features
- **Gemini-Modelle live von Google statt fester Liste** (Folge zu #108/#114): Die Modellauswahl lädt die verfügbaren Modelle mit dem eingetragenen API-Key über `GET /v1beta/models` (neue Klasse `FriendsOfRedaxo\FilePond\GeminiModelCatalog`). Angezeigt werden nur Modelle, die Bilder beschreiben können (ohne Embedding, TTS, Live, Transkription, Bildgenerierung), neueste zuerst. Zwischenspeicher 24 Stunden je API-Key, Aktualisierung bei jedem „Verbindung testen“ – die Auswahl wird dabei ohne Neuladen aktualisiert. Ohne Key oder Verbindung greift die bisherige Standardliste.
- **Verbindungstest erkennt für das Projekt abgeschaltete Modelle**: Google listet z. B. die 2.5-Modelle weiter, neue Projekte bekommen aber 404. Der Test nennt das von Google empfohlene Ersatzmodell und wählt es in der Liste vor.
- Kein gespeichertes Modell bzw. ein Modell, das Google nicht mehr kennt: Es wird automatisch das neueste stabile Flash-Modell der Live-Liste verwendet.

### 🐛 Bugfixes
- `curl_close()`-Aufrufe in den KI-Providern entfernt: Unter PHP 8.5 erzeugten sie eine Deprecation-Meldung, die bei aktivem `display_errors` vor dem JSON landete und die Antwort für das JavaScript unlesbar machte (seit PHP 8.0 ohne Wirkung).

## 2.10.0 (2026-10-02)

### 🎉 Neue Features
- **Platzhalter `{filename}` im eigenen KI-Prompt** (#115): Dateinamen enthalten oft wichtigen Kontext. Bei frischen Uploads wird der ursprüngliche Dateiname verwendet, nicht der Name der temporären Datei.

### 🐛 Bugfixes
- **Erneutes Generieren liefert wieder neue Texte** (#111): Die Mehrsprachen-Buttons („AI ALT alle“) füllten nur leere Sprachfelder – waren alle befüllt, passierte beim nächsten Klick nichts. Jetzt füllt der erste Klick die Lücken, ein weiterer Klick erzeugt alle Sprachen neu. Bewusste Neuerzeugung umgeht den Ergebnis-Cache (`regenerate`), auch beim Einzelfeld-Button.
- **Upload bricht mit YRewrite „Startsprache gemäß Browser“ ab** (#112): Das Widget schickte Uploads an die Domain-Wurzel bzw. `<base href>` – also ans Frontend, auch im Backend. YRewrite leitete dort per 302 auf die Browsersprache um, der Upload bekam HTML statt JSON. Uploads gehen jetzt an die aktuelle Seite (Backend: `/redaxo/index.php`, Frontend: die aktuelle Artikel-URL).
- **Keine API-Aufrufe mehr auf jeder Backend-Seite** (#110): Die MetaInfo-Felder werden erst beim Öffnen des Metadaten-Dialogs geladen (einmal pro Seite statt Prefetch pro Widget), die KI-Button-Konfiguration kommt per `rex_view::setJsProperty()` mit der Seite (`rex.filepond_ai`). Die Aufrufe von `filepond_auto_metainfo` geben außerdem sofort die Session-Sperre frei (`session_write_close()`), statt nachfolgende Seitenaufrufe zu blockieren.

- **AI-ALT-Button vervielfachte sich endlos im Metadaten-Dialog** (#113, danke @alexwenz): Die Doppel-Prüfung suchte in `.form-group`, das Feld liegt im Dialog aber in `.simple-modal-form-group`.
- **Gemini-Modellliste aktualisiert** (#108, #114, danke @alexwenz): Von Google abgeschaltete Modelle werden automatisch durch ihren Nachfolger ersetzt.

### 🔒 Sicherheit
- **`rex_api_filepond_auto_metainfo` prüfte keine Anmeldung**: Die API ist öffentlich aufrufbar (`$published = true`), hatte aber keinerlei Berechtigungsprüfung – MetaInfo-Daten jeder Mediendatei ließen sich ohne Login lesen und per `save_metadata` überschreiben. Jetzt gilt dieselbe Prüfung wie beim Upload-Endpunkt (Backend-Login, API-Token oder YCom-Login). Fehlerantworten liefern zudem den korrekten HTTP-Status (vorher wurde z. B. 401/400 von `rex_response::sendJson()` überschrieben).

## 2.9.1 (2026-09-18)

### 🐛 Bugfixes
- **MediaPlace-Upload-Anbieter registrierte sich nie**: `assets/mediaplace_upload_provider.js` wartete auf `window.MP3`, MediaPlace's tatsächliches globales Objekt heißt aber `window.MP` – dadurch gab die Registrierung nach 10 Sekunden lautlos auf, FilePond übernahm den Upload-Button/Drag&Drop im MediaPlace-Overlay nie, und der eingebaute MediaPlace-Upload lief unbemerkt weiter.
- **Dark-Mode- und Z-Index-Abgleich mit MediaPlace griff nie**: dieselbe Namensverwechslung (`#mp3-overlay`/`.mp3-dark-mode` statt `#mp-overlay`/`.mp-dark-mode`) betraf auch den Metadaten-Dialog (`assets/filepond_modal.js`, `assets/filepond_metainfo_lang.css`) sowie die AI-Alt-Text-Buttons im eigenen JSON-Alt-Feld von MediaPlace (`assets/mediapool_ai.js`, u. a. `.mp3-alt-wrap`/`.mp3-lang-inputs`/`#mp3-detail`/`.mp3-metainfo-canvas-open` → `.mp-*`/`#mp-*`).

## 2.9.0 (2026-08-30)

### 🎉 Neue Features
- **MediaPlace Upload-Anbieter**: filepond_uploader registriert sich jetzt als Upload-Anbieter für [MediaPlace](https://github.com/FriendsOfREDAXO/mediaplace) (neuer Erweiterungspunkt `MEDIAPLACE_UPLOAD_PROVIDERS` dort). Ist "FilePond" in MediaPlace's Einstellungen als aktiver Upload-Anbieter gewählt, übernimmt FilePond's eigener Metadaten-Dialog den Upload-Button/Drag&Drop/Paste im MediaPlace-Overlay komplett – die Dateien werden direkt an die bereits bestehende Upload-Pipeline weitergereicht (inkl. Chunk-Upload, KI-Alt-Text-Vorschlag, Pflichtfelder), keine doppelte Logik. Rein optional: ohne installiertes/aktives MediaPlace bleibt alles unverändert.

### 🐛 Bugfixes
- **Dark-Mode-Harmonisierung mit MediaPlace**: Der Metadaten-Dialog sowie die darin eingebetteten AI-Alt-Text-Buttons reagierten bisher nur auf REDAXOs eigenes Backend-Theme (`body.rex-theme-dark`), nicht auf MediaPlace's unabhängigen eigenen Dark-Mode-Umschalter (`#mp3-overlay.mp3-dark-mode`) – bei "Backend hell, MediaPlace dunkel" wirkte der Dialog unpassend hell. Reagiert jetzt auf beide unabhängig voneinander.
- **Metadaten-Dialog wurde vom MediaPlace-Overlay verdeckt**: `#mp3-overlay` liegt mit `z-index:999999` über dem bisherigen `z-index:10000` des Dialogs – erschien dadurch unsichtbar hinter MediaPlace, sichtbar erst nach dessen Schließen. Auf `1000000` angehoben.

## 2.8.1 (2026-08-30)

### 🐛 Bugfixes
- **Icon der Medienpool-Unterseiten "Mehrfach-Upload" und "Alt-Text-Checker" korrigiert**: Das Icon wurde als rohes `<i class="fa-solid ...">`-Tag in den Seitentitel eingebettet, statt über `rex_be_page::setIcon()` gesetzt zu werden – dadurch fehlte es dort, wo REDAXO den Titel als reinen Text behandelt (z. B. Browser-Tab-Titel, Breadcrumb).

## 2.8.0 (2026-08-30)

### 🎉 Neue Features
- **MediaPlace-Integration für AI-Alt-Text**: Ist das Addon [MediaPlace](https://github.com/FriendsOfREDAXO/mediaplace) installiert und dort ein eigenes JSON-basiertes Alt-Text-Feld aktiv, hängt sich der Zauberbutton automatisch an dieses eigene Feld statt an das klassische `med_alt` — das eigene MediaPlace-Feld hat Vorrang. Rein optional: ohne installiertes/aktives MediaPlace bleibt das klassische Verhalten unverändert.

### ✨ Verbesserungen
- **Reaktive Button-Erkennung statt einmaligem Seiten-Scan**: Die Zauberbutton-Platzierung läuft jetzt über einen `MutationObserver` statt eines einmaligen Scans beim Seitenladen. Das behebt zugleich, dass der Button am klassischen `med_alt`-Feld bisher nicht erschien, wenn dieses Feld erst nachträglich per AJAX geladen wurde (z. B. in MediaPlace's nativer Metadaten-Maske).
- **Dateiname-Erkennung erweitert**: Neue Erkennung des aktuell geöffneten Mediums über MediaPlace's Detail-Panel bzw. dessen Metadaten-Masken-Trigger, zusätzlich zu den bisherigen klassischen Erkennungswegen.

## 2.7.0 (2026-08-10)

### 🎉 Neue Features
- **Datei-Ersetzen im Medienpool mit FilePond**: Auf der Medien-Detailseite steht jetzt ein eigenes FilePond-Panel bereit, um bestehende Dateien direkt zu ersetzen.
- **Chunk-Replace für große Dateien**: Auch beim Ersetzen nutzt FilePond den vorhandenen Chunk-Workflow und kann dadurch große Dateien zuverlässig austauschen.

### ✨ Verbesserungen
- **Automatischer Reload nach erfolgreichem Replace**: Nach dem Austausch springt die Detailseite sauber mit REDAXO-Erfolgsmeldung zurück.
- **Legacy-Dateidialog entfernt**: Der alte Core-Block „Datei austauschen“ wird auf der Detailseite ausgeblendet, sobald das FilePond-Replace-Panel aktiv ist.
- **Eigenes Setting für Detailseiten-Replace**: Die neue Funktion kann separat in den Medienpool-Einstellungen aktiviert oder deaktiviert werden.

## 2.6.1 (2026-06-04)

### 🐛 Bugfixes
- **Blocklist-Sprachen robuster normalisiert**: `ai_blocked_languages` verarbeitet jetzt auch Legacy-/manuelle Werte wie `EN` oder `de_DE` zuverlässig.
- **Cache-Key-Generierung gegen UTF-8-Probleme gehärtet**: Die AI-Cache-Hashbildung nutzt jetzt UTF-8-Substitution und einen sicheren Fallback, damit keine kollidierenden Keys durch fehlerhafte Encoding-Werte entstehen.
- **Einheitliche i18n-Fehlermeldung im Upload-Dialog**: Die JSON-Parse-Fehlermeldung der AI-Vorschläge nutzt konsistente Übersetzungsstrings (DE/EN) statt gemischter Hardcoded-Texte.

## 2.6.0 (2026-06-04)

### 🎉 Neue Features
- **AI-Result-Cache eingeführt**: Generierte Alt-Texte werden jetzt optional dateibasiert zwischengespeichert (konfigurierbare Gültigkeit in Stunden), sodass identische Anfragen keine unnötigen AI-Requests mehr auslösen.
- **Konfigurierbare Prompt-Profile**: Neue Profile `Barrierefrei (Standard)`, `Kurz / neutral` und `SEO-fokussiert` steuern den Stil der generierten Alt-Texte ohne Custom-Prompt.

### ✨ Verbesserungen
- **Empfehlung für mehrsprachige ALT-Felder dokumentiert**: Für ALT-Zielfelder mit mehreren Sprachen wird der MetaInfo-Typ `lang_text_all` empfohlen.
- **Mehrsprachige AI-Generierung beschleunigt**: Bei mehrsprachigen Zielfeldern wird die Bilderkennung jetzt bevorzugt in einem einzigen Request für alle benötigten Sprachen ausgeführt. Fehlende Einzelsprachen werden bei Bedarf per Fallback nachgezogen.
- **Fallbacksprache konfigurierbar**: Neue Einstellung `ai_fallback_language` (Default `en`) für den Fall, dass ein Modell einzelne Zielsprachen nicht liefert.
- **Negativliste für Modellsprachen**: Neue Einstellung `ai_blocked_languages` (z.B. `sl, cs, hr`), damit ausgewählte Sprachen nicht direkt vom Modell angefragt werden. Diese Sprachen werden stattdessen mit dem Text der Fallbacksprache befüllt.
- **Transparenter Fallback-Hinweis im Alt-Text-Checker**: Am Listenanfang wird jetzt angezeigt, welche Zielsprachen direkt übersprungen werden und stattdessen den Fallback-Text nutzen.
- **Neue AI-Einstellung für Bildgröße**: Die maximale Bildkante vor dem AI-Request ist jetzt konfigurierbar (`ai_max_image_dimension`, 256-2048 px). Standard bleibt 1024 px; kleinere Werte (z.B. 768) beschleunigen die Verarbeitung.
- **GD-Pfad bewusst eingeschränkt**: Wenn nur GD verfügbar ist, werden nur gängige Formate (`JPEG`, `PNG`, `GIF`) für die AI-Vorbereitung akzeptiert; Spezialformate benötigen Imagick oder CLI-Konvertierung.
- **Alt-Text-Checker UI neu ausbalanciert**: Die Dateiname-Spalte wurde in die Alt-Text-Zelle integriert (Dateiname + Meta oberhalb des Inputs), damit das Eingabefeld mehr Breite bekommt und lange Dateinamen die Zeilenstruktur nicht mehr zerziehen.

### 🐛 Bugfixes
- **AI-Textgenerierung füllt MetaInfo-Lang-Felder (z. B. `lang_textarea_all`) im Upload-Metadialog und auf der Medienpool-Detailseite**: Auf der Detailseite wird der Zauberbutton jetzt am Sprachen-Container des Felds platziert und schreibt die generierten Texte direkt in die einzelnen Sprach-Inputs (statt nur ins versteckte JSON-Feld). Im Upload-Metadialog werden bei mehrsprachigen Zielfeldern jetzt alle (leeren) Sprach-Tabs nacheinander befüllt – analog zum Verhalten im Alt-Text-Checker.
- **Medienpool-Detail: Mehrsprachen-Request-Parameter korrigiert**: Die Sprachliste wird jetzt korrekt als `languages[]` per POST übergeben, damit der Sammel-Request greift und nicht unnötig in Einzelsprachen-Fallbacks läuft.
- **Alt-Text-Checker auf Sammelrequest umgestellt**: Pro Bild wird die AI-Generierung für alle Sprachen in einem Request ausgeführt (inkl. Negativlisten-/Fallback-Handling), wodurch das erste sichtbare Eingabefeld wieder zuverlässig befüllt wird und keine teilweisen Einzelupdates mehr entstehen.
- **Sprachmapping erweitert (u.a. Slowenisch `sl`)**: Unbekannte Sprachcodes fallen im Prompt nicht mehr automatisch auf Deutsch zurück.
- **OpenWebUI-Konfigurationsprüfung korrigiert**: Die Verbindungstest-Logik erkennt OpenWebUI jetzt auch ohne API-Key als konfiguriert, wenn Base-URL und Modell gesetzt sind (analog zu lokalen OpenWebUI/Ollama-Setups).
- **Leerer Authorization-Header vermieden**: Beim OpenAI-kompatiblen Provider wird der `Authorization: Bearer ...` Header nur noch gesendet, wenn tatsächlich ein API-Key hinterlegt ist.
- **AI-Icon in Medien-Details besser sichtbar**: Der Zauberbutton für die Medienpool-Detailseite wurde kontrastreicher gestaltet (inkl. Light/Dark/Auto-Theme-Unterstützung).
- **AI-Bildvorverarbeitung robuster gemacht**: HEIC/HEIF/AVIF/WEBP werden jetzt bevorzugt über Imagick verarbeitet (mit CLI-Fallback über `magick`/`convert`/`sips`), um langsame oder fehleranfällige GD-Pfade zu vermeiden.
- **HEIC/HEIF in der AI-Erkennung stabilisiert**: Bilder mit uneinheitlicher MIME-Erkennung werden zusätzlich über die Dateiendung als Bild akzeptiert, damit nicht fälschlich „Keine Bilddatei“ zurückkommt.

## 2.5.1 (2026-06-01)

### ✨ Verbesserungen
- **YForm-Value erweitert**: Zusätzliche Optionen sind jetzt direkt im `filepond`-Value verfügbar (u.a. `chunk_enabled`, `chunk_size`, `alt_required`, `max_pixel`, `image_quality`, `client_resize`, `ai_enabled`, `ai_target_field`) statt nur über Template-/Config-Fallbacks.
- **YForm-Template priorisiert Value-Optionen**: Das Bootstrap-Template nutzt jetzt vorrangig die per Value gesetzten Optionen und fällt nur bei fehlenden Werten auf die Addon-Konfiguration zurück.
- **README/README.de Frontendeinbindung aktualisiert**: Frontend-Beispiele wurden auf benannte YForm-Optionen, optionalen Token-Fallback und die tatsächliche Auto-Initialisierung (`filepond:init` bei dynamischem Markup) angepasst.

### 🐛 Bugfixes
- **Doku-Konsistenz verbessert**: Veraltete Frontend-Hinweise und nicht mehr benötigte Sonderattribute in den Frontend-Beispielen wurden bereinigt.

## 2.5.0 (2026-05-31)

### ✨ Verbesserungen
- **AI-Bereiche getrennt steuerbar**: In den Einstellungen gibt es jetzt separate Schalter für den AI-Zauberbutton im Upload-Metadialog und auf der Medienpool-Detailseite.
- **Konfigurierbares Zielfeld auch im Medienpool**: Der AI-Button auf der Medienpool-Detailseite nutzt jetzt ebenfalls das konfigurierte Zielfeld (`ai_target_field`) inkl. Sprachvarianten.
- **ALT-Pflicht im Upload-Metadialog konfigurierbar**: Neue Einstellung `alt_required_default` zum Ein-/Ausschalten der Pflicht für `med_alt` bei Bildern.
- **Sinnvoller Default für ALT-Pflicht**: `alt_required_default` ist standardmäßig aktiviert, damit `med_alt` bei Bildern ohne zusätzliche Konfiguration weiterhin Pflicht bleibt.
- **Doku aktualisiert (DE/EN)**: README und Hinweise zu Pflichtfeldern wurden auf die getrennte Behandlung von `med_alt` angepasst.

### 🐛 Bugfixes
- **Medienpool-Detailseite Button-Anzeige**: Feldsuche und Seitenerkennung wurden robust gemacht, damit der AI-Button zuverlässig am richtigen Feld erscheint.
- **ALT-Pflicht respektiert Settings**: Die bisher harte JS-Pflichtvalidierung für `med_alt` wurde an die neue Konfiguration gebunden und ist bei deaktivierter ALT-Pflicht nicht mehr erzwungen.
- **Pflichtfeld-Hinweis präzisiert**: Der Hinweis zu `required_metadata_fields` nennt `med_alt` nicht mehr als Listenbeispiel, da die ALT-Pflicht separat konfiguriert wird.


## 2.4.1 (2026.05.04)

fixed https://github.com/KLXM/filepond_uploader/issues/102, danke @milorad-micko

## Version 2.4.0 (2026-04-27)

### 🎉 Neue Features
- **YCom-Medienberechtigungen beim Upload setzen** (optional): Auf der Upload-Seite erscheint für berechtigte Backend-User ein ausklappbares Panel, in dem `ycom_auth_type`, `ycom_group_type` und `ycom_groups` als Defaults für die laufende Backend-Sitzung gesetzt werden können. Jede neu hochgeladene Datei (inkl. Chunk-Upload) erhält die gewählten Werte automatisch in `rex_media`.
  - Voraussetzung: Plugin `ycom/media_auth` aktiv.
  - Per Schalter **„YCom-Medienberechtigungen beim Upload setzen“** in den Einstellungen aktivierbar (Standard: aus).
  - Eigene Backend-Permission `filepond_uploader[ycom_media_auth]` (Admins haben automatisch Zugriff).
  - Status-Badge im Panel-Header zeigt, ob aktuell „öffentlich“ oder „nur eingeloggte“ als Default greift; Reset-Button setzt die Sitzungs-Defaults zurück.
  - Gruppen-Felder werden nur eingeblendet, wenn `ycom/group` verfügbar ist und ein konkreter Gruppentyp gewählt wurde.

### 🐛 Bugfixes
- **Defaults landeten nicht in `rex_media`**: Beim Upload über den Medienpool oder die Upload-Seite werden die YCom-Auth-Defaults jetzt zusätzlich direkt in der Upload-FormData mitgeschickt (POST-Werte `ycom_auth_type`, `ycom_group_type`, `ycom_groups[]`). Der Server bevorzugt diese POST-Werte und greift nur als Fallback auf die Session zurück – damit greift das Feature auch dann zuverlässig, wenn die asynchrone Session-Synchronisation noch nicht durchgelaufen ist oder der Autoload-Cache nach Erstinstallation veraltet ist.
- **API-Endpoint defensiv registriert**: `?rex-api-call=filepond_ycom_auth` wird in `boot.php` jetzt explizit via `rex_api_function::register()` registriert, damit er auch ohne vorherigen Cache-Refresh sofort auflöst.
- **Diagnose-Logging**: `applyYcomMediaAuthDefaults()` schreibt informative Log-Einträge ins FilePond-Logfile, wenn Defaults bewusst übersprungen werden (Feature aus, kein Backend-Login, fehlende Permission) bzw. erfolgreich angewendet werden – inkl. Quelle (`POST` oder `SESSION`).

## Version 2.3.5 (2026-04-21)

### 🐛 Bugfixes (PHP 8.5 Kompatibilität)
- **`imagedestroy()` entfernt**: In `filepond_ai_alt_generator.php` wurden veraltete `imagedestroy()`-Aufrufe entfernt, die in PHP 8.5 Deprecated-Notices erzeugen und die JSON-API-Antwort zerstören.
- **`curl_close()` entfernt**: In `filepond_ai_provider_openai_compatible.php` wurden beide `curl_close()`-Aufrufe entfernt (ebenfalls PHP 8.5 deprecated).
- **`max_completion_tokens` statt `max_tokens`**: Der OpenAI-kompatible Provider sendet jetzt `max_completion_tokens` statt dem veralteten `max_tokens`, das neuere Modelle (z.B. gpt-5-mini) ablehnen.
- **`temperature` entfernt**: Der OpenAI-kompatible Provider sendet keinen expliziten `temperature`-Wert mehr – neuere Modelle akzeptieren nur den API-Default (1.0).

## Version 2.3.4 (2026-04-21)

### 🐛 Bugfixes
- **Metainfo-Feldbezeichnungen**: Im Metadaten-Modal werden jetzt die in MetaInfo konfigurierten Feldtitel korrekt angezeigt statt der rohen Spaltennamen (z.B. `med_copyright_link`). Ursache war ein Fallback in `getFieldTranslation()`, der bei unbekannten Feldern den Spaltennamen statt `null` zurückgab, wodurch `field.label` nie zum Einsatz kam.
- **MetaInfo-Labels aus DB**: Die API-Methode `getMetaInfoFields()` liest `name`, `title` und `type_label` jetzt in einer einzigen JOIN-Query statt in N+1 Einzelabfragen. Labels werden korrekt per `rex_i18n::msg()` übersetzt wenn sie mit `translate:` beginnen, andernfalls direkt aus der DB verwendet.
- **Humanisierter Fallback**: Felder ohne konfigurierten MetaInfo-Titel zeigen jetzt einen lesbaren Namen (z.B. `Copyright Link`) statt dem rohen Spaltennamen.

## Version 2.3.3 (2026-03-05)

### ✨ Verbesserungen
- **Multiupload als Medienpool-Unterseite**: Der FilePond-Upload kann optional als zusätzliche Unterseite "Multiupload" im Medienpool eingebunden werden (Einstellungen → Medienpool-Integration). Die Seite erscheint direkt nach der normalen Upload-Seite und erfordert die Berechtigung `filepond_uploader[upload]`.

## Version 2.3.1 - 2.3.2 (2026-02-27)

### 🐛 Bugfixes
- **OpenAI UX vereinfacht**: Bei OpenAI-kompatiblen Einstellungen ist die Base URL jetzt optional. Wenn das Feld leer bleibt, wird automatisch die offizielle OpenAI API verwendet.
- **Verbindungstest abgesichert**: Der Test-Button ist nur aktiv, wenn für den gewählten Provider bereits eine gespeicherte Konfiguration vorliegt.
- **Testergebnis sichtbar**: Erfolg-/Fehlermeldungen des Verbindungstests bleiben nun korrekt sichtbar und werden nicht mehr direkt überschrieben.
- **Vision-Modelle gefiltert**: Beim OpenAI-kompatiblen Verbindungstest werden nur noch visuell/vision-fähige Modelle in der Modellliste ausgegeben.
- **Dependencies aktualisiert**: NPM-Abhängigkeiten wurden aktualisiert und die FilePond-Assets neu gebaut (inkl. aktualisiertem `package-lock.json`).
- **Automatische Mediapool-Erweiterung**: Konfigurierte MIME-Types werden zur Laufzeit automatisch im Mediapool freigeschaltet – keine manuelle Pflege der `allowed_mime_types` mehr nötig.

## Version 2.3.0 (2026-02-25)

### 🎉 Neue Features
- **Automatische Mediapool-Erweiterung**: Konfigurierte MIME-Types werden zur Laufzeit automatisch im Mediapool freigeschaltet – keine manuelle Pflege der `allowed_mime_types` mehr nötig.
- **Dateitypen-Auswahl als Accordion**: Übersichtliche Auswahl der erlaubten Dateitypen in 8 Gruppen (Bilder, Dokumente, Archive, Video, Audio, Office, OpenDocument, Fonts) mit Badge-Zähler pro Gruppe.
- **Wildcard-Unterstützung**: `image/*`, `video/*`, `audio/*` deaktivieren automatisch die Einzel-Checkboxen der jeweiligen Gruppe.
- **Eigene MIME-Types**: Freitext-Feld für benutzerdefinierte MIME-Types oder Dateiendungen.
- Unterstützung für Wildcards (`image/*`, `video/*`) und Dateiendungen (`.pdf`, `.docx`) bei der automatischen Mediapool-Freischaltung.

## Version 2.2.4 (2026-02-15)

### 🐛 Bugfixes
- Blob-Bilder korrekt behandelt.

## Version 2.2.3 (2026-02-14)

### 🐛 Bugfixes
- Frontend-Upload Fix.
- Komma-Fehler behoben.

## Version 2.2.2 (2026-02-12)

### ✨ Verbesserungen
- Rexstan Level 8 Kompatibilität.
- PHPStan-Fixes und Code-Qualität verbessert.
- AltTextChecker.php aktualisiert.
- YForm Value-Typ Syntax-Fix.

## Version 2.2.1 (2026-02-10)

### 🐛 Bugfixes
- Syntax-Fehler behoben (doppelte schließende Klammer vor catch-Block).
- Fritz's Fehler korrigiert.

## Version 2.2.0 (2026-02-08)

### 🎉 Neue Features
- **AI-Provider Registry**: Erweiterbare Provider-Architektur für AI Alt-Text-Generierung.
- **OpenWebUI Support**: Neuer AI-Provider für OpenWebUI-Kompatibilität.
- YForm Value-Typ Verbesserungen.

## Version 2.0.6 (2026-01-30)

### 🎉 Neue Features
- **Optionale Accessibility-Statistiken** im Alt-Text-Checker.
- **AI-Generierung in Medienpool-Detailansicht**: AI Alt-Text direkt beim Bearbeiten einer Datei generieren.

### 🐛 Bugfixes
- Doppeltes Modal-Problem behoben.
- Fehlerhafter Fallback entfernt.
- Settings-Handling für Alt-Checker und Stats verbessert.
- Abhängigkeit von `med_description` entfernt.

## Version 2.0.5 (2026-01-28)

### ✨ Verbesserungen
- **Konfigurierbare AI-Token-Limits**.
- Bessere Upload-Performance und Workflow.
- Alt-Checks verbessert.

## Version 2.0.4 (2026-01-25)

### 🐛 Bugfixes
- Kritische Bugfixes für Bildverarbeitung.
- `form-horizontal` Klasse entfernt.
- Alt-Checker: Nur registrieren wenn `med_alt` Feld existiert.

## Version 2.0.3 (2026-01-22)

### 🐛 Bugfixes
- Alt-Text-Checker Kategorie-Filter korrigiert.
- Kritische Bugfixes für Bildverarbeitung.
- Clientseitige Bildverkleinerung standardmäßig deaktiviert.

## Version 2.0.2 (2026-01-20)

### 🎉 Neue Features
- **Bulk Resize Feature**: Nachträgliche Bildoptimierung für bestehende Medien.
- Performance-Optimierung für Shared Hosting.
- Verbesserte Fehlerbehandlung und Debugging.

## Version 2.0.1 (2026-01-15)

### 🎉 Neue Features
- **AI Alt-Text Generierung mit Google Gemini**: Automatische Alt-Text-Erzeugung für Bilder.
- **Gemini Modell-Auswahl**: 2.5 Flash als Standard.
- **Alt-Text-Checker für Barrierefreiheit**: Prüfung fehlender Alt-Texte.
- **Multilang-Unterstützung**: AI generiert Alt-Texte für alle Sprachen.
- SVG von AI-Generierung ausgeschlossen.
- Konfigurierbarer Sort-Order für Bulk Resize und Alt-Text-Checker.

### 🐛 Bugfixes
- `maxOutputTokens` erhöht, keine abgeschnittenen Sätze mehr.
- Bessere Fehlerdiagnose beim API-Test.
- `updatedate` und `updateuser` bleiben beim Resize/Alt-Update erhalten.

## Version 1.15.0-beta (2025-12-xx)

### 🎉 Neue Features
- Clientseitiges Resize.
- Bulk Resize Feature für nachträgliche Bildoptimierung.

## Version 1.13.3 (2025-12-xx)

### 🎉 Neue Features
- **Info Center Widget**: FilePond Upload-Widget für Info Center AddOn.

## Version 1.13.2 (2025-12-xx)

### 🐛 Bugfixes
- OutputFilter-Fix.

## Version 1.13.1 (2025-12-xx)

### ✨ Verbesserungen
- CSS-Refactoring: Inline-Styles durch CSS-Klassen mit Dark-Theme-Support ersetzt.
- CSS-Variablen für bessere Wartbarkeit.

## Version 1.13.0 (2025-11-xx)

### 🎉 Neue Features
- **Umfassende Media Widget Integration** für Medienpool.
- **Mehrsprachige MetaInfo-Integration** mit verbesserter UI.
- Video-Vorschau in MetaInfo-Dialogen.

### 🔒 Security
- Kritische Sicherheitslücken behoben (XSS und Injection).
- Robuste Fehlerbehandlung für EXIF-Orientierungskorrektur.

## Version 1.12.1 (2025-11-xx)

### 🐛 Bugfixes
- Memory-Leaks bei Bild-Rotation behoben.
- Fehler-Logging für `imagerotate()` Fehler.
- EXIF-Orientierungskorrektur standardmäßig aktiviert.

## Version 1.12.0 (2025-10-xx)

### 🎉 Neue Features
- **EXIF-Orientierungskorrektur**: Automatische Korrektur der Bildausrichtung.

## Version 1.11.3 (2025-10-xx)

### 🐛 Bugfixes
- Unterstützung für Dateiendungen im `allowed_types` Parameter.

## Version 1.11.2 (2025-10-xx)

### 🐛 Bugfixes
- `delayed-type=1` als Standard wenn Delayed Upload aktiviert.
- Fehlende Übersetzung für Upload-Button.
- Fehlender Upload-Button im Delayed-Upload-Modus für Medienpool.

## Version 1.11.1 (2025-09-xx)

### 🐛 Bugfixes
- Error-Response Status auf HTTP_FORBIDDEN geändert.

## Version 1.11.0 (2025-09-xx)

### 🎉 Neue Features
- **Delayed Upload**: Upload erst beim Formular-Submit (Beitrag von @godsdog).

## Version 1.10.0 (2025-08-xx)

### ✨ Verbesserungen
- FilePond-Thumbnail-Gradients durch einfache Borders ersetzt.
- Glow-Animation für FilePond-Borders.
- Retry-Button-Sichtbarkeit verbessert.

## Version 1.9.0 (2025-07-xx)

### ✨ Verbesserungen
- UI- und Optik-Verbesserungen.
- Cancel-Fix.

## Version 1.8.0 (2025-06-xx)

### 🎉 Neue Features
- **Dekorative Bilder**: Möglichkeit, Bilder als dekorativ zu markieren (kein Alt-Text nötig).
- **Neuer Upload-Modus**.
- Direkte `skipMeta`-Parameter-Übergabe als Alternative zur Session-Variable.

## Version 1.7.4 (2025-05-xx)

### 🐛 Bugfixes
- API-Fixes.

## Version 1.7.3 (2025-05-xx)

### 🎉 Neue Features
- **Chunk-Uploads**: Große Dateien werden in Teilen hochgeladen.

## Version 1.7.1 (2025-04-xx)

### 🐛 Bugfixes
- Dateinamen-Fix.
- FilePond Value wird aus dem E-Mail-Valuepool gezogen (Beitrag von @dtpop).

## Version 1.7.0 (2025-04-xx)

### 🎉 Neue Features
- **YForm Action für E-Mail-Versand**: Hochgeladene Dateien als E-Mail-Anhang.

## Version 1.5.0 (2025-03-xx)

### 🎉 Neue Features
- Chunk-Upload-Support.
- Skip-Meta-Modus.
- Diverses UI-Refactoring.

## Version 1.4.1 (2025-02-xx)

### 🐛 Bugfixes
- Widget-Verbesserungen.
- YForm-Template-Fixes.

## Version 1.2.1 (2025-01-xx)

### ✨ Verbesserungen
- API und Meta per Session aktivieren/deaktivieren.

## Version 1.0.0 (2024-xx-xx)

### 🎉 Initiales Release
- FilePond Upload-Widget für REDAXO.
- YForm Value-Typ `filepond`.
- Medienpool-Integration mit MetaInfo-Modal.
- Dark-Mode-Unterstützung.
- Lokale Assets (DSGVO-konform, kein CDN).
- Konfigurierbare Dateitypen, Größenlimits und Kategorien.
