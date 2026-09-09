<?php

final class ColumnManagerWidget
{
    public static function render(array $options): void
    {
        $module = (string)($options['module'] ?? '');
        if ($module === '') {
            return;
        }

        $slug = preg_replace('/[^a-z0-9_]+/i', '_', strtolower($module)) ?: 'column_manager';
        $modalId = 'columnManagerModal_' . $slug;
        $labelId = 'columnManagerLabel_' . $slug;
        $listId = 'columnManagerList_' . $slug;
        $searchId = 'columnManagerSearch_' . $slug;
        $saveBtnId = 'columnManagerSave_' . $slug;
        $resetBtnId = 'columnManagerReset_' . $slug;
        $toastId = 'columnManagerToast_' . $slug;
        $toastMsgId = 'columnManagerToastMsg_' . $slug;

        $columnsByKey = is_array($options['columns_by_key'] ?? null) ? $options['columns_by_key'] : [];
        $columnOrder = is_array($options['column_order'] ?? null) ? $options['column_order'] : array_keys($columnsByKey);
        $visibleKeys = is_array($options['visible_keys'] ?? null) ? $options['visible_keys'] : [];
        $title = (string)($options['title'] ?? 'Manage Columns');
        $description = (string)($options['description'] ?? '');
        $buttonLabel = (string)($options['button_label'] ?? 'Manage Columns');
        $buttonClass = (string)($options['button_class'] ?? 'btn btn-sm btn-outline-secondary');
        $saveEndpoint = (string)($options['save_endpoint'] ?? '/api/column_preferences.php');
        $currentSortColumn = (string)($options['current_sort_column'] ?? '');
        $currentSortDirection = (string)($options['current_sort_direction'] ?? 'ASC');
        $currentPageSize = (int)($options['current_page_size'] ?? 20);
        $perPageSelectorId = (string)($options['per_page_selector_id'] ?? '');

        ob_start();
        ?>
        <button type="button" class="<?= htmlspecialchars($buttonClass) ?>" data-bs-toggle="modal" data-bs-target="#<?= htmlspecialchars($modalId) ?>">
            <i class="bi bi-layout-three-columns"></i> <?= htmlspecialchars($buttonLabel) ?>
        </button>

        <div class="modal fade" id="<?= htmlspecialchars($modalId) ?>" tabindex="-1" aria-labelledby="<?= htmlspecialchars($labelId) ?>" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0">
                    <div class="modal-header bg-dark text-white">
                        <h5 class="modal-title" id="<?= htmlspecialchars($labelId) ?>">
                            <i class="bi bi-layout-three-columns me-2"></i><?= htmlspecialchars($title) ?>
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small mb-3">
                            <i class="bi bi-grip-vertical"></i> Drag to reorder &nbsp;·&nbsp;
                            <i class="bi bi-check2-square"></i> Check to show &nbsp;·&nbsp;
                            <i class="bi bi-search"></i> Search columns
                        </p>
                        <?php if ($description !== ''): ?>
                        <p class="text-muted small"><?= htmlspecialchars($description) ?></p>
                        <?php endif; ?>
                        <div class="mb-3">
                            <input type="search" id="<?= htmlspecialchars($searchId) ?>" class="form-control" placeholder="Search columns...">
                        </div>
                        <ul id="<?= htmlspecialchars($listId) ?>" class="list-group" style="max-height:420px;overflow-y:auto">
                            <?php foreach ($columnOrder as $key): ?>
                                <?php if (!isset($columnsByKey[$key])) { continue; } ?>
                                <?php
                                $column = $columnsByKey[$key];
                                $isLocked = !empty($column['locked']);
                                $isVisible = in_array($key, $visibleKeys, true);
                                ?>
                                <li class="list-group-item d-flex align-items-center gap-2 py-2"
                                    data-col-key="<?= htmlspecialchars($key) ?>"
                                    data-col-label="<?= htmlspecialchars(strtolower((string)$column['label'])) ?>"
                                    <?= $isLocked ? '' : 'draggable="true"' ?>>
                                    <?php if ($isLocked): ?>
                                        <i class="bi bi-lock text-muted flex-shrink-0" title="Cannot be hidden"></i>
                                        <input type="checkbox" class="form-check-input flex-shrink-0" checked disabled>
                                        <label class="form-check-label flex-grow-1 text-muted">
                                            <?= htmlspecialchars((string)$column['label']) ?>
                                            <span class="badge bg-secondary ms-1">Locked</span>
                                        </label>
                                    <?php else: ?>
                                        <i class="bi bi-grip-vertical col-drag-handle flex-shrink-0" title="Drag to reorder"></i>
                                        <input type="checkbox" class="form-check-input col-visible-check flex-shrink-0"
                                               id="<?= htmlspecialchars($slug . '_' . $key) ?>"
                                               <?= $isVisible ? 'checked' : '' ?>>
                                        <label class="form-check-label flex-grow-1" for="<?= htmlspecialchars($slug . '_' . $key) ?>">
                                            <?= htmlspecialchars((string)$column['label']) ?>
                                        </label>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <div class="modal-footer justify-content-between">
                        <button type="button" class="btn btn-outline-danger btn-sm" id="<?= htmlspecialchars($resetBtnId) ?>">
                            <i class="bi bi-arrow-counterclockwise"></i> Reset Defaults
                        </button>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-primary" id="<?= htmlspecialchars($saveBtnId) ?>">
                                <i class="bi bi-floppy"></i> Save Preferences
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:9999">
            <div id="<?= htmlspecialchars($toastId) ?>" class="toast align-items-center border-0" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="d-flex">
                    <div class="toast-body" id="<?= htmlspecialchars($toastMsgId) ?>"></div>
                    <button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            </div>
        </div>

        <script>
        (function () {
            'use strict';

            const modalId = <?= json_encode($modalId) ?>;
            const listId = <?= json_encode($listId) ?>;
            const searchId = <?= json_encode($searchId) ?>;
            const saveBtnId = <?= json_encode($saveBtnId) ?>;
            const resetBtnId = <?= json_encode($resetBtnId) ?>;
            const toastId = <?= json_encode($toastId) ?>;
            const toastMsgId = <?= json_encode($toastMsgId) ?>;
            const perPageSelectorId = <?= json_encode($perPageSelectorId) ?>;
            const endpoint = <?= json_encode($saveEndpoint) ?>;
            const moduleName = <?= json_encode($module) ?>;
            const currentSortColumn = <?= json_encode($currentSortColumn) ?>;
            const currentSortDirection = <?= json_encode($currentSortDirection) ?>;
            const currentPageSize = <?= json_encode($currentPageSize) ?>;

            const list = document.getElementById(listId);
            const search = document.getElementById(searchId);
            const saveBtn = document.getElementById(saveBtnId);
            const resetBtn = document.getElementById(resetBtnId);

            function showToast(message, type) {
                const toast = document.getElementById(toastId);
                const toastMsg = document.getElementById(toastMsgId);
                if (!toast || !toastMsg || !window.bootstrap || !bootstrap.Toast) {
                    return;
                }
                toast.className = 'toast align-items-center border-0 text-bg-' + (type || 'success');
                toastMsg.textContent = message;
                bootstrap.Toast.getOrCreateInstance(toast, { delay: 3000 }).show();
            }

            if (perPageSelectorId) {
                const perPageSelect = document.getElementById(perPageSelectorId);
                if (perPageSelect) {
                    perPageSelect.addEventListener('change', function () {
                        const url = new URL(window.location.href);
                        url.searchParams.set('per_page', this.value);
                        url.searchParams.set('page', '1');
                        window.location.href = url.toString();
                    });
                }
            }

            if (search && list) {
                search.addEventListener('input', function () {
                    const needle = this.value.trim().toLowerCase();
                    list.querySelectorAll('[data-col-key]').forEach(function (item) {
                        const haystack = item.dataset.colLabel || '';
                        item.style.display = needle === '' || haystack.indexOf(needle) !== -1 ? '' : 'none';
                    });
                });
            }

            let dragSource = null;
            if (list) {
                list.querySelectorAll('[draggable="true"]').forEach(function (item) {
                    item.addEventListener('dragstart', function (event) {
                        dragSource = this;
                        this.style.opacity = '0.45';
                        event.dataTransfer.effectAllowed = 'move';
                    });
                    item.addEventListener('dragend', function () {
                        this.style.opacity = '';
                        list.querySelectorAll('.drag-over').forEach(function (el) {
                            el.classList.remove('drag-over');
                        });
                    });
                    item.addEventListener('dragover', function (event) {
                        event.preventDefault();
                        event.dataTransfer.dropEffect = 'move';
                        list.querySelectorAll('.drag-over').forEach(function (el) {
                            el.classList.remove('drag-over');
                        });
                        this.classList.add('drag-over');
                    });
                    item.addEventListener('dragleave', function () {
                        this.classList.remove('drag-over');
                    });
                    item.addEventListener('drop', function (event) {
                        event.stopPropagation();
                        if (!dragSource || dragSource === this) {
                            this.classList.remove('drag-over');
                            return;
                        }
                        const items = Array.from(list.children);
                        const sourceIndex = items.indexOf(dragSource);
                        const targetIndex = items.indexOf(this);
                        if (sourceIndex < targetIndex) {
                            list.insertBefore(dragSource, this.nextSibling);
                        } else {
                            list.insertBefore(dragSource, this);
                        }
                        this.classList.remove('drag-over');
                    });
                });
            }

            function collectPreferences() {
                const items = Array.from(list.querySelectorAll('[data-col-key]'));
                const order = items.map(function (item) { return item.dataset.colKey; });
                const visible = items.filter(function (item) {
                    const checkbox = item.querySelector('.col-visible-check');
                    return !checkbox || checkbox.checked || checkbox.disabled;
                }).map(function (item) {
                    return item.dataset.colKey;
                });

                return {
                    action: 'save',
                    module: moduleName,
                    column_order: order,
                    visible_columns: visible,
                    default_sort_column: currentSortColumn,
                    default_sort_direction: currentSortDirection,
                    page_size: currentPageSize
                };
            }

            function setButtonState(button, busy, busyText, idleHtml) {
                if (!button) {
                    return;
                }
                button.disabled = busy;
                button.innerHTML = busy
                    ? '<span class="spinner-border spinner-border-sm me-1"></span>' + busyText
                    : idleHtml;
            }

            if (saveBtn) {
                const idleHtml = saveBtn.innerHTML;
                saveBtn.addEventListener('click', function () {
                    setButtonState(saveBtn, true, 'Saving…', idleHtml);
                    fetch(endpoint, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(collectPreferences())
                    })
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        if (data.success) {
                            showToast('Column preferences saved.', 'success');
                            window.setTimeout(function () { window.location.reload(); }, 700);
                            return;
                        }
                        showToast(data.error || 'Could not save column preferences.', 'danger');
                        setButtonState(saveBtn, false, 'Saving…', idleHtml);
                    })
                    .catch(function () {
                        showToast('Network error while saving preferences.', 'danger');
                        setButtonState(saveBtn, false, 'Saving…', idleHtml);
                    });
                });
            }

            if (resetBtn) {
                const idleHtml = resetBtn.innerHTML;
                resetBtn.addEventListener('click', function () {
                    if (!window.confirm('Reset to the default column layout?')) {
                        return;
                    }
                    setButtonState(resetBtn, true, 'Resetting…', idleHtml);
                    fetch(endpoint, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ action: 'reset', module: moduleName })
                    })
                    .then(function (response) { return response.json(); })
                    .then(function (data) {
                        if (data.success) {
                            showToast('Column preferences reset.', 'info');
                            window.setTimeout(function () {
                                const modal = document.getElementById(modalId);
                                if (modal && window.bootstrap && bootstrap.Modal) {
                                    const instance = bootstrap.Modal.getInstance(modal);
                                    if (instance) {
                                        instance.hide();
                                    }
                                }
                                window.location.href = window.location.pathname;
                            }, 500);
                            return;
                        }
                        showToast(data.error || 'Could not reset column preferences.', 'danger');
                        setButtonState(resetBtn, false, 'Resetting…', idleHtml);
                    })
                    .catch(function () {
                        showToast('Network error while resetting preferences.', 'danger');
                        setButtonState(resetBtn, false, 'Resetting…', idleHtml);
                    });
                });
            }
        })();
        </script>
        <?php
        echo ob_get_clean();
    }
}
