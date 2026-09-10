const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

function createClassList(initial) {
  const values = new Set(initial || []);
  return {
    add: (...names) => names.forEach((name) => values.add(name)),
    remove: (...names) => names.forEach((name) => values.delete(name)),
    contains: (name) => values.has(name),
    toString: () => Array.from(values).join(' ')
  };
}

function createStyle(initial) {
  const store = Object.assign({}, initial || {});
  return new Proxy(store, {
    get(target, prop) {
      if (prop === 'removeProperty') {
        return (name) => { delete target[name]; };
      }
      return target[prop];
    },
    set(target, prop, value) {
      target[prop] = value;
      return true;
    }
  });
}

function createEnvironment() {
  const listeners = { document: {}, window: {} };
  let nextTimerId = 1;
  const timeoutQueue = new Map();
  const intervalQueue = new Map();
  const registry = {
    modals: [],
    backdrops: [],
    forms: [],
    elementsById: new Map()
  };

  function attach(kind, element) {
    element.__kind = kind;
    element.parentNode = {
      removeChild(node) {
        if (kind === 'backdrop') {
          registry.backdrops = registry.backdrops.filter((item) => item !== node);
        } else if (kind === 'modal') {
          registry.modals = registry.modals.filter((item) => item !== node);
        }
        node.parentNode = null;
      }
    };
    return element;
  }

  function createElement({ id = '', classes = [], dataset = {}, style = {}, attributes = {} } = {}) {
    const attrs = Object.assign({}, attributes);
    const element = {
      id,
      dataset: Object.assign({}, dataset),
      style: createStyle(style),
      classList: createClassList(classes),
      attributes: attrs,
      querySelectorAll() { return []; },
      closest() { return null; },
      setAttribute(name, value) { attrs[name] = value; },
      removeAttribute(name) { delete attrs[name]; },
      getAttribute(name) { return attrs[name]; }
    };
    if (id) {
      registry.elementsById.set(id, element);
    }
    return element;
  }

  const body = createElement({ style: { overflow: 'hidden', 'padding-right': '15px' } });
  body.classList.add('modal-open');
  body.removeAttribute = function (name) { delete this.attributes[name]; };
  body.setAttribute = function (name, value) { this.attributes[name] = value; };

  const document = {
    readyState: 'complete',
    body,
    addEventListener(type, handler) {
      listeners.document[type] = listeners.document[type] || [];
      listeners.document[type].push(handler);
    },
    querySelectorAll(selector) {
      if (selector === '.modal.show') {
        return registry.modals.filter((modal) => modal.classList.contains('show'));
      }
      if (selector === '.modal-backdrop') {
        return registry.backdrops.slice();
      }
      if (selector === '.modal[data-modal-opening="1"]') {
        return registry.modals.filter((modal) => modal.dataset.modalOpening === '1');
      }
      if (selector === '.js-managed-modal') {
        return registry.modals.filter((modal) => modal.classList.contains('js-managed-modal'));
      }
      if (selector === '.js-modal-form') {
        return registry.forms.slice();
      }
      return [];
    },
    querySelector(selector) {
      if (selector === '.js-managed-modal') {
        return registry.modals.find((modal) => modal.classList.contains('js-managed-modal')) || null;
      }
      if (selector.startsWith('#')) {
        return registry.elementsById.get(selector.slice(1)) || null;
      }
      return null;
    },
    getElementById(id) {
      return registry.elementsById.get(id) || null;
    }
  };

  const windowObj = {
    document,
    console,
    Promise,
    setTimeout(fn) {
      const timerId = nextTimerId++;
      timeoutQueue.set(timerId, fn);
      return timerId;
    },
    clearTimeout(timerId) {
      timeoutQueue.delete(timerId);
    },
    setInterval(fn) {
      const timerId = nextTimerId++;
      intervalQueue.set(timerId, fn);
      return timerId;
    },
    clearInterval(timerId) {
      intervalQueue.delete(timerId);
    },
    addEventListener(type, handler) {
      listeners.window[type] = listeners.window[type] || [];
      listeners.window[type].push(handler);
    }
  };

  const context = {
    window: windowObj,
    document,
    console,
    Promise,
    HTMLFormElement: function HTMLFormElement() {},
    Array
  };

  const source = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'js', 'ModalManager.js'), 'utf8');
  vm.runInNewContext(source, context, { filename: 'ModalManager.js' });

  return {
    window: windowObj,
    document,
    registry,
    flushTimeouts() {
      const pending = Array.from(timeoutQueue.entries());
      timeoutQueue.clear();
      pending.forEach(([, callback]) => callback());
    },
    runInterval(timerId) {
      if (intervalQueue.has(timerId)) {
        intervalQueue.get(timerId)();
      }
    },
    createModal(options = {}) {
      const modal = attach('modal', createElement({ classes: ['modal', 'js-managed-modal'].concat(options.show === false ? [] : ['show']), dataset: options.dataset || {} }));
      registry.modals.push(modal);
      return modal;
    },
    createBackdrop() {
      const backdrop = attach('backdrop', createElement({ classes: ['modal-backdrop', 'fade', 'show'] }));
      registry.backdrops.push(backdrop);
      return backdrop;
    },
    createNamedElement(id, classes = [], style = {}) {
      return createElement({ id, classes, style });
    }
  };
}

(function run() {
  {
    const env = createEnvironment();
    let loaderHidden = false;
    let dropdownClosed = false;
    env.createNamedElement('pageLoader', ['is-active']);
    env.createNamedElement('pageLoaderBar', ['is-active']);
    env.window.PRMSPageLoader = { hide() { loaderHidden = true; } };
    env.window.prmsCloseNotifDropdown = function () { dropdownClosed = true; };
    env.createBackdrop();
    env.createBackdrop();

    env.window.ModalManager.prepare(env.createModal({ show: false }));

    assert.equal(env.registry.backdrops.length, 0, 'prepare() should remove orphaned backdrops before opening');
    assert.equal(env.document.body.classList.contains('modal-open'), false, 'prepare() should clear modal-open before opening');
    assert.equal(loaderHidden, true, 'prepare() should hide the page loader');
    assert.equal(dropdownClosed, true, 'prepare() should close the notification overlay');
  }

  {
    const env = createEnvironment();
    const firstModal = env.createModal();
    const secondModal = env.createModal();
    const staleBackdrop = env.createBackdrop();
    const activeBackdrop = env.createBackdrop();

    env.window.ModalManager.cleanup();

    assert.equal(env.registry.backdrops.length, 1, 'cleanup() should keep only one active backdrop');
    assert.strictEqual(env.registry.backdrops[0], activeBackdrop, 'cleanup() should retain the newest backdrop');
    assert.equal(activeBackdrop.style.zIndex, '1055', 'active backdrop should sit below the top modal and above lower modal layers');
    assert.equal(firstModal.style.zIndex, '1050', 'first open modal should use the base modal z-index');
    assert.equal(secondModal.style.zIndex, '1060', 'topmost modal should stack above the first modal');
    assert.equal(env.document.body.classList.contains('modal-open'), true, 'cleanup() should preserve modal-open while a modal remains open');
    assert.ok(!env.registry.backdrops.includes(staleBackdrop), 'stale backdrop should be removed');
  }

  {
    const env = createEnvironment();
    env.createBackdrop();
    env.document.body.classList.add('modal-open');
    env.document.body.style.overflow = 'hidden';
    env.document.body.style['padding-right'] = '15px';

    env.window.ModalManager.cleanup({ force: true });

    assert.equal(env.registry.backdrops.length, 0, 'force cleanup should remove any remaining backdrop');
    assert.equal(env.document.body.classList.contains('modal-open'), false, 'force cleanup should release body modal state');
    assert.equal(env.document.body.style.overflow, undefined, 'force cleanup should remove body overflow lock');
    assert.equal(env.document.body.style['padding-right'], undefined, 'force cleanup should remove body padding compensation');
  }

  console.log('ModalManagerBehaviorTest passed');
})();
