# FilePond Uploader for REDAXO

File uploader for the REDAXO media pool based on [FilePond](https://pqina.nl/filepond/): chunked upload for large files, metadata dialog with meta info fields, built-in image editor, AI alt texts via [AI Platform](https://github.com/FriendsOfREDAXO/ai_platform), YForm field type, frontend use and MediaPlace integration.

![Screenshot](https://github.com/FriendsOfREDAXO/filepond_uploader/blob/assets/screenshot.png?raw=true)

German documentation: [README.de.md](README.de.md) · Alternative: [uppy](https://github.com/FriendsOfREDAXO/uppy)

## Contents

- [Features](#features)
- [Requirements and installation](#requirements-and-installation)
- [Upgrading from 2.x](#upgrading-from-2x)
- [Settings](#settings)
- [In the backend](#in-the-backend)
- [YForm field type](#yform-field-type)
- [In modules](#in-modules)
- [In the frontend](#in-the-frontend)
- [Image editor](#image-editor)
- [AI alt texts](#ai-alt-texts)
- [Alt text checker](#alt-text-checker)
- [Metadata](#metadata)
- [Data attributes](#data-attributes)
- [Security](#security)
- [PHP API and classes](#php-api-and-classes)
- [JavaScript](#javascript)
- [Styling](#styling)
- [Maintenance](#maintenance)
- [Credits](#credits)

## Features

- **Chunked upload:** large files in parts (default 5 MB), with progress and cancel; leftovers of cancelled uploads are removed automatically.
- **Metadata dialog:** title, alt text, copyright and all other media pool meta info fields, also multilingual (MetaInfo Lang Fields). Required fields and decorative images.
- **Image editor:** crop with free or fixed aspect ratio, rotate in 90° steps, flip – before uploading.
- **Image optimization:** resize in the browser or on the server (ImageMagick or GD), EXIF orientation, colour profiles are kept.
- **AI alt texts** via an image understanding profile of the AI Platform addon, single and multilingual.
- **Alt text checker** in the media pool: find missing alt texts, edit them inline, generate them with AI.
- **YForm field type** `filepond`, including automatic deletion of files that are no longer used, and the `filepond2email` action.
- **Media pool:** own upload page, optionally replacing the media pool upload page, replace files on the detail page, upload provider for MediaPlace.
- **Frontend:** for YCom users or guests (API token in the session), with signed target category and field limits.

## Requirements and installation

- REDAXO 5.17.1 or later, PHP 8.4 or later
- Addons: `mediapool`, `metainfo`, `yform` (4.0 or later)
- Optional: `ai_platform` (AI alt texts), `mediaplace`, `ycom`, `metainfo_lang_fields`

Install via the REDAXO installer. Installation creates the meta info fields `med_alt` (alternative text) and `med_copyright` if they are missing, and generates an API token.

## Upgrading from 2.x

3.0.0 contains changes that can affect your own extensions:

- **Namespace** `FriendsOfRedaxo\FilePondUploader`. The old class names (`filepond_helper`, `rex_api_filepond_*`, `filepond_alt_text_checker`, `filepond_ai_alt_generator`, `FriendsOfRedaxo\FilePond\FilePondMediaCleanup`, …) keep working as aliases.
- **AI only via AI Platform.** The built-in connections for Gemini, Cloudflare and Open WebUI are removed, together with their settings. Create a profile of type "image understanding" in AI Platform and select it in the FilePond settings.
- **Custom widgets need security attributes:** every request needs a CSRF token, frontend uploads additionally need a signed category. Output `Helper::widgetSecurityAttributes()` in custom widgets (see [In the frontend](#in-the-frontend)). Backend pages get the token automatically.
- **Removed:** Info Center widget, "Bulk Resize", the API functions `load`, `restore`, `cancel-upload` and `save_metadata`, the session switches `filepond_no_meta` and `filepond_title_required` (use `data-filepond-skip-meta` and `data-filepond-title-required` instead).
- **Settings** are split into tabs. Existing values are kept; the update removes keys that are no longer used.

All changes are listed in the [CHANGELOG](CHANGELOG.md).

## Settings

**FilePond Uploader → Settings** (admins only):

| Tab | Contents |
| --- | --- |
| Upload | number and size, delayed upload, default category, language, required fields, chunked upload, allowed file types |
| Images | maximum pixel size, quality, EXIF orientation, resize in the browser or on the server, image editor |
| Metadata | always show or skip the dialog, additional required fields, hidden fields |
| Media pool | delete unused files from YForm fields, replace the media pool upload, replace files, multiupload subpage, alt text checker, YCom media protection |
| AI | AI Platform profile, where to offer AI, target field, languages, prompt, result cache |
| System | API token, debug log, clean up temporary files |

## In the backend

- **FilePond Uploader → Upload:** upload into a selectable category (only categories the user has permissions for).
- **Media pool:** FilePond can replace the media pool's upload page or appear as an additional "Multiupload" subpage.
- **Replace file:** on a media file's detail page the file can be exchanged; file name and metadata are kept.
- **Media widgets:** opened from `REX_MEDIA`/`REX_MEDIALIST`, uploaded files can be taken over directly.
- **MediaPlace:** choose "FilePond" as upload provider in the MediaPlace settings; upload button, drag & drop and paste then use the FilePond dialog (including chunked upload, image editor, AI suggestion). Permission for non-admins: `filepond_uploader[mediaplace_upload]`.

Permissions: `filepond_uploader[upload]` (upload page), `filepond_uploader[alt_checker]`, `filepond_uploader[mediaplace_upload]`, `filepond_uploader[ycom_media_auth]`. Uploads are only allowed into media categories the user has permissions for.

## YForm field type

```php
$yform->setValueField('filepond', [
    'name' => 'images',
    'label' => 'Gallery',
    'category' => 1,
    'allowed_types' => 'image/*,application/pdf',
    'allowed_filesize' => 10,
    'allowed_max_files' => 5,
    'required' => 1,
    'empty_value' => 'Please select at least one file.',
    'delayed_upload' => 0,
]);
```

Pipe notation: `filepond|name|label|category|allowed_types|allowed_filesize|allowed_max_files|required|notice|empty_value|skip_meta|chunk_enabled|chunk_size|delayed_upload|title_required|alt_required|max_pixel|image_quality|client_resize|ai_enabled|ai_target_field`

Empty options fall back to the global settings. The file names are stored comma-separated.

| `delayed_upload` | Behaviour |
| --- | --- |
| `0` | upload right after selecting |
| `1` | upload via a separate button |
| `2` | upload when the form is submitted, then the form is sent |

Rejected files (type, size) block submitting with a notice at the field until they are removed.

**Types and size** are also checked on the server: the field signs its limits, and the upload endpoint rejects files outside them.

**Delete unused files:** with "automatic deletion" enabled, files removed from the field are deleted from the media pool when the record is saved – unless they are used elsewhere.

**Send files by e-mail:** the action attaches the files of a field to the YForm e-mail.

```php
$yform->setActionField('filepond2email', ['images']);
// Pipe: action|filepond2email|images
```

## In modules

Input:

```php
<?php use FriendsOfRedaxo\FilePondUploader\Helper; ?>
<input type="hidden" name="REX_INPUT_VALUE[1]" value="REX_VALUE[1]"
    data-widget="filepond"
    data-filepond-cat="1"
    <?= Helper::configAttributes(['types' => 'image/*', 'maxfiles' => 5]) ?>
>
```

`Helper::configAttributes()` returns all `data-filepond-*` attributes from the settings; single values can be overridden. In the backend, scripts, styles and the CSRF token are loaded automatically.

Output:

```php
<?php
foreach (array_filter(explode(',', 'REX_VALUE[1]')) as $filename) {
    $media = rex_media::get($filename);
    if (null !== $media) {
        echo '<img src="' . $media->getUrl() . '" alt="' . rex_escape((string) $media->getValue('med_alt')) . '">';
    }
}
```

## In the frontend

Frontend uploads are allowed for logged-in YCom users or – for guests – when the API token is stored in the session. The token is never written to the HTML.

```php
<?php
use FriendsOfRedaxo\FilePondUploader\Config;
use FriendsOfRedaxo\FilePondUploader\Helper;

rex_login::startSession();
rex_set_session('filepond_token', Config::string('api_token')); // only needed for guests

$categoryId = 3;
$types = 'image/*,application/pdf';
$maxSizeMb = 10;

echo Helper::getStyles();
?>
<form method="post">
    <input type="hidden" name="files" value=""
        data-widget="filepond"
        data-filepond-cat="<?= $categoryId ?>"
        <?= Helper::configAttributes(['types' => $types, 'maxsize' => $maxSizeMb]) ?>
        <?= Helper::widgetSecurityAttributes($categoryId, $types, $maxSizeMb) ?>
    >
</form>
<?= Helper::getScripts() ?>
```

`widgetSecurityAttributes()` outputs the CSRF token and a signature over category, types and size. The values must match `data-filepond-cat`, `types` and `maxsize`; the server only accepts signed values. A complete example is in `demo/frontend_demo.php`.

In frontend YForm forms the `filepond` field type (template `bootstrap`) sets all attributes itself; add `getStyles()`/`getScripts()` and – for guests – the session token as above. AI suggestions are not available in the frontend.

Upload requests go to the current page URL, where REDAXO handles `rex-api-call`.

## Image editor

For JPEG, PNG and WebP: crop (free, original, 1:1, 4:3, 3:2, 16:9 and portrait formats), rotate by 90°, flip horizontally and vertically, reset. Works with mouse, touch and keyboard (arrow keys move, Shift + arrow keys resize, Enter applies, Escape cancels).

- In the **metadata dialog** via "Edit image" below the preview – in all upload modes.
- With **delayed upload** also directly on the file item (via `filepond-plugin-image-edit`); preview and upload apply the crop.

Disable it under Settings → Images → Image editor, or per widget with `data-filepond-image-editor="false"`.

## AI alt texts

1. Install [AI Platform](https://github.com/FriendsOfREDAXO/ai_platform) and create a profile of type **image understanding** (for example a local Ollama model or a cloud provider).
2. **Settings → AI:** enable AI alt texts and select the profile (without a selection, AI Platform's default profile is used).
3. Choose where AI is offered: metadata dialog, media detail page, alt text checker.

For multilingual alt fields one click generates all languages. Languages the model handles poorly can be blocked; they receive the text of the fallback language. Results are cached per image and profile; "regenerate" bypasses the cache.

Custom prompt with placeholders:

| Placeholder | Replaced with |
| --- | --- |
| `{language}` | language name, e.g. "English" |
| `{lang}` | language code, e.g. `en` |
| `{filename}` | original file name |

Without a custom prompt the selected prompt profile is used (accessible, neutral, SEO). AI features are only available to backend users.

## Alt text checker

**Media pool → Alt text checker** (admins and permission `filepond_uploader[alt_checker]`):

- statistics on images with and without alt text
- enter alt texts directly in the list, also multilingual; Enter saves, "Save all" saves all changes
- mark images as decorative (counted as done)
- filter by file name and category, large preview
- AI suggestions per image or for all visible images

Requires the meta info field `med_alt`.

## Metadata

The dialog shows the media pool's meta info fields, ordered `title`, `med_title_lang`, `med_alt`, `med_copyright`, `med_description`, then all others. Multilingual fields (MetaInfo Lang Fields) get language tabs.

- `title`: optionally required (setting or `data-filepond-title-required`)
- `med_title_lang`: always required
- `med_alt`: required for images by default, alternatively "decorative image"

Further required and hidden fields can be set under Settings → Metadata.

## Data attributes

| Attribute | Meaning | Default |
| --- | --- | --- |
| `data-widget="filepond"` | turns an `<input type="hidden">` into an upload field | – |
| `data-filepond-cat` | target category | `0` |
| `data-filepond-types` | allowed types, MIME or extension, e.g. `image/*,.pdf` | setting |
| `data-filepond-maxfiles` | maximum number of files | setting |
| `data-filepond-maxsize` | maximum size in MB | setting |
| `data-filepond-lang` | `de_de` or `en_gb` | user language |
| `data-filepond-skip-meta` | skip the metadata dialog | `false` |
| `data-filepond-title-required` | title is required | setting |
| `data-filepond-alt-required` | alt text is required for images | setting |
| `data-filepond-chunk-enabled` | chunked upload | setting |
| `data-filepond-chunk-size` | chunk size in bytes (values up to 1024 are treated as MB) | 5 MB |
| `data-filepond-delayed-upload` | delayed upload | `false` |
| `data-filepond-delayed-type` | `1` button, `2` on submit | `1` |
| `data-filepond-client-resize` | resize in the browser | setting |
| `data-filepond-max-pixel` | maximum edge length | setting |
| `data-filepond-image-quality` | JPEG/WebP quality 10–100 | setting |
| `data-filepond-image-editor` | image editor | setting |
| `data-filepond-ai-enabled` | AI suggestion in the dialog (backend only) | setting |
| `data-filepond-ai-target-field` | target field of the AI suggestion | `med_alt` |
| `data-filepond-media-url` | URL of the media folder | `/media/` |

The security attributes (`data-filepond-csrf`, `data-filepond-cat-sig`, `data-filepond-policy-*`) are generated by `Helper::widgetSecurityAttributes()`.

## Security

- Every request needs the addon's CSRF token (backend automatically, frontend via `widgetSecurityAttributes()`).
- **Backend:** uploads only into categories with permissions; deleting only own uploads or with category permission.
- **Frontend:** only YCom users or a session with the API token; category, types and size are signed and checked on the server; only the session's own uploads can be deleted.
- The file type is checked by content (not by extension or browser information), plus the media pool rules for allowed extensions.
- Chunks and metadata of an upload belong to the session that prepared it.
- **External clients** without a session authenticate with the `api_token` parameter (Settings → System). Treat the token like a password.

## PHP API and classes

```php
use FriendsOfRedaxo\FilePondUploader\Helper;

Helper::getStyles();                 // frontend: <link> tags, backend: via rex_view
Helper::getScripts();                // frontend: <script> tags, backend: via rex_view
Helper::configAttributes([...]);     // data-filepond-* from the settings
Helper::widgetSecurityAttributes($categoryId, $types, $maxSizeMb);
```

| Class | Purpose | Alias up to 2.x |
| --- | --- | --- |
| `FriendsOfRedaxo\FilePondUploader\Helper` | assets and widget attributes | `filepond_helper` |
| `…\Config` | read settings | – |
| `…\MediaCleanup` | usage check, deleting unused files | `FriendsOfRedaxo\FilePond\FilePondMediaCleanup` |
| `…\AltTextChecker` | alt text statistics | `filepond_alt_text_checker` |
| `…\Ai\AltTextGenerator` | AI alt texts via AI Platform | `filepond_ai_alt_generator` |
| `…\Api\Upload` | API `filepond_uploader` | `rex_api_filepond_uploader` |
| `…\Api\AutoMetainfo`, `…\Api\AiGenerate`, `…\Api\AltChecker`, `…\Api\YcomAuth` | further APIs | `rex_api_filepond_*` |

The APIs are registered as described in the [REDAXO documentation](https://redaxo.org/doku/5.x/api#namespace-registrierung) (`rex-api-call=filepond_uploader` etc.).

Extension point `MEDIA_IS_IN_USE`: files used in a FilePond YForm field count as in use and cannot be deleted in the media pool.

## JavaScript

```js
// After every successful upload, on the input element
document.querySelector('#my-field').addEventListener('filepond:uploaded', (event) => {
    console.log(event.detail.filename);
});

// FilePond instance of a field
const pond = document.querySelector('#my-field').pondInstance;
pond.on('processfile', (error, file) => { /* … */ });
```

Widgets are initialized when the page loads, in the backend additionally on `rex:ready`. For widgets added later: `document.dispatchEvent(new Event('filepond:init'))`.

## Styling

Colours use CSS variables in `assets/filepond_widget.css` (`--fp-primary`, `--fp-border`, `--fp-background`, …) and follow the backend's dark mode. Override them in your project CSS:

```css
:root {
    --fp-primary: #0b6e4f;
    --fp-primary-hover: #095c42;
}
.filepond-upload-btn { border-radius: 0; }
```

## Maintenance

Leftovers of cancelled uploads (chunks, metadata) are deleted automatically after six hours. Under **Settings → System** they can be cleaned up immediately; there you can also regenerate the API token and enable a debug log (in addition to REDAXO's debug mode).

## Credits

- **Friends Of REDAXO** – [github.com/FriendsOfREDAXO](https://github.com/FriendsOfREDAXO)
- **KLXM Crossmedia GmbH** – [klxm.de](https://klxm.de)
- **Thomas Skerbis** – [github.com/skerbis](https://github.com/skerbis)
- FilePond by [PQINA](https://pqina.nl/filepond/), MIT licence

Licence: MIT. Please report bugs and requests as an [issue](https://github.com/FriendsOfREDAXO/filepond_uploader/issues).
