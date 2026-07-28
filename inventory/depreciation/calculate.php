<?php
/**
 * Asset Depreciation Calculation Engine
 * ======================================
 * Supports three methods:
 *   - STRAIGHT_LINE       : (cost - salvage) / useful_life_years  per year
 *   - DECLINING_BALANCE   : book_value * rate  per year
 *   - UNITS_OF_PRODUCTION : (cost - salvage) / total_units * units_used  per period
 */

/**
 * Generate a full depreciation schedule (array of periods).
 *
 * @param string     $method          'STRAIGHT_LINE'|'DECLINING_BALANCE'|'UNITS_OF_PRODUCTION'
 * @param float      $cost            Acquisition / cost basis
 * @param float      $salvageValue    Residual value at end of life
 * @param float|null $usefulLifeYears Useful life in years (required for SL & DB)
 * @param float|null $rate            Annual rate 0–1 (required for DB, e.g. 0.20 = 20%)
 * @param float|null $totalUnits      Lifetime production units (required for UoP)
 * @param array      $unitsPerPeriod  [period => units_consumed] for UoP (indexed from 1)
 * @param string     $startDate       'YYYY-MM-DD' — date placed in service
 *
 * @return array{periods:array,total_depreciation:float,final_book_value:float,error:?string}
 */
function calculateDepreciationSchedule(
    string $method,
    float  $cost,
    float  $salvageValue,
    ?float $usefulLifeYears,
    ?float $rate,
    ?float $totalUnits,
    array  $unitsPerPeriod = [],
    string $startDate = ''
): array {
    $periods        = [];
    $accumulated    = 0.0;
    $bookValue      = $cost;
    $depreciableBase = max(0.0, $cost - $salvageValue);

    if ($startDate === '') {
        $startDate = date('Y-m-d');
    }
    $start = new DateTime($startDate);

    switch (strtoupper($method)) {

        case 'STRAIGHT_LINE':
            if ($usefulLifeYears === null || $usefulLifeYears <= 0) {
                return depErrorSchedule('Useful life must be greater than zero for Straight-Line.');
            }
            $annualCharge = $depreciableBase / $usefulLifeYears;
            $numPeriods   = (int)ceil($usefulLifeYears);

            for ($p = 1; $p <= $numPeriods; $p++) {
                $pStart = (clone $start)->modify('+' . ($p - 1) . ' year');
                $pEnd   = (clone $pStart)->modify('+1 year -1 day');

                // Last period may be fractional
                $charge = ($p === $numPeriods && fmod($usefulLifeYears, 1.0) > 0)
                    ? $depreciableBase - $accumulated
                    : $annualCharge;
                $charge = max(0.0, min($charge, $bookValue - $salvageValue));

                $accumulated += $charge;
                $bookValue   -= $charge;
                $periods[]    = depBuildPeriod($p, $pStart, $pEnd, $charge, $accumulated, $bookValue, null);
            }
            break;

        case 'DECLINING_BALANCE':
            if ($rate === null || $rate <= 0 || $rate >= 1) {
                return depErrorSchedule('Declining balance rate must be between 0 and 1 (exclusive).');
            }
            if ($usefulLifeYears === null || $usefulLifeYears <= 0) {
                return depErrorSchedule('Useful life must be greater than zero for Declining Balance.');
            }
            $numPeriods = (int)ceil($usefulLifeYears);

            for ($p = 1; $p <= $numPeriods; $p++) {
                $pStart = (clone $start)->modify('+' . ($p - 1) . ' year');
                $pEnd   = (clone $pStart)->modify('+1 year -1 day');

                $charge = $bookValue * $rate;
                $charge = max(0.0, min($charge, $bookValue - $salvageValue));

                $accumulated += $charge;
                $bookValue   -= $charge;
                $periods[]    = depBuildPeriod($p, $pStart, $pEnd, $charge, $accumulated, $bookValue, null);

                if ($bookValue <= $salvageValue + 0.005) {
                    break;
                }
            }
            break;

        case 'UNITS_OF_PRODUCTION':
            if ($totalUnits === null || $totalUnits <= 0) {
                return depErrorSchedule('Total production units must be greater than zero.');
            }
            $ratePerUnit = $depreciableBase / $totalUnits;
            $numPeriods  = max(1, count($unitsPerPeriod));

            for ($p = 1; $p <= $numPeriods; $p++) {
                $pStart = (clone $start)->modify('+' . ($p - 1) . ' year');
                $pEnd   = (clone $pStart)->modify('+1 year -1 day');

                $units  = (float)($unitsPerPeriod[$p] ?? 0);
                $charge = max(0.0, min($ratePerUnit * $units, $bookValue - $salvageValue));

                $accumulated += $charge;
                $bookValue   -= $charge;
                $periods[]    = depBuildPeriod($p, $pStart, $pEnd, $charge, $accumulated, $bookValue, $units);
            }
            break;

        default:
            return depErrorSchedule("Unknown depreciation method: {$method}");
    }

    return [
        'periods'            => $periods,
        'total_depreciation' => round($accumulated, 2),
        'final_book_value'   => round($bookValue, 2),
        'error'              => null,
    ];
}

function depBuildPeriod(int $p, DateTime $s, DateTime $e, float $charge, float $acc, float $bv, ?float $units): array {
    return [
        'period_number'            => $p,
        'period_start'             => $s->format('Y-m-d'),
        'period_end'               => $e->format('Y-m-d'),
        'depreciation_charge'      => round($charge, 2),
        'accumulated_depreciation' => round($acc, 2),
        'book_value_end'           => round($bv, 2),
        'units_consumed'           => $units,
    ];
}

function depErrorSchedule(string $msg): array {
    return ['periods' => [], 'total_depreciation' => 0.0, 'final_book_value' => 0.0, 'error' => $msg];
}

/**
 * Persist a generated schedule and its periods to the database.
 * Deactivates any prior schedule for the same asset first.
 *
 * @return int  New schedule_id
 */
function saveDepreciationSchedule(PDO $pdo, int $itemId, int $assetDetailId, array $params, array $schedule): int {
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE asset_depreciation_schedules SET is_active = 0 WHERE item_id = ?")
            ->execute([$itemId]);

        $lastPeriod = end($schedule['periods']);
        $endDate    = $lastPeriod ? $lastPeriod['period_end'] : null;

        $ins = $pdo->prepare("
            INSERT INTO asset_depreciation_schedules
              (item_id, asset_detail_id, method, cost_basis, salvage_value,
               useful_life_years, total_production_units, declining_balance_rate,
               start_date, end_date, is_active, generated_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
        ");
        $ins->execute([
            $itemId, $assetDetailId,
            $params['method'],
            $params['cost'],
            $params['salvage_value']          ?? 0,
            $params['useful_life_years']      ?? null,
            $params['total_production_units'] ?? null,
            $params['rate']                   ?? null,
            $params['start_date'],
            $endDate,
            $_SESSION['user_id'] ?? null,
        ]);
        $scheduleId = (int)$pdo->lastInsertId();

        $ins = $pdo->prepare("
            INSERT INTO asset_depreciation_periods
              (schedule_id, period_number, period_start_date, period_end_date,
               units_consumed, depreciation_charge, accumulated_depreciation, book_value_end)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        foreach ($schedule['periods'] as $p) {
            $ins->execute([
                $scheduleId, $p['period_number'],
                $p['period_start'], $p['period_end'],
                $p['units_consumed'],
                $p['depreciation_charge'],
                $p['accumulated_depreciation'],
                $p['book_value_end'],
            ]);
        }

        $pdo->commit();
        return $scheduleId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
