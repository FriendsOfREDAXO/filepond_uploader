<?php

namespace FriendsOfRedaxo\FilePondUploader;

use rex_addon;

use function array_map;
use function explode;
use function str_contains;
use function str_starts_with;

/**
 * Dateitypen, die FilePond annimmt, der Medienpool aber evtl. nicht kennt.
 */
final class MimeTypes
{
    /** MIME-Type => Endung und alternative MIME-Types, die Browser/finfo dafür liefern */
    public const MAP = [
        // Bilder
        'image/jpeg' => ['ext' => 'jpg', 'alt' => ['image/pjpeg']],
        'image/png' => ['ext' => 'png'],
        'image/gif' => ['ext' => 'gif'],
        'image/webp' => ['ext' => 'webp'],
        'image/svg+xml' => ['ext' => 'svg'],
        'image/tiff' => ['ext' => 'tiff'],
        'image/bmp' => ['ext' => 'bmp'],
        'image/heic' => ['ext' => 'heic'],
        'image/avif' => ['ext' => 'avif'],
        'image/x-icon' => ['ext' => 'ico', 'alt' => ['image/vnd.microsoft.icon']],

        // Dokumente
        'application/pdf' => ['ext' => 'pdf'],
        'text/plain' => ['ext' => 'txt', 'alt' => ['application/octet-stream']],
        'text/csv' => ['ext' => 'csv', 'alt' => ['text/plain', 'application/octet-stream']],
        'text/calendar' => ['ext' => 'ics', 'alt' => ['text/plain', 'application/octet-stream']],
        'text/x-vcalendar' => ['ext' => 'vcal', 'alt' => ['text/calendar', 'text/plain', 'application/octet-stream']],
        'text/vcard' => ['ext' => 'vcf', 'alt' => ['text/x-vcard', 'text/plain', 'application/octet-stream']],
        'text/markdown' => ['ext' => 'md', 'alt' => ['text/plain', 'application/octet-stream']],
        'application/rtf' => ['ext' => 'rtf'],
        'application/json' => ['ext' => 'json', 'alt' => ['text/plain']],
        'text/xml' => ['ext' => 'xml', 'alt' => ['application/xml']],
        'text/vtt' => ['ext' => 'vtt'],
        'text/srt' => ['ext' => 'srt', 'alt' => ['text/plain']],

        // Archive
        'application/zip' => ['ext' => 'zip', 'alt' => ['application/x-zip-compressed']],
        'application/x-gzip' => ['ext' => 'gz', 'alt' => ['application/gzip']],
        'application/x-tar' => ['ext' => 'tar'],
        'application/x-rar-compressed' => ['ext' => 'rar', 'alt' => ['application/vnd.rar']],
        'application/x-7z-compressed' => ['ext' => '7z'],

        // Video
        'video/mp4' => ['ext' => 'mp4'],
        'video/mpeg' => ['ext' => 'mpeg'],
        'video/quicktime' => ['ext' => 'mov'],
        'video/webm' => ['ext' => 'webm'],
        'video/ogg' => ['ext' => 'ogv'],
        'video/x-msvideo' => ['ext' => 'avi'],
        'video/x-matroska' => ['ext' => 'mkv'],

        // Audio
        'audio/mpeg' => ['ext' => 'mp3'],
        'audio/wav' => ['ext' => 'wav', 'alt' => ['audio/x-wav']],
        'audio/ogg' => ['ext' => 'ogg'],
        'audio/aac' => ['ext' => 'aac'],
        'audio/midi' => ['ext' => 'midi', 'alt' => ['audio/x-midi']],
        'audio/flac' => ['ext' => 'flac'],
        'audio/mp4' => ['ext' => 'm4a'],
        'audio/webm' => ['ext' => 'weba'],

        // Office (Modern)
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['ext' => 'docx', 'alt' => ['application/octet-stream', 'application/encrypted']],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['ext' => 'xlsx', 'alt' => ['application/octet-stream', 'application/encrypted']],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['ext' => 'pptx'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.template' => ['ext' => 'dotx', 'alt' => ['application/octet-stream']],
        'application/vnd.openxmlformats-officedocument.presentationml.template' => ['ext' => 'potx'],
        'application/vnd.openxmlformats-officedocument.presentationml.slideshow' => ['ext' => 'ppsx'],

        // Office (Legacy)
        'application/msword' => ['ext' => 'doc', 'alt' => ['application/octet-stream', 'application/encrypted']],
        'application/vnd.ms-excel' => ['ext' => 'xls', 'alt' => ['application/octet-stream', 'application/encrypted']],
        'application/vnd.ms-powerpoint' => ['ext' => 'ppt'],

        // OpenDocument
        'application/vnd.oasis.opendocument.text' => ['ext' => 'odt'],
        'application/vnd.oasis.opendocument.spreadsheet' => ['ext' => 'ods'],
        'application/vnd.oasis.opendocument.presentation' => ['ext' => 'odp'],

        // Fonts
        'font/woff' => ['ext' => 'woff', 'alt' => ['application/font-woff']],
        'font/woff2' => ['ext' => 'woff2'],
        'font/ttf' => ['ext' => 'ttf', 'alt' => ['application/x-font-ttf']],
        'font/otf' => ['ext' => 'otf', 'alt' => ['application/x-font-opentype']],

        // Sonstige
        'application/postscript' => ['ext' => 'eps'],
        'application/epub+zip' => ['ext' => 'epub'],
    ];
    /**
     * Ergänzt die erlaubten MIME-Types des Medienpools um die in FilePond erlaubten Typen
     * (MIME-Types, Wildcards wie image/* und Endungen wie .pdf).
     */
    public static function extendMediapool(string $allowedTypes): void
    {
        if ('' === $allowedTypes) {
            return;
        }

        $mediapool = rex_addon::get('mediapool');
        $mimes = $mediapool->getProperty('allowed_mime_types', []);
        $changed = false;

        foreach (array_map('trim', explode(',', $allowedTypes)) as $type) {
            foreach (self::MAP as $mime => $info) {
                $matches = match (true) {
                    str_contains($type, '/*') => str_starts_with($mime, explode('/*', $type)[0] . '/'),
                    str_starts_with($type, '.') => $info['ext'] === ltrim($type, '.'),
                    default => $mime === $type,
                };
                if ($matches && !isset($mimes[$info['ext']])) {
                    $mimes[$info['ext']] = array_merge([$mime], $info['alt'] ?? []);
                    $changed = true;
                }
            }
        }

        if ($changed) {
            $mediapool->setProperty('allowed_mime_types', $mimes);
        }
    }
}
