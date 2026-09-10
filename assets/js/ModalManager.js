(function (window, document) {
  'use strict';

  if (window.ModalManager) {
    return;
  }

  var watchdogId = null;
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

  function releaseForm(form) {
    if (!form) {
      return;
    }

    delete form.dataset.modalSubmitting;

    toArray(form.querySelectorAll('button[type="submit"], input[type="submit"]')).forEach(function (button) {
      if (button.dataset.modalManagerDisabled === '1') {
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
    var backdrops = getBackdrops();

    if (!openModals.length || settings.force === true) {
      backdrops.forEach(removeNode);
      resetBodyState();
      return;
    }

    backdrops.slice(0, -1).forEach(removeNode);
    document.body.classList.add('modal-open');
    document.body.removeAttribute('aria-hidden');
  }

  function scheduleCleanup(options) {
    var settings = options || {};
    window.setTimeout(function () {
      cleanup(settings);
    }, settings.delay || 0);
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

    cleanup(settings);
  }

  function show(target) {
    var modalEl = typeof target === 'string' ? document.querySelector(target) : target;
    var instance = getModalInstance(modalEl);

    if (!instance) {
      return null;
    }

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
      .finally(function () {
        cleanup(settings);
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
      if (!modalEl || !modalEl.classList.contains('js-managed-modal')) {
        return;
      }

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
        event.target.dataset.modalOpening = '1';
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
      cleanup({ force: true });
    });

    window.addEventListener('pagehide', function () {
      cleanup({ force: true });
    });

    window.addEventListener('pageshow', function () {
      cleanup({ force: true });
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
    startWatchdog(3000);
    cleanup();

    return window.ModalManager;
  }

  window.ModalManager = {
    initialize: initialize,
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
