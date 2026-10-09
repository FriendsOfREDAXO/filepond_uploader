<?php

namespace FriendsOfRedaxo\FilePondUploader\Api;

use FriendsOfRedaxo\FilePondUploader\Config;
use FriendsOfRedaxo\FilePondUploader\Helper;
use FriendsOfRedaxo\FilePondUploader\MediaCleanup;
use FriendsOfRedaxo\FilePondUploader\MetadataWriter;
use Throwable;
use rex;
use Exception;
use FriendsOfRedaxo\FilePondUploader\YcomAuthSettings;
use finfo;
use rex_api_exception;
use rex_api_function;
use rex_api_result;
use rex_backend_login;
use rex_clang;
use rex_config;
use rex_dir;
use rex_file;
use rex_i18n;
use rex_logger;
use rex_media;
use rex_media_cache;
use rex_media_service;
use rex_mediapool;
use rex_path;
use rex_plugin;
use rex_request;
use rex_response;
use rex_sql;
use rex_sql_exception;
use rex_string;
use rex_ycom_auth;
use rex_yform_manager_table;

class Upload extends rex_api_function
{
    use AuthorizesRequests;

    private const MAX_CHUNKS = 100000;

    protected $published = true;
    protected string $chunksDir = '';
    protected string $metadataDir = '';

    // *** GLOBALE DEBUG-VARIABLE ***
    private bool $debug = false; // Standardmäßig: Debug-Meldungen deaktiviert

    public function __construct()
    {
        parent::__construct();
        // Verzeichnisse erstellen, falls sie nicht existieren
        $baseDir = rex_path::addonData('filepond_uploader', 'upload');

        $this->chunksDir = $baseDir . '/chunks';
        rex_dir::create($this->chunksDir);

        $this->metadataDir = $baseDir . '/metadata';
        rex_dir::create($this->metadataDir);
    }

    /**
     * Zentrale Methode für das Senden von JSON-Antworten
     * Stellt sicher, dass immer erst der Output Buffer geleert wird
     * und dass jede Antwort mit exit beendet wird.
     *
     * @param mixed $data Die zu sendenden Daten
     * @param int $statusCode HTTP-Statuscode
     * @return void Diese Methode kehrt nicht zurück (exit)
     */
    /**
     * @return never
     */
    protected function sendResponse(mixed $data, string $statusCode = '200'): void
    {
        rex_response::cleanOutputBuffers();
        if ('200' !== $statusCode) {
            rex_response::setStatus($statusCode);
        }
        rex_response::sendJson($data);
        exit;
    }

    private function log(string $level, string $message): void
    {
        if ($this->debug) {
            $logger = rex_logger::factory();
            /** @phpstan-ignore psr3.interpolated */
            $logger->log($level, 'FILEPOND: {message}', ['message' => $message]);
        }
    }

    public function execute(): rex_api_result
    {
        try {
            $this->authorize();

            $categoryId = rex_request('category_id', 'int', 0);
            match (rex_request('func', 'string', '')) {
                'prepare' => $this->sendResponse($this->handlePrepare()),
                'upload' => $this->sendResponse($this->handleUpload($this->checkCategory($categoryId))),
                'chunk-upload' => $this->handleChunkUpload(),
                'finalize-upload' => $this->sendResponse($this->handleFinalizeUpload($this->checkCategory($categoryId))),
                'delete' => $this->handleDelete(),
                'cleanup' => $this->sendResponse($this->handleCleanup()),
                default => throw new rex_api_exception('Invalid function'),
            };
        } catch (Throwable $e) {
            rex_logger::logException($e);
            // Nur eigene Meldungen nach aussen geben, keine Pfade oder Interna
            $this->sendResponse(['error' => $e instanceof rex_api_exception ? $e->getMessage() : 'Upload failed'], rex_response::HTTP_FORBIDDEN);
        }
    }

    /** Größte erlaubte Dateigröße in Byte: globale Einstellung, ggf. enger durch das Feld. */
    protected function maxFilesize(): int
    {
        $maxMb = Config::int('max_filesize', 200);
        if ($this->policyMaxFilesizeMb > 0) {
            $maxMb = min($maxMb, $this->policyMaxFilesizeMb);
        }

        return $maxMb * 1024 * 1024;
    }

    /**
     * @return array{fileId: string, status: string}
     */
    protected function handlePrepare(): array
    {
        // Diese Methode wird aufgerufen, bevor ein Upload beginnt
        // Hier werden Metadaten gespeichert und ein eindeutiger fileId zurückgegeben

        $fileId = uniqid('filepond_', true);
        $metadata = json_decode(rex_post('metadata', 'string', '{}'), true);
        $fileName = rex_request('fileName', 'string', '');
        $fieldName = rex_request('fieldName', 'string', 'filepond');

        if ('' === $fileName) {
            throw new rex_api_exception('Missing filename');
        }

        // Speichere den originalen Dateinamen für später
        $originalFileName = $fileName;

        // Eigene Normalisierung, die die Dateiendung behält
        $fileName = $this->normalizeFilename($fileName);
        $this->log('info', "Preparing upload for $fileName with ID $fileId");

        // Verzeichnis für Metadaten sicherstellen
        if (!rex_dir::create($this->metadataDir)) {
            throw new rex_api_exception("Failed to create metadata directory: {$this->metadataDir}");
        }

        // Metadaten speichern
        $metaFile = $this->metadataDir . '/' . $fileId . '.json';
        $metaData = [
            'metadata' => $metadata,
            'fileName' => $fileName,
            'originalFileName' => $originalFileName,
            'fieldName' => $fieldName,
            'timestamp' => time(),
        ];

        if (!rex_file::put($metaFile, (string) json_encode($metaData))) {
            throw new rex_api_exception("Failed to write metadata file: $metaFile");
        }

        // Erfolg zurückgeben
        return [
            'fileId' => $fileId,
            'status' => 'ready',
        ];
    }

    /**
     * Normalisiert einen Dateinamen, während die Dateiendung beibehalten wird.
     */
    protected function normalizeFilename(string $filename): string
    {
        // Dateiendung extrahieren
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $basename = pathinfo($filename, PATHINFO_FILENAME);

        // Basename normalisieren mit rex_string::normalize
        $normalizedBasename = rex_string::normalize($basename);

        // Wenn eine Endung vorhanden ist, wieder anhängen
        if ('' !== $extension) {
            return $normalizedBasename . '.' . $extension;
        }

        return $normalizedBasename;
    }

    /**
     * Speichert einen einzelnen Chunk. Zusammengeführt wird erst in handleFinalizeUpload().
     *
     * @return never
     */
    protected function handleChunkUpload(): void
    {
        $fileId = $this->requireFileId();
        $chunkIndex = rex_request('chunkIndex', 'int', -1);
        $totalChunks = rex_request('totalChunks', 'int', 0);
        if ($totalChunks < 1 || $totalChunks > self::MAX_CHUNKS || $chunkIndex < 0 || $chunkIndex >= $totalChunks) {
            throw new rex_api_exception('Invalid chunk index');
        }
        if (!is_file($this->metadataDir . '/' . $fileId . '.json')) {
            throw new rex_api_exception('Upload was not prepared');
        }

        $file = rex_request::files(rex_request('fieldName', 'string', 'filepond'), 'array', []);
        if (!isset($file['tmp_name']) || '' === $file['tmp_name'] || !is_uploaded_file($file['tmp_name'])) {
            throw new rex_api_exception('No file chunk uploaded');
        }

        $chunkDir = $this->chunksDir . '/' . $fileId;
        if (!rex_dir::create($chunkDir) || !move_uploaded_file($file['tmp_name'], $chunkDir . '/' . $chunkIndex)) {
            throw new rex_api_exception('Could not store chunk');
        }

        $this->sendResponse([
            'status' => 'chunk-success',
            'chunkIndex' => $chunkIndex,
            'remaining' => $totalChunks - $chunkIndex - 1,
        ]);
    }

    /**
     * fileId aus dem Request: nur das Format aus handlePrepare() ist erlaubt, da es in Dateipfade eingeht.
     */
    protected function requireFileId(): string
    {
        $fileId = rex_request('fileId', 'string', '');
        if (!Helper::isValidFileId($fileId)) {
            throw new rex_api_exception('Invalid fileId');
        }

        return $fileId;
    }

    protected function cleanupChunks(string $directory): void
    {
        if (is_dir($directory)) {
            $globResult = glob($directory . '/*');
            $files = false !== $globResult ? $globResult : [];
            foreach ($files as $file) {
                if (is_file($file)) {
                    rex_file::delete($file);
                }
            }
            rex_dir::delete($directory);
        }
    }

    /**
     * @return array{status: string, filename: string}
     */
    protected function handleUpload(int $categoryId): array
    {
        // Standard-Upload (kleine Dateien ohne Chunks)
        // Dynamischen Feldnamen nutzen, Fallback auf 'filepond'
        $fieldName = rex_request('fieldName', 'string', 'filepond');
        $file = rex_request::files($fieldName, 'array', []);
        if (!isset($file['tmp_name']) || '' === $file['tmp_name']) {
            // Fallback: Versuche Default-Feldname 'filepond' falls dynamischer Name nicht funktioniert
            if ('filepond' !== $fieldName) {
                $file = rex_request::files('filepond', 'array', []);
            }
            if (!isset($file['tmp_name']) || '' === $file['tmp_name']) {
                throw new rex_api_exception('No file uploaded');
            }
        }

        $fileId = rex_request('fileId', 'string', '');

        // Metadaten aus der Vorbereitungsphase laden
        $metadata = [];
        if (Helper::isValidFileId($fileId)) {
            $metaFile = $this->metadataDir . '/' . $fileId . '.json';
            if (file_exists($metaFile)) {
                $fileContent = rex_file::get($metaFile);
                $metaData = (null !== $fileContent) ? json_decode($fileContent, true) : [];
                $metadata = is_array($metaData) ? ($metaData['metadata'] ?? []) : [];

                // Metadatendatei löschen, da wir sie jetzt verarbeitet haben
                rex_file::delete($metaFile);
            }
        }

        $file['metadata'] = $metadata;

        try {
            /** @var array{name: string, tmp_name: string, type: string, size: int, metadata?: array<string, mixed>} $file */
            $result = $this->processUploadedFile($file, $categoryId);
            return [
                'status' => 'success',
                'filename' => $result,
            ];
        } catch (Exception $e) {
            $this->log('error', 'Upload error: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * @param array{name: string, tmp_name: string, type: string, size: int, metadata?: array<string, mixed>} $file
     */
    protected function processUploadedFile(array $file, int $categoryId): string
    {
        $this->log('info', 'Processing file: ' . $file['name']);

        $replaceFileId = rex_request('replace_file_id', 'int', 0);

        if ($file['size'] > $this->maxFilesize()) {
            throw new rex_api_exception('File too large');
        }

        if (!is_file($file['tmp_name'])) {
            throw new rex_api_exception('Upload failed');
        }

        // Sicherstellen, dass der Dateiname eine Erweiterung hat
        $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ('' === $fileExtension) {
            $this->log('warning', "Missing file extension in: {$file['name']}");
            // Versuche, die Erweiterung aus dem MIME-Typ abzuleiten
            $mimeExtensionMap = [
                // Bilder
                'image/jpeg' => 'jpg',
                'image/pjpeg' => 'jpg',
                'image/png' => 'png',
                'image/gif' => 'gif',
                'image/webp' => 'webp',
                'image/avif' => 'avif',
                'image/tiff' => 'tiff',
                'image/svg+xml' => 'svg',
                'application/postscript' => 'eps',

                // Dokumente
                'application/pdf' => 'pdf',
                'application/rtf' => 'rtf',
                'text/plain' => 'txt',
                'application/octet-stream' => 'bin',

                // Microsoft Office
                'application/msword' => 'doc',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                'application/vnd.ms-excel' => 'xls',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
                'application/vnd.ms-powerpoint' => 'ppt',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
                'application/vnd.openxmlformats-officedocument.presentationml.template' => 'potx',
                'application/vnd.openxmlformats-officedocument.presentationml.slideshow' => 'ppsx',

                // Archive
                'application/x-zip-compressed' => 'zip',
                'application/zip' => 'zip',
                'application/x-gzip' => 'gz',
                'application/x-tar' => 'tar',

                // Audio/Video
                'video/quicktime' => 'mov',
                'audio/mpeg' => 'mp3',
                'video/mpeg' => 'mpg',
                'video/mp4' => 'mp4',
            ];

            $detectedMimeType = rex_file::mimeType($file['tmp_name']);
            if (isset($mimeExtensionMap[$detectedMimeType])) {
                $fileExtension = $mimeExtensionMap[$detectedMimeType];
                $file['name'] = $file['name'] . '.' . $fileExtension;
                $this->log('info', "Added file extension based on MIME type: {$file['name']}");
            }
            // throw new rex_api_exception('Dateiendung konnte nicht erkannt werden');
        }

        // MIME-Typ aus dem Inhalt bestimmen (der Client-Wert ist bei Chunk-Uploads nicht vorhanden)
        {
            // Dateityp neu bestimmen mit rex_file::mimeType (genauer als finfo)
            $detectedMimeType = rex_file::mimeType($file['tmp_name']);
            $this->log('debug', "MIME detection: extension=$fileExtension, original={$file['type']}, detected=$detectedMimeType");

            // Den erkannten MIME-Typ verwenden
            if (null !== $detectedMimeType) {
                $file['type'] = $detectedMimeType;
                $this->log('info', "Using detected MIME type: {$file['type']}");
            }

            // Wenn der MIME-Typ immer noch nicht richtig ist, aus dem Mediapool die erlaubten MIME-Types holen
            $allowedMimeTypes = rex_mediapool::getAllowedMimeTypes();
            if (isset($allowedMimeTypes[$fileExtension]) && !in_array($file['type'], $allowedMimeTypes[$fileExtension], true)) {
                // Ersten erlaubten MIME-Typ für diese Dateiendung verwenden
                $file['type'] = $allowedMimeTypes[$fileExtension][0];
                $this->log('info', "Corrected MIME type to {$file['type']} based on mediapool configuration");
            }
        }

        // Bei Validierung zunächst prüfen, ob die Dateiendung überhaupt erlaubt ist
        if (!rex_mediapool::isAllowedExtension($file['name'])) {
            $this->log('error', "File extension not allowed: .$fileExtension");
            throw new rex_api_exception('File type not allowed');
        }

        // Dann prüfen, ob der MIME-Typ zur Dateiendung passt
        if (!rex_mediapool::isAllowedMimeType($file['tmp_name'], $file['name'])) {
            $this->log('error', "File MIME type not allowed: {$file['type']} for extension .$fileExtension");
            throw new rex_api_exception('File type not allowed');
        }

        if ('' !== $this->policyTypes && !Helper::isTypeAllowed($this->policyTypes, $file['name'], $file['type'])) {
            $this->log('error', "File type not allowed by field: {$file['type']}");
            throw new rex_api_exception('File type not allowed');
        }

        if ($replaceFileId > 0) {
            return $this->replaceExistingMediaFile($replaceFileId, $file);
        }

        // Bildoptimierung für unterstützte Formate (keine GIFs)
        // Nur wenn serverseitige Bildverarbeitung aktiviert ist
        $serverImageProcessing = ('|1|' === (string) rex_config::get('filepond_uploader', 'server_image_processing', ''));
        if ($serverImageProcessing && str_starts_with($file['type'], 'image/') && 'image/gif' !== $file['type']) {
            $this->processImage($file['tmp_name']);
        }

        $originalName = $file['name'];

        $metadata = $file['metadata'] ?? [];
        $skipMeta = '1' === rex_request('skipMeta', 'string', '');

        if ($categoryId < 0) {
            $categoryId = (int) rex_config::get('filepond_uploader', 'category_id', 0);
        }

        $data = [
            'title' => isset($metadata['title']) && is_string($metadata['title']) ? $metadata['title'] : rex_string::normalize(pathinfo($originalName, PATHINFO_FILENAME)),
            'category_id' => $categoryId,
            'file' => [
                'name' => $originalName,
                'tmp_name' => $file['tmp_name'],
                'type' => $file['type'],
                'size' => $file['size'],
            ],
        ];

        try {
            $result = rex_media_service::addMedia($data, true);
            if ($result['ok']) {
                if (!$skipMeta && [] !== $metadata) {
                    MetadataWriter::apply($result['filename'], $metadata);
                }

                $this->applyYcomMediaAuthDefaults($result['filename']);
                $this->rememberUpload($result['filename']);

                return $result['filename'];
            }

            throw new rex_api_exception(implode(', ', $result['messages']));
        } catch (rex_api_exception $e) {
            throw $e;
        } catch (Throwable $e) {
            rex_logger::logException($e);
            throw new rex_api_exception('Upload failed');
        }
    }

    /**
     * Ersetzt eine bestehende Mediapool-Datei über rex_media_service::updateMedia.
     *
     * @param array{name: string, tmp_name: string, type: string, size: int, metadata?: array<string, mixed>} $file
     */
    protected function replaceExistingMediaFile(int $fileId, array $file): string
    {
        $media = rex_media::forId($fileId);
        if (null === $media) {
            throw new rex_api_exception(rex_i18n::msg('pool_file_not_found'));
        }

        $user = rex::getUser();
        if (!$user || !$user->getComplexPerm('media')->hasCategoryPerm($media->getCategoryId())) {
            throw new rex_api_exception(rex_i18n::msg('no_permission'));
        }

        $data = [
            'category_id' => $media->getCategoryId(),
            'title' => $media->getTitle(),
            'file' => [
                'name' => $file['name'],
                'tmp_name' => $file['tmp_name'],
                'error' => 0,
            ],
        ];

        $result = rex_media_service::updateMedia($media->getFileName(), $data);
        if (!isset($result['filename'])) {
            throw new rex_api_exception('Replace media failed');
        }

        return (string) $result['filename'];
    }

    /**
     * Wendet die in der Backend-Session hinterlegten YCom-Media-Auth-Defaults
     * auf die soeben hochgeladene Datei an. Voraussetzung: ycom/media_auth
     * Plugin verfügbar, Feature aktiviert und der eingeloggte Backend-User
     * besitzt die Permission.
     *
     * Quelle der Default-Werte:
     *  1. Direkte POST-Parameter (`ycom_auth_type`, `ycom_group_type`, `ycom_groups[]`),
     *     falls der Upload sie selbst mitliefert (robuster gegen Race-Conditions
     *     und stale Session-Daten).
     *  2. Sonst Session-Defaults aus YcomAuthSettings::getSessionDefaults().
     */
    protected function applyYcomMediaAuthDefaults(string $filename): void
    {
        if ('' === $filename) {
            return;
        }
        if (!YcomAuthSettings::isEnabled()) {
            $this->log('info', 'YCom auth defaults skipped: feature disabled or ycom/media_auth missing');
            return;
        }
        if (!rex_backend_login::hasSession()) {
            $this->log('info', 'YCom auth defaults skipped: no backend session (frontend upload)');
            return;
        }
        if (!YcomAuthSettings::userMayManage(rex::getUser())) {
            $this->log('info', 'YCom auth defaults skipped: user lacks permission filepond_uploader[ycom_media_auth]');
            return;
        }

        // Bevorzugt POST-Werte aus dem aktuellen Upload, sonst Session-Fallback.
        $hasPostAuth = null !== rex_request('ycom_auth_type', 'string', null);
        if ($hasPostAuth) {
            $authType = 1 === rex_request('ycom_auth_type', 'int', 0) ? 1 : 0;
            $groupType = rex_request('ycom_group_type', 'int', 0);
            $groupsRaw = rex_request('ycom_groups', 'array', []);
            $groups = [];
            foreach ($groupsRaw as $g) {
                $gid = (int) $g;
                if ($gid > 0) {
                    $groups[] = $gid;
                }
            }
            $defaults = [
                'ycom_auth_type' => $authType,
                'ycom_group_type' => $groupType,
                'ycom_groups' => $groups,
            ];
        } else {
            $defaults = YcomAuthSettings::getSessionDefaults();
        }

        try {
            $sql = rex_sql::factory();
            $sql->setTable(rex::getTable('media'));
            $sql->setWhere(['filename' => $filename]);
            $sql->setValue('ycom_auth_type', $defaults['ycom_auth_type']);

            if (YcomAuthSettings::isGroupSupportAvailable()) {
                $sql->setValue('ycom_group_type', $defaults['ycom_group_type']);
                $sql->setValue('ycom_groups', implode(',', $defaults['ycom_groups']));
            }

            $sql->update();
            rex_media_cache::delete($filename);
            $this->log('info', sprintf(
                'YCom auth defaults applied to %s (auth_type=%d, group_type=%d, groups=[%s], source=%s)',
                $filename,
                $defaults['ycom_auth_type'],
                $defaults['ycom_group_type'],
                implode(',', $defaults['ycom_groups']),
                $hasPostAuth ? 'POST' : 'SESSION'
            ));
        } catch (rex_sql_exception $e) {
            rex_logger::logException($e);
        }
    }

    /**
     * Process and optimize an image (resize and EXIF orientation fix).
     *
     * @param string $tmpFile Path to the temporary image file
     * @return void
     */
    protected function processImage($tmpFile)
    {
        $maxPixel = (int) rex_config::get('filepond_uploader', 'max_pixel', 1200);
        $quality = (int) rex_config::get('filepond_uploader', 'image_quality', 90);
        $fixExifOrientation = (bool) rex_config::get('filepond_uploader', 'fix_exif_orientation', false);

        $imageInfo = getimagesize($tmpFile);
        if (false === $imageInfo) {
            return;
        }

        [$width, $height, $type] = $imageInfo;

        // Nur unterstützte Bildformate verarbeiten
        if (!in_array($type, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            return;
        }

        // Prüfen ob Resize nötig ist
        $needsResize = ($width > $maxPixel || $height > $maxPixel);

        // Wenn kein Resize nötig und keine EXIF-Korrektur, abbrechen
        if (!$needsResize && !$fixExifOrientation) {
            return;
        }

        // Prüfen ob ImageMagick verfügbar ist
        $convertBin = $this->findImageMagickBinary();
        if (null !== $convertBin) {
            $this->processImageWithImageMagick($tmpFile, $convertBin, $maxPixel, $quality, $fixExifOrientation, $needsResize, $type);
            return;
        }

        // Fallback auf GD
        $this->processImageWithGD($tmpFile, $maxPixel, $quality, $fixExifOrientation, $needsResize, $type, $width, $height);
    }

    /**
     * Find ImageMagick convert binary.
     *
     * @return string|null Path to convert binary or null if not found
     */
    protected function findImageMagickBinary()
    {
        // Versuche verschiedene mögliche Pfade
        $possiblePaths = [
            '/usr/bin/convert',
            '/usr/local/bin/convert',
            '/opt/homebrew/bin/convert',
            'convert', // PATH
        ];

        foreach ($possiblePaths as $path) {
            $output = [];
            $returnCode = 0;
            @exec($path . ' -version 2>&1', $output, $returnCode);
            if (0 === $returnCode && [] !== $output) {
                $versionString = implode(' ', $output);
                if (str_contains($versionString, 'ImageMagick')) {
                    $this->log('info', "Found ImageMagick at: $path");
                    return $path;
                }
            }
        }

        $this->log('warning', 'ImageMagick not found, falling back to GD');
        return null;
    }

    /**
     * Process image with ImageMagick CLI (resize and EXIF orientation fix).
     *
     * @return void
     */
    protected function processImageWithImageMagick(string $tmpFile, string $convertBin, int $maxPixel, int $quality, bool $fixExifOrientation, bool $needsResize, int $type): void
    {
        // ImageMagick Befehl zusammenbauen
        $cmd = escapeshellcmd($convertBin);
        $cmd .= ' ' . escapeshellarg($tmpFile);

        // EXIF-Orientierung korrigieren
        if ($fixExifOrientation) {
            $cmd .= ' -auto-orient';
        }

        // Resize wenn nötig (mit Seitenverhältnis beibehalten)
        if ($needsResize) {
            $cmd .= ' -resize ' . escapeshellarg($maxPixel . 'x' . $maxPixel . '>');
        }

        // Qualität setzen
        $cmd .= ' -quality ' . $quality;

        // Strip metadata für kleinere Dateien
        $cmd .= ' -strip';

        // Ausgabedatei (überschreibt Original)
        $cmd .= ' ' . escapeshellarg($tmpFile);

        $this->log('info', "Executing ImageMagick: $cmd");

        $output = [];
        $returnCode = 0;
        exec($cmd . ' 2>&1', $output, $returnCode);

        if (0 !== $returnCode) {
            $this->log('error', 'ImageMagick error: ' . implode(' ', $output));
        }
    }

    /**
     * Process image with GD (Fallback, resize and EXIF orientation fix).
     *
     * @return void
     */
    protected function processImageWithGD(string $tmpFile, int $maxPixel, int $quality, bool $fixExifOrientation, bool $needsResize, int $type, int $width, int $height): void
    {
        // Fix EXIF orientation first, before any other processing
        if ($fixExifOrientation) {
            $this->fixExifOrientation($tmpFile, $type);
            // Re-read image info after orientation fix
            $imageInfo = getimagesize($tmpFile);
            if (false === $imageInfo) {
                $this->log('error', 'Failed to read image info after EXIF orientation fix');
                return;
            }
            [$width, $height, $type] = $imageInfo;
        }

        // Wenn kein Resize nötig, sind wir fertig (EXIF wurde bereits korrigiert)
        if (!$needsResize) {
            return;
        }

        // Neue Dimensionen berechnen
        $newWidth = $width;
        $newHeight = $height;
        $ratio = $width / $height;
        if ($width > $height) {
            $newWidth = min($width, $maxPixel);
            $newHeight = max(1, (int) floor($newWidth / $ratio));
        } else {
            $newHeight = min($height, $maxPixel);
            $newWidth = max(1, (int) floor($newHeight * $ratio));
        }

        // Create source image based on type
        $srcImage = null;
        switch ($type) {
            case IMAGETYPE_JPEG:
                $srcImage = imagecreatefromjpeg($tmpFile);
                break;
            case IMAGETYPE_PNG:
                $srcImage = imagecreatefrompng($tmpFile);
                break;
            case IMAGETYPE_WEBP:
                if (function_exists('imagecreatefromwebp')) {
                    $srcImage = imagecreatefromwebp($tmpFile);
                }
                break;
            default:
                return;
        }

        if (false === $srcImage || null === $srcImage) {
            return;
        }

        $dstImage = imagecreatetruecolor(max(1, $newWidth), max(1, $newHeight));
        if (false === $dstImage) {
            return;
        }

        // Preserve transparency for PNG images
        if (IMAGETYPE_PNG === $type) {
            imagealphablending($dstImage, false);
            imagesavealpha($dstImage, true);
            $transparent = imagecolorallocatealpha($dstImage, 255, 255, 255, 127);
            if (false !== $transparent) {
                imagefilledrectangle($dstImage, 0, 0, $newWidth, $newHeight, $transparent);
            }
        }

        // Resize image
        imagecopyresampled(
            $dstImage,
            $srcImage,
            0,
            0,
            0,
            0,
            $newWidth,
            $newHeight,
            $width,
            $height,
        );

        // Save image in original format
        if (IMAGETYPE_JPEG === $type) {
            imagejpeg($dstImage, $tmpFile, $quality);
        } elseif (IMAGETYPE_PNG === $type) {
            $pngQuality = (int) min(9, floor($quality / 10));
            imagepng($dstImage, $tmpFile, $pngQuality);
        } elseif (IMAGETYPE_WEBP === $type) {
            imagewebp($dstImage, $tmpFile, $quality);
        }

        // Free memory
    }

    /**
     * Fix image orientation based on EXIF data.
     *
     * @param string $tmpFile Path to the image file
     * @param int $type Image type constant
     * @return void
     */
    protected function fixExifOrientation($tmpFile, $type)
    {
        // Only process JPEG images as they typically contain EXIF data
        if (IMAGETYPE_JPEG !== $type) {
            return;
        }

        // Check if exif functions are available
        if (!function_exists('exif_read_data')) {
            $this->log('warning', 'EXIF functions not available - cannot fix orientation');
            return;
        }

        // Check if imageflip function exists (requires PHP 5.5.0+)
        if (!function_exists('imageflip')) {
            $this->log('warning', 'imageflip() function not available, skipping EXIF orientation fix');
            return;
        }

        // Read EXIF data with error handling
        $exif = @exif_read_data($tmpFile);
        if (false === $exif || !isset($exif['Orientation'])) {
            // No orientation data found, nothing to fix
            return;
        }

        $orientation = $exif['Orientation'];

        // No rotation needed
        if (1 === $orientation) {
            return;
        }

        $this->log('info', "Fixing EXIF orientation: $orientation for file: $tmpFile");

        // Load the image with additional error checking
        $image = @imagecreatefromjpeg($tmpFile);
        if (false === $image) {
            $this->log('error', 'Failed to load image for EXIF orientation fix');
            return;
        }

        // Rotate/flip based on orientation value
        switch ($orientation) {
            case 2: // Horizontal flip
                if (!imageflip($image, IMG_FLIP_HORIZONTAL)) {
                    $this->log('error', 'Failed to flip image horizontally (orientation 2)');
                    return;
                }
                break;
            case 3: // 180 rotate
                $rotated = imagerotate($image, 180, 0);
                if (false === $rotated) {
                    $this->log('error', 'Failed to rotate image 180 degrees');
                    return;
                }
                $image = $rotated;
                break;
            case 4: // Vertical flip
                if (!imageflip($image, IMG_FLIP_VERTICAL)) {
                    $this->log('error', 'Failed to flip image vertically (orientation 4)');
                    return;
                }
                break;
            case 5: // Vertical flip + 90 rotate clockwise
                if (!imageflip($image, IMG_FLIP_VERTICAL)) {
                    $this->log('error', 'Failed to flip image vertically before rotation (orientation 5)');
                    return;
                }
                $rotated = imagerotate($image, -90, 0);
                if (false === $rotated) {
                    $this->log('error', 'Failed to rotate image -90 degrees after vertical flip');
                    return;
                }
                $image = $rotated;
                break;
            case 6: // 90 rotate clockwise
                $rotated = imagerotate($image, -90, 0);
                if (false === $rotated) {
                    $this->log('error', 'Failed to rotate image -90 degrees');
                    return;
                }
                $image = $rotated;
                break;
            case 7: // Horizontal flip + 90 rotate clockwise
                if (!imageflip($image, IMG_FLIP_HORIZONTAL)) {
                    $this->log('error', 'Failed to flip image horizontally before rotation (orientation 7)');
                    return;
                }
                $rotated = imagerotate($image, -90, 0);
                if (false === $rotated) {
                    $this->log('error', 'Failed to rotate image -90 degrees after horizontal flip');
                    return;
                }
                $image = $rotated;
                break;
            case 8: // 90 rotate counter-clockwise
                $rotated = imagerotate($image, 90, 0);
                if (false === $rotated) {
                    $this->log('error', 'Failed to rotate image 90 degrees');
                    return;
                }
                $image = $rotated;
                break;
        }

        // Get image quality setting
        $quality = rex_config::get('filepond_uploader', 'image_quality', 90);

        // Save the corrected image with error handling
        if (!@imagejpeg($image, $tmpFile, $quality)) {
            $this->log('error', 'Failed to save EXIF-corrected image to file: ' . $tmpFile);
            return;
        }


        $this->log('info', 'EXIF orientation corrected successfully');
    }

    /**
     * Löscht eine Datei, die der Aufrufer selbst hochgeladen hat (oder für deren Kategorie er
     * als Backend-User Rechte hat) und die in keinem filepond-Feld verwendet wird.
     *
     * @return never
     */
    protected function handleDelete(): void
    {
        $filename = trim(rex_request('filename', 'string', ''));
        $media = '' === $filename ? null : rex_media::get($filename);
        if (null === $media) {
            $this->sendResponse(['status' => 'success']);
        }

        $mayDelete = $this->isOwnUpload($filename)
            || ('backend' === $this->caller && $this->backendUser?->getComplexPerm('media')->hasCategoryPerm($media->getCategoryId()));
        if (!$mayDelete) {
            throw new rex_api_exception('No permission to delete this file');
        }

        if (!MediaCleanup::isUsedInFilepondField($filename)) {
            rex_media_service::deleteMedia($filename);
        }

        $this->sendResponse(['status' => 'success']);
    }

    /**
     * @return array<string, mixed>
     */
    public function handleCleanup(): array
    {
        // Nur Backend-Benutzer mit Admin-Rechten dürfen aufräumen
        $user = rex_backend_login::createUser();
        if (null === $user || !$user->isAdmin()) {
            throw new rex_api_exception('Unauthorized: Admin privileges required');
        }

        // Debug-Logging NICHT temporär aktivieren, sondern nur verwenden, wenn es global aktiviert ist
        $this->log('info', 'Admin-triggered cleanup of temporary files');

        $cleanedChunks = 0;
        $cleanedMetadata = 0;
        $errors = [];
        $debugInfo = [];

        // Alte Chunk-Verzeichnisse löschen (älter als 1h statt 24h)
        $expireTime = time() - (60 * 60); // 1 Stunde
        $chunksDir = $this->chunksDir;

        $debugInfo['chunks_dir'] = $chunksDir;
        $debugInfo['metadata_dir'] = $this->metadataDir;
        $debugInfo['expire_time'] = date('Y-m-d H:i:s', $expireTime);
        $debugInfo['current_time'] = date('Y-m-d H:i:s');

        if (is_dir($chunksDir)) {
            $globResult = glob($chunksDir . '/*', GLOB_ONLYDIR);
            $chunkDirs = false !== $globResult ? $globResult : [];
            $debugInfo['found_chunk_dirs'] = count($chunkDirs);

            foreach ($chunkDirs as $dir) {
                $dirTime = filemtime($dir);
                if (false === $dirTime) {
                    continue;
                }
                $dirAge = time() - $dirTime;
                $debugInfo['chunk_dirs'][] = [
                    'path' => $dir,
                    'modified' => date('Y-m-d H:i:s', $dirTime),
                    'age_seconds' => $dirAge,
                    'is_expired' => ($dirTime < $expireTime),
                ];

                if ($dirTime < $expireTime) {
                    try {
                        $this->log('info', "Cleaning up chunk directory: $dir (modified: " . date('Y-m-d H:i:s', $dirTime) . ')');
                        $this->cleanupChunks($dir);
                        ++$cleanedChunks;
                    } catch (Exception $e) {
                        $errors[] = "Failed to clean chunk directory $dir: " . $e->getMessage();
                        $this->log('error', "Failed to clean chunk directory $dir: " . $e->getMessage());
                    }
                }
            }
        } else {
            $errors[] = "Chunks directory does not exist: $chunksDir";
            $this->log('error', "Chunks directory does not exist: $chunksDir");

            // Versuchen, das Verzeichnis zu erstellen
            try {
                rex_dir::create($chunksDir);
                $this->log('info', "Created chunks directory: $chunksDir");
            } catch (Exception $e) {
                $errors[] = 'Failed to create chunks directory: ' . $e->getMessage();
                $this->log('error', 'Failed to create chunks directory: ' . $e->getMessage());
            }
        }

        // Alte Metadaten-Dateien löschen (älter als 24h)
        $metadataDir = $this->metadataDir;

        if (is_dir($metadataDir)) {
            $globResult = glob($metadataDir . '/*.json');
            $metaFiles = false !== $globResult ? $globResult : [];
            $debugInfo['found_meta_files'] = count($metaFiles);

            foreach ($metaFiles as $file) {
                $fileTime = filemtime($file);
                if (false === $fileTime) {
                    continue;
                }
                $fileAge = time() - $fileTime;
                $debugInfo['meta_files'][] = [
                    'path' => $file,
                    'modified' => date('Y-m-d H:i:s', $fileTime),
                    'age_seconds' => $fileAge,
                    'is_expired' => ($fileTime < $expireTime),
                ];

                if ($fileTime < $expireTime) {
                    try {
                        $this->log('info', "Deleting metadata file: $file (modified: " . date('Y-m-d H:i:s', $fileTime) . ')');
                        if (!rex_file::delete($file)) {
                            $errors[] = "Failed to delete metadata file: $file";
                            $this->log('error', "Failed to delete metadata file: $file");
                        } else {
                            ++$cleanedMetadata;
                        }
                    } catch (Exception $e) {
                        $errors[] = "Failed to delete metadata file $file: " . $e->getMessage();
                        $this->log('error', "Failed to delete metadata file $file: " . $e->getMessage());
                    }
                }
            }
        } else {
            $errors[] = "Metadata directory does not exist: $metadataDir";
            $this->log('error', "Metadata directory does not exist: $metadataDir");

            // Versuchen, das Verzeichnis zu erstellen
            try {
                rex_dir::create($metadataDir);
                $this->log('info', "Created metadata directory: $metadataDir");
            } catch (Exception $e) {
                $errors[] = 'Failed to create metadata directory: ' . $e->getMessage();
                $this->log('error', 'Failed to create metadata directory: ' . $e->getMessage());
            }
        }

        // Debug-Logging zurücksetzen (falls aktiviert)
        $this->debug = false;

        // Antwort mit detaillierten Informationen
        $response = [
            'status' => [] === $errors ? 'success' : 'partial_success',
            'message' => "Cleanup completed. Removed $cleanedChunks chunk folders and $cleanedMetadata metadata files.",
        ];

        if ([] !== $errors) {
            $response['errors'] = $errors;
            $response['message'] .= ' Encountered ' . count($errors) . ' errors.';
        }

        // Debug-Info nur im Backend anzeigen
        $currentUser = rex::getUser();
        if (rex::isBackend() && null !== $currentUser && $currentUser->isAdmin()) {
            $response['debug'] = $debugInfo;
        }

        return $response;
    }

    /**
     * Führt die Chunks zusammen und übernimmt die Datei in den Medienpool.
     *
     * @return array{status: string, filename: string, originalname: string}
     */
    protected function handleFinalizeUpload(int $categoryId): array
    {
        $fileId = $this->requireFileId();
        $totalChunks = rex_request('totalChunks', 'int', 0);
        if ($totalChunks < 1 || $totalChunks > self::MAX_CHUNKS) {
            throw new rex_api_exception('Invalid chunk count');
        }

        $metaFile = $this->metadataDir . '/' . $fileId . '.json';
        $chunkDir = $this->chunksDir . '/' . $fileId;
        $tmpFile = rex_path::addonData('filepond_uploader', 'upload/' . $fileId . '.part');

        try {
            $metaData = json_decode((string) rex_file::get($metaFile), true);
            if (!is_array($metaData) || !isset($metaData['fileName']) || !is_string($metaData['fileName'])) {
                throw new rex_api_exception('Upload was not prepared');
            }

            $maxSize = $this->maxFilesize();
            $out = fopen($tmpFile, 'w');
            if (false === $out) {
                throw new rex_api_exception('Upload failed');
            }
            try {
                for ($i = 0; $i < $totalChunks; ++$i) {
                    $in = is_file($chunkDir . '/' . $i) ? fopen($chunkDir . '/' . $i, 'r') : false;
                    if (false === $in) {
                        throw new rex_api_exception('Missing chunk ' . $i);
                    }
                    stream_copy_to_stream($in, $out);
                    fclose($in);
                    if (ftell($out) > $maxSize) {
                        throw new rex_api_exception('File too large');
                    }
                }
            } finally {
                fclose($out);
            }

            $size = (int) filesize($tmpFile);
            $filename = $this->processUploadedFile([
                'name' => $metaData['fileName'],
                'type' => (string) rex_file::mimeType($tmpFile),
                'tmp_name' => $tmpFile,
                'size' => $size,
                'metadata' => is_array($metaData['metadata'] ?? null) ? $metaData['metadata'] : [],
            ], $categoryId);

            return [
                'status' => 'success',
                'filename' => $filename,
                'originalname' => (string) ($metaData['originalFileName'] ?? $metaData['fileName']),
            ];
        } finally {
            $this->cleanupChunks($chunkDir);
            rex_file::delete($metaFile);
            rex_file::delete($tmpFile);
        }
    }

}
