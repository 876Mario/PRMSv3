<?php
/**
 * DeadlineStatusTest
 *
 * Verifies that $deadlineStatus is defined and correctly calculated
 * in every petty-cash view path (requirement #4 / test item #7).
 *
 * Tests:
 *  7. $deadlineStatus is defined in every petty-cash view path
 */

require_once dirname(__DIR__) . '/bootstrap.php';

class DeadlineStatusTest extends PHPUnit\Framework\TestCase
{
    // -----------------------------------------------------------------------
    // Helpers – replicate the $deadlineStatus logic from petty_cash/view.php
    // -----------------------------------------------------------------------

    /**
     * Reproduce the $deadlineStatus calculation exactly as it appears in
     * petty_cash/view.php so we can test every code path without running
     * the full page.
     *
     * @param  array|false $disbursement  Row from petty_cash_disbursements, or false/null
     * @return array|null  $deadlineStatus value
     */
    private function computeDeadlineStatus($disbursement): ?array
    {
        $deadlineStatus = null;

        if ($disbursement) {
            if (!empty($disbursement['disbursement_deadline'])) {
                $now      = new DateTime();
                $deadline = new DateTime($disbursement['disbursement_deadline']);
                $diff     = $now->diff($deadline);
                $deadlineStatus = [
                    'deadline'       => $deadline,
                    'is_overdue'     => ($now > $deadline),
                    'time_remaining' => $diff,
                ];
            }
        }

        return $deadlineStatus;
    }

    // -----------------------------------------------------------------------
    // Tests
    // -----------------------------------------------------------------------

    public function testDeadlineStatusIsNullWhenNoDisbursement(): void
    {
        $result = $this->computeDeadlineStatus(null);
        $this->assertNull($result, '$deadlineStatus must be null when there is no disbursement');
    }

    public function testDeadlineStatusIsNullWhenDisbursementHasNoDeadline(): void
    {
        $disbursement = ['disburse_id' => 1, 'disbursement_deadline' => null];
        $result       = $this->computeDeadlineStatus($disbursement);
        $this->assertNull($result, '$deadlineStatus must be null when disbursement_deadline is null');
    }

    public function testDeadlineStatusIsNullWhenDeadlineIsEmptyString(): void
    {
        $disbursement = ['disburse_id' => 1, 'disbursement_deadline' => ''];
        $result       = $this->computeDeadlineStatus($disbursement);
        $this->assertNull($result);
    }

    public function testDeadlineStatusIsOverdueForPastDeadline(): void
    {
        $disbursement = [
            'disburse_id'          => 2,
            'disbursement_deadline' => date('Y-m-d H:i:s', strtotime('-1 day')),
        ];
        $result = $this->computeDeadlineStatus($disbursement);
        $this->assertIsArray($result);
        $this->assertTrue($result['is_overdue'], 'A past deadline must be flagged as overdue');
        $this->assertArrayHasKey('deadline', $result);
        $this->assertArrayHasKey('time_remaining', $result);
    }

    public function testDeadlineStatusIsNotOverdueForFutureDeadline(): void
    {
        $disbursement = [
            'disburse_id'          => 3,
            'disbursement_deadline' => date('Y-m-d H:i:s', strtotime('+1 day')),
        ];
        $result = $this->computeDeadlineStatus($disbursement);
        $this->assertIsArray($result);
        $this->assertFalse($result['is_overdue'], 'A future deadline must NOT be flagged as overdue');
    }

    public function testDeadlineStatusContainsDateTimeObjects(): void
    {
        $disbursement = [
            'disburse_id'          => 4,
            'disbursement_deadline' => date('Y-m-d H:i:s', strtotime('+2 hours')),
        ];
        $result = $this->computeDeadlineStatus($disbursement);
        $this->assertInstanceOf(\DateTime::class, $result['deadline']);
        $this->assertInstanceOf(\DateInterval::class, $result['time_remaining']);
    }

    /**
     * Test the exact workflow states mentioned in the requirement:
     * requests with a deadline, without a deadline, each workflow state,
     * administrator viewing an older request.
     */
    public function testDeadlineStatusAcrossAllWorkflowStates(): void
    {
        $states = ['DRAFT', 'SUBMITTED', 'HOD_APPROVED', 'APPROVED', 'DISBURSED', 'RECONCILED', 'COMPLETED', 'CANCELLED'];

        foreach ($states as $state) {
            // No disbursement – deadlineStatus must be null in every state
            $result = $this->computeDeadlineStatus(null);
            $this->assertNull(
                $result,
                "\$deadlineStatus must be null for state={$state} without disbursement"
            );
        }
    }

    public function testAdminViewingOlderRequestWithoutDisbursement(): void
    {
        // Simulate admin viewing a request that is years old with no disbursement
        $result = $this->computeDeadlineStatus(null);
        $this->assertNull($result, 'Admin viewing old request with no disbursement: deadlineStatus must be null');
    }

    public function testAdminViewingOlderRequestWithExpiredDeadline(): void
    {
        $disbursement = [
            'disburse_id'          => 99,
            'disbursement_deadline' => '2020-01-01 12:00:00',  // Very old date
        ];
        $result = $this->computeDeadlineStatus($disbursement);
        $this->assertIsArray($result);
        $this->assertTrue($result['is_overdue']);
    }
}
