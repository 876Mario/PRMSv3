/**
 * pipeline.js
 *
 * Adds touch-tap activation for workflow pipeline stage tooltips.
 * Desktop hover and keyboard focus are handled purely by CSS
 * (pipeline.css).  This script handles the touch / mobile case.
 *
 * No external libraries required.
 */
(function () {
    'use strict';

    function init() {
        var cells = document.querySelectorAll('.pipeline-stage-cell');

        cells.forEach(function (cell) {
            cell.addEventListener('touchend', function (e) {
                // Only act if the touch ended on the same cell it started on
                // (i.e. it was a tap, not a scroll gesture)
                var touch = e.changedTouches[0];
                var target = document.elementFromPoint(touch.clientX, touch.clientY);
                if (!cell.contains(target)) {
                    return;
                }

                e.preventDefault(); // prevent synthetic click that would re-trigger

                var isOpen = cell.classList.contains('wf-tooltip-open');

                // Close all other open tooltips first
                cells.forEach(function (other) {
                    if (other !== cell) {
                        other.classList.remove('wf-tooltip-open');
                    }
                });

                // Toggle this tooltip
                if (isOpen) {
                    cell.classList.remove('wf-tooltip-open');
                } else {
                    cell.classList.add('wf-tooltip-open');
                }
            }, { passive: false });
        });

        // Close tooltips when tapping anywhere outside a stage cell
        document.addEventListener('touchend', function (e) {
            var touch = e.changedTouches[0];
            var target = document.elementFromPoint(touch.clientX, touch.clientY);
            if (!target || !target.closest('.pipeline-stage-cell')) {
                cells.forEach(function (cell) {
                    cell.classList.remove('wf-tooltip-open');
                });
            }
        }, { passive: true });

        // Close tooltip when focus moves away from a cell (keyboard users)
        cells.forEach(function (cell) {
            cell.addEventListener('blur', function () {
                // Small delay allows focus to settle on a child element
                setTimeout(function () {
                    if (!cell.contains(document.activeElement)) {
                        cell.classList.remove('wf-tooltip-open');
                    }
                }, 100);
            });

            // Allow Escape key to dismiss
            cell.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    cell.classList.remove('wf-tooltip-open');
                    cell.blur();
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
}());
