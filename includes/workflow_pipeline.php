<?php

/**
 * Workflow Pipeline Rendering Helper
 *
 * Provides renderWorkflowPipelineStage() — a single reusable function that
 * renders one stage cell in the workflow pipeline grid, complete with an
 * accessible hover/focus/touch tooltip showing who is responsible.
 *
 * Usage (in a view file):
 *
 *   require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/workflow_pipeline.php';
 *   require_once $_SERVER['DOCUMENT_ROOT'] . '/services/WorkflowResponsibilityService.php';
 *
 *   $svc = new WorkflowResponsibilityService($pdo);
 *   $responsibilities = $svc->getPipelineResponsibility(
 *       $pipelineStages, $request, $current, $approvals, $_SESSION['role_name']
 *   );
 *
 *   foreach ($pipelineStages as $stageKey => $stageInfo):
 *       echo renderWorkflowPipelineStage(
 *           $stageKey, $stageInfo, $stageIdx, $totalStages,
 *           $currentIdx, $responsibilities[$stageKey] ?? []
 *       );
 *   endforeach;
 *
 * The function returns a safe HTML string ready for echo.  All user-supplied
 * strings are escaped with htmlspecialchars() before output.
 */

/**
 * Render a single pipeline stage cell with a responsibility tooltip.
 *
 * @param string $stageKey      Status constant, e.g. 'HOD_APPROVED'
 * @param array  $stageInfo     ['label' => string, 'icon' => string] from pipeline map
 * @param int    $stageIdx      0-based position in the ordered pipeline
 * @param int    $totalStages   Total number of pipeline stages
 * @param int    $currentIdx    0-based index of the current status stage (-1 if unknown)
 * @param array  $responsibility From WorkflowResponsibilityService::getStageResponsibility()
 *
 * @return string Safe HTML
 */
function renderWorkflowPipelineStage(
    string $stageKey,
    array  $stageInfo,
    int    $stageIdx,
    int    $totalStages,
    int    $currentIdx,
    array  $responsibility
): string {

    $isCompleted = ($currentIdx !== -1 && $stageIdx < $currentIdx);
    $isCurrent   = ($stageIdx === $currentIdx);
    $isPending   = !$isCompleted && !$isCurrent;

    // Unique tooltip ID (safe for use as HTML id attribute)
    $tooltipId = 'wf-tip-' . preg_replace('/[^A-Za-z0-9]/', '-', $stageKey) . '-' . $stageIdx;

    // ── Stage state classes ──────────────────────────────────────────────────
    if ($isCompleted) {
        $borderClass = 'border-success bg-success bg-opacity-10';
        $circleClass = 'bg-success text-white';
        $circleContent = '<i class="bi bi-check-lg" aria-hidden="true"></i>';
        $stateLabelClass = '';
    } elseif ($isCurrent) {
        $borderClass = 'border-primary bg-primary bg-opacity-10';
        $circleClass = 'bg-primary text-white';
        $circleContent = '<i class="bi bi-arrow-right" aria-hidden="true"></i>';
        $stateLabelClass = '';
    } else {
        $borderClass = 'border-light bg-light';
        $circleClass = 'bg-secondary bg-opacity-25 text-muted';
        $circleContent = (string)($stageIdx + 1);
        $stateLabelClass = 'text-muted';
    }

    $label = htmlspecialchars($stageInfo['label'] ?? '');

    // ── Screen-reader state description ─────────────────────────────────────
    $stateText = $isCompleted ? 'Completed' : ($isCurrent ? 'In progress' : 'Pending');

    // ── Build tooltip content ────────────────────────────────────────────────
    $tooltipHtml = buildResponsibilityTooltip($stageKey, $stageInfo, $stateText, $responsibility);

    // ── Render ──────────────────────────────────────────────────────────────
    $out  = '<div class="col-lg col-md-3 col-sm-4 col-6">' . "\n";
    $out .= '  <div class="pipeline-stage-cell text-center p-2 rounded-3 border ' . $borderClass . ' h-100"'
          . ' tabindex="0"'
          . ' role="button"'
          . ' aria-label="' . $label . ': ' . $stateText . '"'
          . ' aria-describedby="' . $tooltipId . '"'
          . '>' . "\n";

    // Circle indicator
    $out .= '    <div class="rounded-circle d-inline-flex align-items-center justify-content-center fw-bold '
          . $circleClass . ' mb-1"'
          . ' style="width:32px;height:32px;font-size:.85rem;" aria-hidden="true">'
          . $circleContent
          . '</div>' . "\n";

    // Stage label
    $out .= '    <div class="small fw-semibold ' . $stateLabelClass . '" style="line-height:1.2">'
          . $label
          . '</div>' . "\n";

    // Tooltip panel (hidden by default, shown on hover/focus/touch via CSS+JS)
    $out .= '    <div id="' . $tooltipId . '"'
          . ' role="tooltip"'
          . ' class="stage-responsibility-tooltip"'
          . '>' . $tooltipHtml . '</div>' . "\n";

    $out .= '  </div>' . "\n";
    $out .= '</div>' . "\n";

    return $out;
}

/**
 * Build the inner HTML of a responsibility tooltip.
 *
 * @param string $stageKey      Status key
 * @param array  $stageInfo     Stage metadata
 * @param string $stateText     'Completed' | 'In progress' | 'Pending'
 * @param array  $responsibility From WorkflowResponsibilityService
 *
 * @return string Safe HTML (not escaped at the outer level — inner values are)
 */
function buildResponsibilityTooltip(
    string $stageKey,
    array  $stageInfo,
    string $stateText,
    array  $responsibility
): string {

    $label  = htmlspecialchars($stageInfo['label'] ?? $stageKey);
    $state  = htmlspecialchars($stateText);
    $isConf = (bool)($responsibility['is_configured'] ?? false);

    $html  = '<div class="wf-tooltip-title">' . $label . '</div>';
    $html .= '<div class="wf-tooltip-state">' . $state . '</div>';

    if (!$isConf) {
        $html .= '<div class="wf-tooltip-row wf-tooltip-fallback">Responsibility not configured</div>';
        return $html;
    }

    $role   = htmlspecialchars($responsibility['responsible_role']   ?? '');
    $source = htmlspecialchars($responsibility['source_type']        ?? '');
    $action = htmlspecialchars($responsibility['action_description'] ?? '');

    if ($role !== '') {
        $html .= '<div class="wf-tooltip-row"><span class="wf-tooltip-key">Responsible role:</span> ' . $role . '</div>';
    }

    // Multiple named officers (e.g. Requestor + Branch Head, or Procurement
    // Officer + Director of Procurement). Rendered one row per officer so
    // duplicates already removed by the service are never shown twice.
    $officers = $responsibility['assigned_officers'] ?? [];

    if (!empty($officers) && $stateText !== 'Completed') {
        foreach ($officers as $officer) {
            $officerRole = htmlspecialchars($officer['role'] ?? '');
            $officerName = $officer['name'] ?? null;

            if ($officerName !== null) {
                $html .= '<div class="wf-tooltip-row"><span class="wf-tooltip-key">' . $officerRole . ':</span> '
                       . htmlspecialchars($officerName) . '</div>';
            } else {
                $html .= '<div class="wf-tooltip-row wf-tooltip-fallback"><span class="wf-tooltip-key">'
                       . $officerRole . ':</span> Not yet assigned</div>';
            }
        }
    } else {
        // Assigned user (pending stage)
        $assignedUser = $responsibility['assigned_user'] ?? null;
        if ($assignedUser !== null && $stateText !== 'Completed') {
            $html .= '<div class="wf-tooltip-row"><span class="wf-tooltip-key">Assigned to:</span> '
                   . htmlspecialchars($assignedUser) . '</div>';
        }
    }

    $assignedUser = $responsibility['assigned_user'] ?? null;

    // Source type label (skip for simple 'Assigned by job title' when no named user)
    if ($source !== '' && !($source === 'Assigned by job title' && $assignedUser === null)) {
        $html .= '<div class="wf-tooltip-row wf-tooltip-source">' . $source . '</div>';
    }

    // Completer info (completed stage)
    $completerName = $responsibility['completer_name'] ?? null;
    $completerRole = $responsibility['completer_role'] ?? null;
    if ($completerName !== null) {
        $html .= '<div class="wf-tooltip-row"><span class="wf-tooltip-key">Completed by:</span> '
               . htmlspecialchars($completerName) . '</div>';
        if ($completerRole !== null) {
            $html .= '<div class="wf-tooltip-row wf-tooltip-source">' . htmlspecialchars($completerRole) . '</div>';
        }
    }

    if ($action !== '') {
        $html .= '<div class="wf-tooltip-action">' . $action . '</div>';
    }

    return $html;
}
