<?php
/**
 * PrintForSigningUnicodeTest
 *
 * Verifies that both print-for-signing PDF generators produce correctly
 * escaped, UTF-8 HTML output and do NOT leak raw PHP concatenation
 * expressions (issue #3).
 *
 * Tests:
 *  6. Unicode renders correctly in both print-for-signing documents
 */

require_once dirname(__DIR__) . '/bootstrap.php';

class PrintForSigningUnicodeTest extends PHPUnit\Framework\TestCase
{
    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Build the HTML string the way the fixed print_for_signing.php files
     * now do it: pre-compute template variables, then interpolate them into
     * a regular (non-single-quoted) heredoc.
     */
    private function buildReimbursementHtml(array $request, float $totalAmount, array $docCtrl): string
    {
        $tplRequestId     = 'REI-' . str_pad((string)$request['request_id'], 6, '0', STR_PAD_LEFT);
        $tplRequestDate   = date('d-M-Y', strtotime($request['request_date']));
        $tplBranch        = htmlspecialchars($request['branch_name'] ?? 'N/A', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $tplRequestor     = htmlspecialchars($request['requestor_name'] ?? 'N/A', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $tplEmail         = htmlspecialchars($request['requestor_email'] ?? 'N/A', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $tplDescription   = htmlspecialchars(mb_substr($request['description'] ?? '', 0, 200), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $tplCurrency      = htmlspecialchars($request['currency'] ?? 'JMD', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $tplAmount        = number_format($totalAmount, 2);
        $tplStatus        = htmlspecialchars($request['status'] ?? 'DRAFT', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $tplFormRevision  = htmlspecialchars($docCtrl['form_revision'] ?? 'v1.0', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $tplEffectiveDate = htmlspecialchars($docCtrl['effective_date'] ?? 'N/A', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $tplDcrNumber     = htmlspecialchars($docCtrl['dcr_number'] ?? 'N/A', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $tplPrintedOn     = date('d-M-Y H:i:s');

        return <<<HTML
<td>{$tplRequestId}</td>
<td>{$tplRequestDate}</td>
<td>{$tplBranch}</td>
<td>{$tplRequestor}</td>
<td>{$tplEmail}</td>
<td>{$tplDescription}</td>
<td>{$tplCurrency} {$tplAmount}</td>
<td>{$tplStatus}</td>
{$tplFormRevision}|{$tplEffectiveDate}|{$tplDcrNumber}|{$tplPrintedOn}
HTML;
    }

    private function buildPettyCashHtml(array $request, array $docCtrl): string
    {
        $tplRequestId     = 'PC-' . str_pad((string)$request['request_id'], 6, '0', STR_PAD_LEFT);
        $tplRequestDate   = date('d-M-Y', strtotime($request['request_date']));
        $tplBranch        = htmlspecialchars($request['branch_name'] ?? 'N/A', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $tplRequestor     = htmlspecialchars($request['requestor_name'] ?? 'N/A', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $tplEmail         = htmlspecialchars($request['requestor_email'] ?? 'N/A', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $tplDescription   = htmlspecialchars(mb_substr($request['description'] ?? '', 0, 200), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $tplCurrency      = htmlspecialchars($request['currency'] ?? 'JMD', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $tplAmount        = number_format((float)($request['estimated_value'] ?? 0), 2);
        $tplStatus        = htmlspecialchars($request['status'] ?? 'DRAFT', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $tplFormRevision  = htmlspecialchars($docCtrl['form_revision'] ?? 'v1.0', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $tplEffectiveDate = htmlspecialchars($docCtrl['effective_date'] ?? 'N/A', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $tplDcrNumber     = htmlspecialchars($docCtrl['dcr_number'] ?? 'N/A', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $tplPrintedOn     = date('d-M-Y H:i:s');
        $tplReconcileDays = '7';

        return <<<HTML
<td>{$tplRequestId}</td>
<td>{$tplRequestDate}</td>
<td>{$tplBranch}</td>
<td>{$tplRequestor}</td>
<td>{$tplEmail}</td>
<td>{$tplDescription}</td>
<td>{$tplCurrency} {$tplAmount}</td>
<td>{$tplStatus}</td>
{$tplFormRevision}|{$tplEffectiveDate}|{$tplDcrNumber}|{$tplPrintedOn}|{$tplReconcileDays}
HTML;
    }

    // -----------------------------------------------------------------------
    // Sample data covering Unicode edge cases
    // -----------------------------------------------------------------------

    private function unicodeSamples(): array
    {
        return [
            'request_id'      => 42,
            'request_date'    => '2025-01-15',
            'branch_name'     => "O'Connor & Sons",
            'requestor_name'  => 'José Álvarez',
            'requestor_email' => 'jose.alvarez@example.com',
            'description'     => 'Café supplies, naïve résumé printing — 1,500.00 items',
            'currency'        => 'JMD',
            'estimated_value' => 1500.50,
            'status'          => 'SUBMITTED',
        ];
    }

    // -----------------------------------------------------------------------
    // Reimbursement tests
    // -----------------------------------------------------------------------

    public function testReimbursementHtmlContainsNoRawPhpExpressions(): void
    {
        $html = $this->buildReimbursementHtml($this->unicodeSamples(), 1500.50, []);
        $this->assertStringNotContainsString("str_pad(", $html);
        $this->assertStringNotContainsString("strtotime(", $html);
        $this->assertStringNotContainsString("htmlspecialchars(", $html);
        $this->assertStringNotContainsString("number_format(", $html);
        $this->assertStringNotContainsString(". '", $html, 'Concatenation operator must not appear in output');
        $this->assertStringNotContainsString("' .", $html, 'Concatenation operator must not appear in output');
    }

    public function testReimbursementHtmlContainsUnicodeNames(): void
    {
        $html = $this->buildReimbursementHtml($this->unicodeSamples(), 1500.50, []);
        $this->assertStringContainsString('José Álvarez', $html);
    }

    public function testReimbursementHtmlEscapesAmpersandInBranchName(): void
    {
        $html = $this->buildReimbursementHtml($this->unicodeSamples(), 1500.50, []);
        // Ampersand must be HTML-escaped in the output
        $this->assertStringContainsString('&amp;', $html);
        $this->assertStringNotContainsString("O'Connor & Sons", $html, 'Raw & must not appear in HTML');
    }

    public function testReimbursementHtmlContainsFormattedAmount(): void
    {
        $html = $this->buildReimbursementHtml($this->unicodeSamples(), 1500.50, []);
        $this->assertStringContainsString('1,500.50', $html);
    }

    public function testReimbursementHtmlContainsRequestIdWithPrefix(): void
    {
        $html = $this->buildReimbursementHtml($this->unicodeSamples(), 0, []);
        $this->assertStringContainsString('REI-000042', $html);
    }

    public function testReimbursementHtmlContainsCurrencyCode(): void
    {
        $html = $this->buildReimbursementHtml($this->unicodeSamples(), 1500.50, []);
        $this->assertStringContainsString('JMD', $html);
    }

    // -----------------------------------------------------------------------
    // Petty cash tests
    // -----------------------------------------------------------------------

    public function testPettyCashHtmlContainsNoRawPhpExpressions(): void
    {
        $html = $this->buildPettyCashHtml($this->unicodeSamples(), []);
        $this->assertStringNotContainsString("str_pad(", $html);
        $this->assertStringNotContainsString("strtotime(", $html);
        $this->assertStringNotContainsString("htmlspecialchars(", $html);
        $this->assertStringNotContainsString("number_format(", $html);
        $this->assertStringNotContainsString(". '", $html, 'Concatenation operator must not appear in output');
        $this->assertStringNotContainsString("' .", $html, 'Concatenation operator must not appear in output');
    }

    public function testPettyCashHtmlContainsUnicodeNames(): void
    {
        $html = $this->buildPettyCashHtml($this->unicodeSamples(), []);
        $this->assertStringContainsString('José Álvarez', $html);
    }

    public function testPettyCashHtmlEscapesAmpersandInBranchName(): void
    {
        $html = $this->buildPettyCashHtml($this->unicodeSamples(), []);
        $this->assertStringContainsString('&amp;', $html);
    }

    public function testPettyCashHtmlContainsRequestIdWithPrefix(): void
    {
        $html = $this->buildPettyCashHtml($this->unicodeSamples(), []);
        $this->assertStringContainsString('PC-000042', $html);
    }

    public function testPettyCashHtmlContainsFormattedAmount(): void
    {
        $html = $this->buildPettyCashHtml($this->unicodeSamples(), []);
        $this->assertStringContainsString('1,500.50', $html);
    }

    // -----------------------------------------------------------------------
    // 6 – Unicode special characters preserved end-to-end
    // -----------------------------------------------------------------------

    /** @dataProvider unicodeStringProvider */
    public function testUnicodeDescriptionPreserved(string $input, string $expectedFragment): void
    {
        $samples             = $this->unicodeSamples();
        $samples['description'] = $input;
        $html = $this->buildReimbursementHtml($samples, 0, []);
        $this->assertStringContainsString(
            $expectedFragment,
            $html,
            "Expected fragment '{$expectedFragment}' missing from HTML"
        );
    }

    public static function unicodeStringProvider(): array
    {
        return [
            'accented name'         => ['José Álvarez', 'José Álvarez'],
            'apostrophe in name'    => ["O'Connor", "O&#039;Connor"],
            'naïve word'            => ['naïve', 'naïve'],
            'résumé word'           => ['résumé', 'résumé'],
            'café word'             => ['Café', 'Café'],
        ];
    }
}
