<?php
/**
 * SignedRequestService
 * Centralized service for managing signed request document uploads, versioning, and validation
 * Supports REGULAR, REIMBURSEMENT, and PETTY_CASH request types
 */

class SignedRequestService {
    private $pdo;
    private $uploadBasePath;
    private $maxFileSize = 26214400; // 25MB in bytes
    private $allowedMimeTypes = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx'
    ];

    public function __construct(PDO $pdo, $uploadBasePath = null) {
        $this->pdo = $pdo;
        $this->uploadBasePath = $uploadBasePath ?? $_SERVER['DOCUMENT_ROOT'] . '/uploads/signed_requests';
    }

    /**
     * Check if a signed request document upload is pending (required before workflow progression)
     * Applies to SUBMITTED status only
     */
    public function isUploadPending($requestId, $requestType = 'REGULAR') {
        $stmt = $this->pdo->prepare("
            SELECT signed_request_document_path 
            FROM procurement_requests 
            WHERE request_id = ? AND status = 'SUBMITTED'
        ");
        $stmt->execute([$requestId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            return false; // Not in SUBMITTED status, gate doesn't apply
        }
        
        // Gate applies to all request types that require signed requests
        return empty($result['signed_request_document_path']);
    }

    /**
     * Validate file before upload
     * Returns array: ['valid' => bool, 'error' => string or null]
     */
    public function validateFile($fileArray) {
        if (!isset($fileArray) || $fileArray['error'] === UPLOAD_ERR_NO_FILE) {
            return ['valid' => false, 'error' => 'No file provided'];
        }

        if ($fileArray['error'] !== UPLOAD_ERR_OK) {
            return ['valid' => false, 'error' => $this->getUploadErrorMessage($fileArray['error'])];
        }

        // Check file size
        if ($fileArray['size'] > $this->maxFileSize) {
            return ['valid' => false, 'error' => 'File exceeds maximum size of 25MB'];
        }

        if ($fileArray['size'] <= 0) {
            return ['valid' => false, 'error' => 'File is empty'];
        }

        // Validate MIME type using finfo
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $fileArray['tmp_name']);
        finfo_close($finfo);

        if (!isset($this->allowedMimeTypes[$mimeType])) {
            return ['valid' => false, 'error' => "File type not allowed. Accepted: PDF, JPG, PNG, GIF, DOC, DOCX. Got: " . htmlspecialchars($mimeType)];
        }

        // Additional magic byte check for common formats
        if (!$this->validateMagicBytes($fileArray['tmp_name'], $mimeType)) {
            return ['valid' => false, 'error' => 'File appears to be corrupted or mismatched with its extension'];
        }

        return [
            'valid' => true,
            'error' => null,
            'mime_type' => $mimeType
        ];
    }

    /**
     * Validate file magic bytes to prevent file type spoofing
     */
    private function validateMagicBytes($filePath, $mimeType) {
        $magicBytes = [
            'application/pdf' => [0x25, 0x50, 0x44, 0x46], // %PDF
            'image/jpeg' => [0xFF, 0xD8, 0xFF],
            'image/png' => [0x89, 0x50, 0x4E, 0x47],
            'image/gif' => [0x47, 0x49, 0x46]
        ];

        if (!isset($magicBytes[$mimeType])) {
            return true; // Skip validation for types without known magic bytes
        }

        $file = fopen($filePath, 'rb');
        if (!$file) return false;

        $bytes = array_values(unpack('C*', fread($file, 4)));
        fclose($file);

        $expectedBytes = $magicBytes[$mimeType];
        for ($i = 0; $i < count($expectedBytes); $i++) {
            if ($bytes[$i] !== $expectedBytes[$i]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get user-friendly upload error message
     */
    private function getUploadErrorMessage($errorCode) {
        $errors = [
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize in php.ini',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE in form',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder on server',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension'
        ];
        return $errors[$errorCode] ?? 'Unknown upload error';
    }

    /**
     * Process and store uploaded signed request document
     * Returns array: ['success' => bool, 'message' => string, 'path' => string or null]
     */
    public function uploadDocument($requestId, $requestType, $fileArray, $uploadedByUserId) {
        // Validate input
        if (!in_array($requestType, ['REGULAR', 'REIMBURSEMENT', 'PETTY_CASH'])) {
            return ['success' => false, 'message' => 'Invalid request type'];
        }

        // Validate file
        $validation = $this->validateFile($fileArray);
        if (!$validation['valid']) {
            return ['success' => false, 'message' => $validation['error']];
        }

        // Verify request exists and user has permission
        $stmt = $this->pdo->prepare("
            SELECT request_id, request_type, created_by, status 
            FROM procurement_requests 
            WHERE request_id = ? AND request_type = ?
        ");
        $stmt->execute([$requestId, $requestType]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            return ['success' => false, 'message' => 'Request not found or type mismatch'];
        }

        // Verify permission: creator, HOD, Branch Head, or authorized roles
        $authorizedRoles = ['Procurement Officer', 'Admin', 'SuperAdmin'];
        $isAuthorized = (
            $_SESSION['user_id'] == $request['created_by'] ||
            in_array($_SESSION['role_name'] ?? '', $authorizedRoles)
        );

        if (!$isAuthorized) {
            // Log unauthorized attempt
            logAudit(
                $this->pdo,
                'signed_request_documents',
                $requestId,
                'UNAUTHORIZED_UPLOAD',
                'User ' . ($_SESSION['full_name'] ?? $_SESSION['user_id']) .
                ' attempted to upload signed document without authorization'
            );
            return ['success' => false, 'message' => 'You do not have permission to upload documents for this request'];
        }

        try {
            $this->pdo->beginTransaction();

            // Create type-specific upload directory
            $typeDir = $this->uploadBasePath . '/' . strtolower($requestType);
            if (!is_dir($typeDir)) {
                if (!mkdir($typeDir, 0750, true)) {
                    throw new Exception("Failed to create upload directory: " . htmlspecialchars($typeDir));
                }
            }

            // Generate secure filename
            $mimeType = $validation['mime_type'];
            $ext = $this->allowedMimeTypes[$mimeType];
            $timestamp = time();
            $randomHash = bin2hex(random_bytes(8));
            $safeFilename = sprintf(
                'SIGNED_%s_%d_%d_%s.%s',
                strtoupper($requestType),
                $requestId,
                $timestamp,
                $randomHash,
                $ext
            );

            $fullPath = $typeDir . '/' . $safeFilename;
            $relativePath = '/uploads/signed_requests/' . strtolower($requestType) . '/' . $safeFilename;

            // Move uploaded file
            if (!move_uploaded_file($fileArray['tmp_name'], $fullPath)) {
                throw new Exception('Failed to move uploaded file to storage location');
            }

            // Ensure proper permissions
            chmod($fullPath, 0640);

            // Get current version count
            $versionStmt = $this->pdo->prepare("
                SELECT COALESCE(MAX(version_number), 0) as current_version 
                FROM signed_request_documents 
                WHERE request_id = ? AND is_deleted = 0
            ");
            $versionStmt->execute([$requestId]);
            $versionResult = $versionStmt->fetch(PDO::FETCH_ASSOC);
            $newVersion = $versionResult['current_version'] + 1;

            // Mark previous active document as inactive
            $updatePrevStmt = $this->pdo->prepare("
                UPDATE signed_request_documents 
                SET is_active = 0 
                WHERE request_id = ? AND is_active = 1
            ");
            $updatePrevStmt->execute([$requestId]);

            // Insert new document record
            $docStmt = $this->pdo->prepare("
                INSERT INTO signed_request_documents 
                (request_id, request_type, document_path, file_name, original_file_name, 
                 file_type, file_size, version_number, is_active, uploaded_by_user_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
            ");
            $docStmt->execute([
                $requestId,
                $requestType,
                $relativePath,
                $safeFilename,
                basename($fileArray['name']),
                $mimeType,
                $fileArray['size'],
                $newVersion,
                $uploadedByUserId
            ]);

            // Update procurement_requests with signed document info
            $updateReqStmt = $this->pdo->prepare("
                UPDATE procurement_requests 
                SET signed_request_document_path = ?,
                    signed_request_received_date = NOW(),
                    signed_by_user_id = ?,
                    signed_request_version_count = ?,
                    signed_request_active_since = NOW()
                WHERE request_id = ?
            ");
            $updateReqStmt->execute([
                $relativePath,
                $uploadedByUserId,
                $newVersion,
                $requestId
            ]);

            $this->pdo->commit();

            // Log the successful upload
            logAudit(
                $this->pdo,
                'signed_request_documents',
                $requestId,
                'DOCUMENT_UPLOADED',
                'Signed ' . $requestType . ' request document uploaded by ' .
                ($_SESSION['full_name'] ?? $_SESSION['user_id']) .
                ' (version ' . $newVersion . '). File: ' . htmlspecialchars(basename($fileArray['name']))
            );

            return [
                'success' => true,
                'message' => 'Signed document uploaded successfully',
                'path' => $relativePath,
                'version' => $newVersion
            ];

        } catch (Exception $e) {
            $this->pdo->rollBack();
            
            // Clean up partial upload if it exists
            if (isset($fullPath) && file_exists($fullPath)) {
                unlink($fullPath);
            }

            error_log("Signed request upload error for request $requestId: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error saving document: ' . htmlspecialchars($e->getMessage())];
        }
    }

    /**
     * Get active signed document for a request
     */
    public function getActiveDocument($requestId) {
        $stmt = $this->pdo->prepare("
            SELECT srd.*, COALESCE(u.full_name, 'Unknown') as uploaded_by_name
            FROM signed_request_documents srd
            LEFT JOIN users u ON srd.uploaded_by_user_id = u.user_id
            WHERE srd.request_id = ? AND srd.is_active = 1 AND srd.is_deleted = 0
            LIMIT 1
        ");
        $stmt->execute([$requestId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Get all versions of signed document (history)
     */
    public function getDocumentHistory($requestId) {
        $stmt = $this->pdo->prepare("
            SELECT srd.*, COALESCE(u.full_name, 'Unknown') as uploaded_by_name
            FROM signed_request_documents srd
            LEFT JOIN users u ON srd.uploaded_by_user_id = u.user_id
            WHERE srd.request_id = ? AND srd.is_deleted = 0
            ORDER BY srd.uploaded_at DESC
        ");
        $stmt->execute([$requestId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Check if user can upload signed document for this request
     */
    public function canUserUpload($requestId, $requestType, $userId, $userRole) {
        $stmt = $this->pdo->prepare("
            SELECT created_by FROM procurement_requests 
            WHERE request_id = ? AND request_type = ?
        ");
        $stmt->execute([$requestId, $requestType]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) return false;

        $authorizedRoles = ['Procurement Officer', 'Admin', 'SuperAdmin'];
        return (
            $userId == $request['created_by'] ||
            in_array($userRole, $authorizedRoles)
        );
    }

    /**
     * Soft-delete a signed document (preserve audit trail)
     */
    public function deleteDocument($docId, $deletedByUserId) {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("
                UPDATE signed_request_documents 
                SET is_deleted = 1, deleted_by_user_id = ?, deleted_at = NOW(), is_active = 0
                WHERE doc_id = ?
            ");
            $stmt->execute([$deletedByUserId, $docId]);

            $this->pdo->commit();
            return true;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Error soft-deleting signed document: " . $e->getMessage());
            return false;
        }
    }
}
?>
