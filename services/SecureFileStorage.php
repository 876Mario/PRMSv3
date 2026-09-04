<?php

class SecureFileStorage
{
    public const PRIVATE_SCHEME = 'private://';

    public static function getPrivateStorageRoot(): string
    {
        $documentRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__)), '/');
        return dirname($documentRoot) . '/private_uploads';
    }

    public static function ensureDirectory(string $relativeDirectory): string
    {
        $normalized = trim(str_replace('..', '', $relativeDirectory), '/');
        $fullPath = self::getPrivateStorageRoot() . '/' . $normalized;

        if (!is_dir($fullPath) && !mkdir($fullPath, 0750, true) && !is_dir($fullPath)) {
            throw new RuntimeException('Failed to create upload directory.');
        }

        return $fullPath;
    }

    public static function storeUploadedFile(
        array $file,
        string $relativeDirectory,
        string $filePrefix,
        array $mimeMap,
        int $maxBytes
    ): array {
        self::assertUploadIsValid($file, $maxBytes);

        $mimeType = self::detectMimeType($file['tmp_name']);
        if (!isset($mimeMap[$mimeType])) {
            throw new RuntimeException('Invalid file type.');
        }

        self::assertMagicBytes($file['tmp_name'], $mimeType);

        $extension = $mimeMap[$mimeType];
        $directory = self::ensureDirectory($relativeDirectory);
        $safePrefix = preg_replace('/[^A-Z0-9_\-]/i', '_', $filePrefix);
        $storedName = sprintf(
            '%s_%s_%s.%s',
            trim((string)$safePrefix, '_'),
            date('YmdHis'),
            bin2hex(random_bytes(8)),
            $extension
        );
        $destination = $directory . '/' . $storedName;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new RuntimeException('Failed to save the uploaded file.');
        }

        @chmod($destination, 0640);
        $fileSize = @filesize($destination);
        if ($fileSize === false) {
            throw new RuntimeException('Failed to read uploaded file metadata.');
        }

        $relativePath = trim($relativeDirectory, '/') . '/' . $storedName;

        return [
            'stored_name' => $storedName,
            'storage_path' => self::PRIVATE_SCHEME . $relativePath,
            'mime_type' => $mimeType,
            'file_size' => (int)$fileSize,
            'original_name' => self::sanitizeOriginalFilename((string)($file['name'] ?? 'document')),
            'absolute_path' => $destination,
        ];
    }

    public static function deleteStoredFile(?string $storedPath, ?string $legacyRelativeDirectory = null): void
    {
        if (!$storedPath) {
            return;
        }

        $resolved = self::resolveStoredPath($storedPath, $legacyRelativeDirectory);
        if ($resolved !== null && is_file($resolved)) {
            @unlink($resolved);
        }
    }

    public static function resolveStoredPath(string $storedPath, ?string $legacyRelativeDirectory = null): ?string
    {
        $storedPath = trim($storedPath);
        if ($storedPath === '') {
            return null;
        }

        if (str_starts_with($storedPath, self::PRIVATE_SCHEME)) {
            $relative = trim(substr($storedPath, strlen(self::PRIVATE_SCHEME)), '/');
            if ($relative === '' || str_contains($relative, '..')) {
                return null;
            }

            $root = rtrim(self::getPrivateStorageRoot(), '/');
            if ($root === '') {
                return null;
            }

            if (!is_dir($root) && !mkdir($root, 0750, true) && !is_dir($root)) {
                return null;
            }

            $root = realpath($root) ?: $root;
            $root = rtrim(str_replace('\\', '/', $root), '/');
            $relative = ltrim(str_replace('\\', '/', $relative), '/');

            return $root . '/' . $relative;
        }

        if ($legacyRelativeDirectory !== null && !str_starts_with($storedPath, '/')) {
            $storedPath = '/' . trim($legacyRelativeDirectory, '/') . '/' . ltrim($storedPath, '/');
        }

        if (!str_starts_with($storedPath, '/uploads/')) {
            return null;
        }

        $documentRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__)));
        if ($documentRoot === false) {
            return null;
        }

        $path = $documentRoot . $storedPath;
        $directory = realpath(dirname($path));
        $uploadsRoot = realpath($documentRoot . '/uploads');
        if ($directory === false || $uploadsRoot === false || strpos($directory, $uploadsRoot) !== 0) {
            return null;
        }

        return $path;
    }

    public static function streamStoredFile(
        string $storedPath,
        string $mimeType,
        string $downloadName,
        string $action = 'download',
        ?string $legacyRelativeDirectory = null
    ): void {
        $absolutePath = self::resolveStoredPath($storedPath, $legacyRelativeDirectory);
        if ($absolutePath === null || !is_file($absolutePath) || !is_readable($absolutePath)) {
            throw new RuntimeException('File not found on server.');
        }

        $safeMime = self::sanitizeMimeType($mimeType);
        $safeName = self::sanitizeDownloadFilename($downloadName);
        $asciiFallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $safeName) ?: 'download';
        $encodedName = rawurlencode($safeName);
        $disposition = ($action === 'view' && self::isInlineMimeType($safeMime)) ? 'inline' : 'attachment';

        header('Content-Type: ' . $safeMime);
        header('Content-Length: ' . (string)filesize($absolutePath));
        header("Content-Disposition: {$disposition}; filename=\"{$asciiFallback}\"; filename*=UTF-8''{$encodedName}");
        header('Cache-Control: private, no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Content-Type-Options: nosniff');

        readfile($absolutePath);
        exit;
    }

    public static function detectStoredMimeType(string $storedPath, ?string $legacyRelativeDirectory = null): string
    {
        $absolutePath = self::resolveStoredPath($storedPath, $legacyRelativeDirectory);
        if ($absolutePath === null || !is_file($absolutePath) || !is_readable($absolutePath)) {
            return 'application/octet-stream';
        }

        try {
            return self::detectMimeType($absolutePath);
        } catch (Throwable $e) {
            return 'application/octet-stream';
        }
    }

    public static function sanitizeOriginalFilename(string $name): string
    {
        $base = basename($name);
        $sanitized = preg_replace('/[^A-Za-z0-9._\-]/', '_', $base) ?: 'document';
        return trim($sanitized, '._') !== '' ? $sanitized : 'document';
    }

    public static function sanitizeDownloadFilename(string $name): string
    {
        return self::sanitizeOriginalFilename($name);
    }

    public static function sanitizeMimeType(?string $mimeType): string
    {
        return is_string($mimeType) && preg_match('/^[A-Za-z0-9.+\-]+\/[A-Za-z0-9.+\-]+$/', $mimeType)
            ? $mimeType
            : 'application/octet-stream';
    }

    private static function assertUploadIsValid(array $file, int $maxBytes): void
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('Please select a file to upload.');
        }

        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('File upload failed. Please try again.');
        }

        $size = filesize((string)($file['tmp_name'] ?? ''));
        if ($size === false || $size <= 0) {
            throw new RuntimeException('Uploaded file is empty or unreadable.');
        }

        if ($size > $maxBytes) {
            throw new RuntimeException('File size exceeds the allowed limit.');
        }
    }

    private static function detectMimeType(string $tmpFile): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            throw new RuntimeException('File validation is unavailable.');
        }

        $mimeType = finfo_file($finfo, $tmpFile);
        finfo_close($finfo);

        if (!is_string($mimeType) || $mimeType === '') {
            throw new RuntimeException('Unable to determine file type.');
        }

        return $mimeType;
    }

    private static function assertMagicBytes(string $tmpFile, string $mimeType): void
    {
        $signatures = [
            'application/pdf' => [0x25, 0x50, 0x44, 0x46],
            'image/jpeg' => [0xFF, 0xD8, 0xFF],
            'image/png' => [0x89, 0x50, 0x4E, 0x47],
            'image/gif' => [0x47, 0x49, 0x46],
        ];

        if (!isset($signatures[$mimeType])) {
            return;
        }

        $handle = fopen($tmpFile, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to inspect uploaded file.');
        }

        $bytes = array_values(unpack('C*', fread($handle, 4)) ?: []);
        fclose($handle);

        foreach ($signatures[$mimeType] as $index => $expected) {
            if (!isset($bytes[$index]) || $bytes[$index] !== $expected) {
                throw new RuntimeException('Uploaded file content does not match the declared type.');
            }
        }
    }

    private static function isInlineMimeType(string $mimeType): bool
    {
        return in_array($mimeType, ['application/pdf', 'image/jpeg', 'image/png', 'image/gif'], true);
    }
}
