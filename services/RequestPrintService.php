<?php
/**
 * RequestPrintService - Generate request-type-specific approval forms
 * 
 * Handles PDF generation for all request types:
 * - Procurement (regular)
 * - Reimbursement
 * - Petty Cash
 * 
 * Each request type generates a form specific to its requirements with:
 * - Request-specific fields and labels
 * - Type-appropriate approval sections
 * - Relevant document attachments
 * - Workflow-appropriate metadata
 */

class RequestPrintService {
    
    private $pdo;
    private $request;
    private $requestType;
    private $documentControlSettings;
    
    public function __construct($pdo, $request) {
        $this->pdo = $pdo;
        $this->request = $request;
        $this->requestType = $request['request_type'] ?? 'REGULAR';
        $this->loadDocumentControlSettings();
    }
    
    /**
     * Load document control settings for the request type
     */
    private function loadDocumentControlSettings() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT id, request_type, form_revision, effective_date, dcr_number,
                       updated_at, updated_by_id, updated_by_name
                FROM doc_ctrl_settings 
                WHERE request_type = ?
                LIMIT 1
            ");
            $stmt->execute([$this->requestType]);
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($settings) && !empty($settings)) {
                $this->documentControlSettings = $settings;
                return;
            }
        } catch (Exception $e) {
            // Fallback handled below for legacy/global single-row settings.
        }

        try {
            $stmt = $this->pdo->query("
                SELECT id, request_type, form_revision, effective_date, dcr_number,
                       updated_at, updated_by_id, updated_by_name
                FROM doc_ctrl_settings
                WHERE id = 1
                LIMIT 1
            ");
            $this->documentControlSettings = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Exception $e) {
            $this->documentControlSettings = [];
        }
    }
    
    /**
     * Validate document control settings are configured
     * 
     * @return array Array with 'valid' boolean and 'missing' array of missing fields
     */
    public function validateDocumentControlSettings() {
        $missing = [];
        
        if (empty($this->documentControlSettings['form_revision'])) {
            $missing[] = '<strong>Form Revision</strong>';
        }
        if (empty($this->documentControlSettings['effective_date'])) {
            $missing[] = '<strong>Effective Date</strong>';
        }
        if (empty($this->documentControlSettings['dcr_number'])) {
            $missing[] = '<strong>DCR Number</strong>';
        }
        
        return [
            'valid' => empty($missing),
            'missing' => $missing
        ];
    }
    
    /**
     * Generate HTML content for the approval form based on request type
     * 
     * @return string HTML content for the form
     */
    public function generateFormHTML() {
        switch ($this->requestType) {
            case 'REGULAR':
                return $this->generateProcurementForm();
            case 'REIMBURSEMENT':
                return $this->generateReimbursementForm();
            case 'PETTY_CASH':
                return $this->generatePettyCashForm();
            default:
                throw new Exception("Unknown request type: " . htmlspecialchars($this->requestType));
        }
    }
    
    /**
     * Generate procurement request approval form
     */
    private function generateProcurementForm() {
        $html = '';
        $html .= $this->generateFormHeader('PROCUREMENT REQUEST FOR APPROVAL');
        $html .= $this->generateDocumentControlSection();
        $html .= $this->generateRequestInfoSection();
        $html .= $this->generateProcurementDescriptionSection();
        $html .= $this->generateItemsTable();
        $html .= $this->generateProcurementDetailsSection();
        $html .= $this->generateProcurementSignatureSection();
        $html .= $this->generateFormFooter();
        
        return $html;
    }
    
    /**
     * Generate reimbursement invoice approval form
     */
    private function generateReimbursementForm() {
        $html = '';
        $html .= $this->generateFormHeader('REIMBURSEMENT INVOICE FOR APPROVAL');
        $html .= $this->generateDocumentControlSection();
        $html .= $this->generateRequestInfoSection();
        $html .= $this->generateReimbursementDetailsSection();
        $html .= $this->generateReimbursementAmountSection();
        $html .= $this->generateReimbursementSignatureSection();
        $html .= $this->generateFormFooter();
        
        return $html;
    }
    
    /**
     * Generate petty cash reconciliation approval form
     */
    private function generatePettyCashForm() {
        $html = '';
        $html .= $this->generateFormHeader('PETTY CASH RECONCILIATION FOR APPROVAL');
        $html .= $this->generateDocumentControlSection();
        $html .= $this->generateRequestInfoSection();
        $html .= $this->generatePettyCashDetailsSection();
        $html .= $this->generatePettyCashReconciliationSection();
        $html .= $this->generatePettyCashSignatureSection();
        $html .= $this->generateFormFooter();
        
        return $html;
    }
    
    /**
     * Generate form header with branding
     */
    private function generateFormHeader($title) {
        return <<<HTML
        <div style="
            background-color: #1f4788;
            color: white;
            padding: 20px;
            text-align: center;
            margin-bottom: 20px;
            border-bottom: 3px solid #d4af37;
        ">
            <h1 style="margin: 0; font-size: 24px; font-weight: bold;">GOVERNMENT CHEMIST DIRECTORATE</h1>
            <h2 style="margin: 10px 0 0 0; font-size: 18px; font-weight: normal;">$title</h2>
        </div>
        <div style="text-align: right; font-size: 11px; margin-bottom: 15px; color: #666;">
            Generated: {DATE} at {TIME}
        </div>
        HTML;
    }
    
    /**
     * Generate document control section
     */
    private function generateDocumentControlSection() {
        $rev = htmlspecialchars($this->documentControlSettings['form_revision'] ?? 'N/A');
        $eff = htmlspecialchars($this->documentControlSettings['effective_date'] ?? 'N/A');
        $dcr = htmlspecialchars($this->documentControlSettings['dcr_number'] ?? 'N/A');
        
        return <<<HTML
        <table style="width: 100%; margin-bottom: 20px; font-size: 11px; border: 1px solid #ccc;">
            <tr style="background-color: #f5f5f5;">
                <td style="padding: 8px; border-right: 1px solid #ccc; font-weight: bold;">Form Revision:</td>
                <td style="padding: 8px;">$rev</td>
                <td style="padding: 8px; border-right: 1px solid #ccc; border-left: 1px solid #ccc; font-weight: bold;">Effective Date:</td>
                <td style="padding: 8px;">$eff</td>
            </tr>
            <tr style="background-color: #f9f9f9;">
                <td colspan="4" style="padding: 8px; border-top: 1px solid #ccc; font-weight: bold;">DCR Number: $dcr</td>
            </tr>
        </table>
        HTML;
    }
    
    /**
     * Generate basic request information section
     */
    private function generateRequestInfoSection() {
        $reqNum = htmlspecialchars($this->request['request_number'] ?? 'N/A');
        $reqDate = htmlspecialchars($this->request['request_date'] ?? date('Y-m-d'));
        $branch = htmlspecialchars($this->request['branch_name'] ?? 'N/A');
        $creator = htmlspecialchars($this->request['created_by_name'] ?? 'N/A');
        $desc = htmlspecialchars($this->request['description'] ?? '');
        
        return <<<HTML
        <table style="width: 100%; margin-bottom: 20px; font-size: 12px; border-collapse: collapse;">
            <tr style="background-color: #e8e8e8;">
                <td colspan="4" style="padding: 8px; font-weight: bold; border: 1px solid #999;">REQUEST INFORMATION</td>
            </tr>
            <tr>
                <td style="padding: 8px; border: 1px solid #ccc; width: 25%; font-weight: bold;">Request Number:</td>
                <td style="padding: 8px; border: 1px solid #ccc; width: 25%;">$reqNum</td>
                <td style="padding: 8px; border: 1px solid #ccc; width: 25%; font-weight: bold;">Request Date:</td>
                <td style="padding: 8px; border: 1px solid #ccc; width: 25%;">$reqDate</td>
            </tr>
            <tr>
                <td style="padding: 8px; border: 1px solid #ccc; font-weight: bold;">Branch:</td>
                <td style="padding: 8px; border: 1px solid #ccc;">$branch</td>
                <td style="padding: 8px; border: 1px solid #ccc; font-weight: bold;">Requestor:</td>
                <td style="padding: 8px; border: 1px solid #ccc;">$creator</td>
            </tr>
            <tr>
                <td colspan="4" style="padding: 8px; border: 1px solid #ccc;">
                    <strong>Description/Purpose:</strong><br/>
                    <div style="margin-top: 5px; padding: 10px; background-color: #f9f9f9; min-height: 60px;">$desc</div>
                </td>
            </tr>
        </table>
        HTML;
    }
    
    /**
     * Generate procurement-specific description section
     */
    private function generateProcurementDescriptionSection() {
        // This is merged into request info for procurement, so return empty
        return '';
    }
    
    /**
     * Generate reimbursement-specific details section
     */
    private function generateReimbursementDetailsSection() {
        $invoiceId = htmlspecialchars($this->request['invoice_id'] ?? 'N/A');
        $amount = htmlspecialchars($this->request['estimated_value'] ?? '0.00');
        $currency = htmlspecialchars($this->request['currency'] ?? 'USD');
        
        return <<<HTML
        <table style="width: 100%; margin-bottom: 20px; font-size: 12px; border-collapse: collapse;">
            <tr style="background-color: #e8e8e8;">
                <td colspan="4" style="padding: 8px; font-weight: bold; border: 1px solid #999;">INVOICE DETAILS</td>
            </tr>
            <tr>
                <td style="padding: 8px; border: 1px solid #ccc; width: 25%; font-weight: bold;">Invoice ID:</td>
                <td style="padding: 8px; border: 1px solid #ccc; width: 25%;">$invoiceId</td>
                <td style="padding: 8px; border: 1px solid #ccc; width: 25%; font-weight: bold;">Total Amount:</td>
                <td style="padding: 8px; border: 1px solid #ccc; width: 25%;">$amount $currency</td>
            </tr>
            <tr>
                <td colspan="4" style="padding: 8px; border: 1px solid #ccc;">
                    <strong>Purpose of Reimbursement:</strong><br/>
                    <div style="margin-top: 5px; padding: 10px; background-color: #f9f9f9; min-height: 60px;">
                        {DESCRIPTION}
                    </div>
                </td>
            </tr>
        </table>
        HTML;
    }
    
    /**
     * Generate petty cash-specific details section
     */
    private function generatePettyCashDetailsSection() {
        $amount = htmlspecialchars($this->request['estimated_value'] ?? '0.00');
        $currency = htmlspecialchars($this->request['currency'] ?? 'USD');
        
        return <<<HTML
        <table style="width: 100%; margin-bottom: 20px; font-size: 12px; border-collapse: collapse;">
            <tr style="background-color: #e8e8e8;">
                <td colspan="2" style="padding: 8px; font-weight: bold; border: 1px solid #999;">DISBURSEMENT DETAILS</td>
            </tr>
            <tr>
                <td style="padding: 8px; border: 1px solid #ccc; width: 50%; font-weight: bold;">Amount Authorized:</td>
                <td style="padding: 8px; border: 1px solid #ccc; width: 50%;">$amount $currency</td>
            </tr>
            <tr>
                <td colspan="2" style="padding: 8px; border: 1px solid #ccc;">
                    <strong>Purpose:</strong><br/>
                    <div style="margin-top: 5px; padding: 10px; background-color: #f9f9f9; min-height: 60px;">
                        {DESCRIPTION}
                    </div>
                </td>
            </tr>
        </table>
        HTML;
    }
    
    /**
     * Generate items table for procurement requests
     */
    private function generateItemsTable() {
        if ($this->requestType !== 'REGULAR') {
            return '';
        }
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT item_name, specification, quantity, remarks
                FROM procurement_request_items
                WHERE request_id = ?
                ORDER BY item_id ASC
            ");
            $stmt->execute([$this->request['request_id']]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $items = [];
        }
        
        if (empty($items)) {
            return '';
        }
        
        $html = <<<HTML
        <table style="width: 100%; margin-bottom: 20px; font-size: 11px; border-collapse: collapse;">
            <tr style="background-color: #e8e8e8;">
                <td colspan="4" style="padding: 8px; font-weight: bold; border: 1px solid #999;">REQUESTED ITEMS</td>
            </tr>
            <tr style="background-color: #f5f5f5;">
                <td style="padding: 8px; border: 1px solid #ccc; font-weight: bold; width: 35%;">Item Name</td>
                <td style="padding: 8px; border: 1px solid #ccc; font-weight: bold; width: 30%;">Specification</td>
                <td style="padding: 8px; border: 1px solid #ccc; font-weight: bold; width: 15%;">Quantity</td>
                <td style="padding: 8px; border: 1px solid #ccc; font-weight: bold; width: 20%;">Remarks</td>
            </tr>
        HTML;
        
        foreach ($items as $item) {
            $name = htmlspecialchars($item['item_name'] ?? '');
            $spec = htmlspecialchars($item['specification'] ?? '');
            $qty = htmlspecialchars($item['quantity'] ?? '');
            $remarks = htmlspecialchars($item['remarks'] ?? '');
            
            $html .= <<<HTML
            <tr>
                <td style="padding: 8px; border: 1px solid #ccc;">$name</td>
                <td style="padding: 8px; border: 1px solid #ccc;">$spec</td>
                <td style="padding: 8px; border: 1px solid #ccc; text-align: center;">$qty</td>
                <td style="padding: 8px; border: 1px solid #ccc;">$remarks</td>
            </tr>
            HTML;
        }
        
        $html .= '</table>';
        return $html;
    }
    
    /**
     * Generate procurement-specific details section
     */
    private function generateProcurementDetailsSection() {
        $method = htmlspecialchars($this->request['procurement_method'] ?? 'N/A');
        $value = htmlspecialchars($this->request['estimated_value'] ?? '0.00');
        $currency = htmlspecialchars($this->request['currency'] ?? 'USD');
        
        return <<<HTML
        <table style="width: 100%; margin-bottom: 20px; font-size: 12px; border-collapse: collapse;">
            <tr style="background-color: #e8e8e8;">
                <td colspan="2" style="padding: 8px; font-weight: bold; border: 1px solid #999;">PROCUREMENT DETAILS</td>
            </tr>
            <tr>
                <td style="padding: 8px; border: 1px solid #ccc; width: 50%; font-weight: bold;">Procurement Method:</td>
                <td style="padding: 8px; border: 1px solid #ccc; width: 50%;">$method</td>
            </tr>
            <tr>
                <td style="padding: 8px; border: 1px solid #ccc; font-weight: bold;">Estimated Value:</td>
                <td style="padding: 8px; border: 1px solid #ccc;">$value $currency</td>
            </tr>
        </table>
        HTML;
    }
    
    /**
     * Generate reimbursement amount section
     */
    private function generateReimbursementAmountSection() {
        $amount = htmlspecialchars($this->request['estimated_value'] ?? '0.00');
        $currency = htmlspecialchars($this->request['currency'] ?? 'USD');
        
        return <<<HTML
        <table style="width: 100%; margin-bottom: 20px; font-size: 12px; border-collapse: collapse;">
            <tr style="background-color: #e8e8e8;">
                <td style="padding: 8px; font-weight: bold; border: 1px solid #999;">AMOUNT SUMMARY</td>
            </tr>
            <tr>
                <td style="padding: 8px; border: 1px solid #ccc;">
                    <strong>Total Reimbursement Amount:</strong> $amount $currency
                </td>
            </tr>
        </table>
        HTML;
    }
    
    /**
     * Generate petty cash reconciliation section
     */
    private function generatePettyCashReconciliationSection() {
        $authorized = htmlspecialchars($this->request['estimated_value'] ?? '0.00');
        $currency = htmlspecialchars($this->request['currency'] ?? 'USD');
        
        return <<<HTML
        <table style="width: 100%; margin-bottom: 20px; font-size: 12px; border-collapse: collapse;">
            <tr style="background-color: #e8e8e8;">
                <td colspan="2" style="padding: 8px; font-weight: bold; border: 1px solid #999;">RECONCILIATION NOTES</td>
            </tr>
            <tr>
                <td colspan="2" style="padding: 8px; border: 1px solid #ccc;">
                    <strong>Total Authorized Amount:</strong> $authorized $currency<br/><br/>
                    <strong>Reconciliation Deadline:</strong> 24 hours from disbursement<br/><br/>
                    <strong>Supporting Documents Required:</strong>
                    <ul style="margin: 10px 0;">
                        <li>Receipts for all purchases</li>
                        <li>Invoices/proof of purchase</li>
                        <li>Change return documentation (if applicable)</li>
                    </ul>
                </td>
            </tr>
        </table>
        HTML;
    }
    
    /**
     * Generate procurement signature section
     */
    private function generateProcurementSignatureSection() {
        return <<<HTML
        <table style="width: 100%; margin-bottom: 20px; font-size: 12px; border-collapse: collapse;">
            <tr style="background-color: #e8e8e8;">
                <td colspan="2" style="padding: 8px; font-weight: bold; border: 1px solid #999;">AUTHORIZATION SIGNATURES</td>
            </tr>
            <tr>
                <td style="padding: 15px; border: 1px solid #ccc; width: 50%; text-align: center;">
                    <strong>Branch Head Approval</strong><br/><br/><br/>
                    ___________________________<br/>
                    Signature & Date
                </td>
                <td style="padding: 15px; border: 1px solid #ccc; width: 50%; text-align: center;">
                    <strong>Finance Officer Authorization</strong><br/><br/><br/>
                    ___________________________<br/>
                    Signature & Date
                </td>
            </tr>
        </table>
        HTML;
    }
    
    /**
     * Generate reimbursement signature section
     */
    private function generateReimbursementSignatureSection() {
        return <<<HTML
        <table style="width: 100%; margin-bottom: 20px; font-size: 12px; border-collapse: collapse;">
            <tr style="background-color: #e8e8e8;">
                <td colspan="2" style="padding: 8px; font-weight: bold; border: 1px solid #999;">AUTHORIZATION SIGNATURES</td>
            </tr>
            <tr>
                <td style="padding: 15px; border: 1px solid #ccc; width: 50%; text-align: center;">
                    <strong>Finance Officer Verification</strong><br/><br/><br/>
                    ___________________________<br/>
                    Signature & Date
                </td>
                <td style="padding: 15px; border: 1px solid #ccc; width: 50%; text-align: center;">
                    <strong>Director Approval</strong><br/><br/><br/>
                    ___________________________<br/>
                    Signature & Date
                </td>
            </tr>
        </table>
        HTML;
    }
    
    /**
     * Generate petty cash signature section
     */
    private function generatePettyCashSignatureSection() {
        return <<<HTML
        <table style="width: 100%; margin-bottom: 20px; font-size: 12px; border-collapse: collapse;">
            <tr style="background-color: #e8e8e8;">
                <td colspan="3" style="padding: 8px; font-weight: bold; border: 1px solid #999;">AUTHORIZATION & RECONCILIATION SIGNATURES</td>
            </tr>
            <tr>
                <td style="padding: 15px; border: 1px solid #ccc; width: 33%; text-align: center;">
                    <strong>Disbursement Officer</strong><br/><br/><br/>
                    ___________________________<br/>
                    Signature & Date
                </td>
                <td style="padding: 15px; border: 1px solid #ccc; width: 33%; text-align: center;">
                    <strong>Recipient</strong><br/><br/><br/>
                    ___________________________<br/>
                    Signature & Date
                </td>
                <td style="padding: 15px; border: 1px solid #ccc; width: 34%; text-align: center;">
                    <strong>Procurement Officer</strong><br/>(Reconciliation Verification)<br/><br/>
                    ___________________________<br/>
                    Signature & Date
                </td>
            </tr>
        </table>
        HTML;
    }
    
    /**
     * Generate form footer with confidentiality notice
     */
    private function generateFormFooter() {
        return <<<HTML
        <div style="margin-top: 30px; padding-top: 15px; border-top: 2px solid #ccc; font-size: 10px; color: #666;">
            <p style="margin: 5px 0;">
                <strong>CONFIDENTIAL</strong> - This document contains confidential information relating to procurement 
                and financial decisions. Unauthorized copying or distribution is prohibited.
            </p>
            <p style="margin: 5px 0;">
                For questions or corrections, please contact the Procurement Office before submitting for approval.
            </p>
        </div>
        HTML;
    }
    
    /**
     * Persist document control snapshot to database
     */
    public function persistDocumentControlSnapshot() {
        if (empty($this->documentControlSettings)) {
            return false;
        }
        
        try {
            $stmt = $this->pdo->prepare("
                UPDATE procurement_requests
                SET doc_ctrl_form_revision  = ?,
                    doc_ctrl_effective_date = ?,
                    doc_ctrl_dcr_number     = ?
                WHERE request_id = ?
            ");
            return $stmt->execute([
                $this->documentControlSettings['form_revision'],
                $this->documentControlSettings['effective_date'],
                $this->documentControlSettings['dcr_number'],
                $this->request['request_id']
            ]);
        } catch (Exception $e) {
            error_log("Failed to persist document control snapshot: " . $e->getMessage());
            return false;
        }
    }
}
?>
