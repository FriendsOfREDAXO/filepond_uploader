<?php

namespace FriendsOfRedaxo\FilePondUploader\Api;

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
            $this->log('info', 'Starting execute()');

            // Authentifizierung prüfen
            if (!$this->isAuthorized()) {
                throw new rex_api_exception('Unauthorized access');
            }

            $func = rex_request('func', 'string', '');
            $categoryId = rex_request('category_id', 'int', 0);

            if ('prepare' === $func) {
                $result = $this->handlePrepare();
                $this->sendResponse($result);
            } elseif ('upload' === $func) {
                $result = $this->handleUpload($categoryId);
                $this->sendResponse($result);
            } elseif ('chunk-upload' === $func) {
                $this->handleChunkUpload($categoryId);
            } elseif ('finalize-upload' === $func) {
                $result = $this->handleFinalizeUpload($categoryId);
                $this->sendResponse($result);
            } elseif ('delete' === $func) {
                $this->handleDelete();
            } elseif ('cancel-upload' === $func) {
                $result = $this->handleCancelUpload();
                $this->sendResponse($result);
            } elseif ('load' === $func) {
                $this->handleLoad();
            } elseif ('restore' === $func) {
                $this->handleRestore();
            } elseif ('cleanup' === $func) {
                $result = $this->handleCleanup();
                $this->sendResponse($result);
            } else {
                throw new rex_api_exception('Invalid function: ' . $func);
            }
        } catch (Exception $e) {
            rex_logger::logException($e);
            $this->sendResponse(['error' => $e->getMessage()], rex_response::HTTP_FORBIDDEN);
        }

        return new rex_api_result(true);
    }

    protected function isAuthorized(): bool
    {
        $this->log('info', 'Checking authorization');

        // Backend User Check
        $user = rex_backend_login::createUser();
        $isBackendUser = null !== $user;
        $this->log('info', 'isBackendUser = ' . ($isBackendUser ? 'true' : 'false'));

        // Token Check
        $apiToken = rex_config::get('filepond_uploader', 'api_token');
        $apiTokenStr = is_string($apiToken) ? $apiToken : '';
        $requestToken = rex_request('api_token', 'string', '');
        $sessionToken = rex_session('filepond_token', 'string', '');

        $isValidToken = ('' !== $apiTokenStr && '' !== $requestToken && hash_equals($apiTokenStr, $requestToken))
            || ('' !== $apiTokenStr && '' !== $sessionToken && hash_equals($apiTokenStr, $sessionToken));

        // YCom Check
        $isYComUser = false;
        if (rex_plugin::get('ycom', 'auth')->isAvailable()) {
            /** @phpstan-ignore class.notFound */
            if (null !== rex_ycom_auth::getUser()) {
                $isYComUser = true;
            }
        }

        $authorized = $isBackendUser || $isValidToken || $isYComUser;

        if (!$authorized) {
            $this->log('error', 'Unauthorized - no YCom login, no Backend login, invalid API token');
        }

        return $authorized;
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

    protected function handleChunkUpload(int $categoryId): void
    {
        // Chunk-Informationen aus dem Request holen
        $chunkIndex = rex_request('chunkIndex', 'int', 0);
        $totalChunks = rex_request('totalChunks', 'int', 1);
        $fileId = rex_request('fileId', 'string', '');
        $fieldName = rex_request('fieldName', 'string', 'filepond'); // Feldname für die Identifikation

        $logger = rex_logger::factory();

        if ('' === $fileId) {
            throw new rex_api_exception('Missing fileId');
        }

        $metaFile = $this->metadataDir . '/' . $fileId . '.json';

        if (!file_exists($metaFile)) {
            $logger->log('warning', "FILEPOND: Metadata file not found for $fileId, creating fallback metadata");

            // Fallback-Metadaten erstellen
            $fallbackMetadata = [
                'metadata' => [
                    'title' => pathinfo(rex_request('fileName', 'string', 'unknown'), PATHINFO_FILENAME),
                    'alt' => pathinfo(rex_request('fileName', 'string', 'unknown'), PATHINFO_FILENAME),
                    'copyright' => '',
                ],
                'fileName' => rex_request('fileName', 'string', 'unknown'),
                'fieldName' => $fieldName,
                'timestamp' => time(),
            ];

            // Verzeichnis erstellen, wenn es nicht existiert
            rex_dir::create($this->metadataDir);

            // Fallback-Metadaten speichern
            rex_file::put($metaFile, (string) json_encode($fallbackMetadata));

            // Lokale Variable setzen
            $metaData = $fallbackMetadata;
        } else {
            $metaContent = rex_file::get($metaFile);
            if (null === $metaContent) {
                throw new rex_api_exception('Could not read metadata file for chunk upload');
            }
            $decoded = json_decode($metaContent, true);
            $metaData = is_array($decoded) ? $decoded : [];
        }

        $fileName = $metaData['fileName'];
        $storedFieldName = $metaData['fieldName'] ?? 'filepond';

        // Überprüfen, ob das Feld übereinstimmt
        if ($fieldName !== $storedFieldName) {
            $logger->log('warning', "FILEPOND: Field name mismatch for $fileId. Expected $storedFieldName, got $fieldName");
        }

        $this->log('info', "Processing chunk $chunkIndex of $totalChunks for $fileName (ID: $fileId)");
        $this->log('debug', "chunkIndex = $chunkIndex, totalChunks = $totalChunks, fileId = $fileId, fieldName = $fieldName");

        // Chunk-Datei aus dem Upload holen
        $file = rex_request::files($fieldName, 'array', []);
        if (!isset($file['tmp_name']) || '' === $file['tmp_name']) {
            rex_response::setStatus(rex_response::HTTP_BAD_REQUEST);
            throw new rex_api_exception("No file chunk uploaded for field $fieldName");
        }

        $this->log('debug', "\$_FILES[$fieldName] = " . print_r($file, true));

        // Verzeichnis für die Chunks dieses Files erstellen
        $fileChunkDir = $this->chunksDir . '/' . $fileId;
        if (!file_exists($fileChunkDir)) {
            if (!rex_dir::create($fileChunkDir)) {
                throw new rex_api_exception("Failed to create chunk directory: $fileChunkDir");
            }
            $this->log('info', "Created chunk directory: $fileChunkDir");
        }

        // LOCK-MECHANISMUS: Stellt sicher, dass nur ein Prozess auf Chunks zugreift
        $lockFile = $fileChunkDir . '/.lock';
        $lock = fopen($lockFile, 'w+');
        if (false === $lock) {
            throw new rex_api_exception("Could not create lock file: $lockFile");
        }

        if (!flock($lock, LOCK_EX)) {  // Exklusives Lock anfordern
            fclose($lock);
            throw new rex_api_exception("Could not acquire lock for chunk directory: $fileChunkDir");
        }

        try {
            // Chunk speichern
            $chunkPath = $fileChunkDir . '/' . $chunkIndex;
            $this->log('debug', "Saving chunk to: $chunkPath, size = " . $file['size']);
            if (!move_uploaded_file($file['tmp_name'], $chunkPath)) {
                $error = error_get_last();
                $this->log('error', 'move_uploaded_file failed: ' . print_r($error, true));
                throw new rex_api_exception("Failed to save chunk $chunkIndex");
            }
            $this->log('info', "Saved chunk $chunkIndex successfully");

            // Prüfen ob alle Chunks hochgeladen wurden
            if ($chunkIndex === $totalChunks - 1) { // Letzter Chunk
                $this->log('info', "Last chunk received for $fileName, merging chunks...");

                // Temporäre Datei für das zusammengeführte Ergebnis im Addon-Data-Verzeichnis
                $tmpFile = rex_path::addonData('filepond_uploader', 'upload/') . $fileId;

                // Ältere temporäre Datei entfernen falls vorhanden
                rex_file::delete($tmpFile);

                // Chunks zusammenführen
                $out = fopen($tmpFile, 'w');
                if (false === $out) {
                    throw new rex_api_exception('Could not create output file');
                }

                // DATEISYSTEM-CACHE LEEREN vor dem Auflisten der Chunks
                clearstatcache();

                // Chunk-Zählung und Validierung
                $files = scandir($fileChunkDir);
                $actualChunks = 0;
                $chunkFiles = [];
                foreach ($files as $f) {
                    if ('.' !== $f && '..' !== $f && '.lock' !== $f && is_file($fileChunkDir . '/' . $f)) {
                        ++$actualChunks;
                        $chunkFiles[] = $f;
                    }
                }

                $this->log('info', "Expected $totalChunks chunks, found $actualChunks for $fileName");

                // Sortierte Auflistung der gefundenen Chunks
                sort($chunkFiles, SORT_NUMERIC);
                $this->log('debug', 'Chunk files (sorted): ' . implode(', ', $chunkFiles));

                // Überprüfen ob Chunks fehlen
                if ($actualChunks < $totalChunks) {
                    $this->log('warning', "Expected $totalChunks chunks, but found only $actualChunks for $fileName");

                    // Ressourcen freigeben
                    fclose($out);
                    flock($lock, LOCK_UN);
                    fclose($lock);
                    rex_file::delete($lockFile);
                    $missingChunks = [];
                    for ($i = 0; $i < $totalChunks; ++$i) {
                        if (!in_array((string) $i, $chunkFiles, true)) {
                            $missingChunks[] = $i;
                        }
                    }

                    $this->cleanupChunks($fileChunkDir);
                    throw new rex_api_exception('Missing chunks: ' . implode(', ', $missingChunks) .
                        ". Expected $totalChunks chunks but found only $actualChunks");
                }

                // Chunks in der richtigen Reihenfolge zusammenfügen
                for ($i = 0; $i < $totalChunks; ++$i) {
                    $chunkPath = $fileChunkDir . '/' . $i;
                    if (!file_exists($chunkPath)) {
                        // Dieser Fall sollte nach der vorherigen Überprüfung eigentlich nie eintreten
                        fclose($out);
                        flock($lock, LOCK_UN);
                        fclose($lock);
                        rex_file::delete($lockFile);
                        $this->cleanupChunks($fileChunkDir);
                        throw new rex_api_exception("Chunk $i is missing despite previous validation");
                    }

                    $in = fopen($chunkPath, 'r');
                    if (false === $in) {
                        fclose($out);
                        flock($lock, LOCK_UN);
                        fclose($lock);
                        rex_file::delete($lockFile);
                        $this->cleanupChunks($fileChunkDir);
                        throw new rex_api_exception("Could not open chunk $i for reading");
                    }

                    // Chunk zum Gesamtergebnis hinzufügen
                    $bytesWritten = stream_copy_to_stream($in, $out);
                    fclose($in);
                    $this->log('debug', "Added chunk $i to result file, $bytesWritten bytes written");
                }

                fclose($out);
                $this->log('info', 'All chunks merged successfully');

                // Dateityp ermitteln
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $type = $finfo->file($tmpFile);
                $finalSize = filesize($tmpFile);

                $this->log('info', "Final file type: $type, size: $finalSize bytes");

                // WICHTIG: Die Datei wird NICHT mehr hier zum Medienpool hinzugefügt,
                // sondern erst in handleFinalizeUpload, um doppelte Einträge zu vermeiden

                flock($lock, LOCK_UN); // Lock freigeben
                fclose($lock);
                rex_file::delete($lockFile);

                $this->sendResponse([
                    'status' => 'chunk-success',
                    'chunkIndex' => $chunkIndex,
                    'remaining' => 0,
                ]);
            }

            // Antwort für erfolgreichen Chunk-Upload
            flock($lock, LOCK_UN); // Lock freigeben
            fclose($lock);
            rex_file::delete($lockFile);

            $this->sendResponse([
                'status' => 'chunk-success',
                'chunkIndex' => $chunkIndex,
                'remaining' => $totalChunks - $chunkIndex - 1,
            ]);
        } catch (Exception $e) {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN); // Lock freigeben
                fclose($lock);
                rex_file::delete($lockFile);
            }
            $this->cleanupChunks($fileChunkDir); // Räume die Chunks weg
            $this->log('error', 'Chunk upload error: ' . $e->getMessage());
            $this->sendResponse(['error' => $e->getMessage()], rex_response::HTTP_BAD_REQUEST);
        }
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

        $this->log('info', "Processing standard upload for file: {$file['name']}, ID: $fileId");

        // Metadaten aus der Vorbereitungsphase laden
        $metadata = [];
        if ('' !== $fileId) {
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

        // Validierung der Dateigröße
        $maxSize = (int) rex_config::get('filepond_uploader', 'max_filesize', 10) * 1024 * 1024;
        if ($file['size'] > $maxSize) {
            throw new rex_api_exception('File too large');
        }

        // Sicherstellen, dass die temporäre Datei existiert
        if (!file_exists($file['tmp_name'])) {
            $this->log('error', "Temporary file not found: {$file['tmp_name']} - skipping upload");

            // Statt eines Exceptions geben wir einen "Erfolg" zurück,
            // aber mit dem ursprünglichen Dateinamen, damit FilePond nicht irritiert wird
            return $file['name']; // Erfolg zurückmelden, aber Upload überspringen
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

        // Verbesserte MIME-Typ-Erkennung für Chunk-Uploads mit REDAXO-eigenen Methoden
        if (str_contains($file['tmp_name'], 'upload/filepond/')) {
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
        $skipMeta = rex_session('filepond_no_meta', 'boolean', false);

        // Direkt übergebenen Parameter mit höherer Priorität berücksichtigen
        if ('1' === rex_request('skipMeta', 'string', '')) {
            $skipMeta = true;
        }

        if ($categoryId < 0) {
            $categoryId = (int) rex_config::get('filepond_uploader', 'category_id', 0);
        }

        $data = [
            'title' => $metadata['title'] ?? rex_string::normalize(pathinfo($originalName, PATHINFO_FILENAME)),
            'category_id' => $categoryId,
            'file' => [
                'name' => $originalName,
                'tmp_name' => $file['tmp_name'],
                'type' => $file['type'],
                'size' => $file['size'],
            ],
        ];

        try {
            // Erneut prüfen, ob die Datei noch existiert, direkt vor dem Upload
            if (!file_exists($file['tmp_name'])) {
                $this->log('error', "File not found before upload: {$file['tmp_name']}");
                return $file['name']; // Erfolg zurückmelden, aber Upload überspringen
            }

            // Übergebe die Datei an den MediaPool, der sich um Dateinamen-Duplizierung kümmert
            $result = rex_media_service::addMedia($data, true);
            if ($result['ok']) {
                if (!$skipMeta && [] !== $metadata) {
                    $sql = rex_sql::factory();
                    $sql->setTable(rex::getTable('media'));
                    $sql->setWhere(['filename' => $result['filename']]);

                    // Standard-Felder verarbeiten
                    $sql->setValue('title', $metadata['title'] ?? '');

                    // Prüfen, ob Bild als dekorativ markiert ist
                    $isDecorative = isset($metadata['decorative']) && true === $metadata['decorative'];

                    // Alt-Text und Copyright nur setzen, wenn nicht übersprungen und nicht dekorativ
                    if (!$isDecorative) {
                        // Prüfe ob alt-Text mehrsprachig ist
                        if (isset($metadata['med_alt']) && is_array($metadata['med_alt'])) {
                            // Mehrsprachiges Alt-Text Feld - konvertiere zu MetaInfo Lang Fields Format
                            $langData = $this->convertToMetaInfoLangFormat($metadata['med_alt']);
                            $sql->setValue('med_alt', json_encode($langData));
                        } else {
                            // Standard Alt-Text
                            $sql->setValue('med_alt', $metadata['alt'] ?? $metadata['med_alt'] ?? '');
                        }

                        // Prüfe ob Copyright mehrsprachig ist
                        if (isset($metadata['med_copyright']) && is_array($metadata['med_copyright'])) {
                            // Mehrsprachiges Copyright Feld
                            $langData = $this->convertToMetaInfoLangFormat($metadata['med_copyright']);
                            $sql->setValue('med_copyright', json_encode($langData));
                        } else {
                            // Standard Copyright
                            $sql->setValue('med_copyright', $metadata['copyright'] ?? $metadata['med_copyright'] ?? '');
                        }

                        // Weitere MetaInfo-Felder verarbeiten
                        $this->processAdditionalMetaInfoFields($sql, $metadata);
                    } elseif ($isDecorative) {
                        // Bei dekorativen Bildern leeren Alt-Text setzen
                        $sql->setValue('med_alt', '');
                    }

                    $sql->update();
                }

                // YCom-Media-Auth-Defaults aus der Backend-Session anwenden (optional, gegated)
                $this->applyYcomMediaAuthDefaults($result['filename']);

                return $result['filename'];
            }

            throw new rex_api_exception(implode(', ', $result['messages']));
        } catch (Exception $e) {
            throw new rex_api_exception('Upload failed: ' . $e->getMessage());
        } finally {
            // Aufräumen, wenn die Datei eine temporäre war (Chunk-Upload)
            if (str_contains($file['tmp_name'], 'upload/filepond/') && file_exists($file['tmp_name'])) {
                rex_file::delete($file['tmp_name']);
            }
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
        imagedestroy($srcImage);
        imagedestroy($dstImage);
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
                    imagedestroy($image);
                    return;
                }
                break;
            case 3: // 180 rotate
                $rotated = imagerotate($image, 180, 0);
                if (false === $rotated) {
                    $this->log('error', 'Failed to rotate image 180 degrees');
                    imagedestroy($image);
                    return;
                }
                imagedestroy($image);
                $image = $rotated;
                break;
            case 4: // Vertical flip
                if (!imageflip($image, IMG_FLIP_VERTICAL)) {
                    $this->log('error', 'Failed to flip image vertically (orientation 4)');
                    imagedestroy($image);
                    return;
                }
                break;
            case 5: // Vertical flip + 90 rotate clockwise
                if (!imageflip($image, IMG_FLIP_VERTICAL)) {
                    $this->log('error', 'Failed to flip image vertically before rotation (orientation 5)');
                    imagedestroy($image);
                    return;
                }
                $rotated = imagerotate($image, -90, 0);
                if (false === $rotated) {
                    $this->log('error', 'Failed to rotate image -90 degrees after vertical flip');
                    imagedestroy($image);
                    return;
                }
                imagedestroy($image);
                $image = $rotated;
                break;
            case 6: // 90 rotate clockwise
                $rotated = imagerotate($image, -90, 0);
                if (false === $rotated) {
                    $this->log('error', 'Failed to rotate image -90 degrees');
                    imagedestroy($image);
                    return;
                }
                imagedestroy($image);
                $image = $rotated;
                break;
            case 7: // Horizontal flip + 90 rotate clockwise
                if (!imageflip($image, IMG_FLIP_HORIZONTAL)) {
                    $this->log('error', 'Failed to flip image horizontally before rotation (orientation 7)');
                    imagedestroy($image);
                    return;
                }
                $rotated = imagerotate($image, -90, 0);
                if (false === $rotated) {
                    $this->log('error', 'Failed to rotate image -90 degrees after horizontal flip');
                    imagedestroy($image);
                    return;
                }
                imagedestroy($image);
                $image = $rotated;
                break;
            case 8: // 90 rotate counter-clockwise
                $rotated = imagerotate($image, 90, 0);
                if (false === $rotated) {
                    $this->log('error', 'Failed to rotate image 90 degrees');
                    imagedestroy($image);
                    return;
                }
                imagedestroy($image);
                $image = $rotated;
                break;
        }

        // Get image quality setting
        $quality = rex_config::get('filepond_uploader', 'image_quality', 90);

        // Save the corrected image with error handling
        if (!@imagejpeg($image, $tmpFile, $quality)) {
            $this->log('error', 'Failed to save EXIF-corrected image to file: ' . $tmpFile);
            imagedestroy($image);
            return;
        }

        imagedestroy($image);

        $this->log('info', 'EXIF orientation corrected successfully');
    }

    /**
     * @return never
     */
    protected function handleDelete(): void
    {
        $filename = trim(rex_request('filename', 'string', ''));

        if ('' === $filename) {
            throw new rex_api_exception('Missing filename');
        }

        try {
            $media = rex_media::get($filename);
            if (null !== $media) {
                $inUse = false;

                $sql = rex_sql::factory();
                $yformTables = rex_yform_manager_table::getAll();

                foreach ($yformTables as $table) {
                    foreach ($table->getFields() as $field) {
                        if ('value' === $field->getType() && 'filepond' === $field->getTypeName()) {
                            $tableName = $sql->escapeIdentifier($table->getTableName());
                            $fieldName = $sql->escapeIdentifier($field->getName());
                            $filePattern = '%' . str_replace(['%', '_'], ['\%', '\_'], $filename) . '%';
                            $query = "SELECT id FROM $tableName WHERE $fieldName LIKE :filename";

                            try {
                                $result = $sql->getArray($query, [':filename' => $filePattern]);
                                if (count($result) > 0) {
                                    $inUse = true;
                                    break 2;
                                }
                            } catch (Exception $e) {
                                continue;
                            }
                        }
                    }
                }

                if (!$inUse) {
                    rex_media_service::deleteMedia($filename);
                    $this->sendResponse(['status' => 'success']);
                } else {
                    $this->sendResponse(['status' => 'success']);
                }
            } else {
                $this->sendResponse(['status' => 'success']);
            }
        } catch (rex_api_exception $e) {
            throw new rex_api_exception('Error deleting file: ' . $e->getMessage());
        }
    }

    /**
     * @return never
     */
    protected function handleLoad(): void
    {
        $filename = rex_request('filename', 'string');
        if ('' === $filename) {
            throw new rex_api_exception('Missing filename');
        }

        $media = rex_media::get($filename);
        if (null !== $media) {
            $file = rex_path::media($filename);
            if (file_exists($file)) {
                rex_response::cleanOutputBuffers();
                rex_response::sendFile(
                    $file,
                    $media->getType(),
                    'inline',
                    $media->getFileName(),
                );
                exit;
            }
        }

        throw new rex_api_exception('File not found');
    }

    /**
     * @return never
     */
    protected function handleRestore(): void
    {
        $filename = rex_request('filename', 'string');
        if ('' === $filename) {
            throw new rex_api_exception('Missing filename');
        }

        if (null !== rex_media::get($filename)) {
            $this->sendResponse(['status' => 'success']);
        } else {
            throw new rex_api_exception('File not found in media pool');
        }
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
     * Behandelt die Finalisierung eines Chunk-Uploads ohne neuen Chunk zu senden.
     *
     * @return array<string, string>
     */
    protected function handleFinalizeUpload(int $categoryId): array
    {
        // Dateiinformationen aus dem Request holen
        $fileId = rex_request('fileId', 'string', '');
        $fieldName = rex_request('fieldName', 'string', 'filepond');
        $fileName = rex_request('fileName', 'string', '');
        $totalChunks = rex_request('totalChunks', 'int', 0);

        $this->log('info', "Finalizing chunk upload for file: $fileName, ID: $fileId, total chunks: $totalChunks");

        if ('' === $fileId) {
            throw new rex_api_exception('Missing fileId');
        }

        // Metadaten laden
        $metaFile = $this->metadataDir . '/' . $fileId . '.json';

        if (!file_exists($metaFile)) {
            $this->log('warning', "Metadata file not found for $fileId, creating fallback metadata");

            // Fallback-Metadaten erstellen
            $fallbackMetadata = [
                'metadata' => [
                    'title' => pathinfo($fileName, PATHINFO_FILENAME),
                    'alt' => pathinfo($fileName, PATHINFO_FILENAME),
                    'copyright' => '',
                ],
                'fileName' => $fileName,
                'fieldName' => $fieldName,
                'timestamp' => time(),
            ];

            // Verzeichnis erstellen, wenn es nicht existiert
            rex_dir::create($this->metadataDir);

            // Fallback-Metadaten speichern
            rex_file::put($metaFile, (string) json_encode($fallbackMetadata));

            $metaData = $fallbackMetadata;
        } else {
            $metaContentFinalize = rex_file::get($metaFile);
            if (null === $metaContentFinalize) {
                throw new rex_api_exception('Could not read metadata file');
            }
            $decodedFinalize = json_decode($metaContentFinalize, true);
            $metaData = is_array($decodedFinalize) ? $decodedFinalize : [];
        }

        // Temporäre Datei, die alle zusammengeführten Chunks enthält
        $tmpFile = rex_path::addonData('filepond_uploader', 'upload/') . $fileId;
        $fileChunkDir = $this->chunksDir . '/' . $fileId;

        // Überprüfen, ob die zusammengeführte Datei bereits existiert
        if (!file_exists($tmpFile)) {
            $this->log('info', 'Merged file does not exist yet, merging chunks now');

            // Chunks zusammenführen
            $out = fopen($tmpFile, 'w');
            if (false === $out) {
                throw new rex_api_exception('Could not create output file');
            }

            // Dateisystem-Cache leeren vor dem Auflisten der Chunks
            clearstatcache();

            // Chunk-Zählung und Validierung
            if (!file_exists($fileChunkDir)) {
                throw new rex_api_exception("Chunk directory not found: $fileChunkDir");
            }

            $files = scandir($fileChunkDir);
            $actualChunks = 0;
            $chunkFiles = [];
            foreach ($files as $f) {
                if ('.' !== $f && '..' !== $f && '.lock' !== $f && is_file($fileChunkDir . '/' . $f)) {
                    ++$actualChunks;
                    $chunkFiles[] = $f;
                }
            }

            $this->log('info', "Expected $totalChunks chunks, found $actualChunks");

            // Sortierte Auflistung der gefundenen Chunks
            sort($chunkFiles, SORT_NUMERIC);
            $this->log('debug', 'Chunk files (sorted): ' . implode(', ', $chunkFiles));

            if ($actualChunks < $totalChunks) {
                fclose($out);
                throw new rex_api_exception("Expected $totalChunks chunks, but found only $actualChunks");
            }

            // Chunks in der richtigen Reihenfolge zusammenfügen
            for ($i = 0; $i < $totalChunks; ++$i) {
                $chunkPath = $fileChunkDir . '/' . $i;
                if (!file_exists($chunkPath)) {
                    fclose($out);
                    throw new rex_api_exception("Chunk $i is missing");
                }

                $in = fopen($chunkPath, 'r');
                if (false === $in) {
                    fclose($out);
                    throw new rex_api_exception("Could not open chunk $i for reading");
                }

                stream_copy_to_stream($in, $out);
                fclose($in);
            }

            fclose($out);
            $this->log('info', 'All chunks merged successfully');
        } else {
            $this->log('info', "Using existing merged file: $tmpFile");
        }

        // Dateityp und Größe ermitteln
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $type = $finfo->file($tmpFile);
        if (false === $type) {
            $type = 'application/octet-stream';
        }
        $finalSize = filesize($tmpFile);
        if (false === $finalSize) {
            $finalSize = 0;
        }

        $this->log('info', "Final file type: $type, size: $finalSize bytes");

        // Datei zum Medienpool hinzufügen
        $uploadedFile = [
            'name' => (string) ($metaData['fileName'] ?? $fileName),
            'type' => $type,
            'tmp_name' => $tmpFile,
            'size' => $finalSize,
            'metadata' => $metaData['metadata'] ?? [],
        ];

        // skipMeta-Parameter berücksichtigen
        if ('1' === rex_request('skipMeta', 'string', '')) {
            /** @phpstan-ignore disallowed.variable */
            $_REQUEST['skipMeta'] = '1'; // Sicherstellen, dass der Parameter auch für processUploadedFile verfügbar ist
        }

        // Verarbeite die vollständige Datei
        $result = $this->processUploadedFile($uploadedFile, $categoryId);

        // Aufräumen - Chunks und Metadaten löschen
        $this->cleanupChunks($fileChunkDir);
        rex_file::delete($metaFile);

        return [
            'status' => 'success',
            'filename' => $result, // Der tatsächliche Dateiname im Medienpool
            'originalname' => $fileName, // Der ursprüngliche Dateiname
        ];
    }

    /**
     * Löscht eine Datei aus dem Medienpool, wenn der Metadaten-Dialog abgebrochen wurde
     * Diese Methode wird aufgerufen, wenn eine Datei zwar hochgeladen, aber der Metadaten-Dialog abgebrochen wurde
     * Die Datei soll dann nicht im Medienpool bleiben, sondern komplett gelöscht werden.
     *
     * @return array<string, string>
     */
    protected function handleCancelUpload(): array
    {
        $filename = trim(rex_request('filename', 'string', ''));

        if ('' === $filename) {
            throw new rex_api_exception('Missing filename');
        }

        $this->log('info', "Removing file after metadata dialog was cancelled: $filename");

        try {
            $media = rex_media::get($filename);
            if (null !== $media) {
                // Prüfen, ob die Datei in Verwendung ist, sollte normalerweise nicht der Fall sein
                // da sie gerade erst hochgeladen wurde und der Dialog abgebrochen wurde
                $inUse = false;

                // Lösche die Datei aus dem Medienpool
                rex_media_service::deleteMedia($filename);
                $this->log('info', "Successfully removed file from media pool: $filename");
                return [
                    'status' => 'success',
                    'message' => "File $filename removed successfully",
                ];
            }
            $this->log('warning', "File not found in media pool: $filename");
            return [
                'status' => 'success',
                'message' => "File $filename not found in media pool",
            ];
        } catch (Exception $e) {
            $this->log('error', 'Error removing file: ' . $e->getMessage());
            throw new rex_api_exception('Error removing file: ' . $e->getMessage());
        }
    }

    /**
     * Konvertiert Frontend-Sprachdaten ins MetaInfo Lang Fields Format
     * Frontend: {"de": "Text", "en": "Text"}
     * MetaInfo: [{"clang_id": 1, "value": "Text"}, {"clang_id": 2, "value": "Text"}].
     *
     * @param array<int|string, mixed> $fieldValue
     * @return list<array{clang_id: int, value: string}>
     */
    private function convertToMetaInfoLangFormat(array $fieldValue): array
    {
        $result = [];
        $languages = rex_clang::getAll();

        foreach ($fieldValue as $langCode => $value) {
            // Finde Sprach-ID anhand des Codes
            foreach ($languages as $clang) {
                if ($clang->getCode() === (string) $langCode) {
                    $result[] = [
                        'clang_id' => $clang->getId(),
                        'value' => (string) $value,
                    ];
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Verarbeitet zusätzliche MetaInfo-Felder.
     *
     * @param array<string, mixed> $metadata
     */
    private function processAdditionalMetaInfoFields(rex_sql $sql, array $metadata): void
    {
        // Zusätzliche Felder die verarbeitet werden sollen
        $additionalFields = [
            'med_description',
            'med_description_lang',
            'med_title_lang',
            'med_keywords',
            'med_keywords_lang',
            'med_source',
        ];

        foreach ($additionalFields as $fieldName) {
            if (isset($metadata[$fieldName])) {
                if (is_array($metadata[$fieldName])) {
                    // Mehrsprachiges Feld
                    $sanitizedArray = $this->sanitizeMetaInfoValue($metadata[$fieldName]);
                    if (is_array($sanitizedArray)) {
                        $langData = $this->convertToMetaInfoLangFormat($sanitizedArray);
                        $sql->setValue($fieldName, json_encode($langData));
                    }
                } else {
                    // Standard-Feld
                    $sanitizedValue = $this->sanitizeMetaInfoValue($metadata[$fieldName]);
                    if (is_string($sanitizedValue)) {
                        $sql->setValue($fieldName, $sanitizedValue);
                    }
                }
            }
        }
    }

    /**
     * Sanitize a metadata value (string or array).
     *
     * @return array<int|string, mixed>|string
     */
    private function sanitizeMetaInfoValue(mixed $value): array|string
    {
        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $k => $v) {
                // Recursively sanitize for nested arrays (e.g., multilingual fields)
                $sanitized[$k] = $this->sanitizeMetaInfoValue($v);
            }
            return $sanitized;
        }
        // Sanitize string: trim, remove dangerous chars but keep basic formatting
        $sanitized = trim((string) $value);
        // Remove potential script tags and other dangerous content
        $sanitized = (string) preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi', '', $sanitized);
        $sanitized = (string) preg_replace('/javascript:/i', '', $sanitized);
        $sanitized = (string) preg_replace('/on\w+\s*=/i', '', $sanitized);
        return $sanitized;
    }
}
