// ========================================
// COMPONENTS: Modal
// ========================================

class Modal {
  constructor(options = {}) {
    this.id = options.id || 'modal-' + UI.uid();
    this.title = options.title || '';
    this.content = options.content || '';
    this.size = options.size || 'md'; // sm, md, lg, xl
    this.closable = options.closable !== false;
    this.onClose = options.onClose || (() => {});
    this.element = null;
  }

  render() {
    const sizeClasses = {
      sm: 'max-w-md',
      md: 'max-w-lg',
      lg: 'max-w-2xl',
      xl: 'max-w-4xl',
    };

    // Content is an HTML template from the caller (same contract as setContent);
    // dynamic values inside templates are UI.escape'd at the call site.
    const body = UI.create('div', { class: 'sheet-body' });
    if (this.content instanceof Node) {
      body.appendChild(this.content);
    } else {
      body.innerHTML = this.content;
    }

    this.element = UI.create('div', {
      id: this.id,
      class: 'sheet-overlay hidden',
      role: 'dialog',
      'aria-modal': 'true',
      'aria-labelledby': `${this.id}-title`,
    }, [
      UI.create('div', {
        class: `sheet ${sizeClasses[this.size]}`,
      }, [
        UI.create('h3', {
          id: `${this.id}-title`,
          class: 'text-lg font-semibold text-teal-800 mb-4',
        }, this.title),
        body,
        UI.create('div', {
          class: 'sheet-actions mt-4',
        }, [
          this.closable ? UI.create('button', {
            type: 'button',
            class: 'small-btn gray flex-1',
            onclick: () => this.close(),
          }, 'ยกเลิก') : null,
        ].filter(Boolean)),
      ]),
    ]);

    document.body.appendChild(this.element);
    return this;
  }

  open() {
    if (!this.element) this.render();
    UI.show(this.element);
    // Focus first focusable element
    setTimeout(() => {
      const focusable = this.element.querySelector('input, select, textarea, button:not(.gray)');
      if (focusable) focusable.focus();
    }, 100);
    
    // Prevent body scroll
    document.body.style.overflow = 'hidden';
  }

  close() {
    if (this.element) {
      UI.hide(this.element);
      document.body.style.overflow = '';
      this.onClose();
    }
  }

  destroy() {
    this.close();
    if (this.element && this.element.parentNode) {
      this.element.parentNode.removeChild(this.element);
    }
    this.element = null;
  }

  setContent(content) {
    this.content = content;
    if (this.element) {
      const body = this.element.querySelector('.sheet-body');
      if (body) body.innerHTML = content;
    }
  }

  setTitle(title) {
    this.title = title;
    if (this.element) {
      const titleEl = this.element.querySelector('h3');
      if (titleEl) titleEl.textContent = title;
    }
  }
}

// Simple confirm dialog
window.confirmDialog = async (message, options = {}) => {
  return new Promise((resolve) => {
    const modal = new Modal({
      title: options.title || 'ยืนยันการดำเนินการ',
      content: `<p class="mb-4">${UI.escape(message)}</p>`,
      size: 'sm',
      onClose: () => resolve(false),
    }).render();

    const actions = modal.element.querySelector('.sheet-actions');
    actions.innerHTML = `
      <button type="button" class="small-btn gray flex-1" data-action="cancel">${UI.escape(options.cancelText || 'ยกเลิก')}</button>
      <button type="button" class="flex-1" style="background: var(--bad);" data-action="confirm">${UI.escape(options.confirmText || 'ยืนยัน')}</button>
    `;

    actions.querySelector('[data-action="cancel"]').onclick = () => {
      // Resolve before close(): onClose also resolves(false), and the first
      // resolution wins — closing first would swallow the "confirm" = true
      resolve(false);
      modal.close();
    };
    actions.querySelector('[data-action="confirm"]').onclick = () => {
      resolve(true);
      modal.close();
    };

    modal.open();
  });
};

// Simple prompt dialog
window.promptDialog = async (message, defaultValue = '', options = {}) => {
  return new Promise((resolve) => {
    const modal = new Modal({
      title: options.title || 'กรอกข้อมูล',
      content: `
        <p class="mb-4">${UI.escape(message)}</p>
        <input type="text" id="prompt-input" value="${UI.escape(defaultValue)}" class="w-full" placeholder="${UI.escape(options.placeholder || '')}">
      `,
      size: 'sm',
      onClose: () => resolve(null),
    }).render();

    const input = modal.element.querySelector('#prompt-input');
    const actions = modal.element.querySelector('.sheet-actions');
    actions.innerHTML = `
      <button type="button" class="small-btn gray flex-1" data-action="cancel">${UI.escape(options.cancelText || 'ยกเลิก')}</button>
      <button type="button" class="flex-1" data-action="confirm">${UI.escape(options.confirmText || 'ตกลง')}</button>
    `;

    const finish = (value) => {
      modal.close();
      resolve(value);
    };

    actions.querySelector('[data-action="cancel"]').onclick = () => finish(null);
    actions.querySelector('[data-action="confirm"]').onclick = () => finish(input.value);
    input.onkeydown = (e) => {
      if (e.key === 'Enter') finish(input.value);
      if (e.key === 'Escape') finish(null);
    };

    modal.open();
    setTimeout(() => input.focus(), 100);
  });
};

// Alert dialog
window.alertDialog = async (message, options = {}) => {
  return new Promise((resolve) => {
    const modal = new Modal({
      title: options.title || 'แจ้งเตือน',
      content: `<p class="mb-4">${UI.escape(message)}</p>`,
      size: 'sm',
      onClose: () => resolve(),
    }).render();

    const actions = modal.element.querySelector('.sheet-actions');
    actions.innerHTML = `
      <button type="button" class="flex-1" data-action="ok">${UI.escape(options.okText || 'ตกลง')}</button>
    `;

    actions.querySelector('[data-action="ok"]').onclick = () => {
      modal.close();
      resolve();
    };

    modal.open();
  });
};

// Export
window.Modal = Modal;