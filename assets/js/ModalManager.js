(function (window, document) {
  'use strict';

  if (window.ModalManager) {
    return;
  }

  var watchdogId = null;
  var cleanupTimerId = null;
  var initialized = false;

  function toArray(list) {
    return Array.prototype.slice.call(list || []);
  }

  function getOpenModals() {
    return toArray(document.querySelectorAll('.modal.show'));
  }

  function getBackdrops() {
    return toArray(document.querySelectorAll('.modal-backdrop'));
  }

  function getOpeningModals() {
    return toArray(document.querySelectorAll('.modal[data-modal-opening="1"]'));
  }

  function hasManagedModalMarkup() {
    return document.querySelector('.js-managed-modal') !== null;
  }

  function removeNode(node) {
    if (node && node.parentNode) {
      node.parentNode.removeChild(node);
    }
  }

  function resetBodyState() {
    document.body.classList.remove('modal-open');
    document.body.style.removeProperty('overflow');
    document.body.style.removeProperty('padding-right');
    document.body.removeAttribute('aria-hidden');
    document.body.removeAttribute('data-bs-overflow');
    document.body.removeAttribute('data-bs-padding-right');
  }

  function hidePageLoader() {
    if (window.PRMSPageLoader && typeof window.PRMSPageLoader.hide === 'function') {
      window.PRMSPageLoader.hide();
      return;
    }

    var loader = document.getElementById('pageLoader');
    var loaderBar = document.getElementById('pageLoaderBar');
    if (loader) {
      loader.classList.remove('is-active');
    }
    if (loaderBar) {
      loaderBar.classList.remove('is-active');
      loaderBar.classList.add('is-done');
    }
  }

  function closeGlobalOverlays() {
    hidePageLoader();

    if (typeof window.prmsCloseNotifDropdown === 'function') {
      window.prmsCloseNotifDropdown();
    }
  }

  function normalizeModalStack() {
    var openModals = getOpenModals();
    var backdrops = getBackdrops();
    var activeBackdrop = backdrops.length ? backdrops[backdrops.length - 1] : null;

    if (!openModals.length) {
      backdrops.forEach(removeNode);
      return;
    }

    backdrops.slice(0, -1).forEach(removeNode);

    openModals.forEach(function (modalEl, index) {
      modalEl.style.zIndex = String(1050 + (index * 10));
    });

    if (activeBackdrop) {
      activeBackdrop.style.zIndex = openModals.length > 1
        ? String((1050 + ((openModals.length - 1) * 10)) - 5)
        : '1040';
    }
  }

  function prepare(target, options) {
    var settings = options || {};

    closeGlobalOverlays();

    if (settings.preserveBackdrop !== true) {
      getBackdrops().forEach(removeNode);
      resetBodyState();
    }

    if (target && target.classList) {
      delete target.dataset.modalSubmitting;
    }
  }

  function releaseForm(form) {
    if (!form) {
      return;
    }

    var hadLegacySubmitLock = form.dataset.submitting === '1';
    var hadNoticeLock = form.dataset.noticeInProgress === '1';

    delete form.dataset.modalSubmitting;
    delete form.dataset.submitting;
    delete form.dataset.noticeInProgress;

    toArray(form.querySelectorAll('button[type="submit"], input[type="submit"]')).forEach(function (button) {
      if (button.dataset.modalManagerDisabled === '1' || (button.disabled && (hadLegacySubmitLock || hadNoticeLock))) {
        button.disabled = false;
        delete button.dataset.modalManagerDisabled;
      }
    });
  }

  function releaseManagedForms() {
    toArray(document.querySelectorAll('.js-modal-form')).forEach(releaseForm);
  }

  function getModalInstance(modalEl) {
    if (!modalEl || !window.bootstrap || !window.bootstrap.Modal) {
      return null;
    }

    return window.bootstrap.Modal.getInstance(modalEl) || window.bootstrap.Modal.getOrCreateInstance(modalEl);
  }

  function cleanup(options) {
    var settings = options || {};
    var openModals = getOpenModals();
    var openingModals = getOpeningModals();
    var backdrops = getBackdrops();

    closeGlobalOverlays();

    if (settings.force === true || (!openModals.length && !openingModals.length)) {
      backdrops.forEach(removeNode);
      resetBodyState();
      return;
    }

    if (!openModals.length && openingModals.length) {
      return;
    }

    normalizeModalStack();
    document.body.classList.add('modal-open');
    document.body.removeAttribute('aria-hidden');
  }

  function scheduleCleanup(options) {
    var settings = options || {};
    if (cleanupTimerId !== null) {
      window.clearTimeout(cleanupTimerId);
    }
    cleanupTimerId = window.setTimeout(function () {
      cleanupTimerId = null;
      cleanup(settings);
    }, settings.delay || 0);
  }

  function stopWatchdog() {
    if (watchdogId === null) {
      return;
    }

    window.clearInterval(watchdogId);
    watchdogId = null;
  }

  function hide(target, options) {
    var settings = options || {};
    var modalEl = typeof target === 'string' ? document.querySelector(target) : target;

    if (!modalEl) {
      cleanup(settings);
      return;
    }

    var instance = getModalInstance(modalEl);
    if (instance) {
      instance.hide();
    }

    if (settings.immediate === true) {
      modalEl.classList.remove('show');
      modalEl.style.display = 'none';
      modalEl.setAttribute('aria-hidden', 'true');
      modalEl.removeAttribute('aria-modal');
      modalEl.removeAttribute('role');
    }

    scheduleCleanup(settings);
  }

  function show(target) {
    var modalEl = typeof target === 'string' ? document.querySelector(target) : target;
    var instance = getModalInstance(modalEl);

    if (!instance) {
      return null;
    }

    prepare(modalEl);
    startWatchdog(3000);
    modalEl.dataset.modalOpening = '1';
    instance.show();
    scheduleCleanup();

    return instance;
  }

  function withCleanup(work, options) {
    var runner = typeof work === 'function' ? work : function () { return work; };
    var settings = options || {};

    return Promise.resolve()
      .then(runner)
      .then(function (result) {
        cleanup(settings);
        return result;
      }, function (error) {
        cleanup(settings);
        throw error;
      });
  }

  function bindManagedForms() {
    document.addEventListener('submit', function (event) {
      var form = event.target instanceof HTMLFormElement ? event.target : null;
      if (!form || !form.classList.contains('js-modal-form')) {
        return;
      }

      if (form.dataset.modalSubmitting === '1') {
        event.preventDefault();
        event.stopPropagation();
        return;
      }

      form.dataset.modalSubmitting = '1';

      toArray(form.querySelectorAll('button[type="submit"], input[type="submit"]')).forEach(function (button) {
        if (!button.disabled) {
          button.disabled = true;
          button.dataset.modalManagerDisabled = '1';
        }
      });

      var modalEl = form.closest('.modal');
      if (modalEl) {
        modalEl.dataset.modalSubmitting = '1';
        hide(modalEl, { immediate: true });
      } else {
        cleanup();
      }
    }, true);
  }

  function bindManagedTriggers() {
    document.addEventListener('click', function (event) {
      var trigger = event.target.closest('[data-bs-toggle="modal"], [data-toggle="modal"]');
      if (!trigger) {
        return;
      }

      var selector = trigger.getAttribute('data-bs-target') || trigger.getAttribute('data-target') || trigger.getAttribute('href');
      if (!selector || selector.charAt(0) !== '#') {
        return;
      }

      var modalEl = document.querySelector(selector);
      if (!modalEl) {
        return;
      }

      if (!modalEl.classList.contains('js-managed-modal')) {
        return;
      }

      prepare(modalEl);

      startWatchdog(3000);

      if (trigger.dataset.modalTriggerLocked === '1' || modalEl.dataset.modalOpening === '1') {
        event.preventDefault();
        event.stopPropagation();
        return;
      }

      if (modalEl.classList.contains('show')) {
        event.preventDefault();
        event.stopPropagation();
        return;
      }

      trigger.dataset.modalTriggerLocked = '1';
      window.setTimeout(function () {
        delete trigger.dataset.modalTriggerLocked;
      }, 400);
    }, true);
  }

  function bindModalEvents() {
    document.addEventListener('show.bs.modal', function (event) {
      if (event.target && event.target.classList) {
        closeGlobalOverlays();
        event.target.dataset.modalOpening = '1';
        if (event.target.classList.contains('js-managed-modal')) {
          startWatchdog(3000);
        }
      }
      scheduleCleanup();
    }, true);

    document.addEventListener('shown.bs.modal', function (event) {
      if (event.target && event.target.classList) {
        delete event.target.dataset.modalOpening;
      }
      scheduleCleanup();
    }, true);

    document.addEventListener('hidden.bs.modal', function (event) {
      if (event.target && event.target.classList) {
        delete event.target.dataset.modalOpening;
        delete event.target.dataset.modalSubmitting;
      }
      scheduleCleanup();
    }, true);
  }

  function bindLifecycleEvents() {
    window.addEventListener('beforeunload', function () {
      stopWatchdog();
      cleanup({ force: true });
    });

    window.addEventListener('pagehide', function (event) {
      stopWatchdog();
      if (!event.persisted) {
        cleanup({ force: true });
      } else {
        scheduleCleanup();
      }
    });

    window.addEventListener('pageshow', function (event) {
      if (event.persisted && hasManagedModalMarkup()) {
        startWatchdog(3000);
      }
      cleanup();
      releaseManagedForms();
    });
  }

  function bindJQueryAjaxHooks() {
    if (!window.jQuery || window.jQuery(document).data('modalManagerAjaxHooks') === true) {
      return;
    }

    window.jQuery(document).data('modalManagerAjaxHooks', true);
    window.jQuery(document).on('ajaxComplete.modalManager ajaxError.modalManager', function () {
      scheduleCleanup();
    });
  }

  function startWatchdog(intervalMs) {
    if (watchdogId !== null) {
      return;
    }

    watchdogId = window.setInterval(function () {
      var openModals = getOpenModals();
      var hasBackdrop = getBackdrops().length > 0;
      var bodyLocked = document.body.classList.contains('modal-open');

      if (!openModals.length && !hasBackdrop && !bodyLocked) {
        stopWatchdog();
        return;
      }

      if (!openModals.length && (hasBackdrop || bodyLocked)) {
        cleanup({ force: true });
        releaseManagedForms();
      }
    }, intervalMs || 3000);
  }

  function initialize() {
    if (initialized) {
      bindJQueryAjaxHooks();
      return window.ModalManager;
    }

    initialized = true;
    bindManagedForms();
    bindManagedTriggers();
    bindModalEvents();
    bindLifecycleEvents();
    bindJQueryAjaxHooks();
    if (hasManagedModalMarkup()) {
      startWatchdog(3000);
    }
    cleanup();

    return window.ModalManager;
  }

  window.ModalManager = {
    initialize: initialize,
    prepare: prepare,
    show: show,
    hide: hide,
    cleanup: cleanup,
    withCleanup: withCleanup,
    releaseManagedForms: releaseManagedForms
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize, { once: true });
  } else {
    initialize();
  }

  window.addEventListener('load', bindJQueryAjaxHooks, { once: true });
})(window, document);
