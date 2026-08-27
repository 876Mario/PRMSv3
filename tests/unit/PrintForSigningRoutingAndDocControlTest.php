<?php

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/procurement/print_for_signing_helpers.php';

class PrintForSigningRoutingAndDocControlTest extends PHPUnit\Framework\TestCase
{
    public function testRequestTypeToModuleNormalizesTypeNames(): void
    {
        $this->assertSame('reimbursement', requestTypeToModule('REIMBURSEMENT'));
        $this->assertSame('petty_cash', requestTypeToModule('  PETTY CASH  '));
        $this->assertSame('gc10_a', requestTypeToModule('GC10-A'));
    }

    public function testLoadDocControlSettingsReturnsTypeSpecificSettingsWhenPresent(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE doc_ctrl_settings (id INTEGER PRIMARY KEY, request_type TEXT, form_revision TEXT, effective_date TEXT, dcr_number TEXT)');
        $pdo->prepare('INSERT INTO doc_ctrl_settings (id, request_type, form_revision, effective_date, dcr_number) VALUES (?, ?, ?, ?, ?)')
            ->execute([1, 'REGULAR', 'v1.0', '2026-01-01', 'DCR-1']);
        $pdo->prepare('INSERT INTO doc_ctrl_settings (id, request_type, form_revision, effective_date, dcr_number) VALUES (?, ?, ?, ?, ?)')
            ->execute([2, 'REIMBURSEMENT', 'v2.0', '2026-02-01', 'DCR-2']);

        $settings = loadDocControlSettings($pdo, 'REIMBURSEMENT');

        $this->assertSame('v2.0', $settings['form_revision'] ?? null);
        $this->assertSame('DCR-2', $settings['dcr_number'] ?? null);
    }

    public function testLoadDocControlSettingsReturnsEmptyWhenTypeColumnExistsButNoMatch(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE doc_ctrl_settings (id INTEGER PRIMARY KEY, request_type TEXT, form_revision TEXT, effective_date TEXT, dcr_number TEXT)');
        $pdo->prepare('INSERT INTO doc_ctrl_settings (id, request_type, form_revision, effective_date, dcr_number) VALUES (?, ?, ?, ?, ?)')
            ->execute([1, 'REGULAR', 'v1.0', '2026-01-01', 'DCR-1']);

        $settings = loadDocControlSettings($pdo, 'PETTY_CASH');

        $this->assertSame([], $settings);
    }

    public function testLoadDocControlSettingsFallsBackToLegacyRowWhenSchemaLacksTypeColumn(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE doc_ctrl_settings (id INTEGER PRIMARY KEY, form_revision TEXT, effective_date TEXT, dcr_number TEXT)');
        $pdo->prepare('INSERT INTO doc_ctrl_settings (id, form_revision, effective_date, dcr_number) VALUES (?, ?, ?, ?)')
            ->execute([1, 'legacy-v1', '2025-01-01', 'LEGACY-1']);

        $settings = loadDocControlSettings($pdo, 'REIMBURSEMENT');

        $this->assertSame('legacy-v1', $settings['form_revision'] ?? null);
        $this->assertSame('LEGACY-1', $settings['dcr_number'] ?? null);
    }
}
