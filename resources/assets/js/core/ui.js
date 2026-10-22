// ========================================
// CORE: UI Utilities
// ========================================

const UI = {
  // Element selectors
  $: (selector) => document.querySelector(selector),
  $$: (selector) => Array.from(document.querySelectorAll(selector)),

  // Create element with attributes and children
  create(tag, attributes = {}, children = []) {
    const el = document.createElement(tag);
    Object.entries(attributes).forEach(([key, value]) => {
      if (key === 'class') {
        el.className = value;
      } else if (key === 'style' && typeof value === 'object') {
        Object.assign(el.style, value);
      } else if (key.startsWith('on') && typeof value === 'function') {
        el.addEventListener(key.slice(2).toLowerCase(), value);
      } else if (key === 'dataset') {
        Object.entries(value).forEach(([k, v]) => el.dataset[k] = v);
      } else {
        el.setAttribute(key, value);
      }
    });
    // Accept a single string/Node or an array of them
    const list = Array.isArray(children) ? children : [children];
    list.forEach(child => {
      if (child === null || child === undefined || child === false) return;
      if (typeof child === 'string' || typeof child === 'number') {
        el.appendChild(document.createTextNode(String(child)));
      } else if (child instanceof Node) {
        el.appendChild(child);
      }
    });
    return el;
  },

  // Escape HTML
  escape(str) {
    return String(str ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  },

  // Format date for Thai locale
  formatDateThai(dateStr) {
    if (!dateStr) return '-';
    try {
      const date = new Date(dateStr);
      const thaiMonths = [
        'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน',
        'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม',
        'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'
      ];
      const year = date.getFullYear() + 543;
      return `${date.getDate()} ${thaiMonths[date.getMonth()]} ${year}`;
    } catch {
      return dateStr;
    }
  },

  // Format datetime for Thai locale
  formatDateTimeThai(dateStr) {
    if (!dateStr) return '-';
    try {
      const date = new Date(dateStr);
      return date.toLocaleString('th-TH', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
      });
    } catch {
      return dateStr;
    }
  },

  // Show status message
  status(message) {
    const el = document.getElementById('storageState');
    if (el) el.textContent = message;
  },

  // Show alert
  alert(message) {
    alert(message);
  },

  // Confirm dialog
  confirm(message) {
    return confirm(message);
  },

  // Prompt dialog
  prompt(message, defaultValue = '') {
    return prompt(message, defaultValue);
  },

  // Toggle element visibility
  show(el) {
    if (typeof el === 'string') el = this.$(el);
    if (el) el.classList.remove('hidden');
  },

  hide(el) {
    if (typeof el === 'string') el = this.$(el);
    if (el) el.classList.add('hidden');
  },

  toggle(el, force) {
    if (typeof el === 'string') el = this.$(el);
    if (el) el.classList.toggle('hidden', force);
  },

  // Add/remove/toggle class
  addClass(el, className) {
    if (typeof el === 'string') el = this.$(el);
    if (el) el.classList.add(className);
  },

  removeClass(el, className) {
    if (typeof el === 'string') el = this.$(el);
    if (el) el.classList.remove(className);
  },

  // Set loading state on button
  setLoading(button, loading = true) {
    if (typeof button === 'string') button = this.$(button);
    if (!button) return;
    
    if (loading) {
      button.disabled = true;
      button.dataset.originalText = button.textContent;
      button.textContent = 'กำลังดำเนินการ...';
    } else {
      button.disabled = false;
      if (button.dataset.originalText) {
        button.textContent = button.dataset.originalText;
        delete button.dataset.originalText;
      }
    }
  },

  // Debounce function
  debounce(fn, delay) {
    let timeoutId;
    return (...args) => {
      clearTimeout(timeoutId);
      timeoutId = setTimeout(() => fn(...args), delay);
    };
  },

  // Throttle function
  throttle(fn, limit) {
    let inThrottle;
    return (...args) => {
      if (!inThrottle) {
        fn(...args);
        inThrottle = true;
        setTimeout(() => inThrottle = false, limit);
      }
    };
  },

  // Generate unique ID
  uid() {
    return 'id_' + Math.random().toString(36).substr(2, 9) + Date.now().toString(36);
  },

  // Deep clone
  clone(obj) {
    return JSON.parse(JSON.stringify(obj));
  },

  // Format number with Thai numerals (optional)
  toThaiNumerals(num) {
    const thaiDigits = ['๐', '๑', '๒', '๓', '๔', '๕', '๖', '๗', '๘', '๙'];
    return String(num).replace(/\d/g, d => thaiDigits[d]);
  },
};

/**
 * ช่องตัวเลขพร้อมปุ่ม − / + ขนาดใหญ่ (กดง่ายบนมือถือ/ผู้สูงอายุ)
 * @param {string} inputAttrs attribute ของ <input type="number"> (ต้อง escape มาแล้ว) — ค่า min ใช้เป็นขั้นต่ำของปุ่ม −
 * @param {string} label ชื่อช่อง (ใช้กับ aria-label ของปุ่ม)
 */
UI.stepper = (inputAttrs, label = 'จำนวน') => `
  <div class="stepper">
    <button type="button" data-step="-1" aria-label="ลด${UI.escape(label)}">−</button>
    <input type="number" inputmode="decimal" step="any" ${inputAttrs}>
    <button type="button" data-step="1" aria-label="เพิ่ม${UI.escape(label)}">+</button>
  </div>`;

// ปุ่ม − / + ทุกตัวในระบบ: ปรับค่าแล้วยิง input + change ให้ handler เดิมของหน้าทำงานเหมือนผู้ใช้พิมพ์เอง
document.addEventListener('click', (event) => {
  const btn = event.target.closest('.stepper [data-step]');
  if (!btn) return;
  event.preventDefault();
  event.stopPropagation();
  const input = btn.parentElement.querySelector('input');
  if (!input || input.disabled || input.readOnly) return;
  const min = input.min !== '' ? Number(input.min) : 0;
  const max = input.max !== '' ? Number(input.max) : Infinity;
  const current = input.value === '' ? (Number(btn.dataset.step) > 0 ? min - 1 : min) : Number(input.value);
  const next = Math.min(max, Math.max(min, Math.round((current + Number(btn.dataset.step)) * 100) / 100));
  input.value = String(next);
  input.dispatchEvent(new Event('input', { bubbles: true }));
  input.dispatchEvent(new Event('change', { bubbles: true }));
}, true);

// Export
window.UI = UI;

// Helper functions for backward compatibility
window.$ = UI.$;
window.$$ = UI.$$;
window.esc = UI.escape;
window.statusText = UI.status;
window.uid = UI.uid;
window.clone = UI.clone;