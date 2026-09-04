<?php
require_once __DIR__ . '/SecureFileStorage.php';
/**
 * RequestDocumentService - Handle signed request document uploads
 * 
 * Manages:
 * - File validation (type, size, integrity)
 * - Secure filename generation
 * - Upload directory management
 * - Database persistence (procurement_requests + signed_request_versions)
 * - Previous version tracking
 * - Authorization checks
 * - Audit logging
 * - Notification dispatch
 */

class RequestDocumentService {
    
    private $pdo;
    private $requestId;
    private $requestType;
    private $currentUserId;
    private $currentUserRole;
    private $currentUserName;
    
    // Configuration
    const STORAGE_DIRECTORY = 'signed_requests';
    const MAX_FILE_SIZE_BYTES = 25 * 1024 * 1024; // 25 MB
    const ALLOWED_MIME_TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx'
    ];
    
    public function __construct($pdo, $requestId, $currentUserId, $currentUserRole, $currentUserName) {
        $this->pdo = $pdo;
        $this->requestId = (int)$requestId;
        $this->currentUserId = (int)$currentUserId;
        $this->currentUserRole = $currentUserRole;
        $this->currentUserName = $currentUserName;
    }

    private function getUploadPermissionForType($requestType) {
        return match (strtoupper((string)$requestType)) {
            'REGULAR' => 'upload_procurement_signed_request',
            'REIMBURSEMENT' => 'upload_signed_reimbursement_document',
            'PETTY_CASH' => 'upload_signed_petty_cash_document',
            default => null,
        };
    }

    private function userHasUploadPermission($requestType) {
        $permission = $this->getUploadPermissionForType($requestType);
        if ($permission === null || !function_exists('has_permission')) {
            return false;
        }

        try {
            return has_permission($permission);
        } catch (Throwable $e) {
            return false;
        }
    }
    
    /**
     * Load request and determine type
     * 
     * @return array Request data or null if not found
     */
    public function loadRequest() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT request_id, request_number, request_type, status, created_by
                FROM procurement_requests 
                WHERE request_id = ?
            ");
            $stmt->execute([$this->requestId]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($request) {
                $this->requestType = $request['request_type'];
            }
            
            return $request;
        } catch (Exception $e) {
            throw new Exception("Failed to load request: " . $e->getMessage());
        }
    }
    
    /**
     * Check if user has authorization to upload signed request
     * 
     * @param array $request Request data
     * @return array ['authorized' => bool, 'reason' => string]
     */
    public function checkUploadAuthorization($request) {
        // Requestor can upload their own
        if ((int)$request['created_by'] === $this->currentUserId) {
            return ['authorized' => true, 'reason' => ''];
        }

        if ($this->userHasUploadPermission($request['request_type'] ?? null)) {
            return ['authorized' => true, 'reason' => ''];
        }
        
        // Log unauthorized attempt
        $this->logUnauthorizedAttempt($request, 'upload');
        
        return ['authorized' => false, 'reason' => 'You do not have permission to upload signed requests for this request.'];
    }
    
    /**
     * Check workflow constraints for document upload
     * 
     * @param array $request Request data
     * @return array ['allowed' => bool, 'reason' => string]
     */
    public function checkWorkflowConstraints($request) {
        // REIMBURSEMENT and PETTY_CASH can upload at various stages
        // REGULAR (procurement) has specific constraints
        
        if ($request['request_type'] === 'REGULAR') {
            // Procurement only allows upload when SUBMITTED
            if ($request['status'] !== 'SUBMITTED') {
                return [
                    'allowed' => false,
                    'reason' => 'Signed request can only be uploaded when request status is SUBMITTED.'
                ];
            }
        }
        
        return ['allowed' => true, 'reason' => ''];
    }
    
    /**
     * Validate uploaded file
     * 
     * @param array $file $_FILES array element
     * @return array ['valid' => bool, 'error' => string]
     */
    public function validateFile($file) {
        // Check file was actually uploaded
        if (!isset($file) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return ['valid' => false, 'error' => 'Please select a file to upload.'];
        }
        
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => 'File size exceeds server limit.',
                UPLOAD_ERR_FORM_SIZE => 'File size exceeds form limit.',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded. Please try again.',
                UPLOAD_ERR_NO_FILE => 'No file was selected.',
                UPLOAD_ERR_NO_TMP_DIR => 'Server error: missing temporary directory.',
                UPLOAD_ERR_CANT_WRITE => 'Server error: cannot write to disk.',
                UPLOAD_ERR_EXTENSION => 'File upload blocked by PHP extension.',
            ];
            $message = $errorMessages[$file['error']] ?? 'Unknown file upload error.';
            return ['valid' => false, 'error' => $message];
        }
        
        // Check file size
        if ($file['size'] > self::MAX_FILE_SIZE_BYTES) {
            return [
                'valid' => false,
                'error' => 'File size exceeds ' . (self::MAX_FILE_SIZE_BYTES / 1024 / 1024) . ' MB limit.'
            ];
        }
        
        // Check file type
        if (!function_exists('finfo_open')) {
            return ['valid' => false, 'error' => 'File type validation is not available.'];
        }
        
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            return ['valid' => false, 'error' => 'Unable to validate file type.'];
        }
        
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if ($mimeType === false || !array_key_exists($mimeType, self::ALLOWED_MIME_TYPES)) {
            return [
                'valid' => false,
                'error' => 'Invalid file type. Only PDF, images (JPG/PNG/GIF), and Word documents are allowed.'
            ];
        }
        
        return ['valid' => true, 'error' => '', 'mimeType' => $mimeType];
    }
    
    /**
     * Process and save uploaded file
     * 
     * @param array $file $_FILES array element
     * @param array $request Request data
     * @return array ['success' => bool, 'path' => string, 'error' => string]
     */
    public function processUpload($file, $request) {
        // Validate file
        $validation = $this->validateFile($file);
        if (!$validation['valid']) {
            return ['success' => false, 'path' => '', 'error' => $validation['error']];
        }
        
        try {
            $storedFile = SecureFileStorage::storeUploadedFile(
                $file,
                self::STORAGE_DIRECTORY,
                sprintf(
                    'SIGNED_%s_%d',
                    strtoupper(substr((string)($request['request_type'] ?? 'R'), 0, 1)),
                    $this->requestId
                ),
                self::ALLOWED_MIME_TYPES,
                self::MAX_FILE_SIZE_BYTES
            );
        } catch (Throwable $e) {
            return ['success' => false, 'path' => '', 'error' => $e->getMessage()];
        }

        return [
            'success' => true,
            'path' => $storedFile['storage_path'],
            'safeName' => $storedFile['stored_name'],
            'originalName' => $storedFile['original_name'],
            'size' => $storedFile['file_size'],
            'mimeType' => $storedFile['mime_type']
        ];
    }
    
    /**
     * Save signed request to database in transaction
     * 
     * @param array $fileInfo File information from processUpload
     * @param array $request Request data
     * @return array ['success' => bool, 'versionId' => int, 'error' => string]
     */
    public function saveToDatabase($fileInfo, $request) {
        if (!$fileInfo['success']) {
            return ['success' => false, 'versionId' => 0, 'error' => $fileInfo['error']];
        }
        
        // Start transaction
        $startedTransaction = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $startedTransaction = true;
        }
        
        try {
            // Mark any existing active versions as inactive
            $stmt = $this->pdo->prepare("
                UPDATE signed_request_versions
                SET is_active = 0, replaced_at = NOW(), replaced_by = ?
                WHERE request_id = ? AND is_active = 1
            ");
            $stmt->execute([$this->currentUserId, $this->requestId]);
            
            // Insert new version record
            $stmt = $this->pdo->prepare("
                INSERT INTO signed_request_versions
                (request_id, document_path, file_name, file_size, mime_type, uploaded_by, uploaded_at, is_active)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), 1)
            ");
            $stmt->execute([
                $this->requestId,
                $fileInfo['path'],
                $fileInfo['originalName'],
                $fileInfo['size'],
                $fileInfo['mimeType'],
                $this->currentUserId
            ]);
            
            $versionId = $this->pdo->lastInsertId();
            
            // Update procurement_requests with signed request info
            $stmt = $this->pdo->prepare("
                UPDATE procurement_requests
                SET signed_request_document_path = ?,
                    signed_request_received_date = NOW(),
                    signed_by_user_id = ?
                WHERE request_id = ?
            ");
            $stmt->execute([
                $fileInfo['path'],
                $this->currentUserId,
                $this->requestId
            ]);
            
            // Insert into request_documents for audit trail
            $stmt = $this->pdo->prepare("
                INSERT INTO request_documents
                (request_id, document_type, document_name, document_path, uploaded_by, notes)
                VALUES (?, 'SIGNED_REQUEST', ?, ?, ?, ?)
            ");
            $stmt->execute([
                $this->requestId,
                $fileInfo['originalName'],
                $fileInfo['path'],
                $this->currentUserId,
                'Signed request uploaded by ' . $this->currentUserName
            ]);
            
            if ($startedTransaction) {
                $this->pdo->commit();
            }
            
            return ['success' => true, 'versionId' => $versionId, 'error' => ''];
            
        } catch (Exception $e) {
            if ($startedTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return ['success' => false, 'versionId' => 0, 'error' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Log the upload action for audit trail
     */
    public function logUploadAction($request, $fileInfo) {
        try {
            $this->logAudit(
                'procurement_requests',
                $this->requestId,
                'UPDATE',
                'Signed request uploaded: ' . $fileInfo['originalName']
            );
            
            $this->logRequestTimeline(
                $this->requestId,
                'SIGNED_REQUEST_UPLOADED',
                'Signed request uploaded by ' . $this->currentUserName . ': ' . $fileInfo['originalName']
            );
            
            $this->logAdminAction(
                'DOCUMENT_UPLOAD',
                'Signed Request',
                'SIGNED_REQUEST_' . $this->requestId,
                $request['request_number'],
                'Uploaded signed approval form: ' . $fileInfo['originalName'],
                $request['status'],
                $request['status']
            );
        } catch (Exception $e) {
            // Log but don't fail the operation
            error_log("Warning: Failed to log signed request upload for request " . $this->requestId . ": " . $e->getMessage());
        }
    }
    
    /**
     * Send notifications for signed request upload
     */
    public function sendNotifications($request) {
        try {
            require_once $_SERVER['DOCUMENT_ROOT'] . '/config/notifications.php';
            
            if (function_exists('notifySignedRequestReceived')) {
                notifySignedRequestReceived($this->requestId, $request['request_number']);
            }
        } catch (Exception $e) {
            error_log("Warning: Failed to send notification for signed request " . $this->requestId . ": " . $e->getMessage());
        }
    }
    
    /**
     * Get active signed request document
     */
    public function getActiveSignedDocument() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM signed_request_versions
                WHERE request_id = ? AND is_active = 1
                ORDER BY uploaded_at DESC
                LIMIT 1
            ");
            $stmt->execute([$this->requestId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Get signed request version history
     */
    public function getVersionHistory() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM signed_request_versions
                WHERE request_id = ?
                ORDER BY uploaded_at DESC
            ");
            $stmt->execute([$this->requestId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * Log audit action
     */
    private function logAudit($tableName, $recordId, $action, $notes) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO audit_log (table_name, record_id, action, changed_by, change_date, notes)
                VALUES (?, ?, ?, ?, NOW(), ?)
            ");
            $stmt->execute([
                $tableName,
                $recordId,
                $action,
                $this->currentUserName,
                $notes
            ]);
        } catch (Exception $e) {
            error_log("Failed to log audit: " . $e->getMessage());
        }
    }
    
    /**
     * Log request timeline
     */
    private function logRequestTimeline($requestId, $action, $notes) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO audit_log (table_name, record_id, action, changed_by, change_date, notes)
                VALUES ('procurement_requests', ?, ?, ?, NOW(), ?)
            ");
            $stmt->execute([
                $requestId,
                $action,
                $this->currentUserName,
                $notes
            ]);
        } catch (Exception $e) {
            error_log("Failed to log timeline: " . $e->getMessage());
        }
    }
    
    /**
     * Log admin action
     */
    private function logAdminAction($actionType, $resourceType, $resourceId, $resourceIdentifier, $description, $statusBefore, $statusAfter) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO admin_action_log
                (admin_user_id, admin_role, action_type, resource_type, resource_id, resource_identifier,
                 action_description, status_before, status_after, ip_address, user_agent, timestamp)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $this->currentUserId,
                $this->currentUserRole,
                $actionType,
                $resourceType,
                $resourceId,
                $resourceIdentifier,
                $description,
                $statusBefore,
                $statusAfter,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]);
        } catch (Exception $e) {
            error_log("Failed to log admin action: " . $e->getMessage());
        }
    }
    
    /**
     * Log unauthorized access attempt
     */
    private function logUnauthorizedAttempt($request, $action) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO admin_action_log
                (admin_user_id, admin_role, action_type, resource_type, resource_id, resource_identifier,
                 action_description, ip_address, user_agent, timestamp)
                VALUES (?, ?, 'UNAUTHORIZED_ATTEMPT', 'REQUEST', ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $this->currentUserId,
                $this->currentUserRole,
                $this->requestId,
                $request['request_number'],
                'Unauthorized attempt to ' . $action . ' signed request for request ' . $request['request_number'],
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ]);
        } catch (Exception $e) {
            error_log("Failed to log unauthorized attempt: " . $e->getMessage());
        }
    }
}
?>
