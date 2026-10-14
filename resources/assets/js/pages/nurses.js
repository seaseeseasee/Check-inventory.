// ========================================
// PAGE: NURSES
// ========================================

// Column mapping for nurse import files (Excel / CSV).
// Header cells are normalized before matching: BOM/NBSP removed, dash variants
// unified to "-", spaces/dots/underscores/slashes dropped, lowercased.
const NURSE_IMPORT_COLUMNS = [
  { key: 'id', required: true, label: 'รหัสพยาบาล', aliases: ['รหัสพยาบาล', 'รหัส', 'รหัสพนักงาน', 'id', 'code', 'nurse_id'] },
  { key: 'name', required: true, label: 'ชื่อ-นามสกุล', aliases: ['ชื่อ-นามสกุล', 'ชื่อ-สกุล', 'ชื่อนามสกุล', 'ชื่อสกุล', 'ชื่อเต็ม', 'ชื่อ', 'name', 'full_name'] },
  { key: 'lastname', required: false, label: 'นามสกุล', aliases: ['นามสกุล', 'สกุล', 'surname', 'last_name', 'family_name'] },
  { key: 'department', required: true, label: 'หน่วยงาน', aliases: ['หน่วยงาน', 'แผนก', 'หน่วยงาน/แผนก', 'ฝ่าย', 'department', 'dept'] },
  { key: 'phone', required: false, label: 'โทรศัพท์', aliases: ['โทรศัพท์', 'เบอร์โทร', 'เบอร์โทรศัพท์', 'โทร', 'มือถือ', 'phone', 'tel', 'telephone', 'mobile'] },
  { key: 'start', required: false, label: 'วันเริ่มปฏิบัติงาน', aliases: ['วันเริ่มปฏิบัติงาน', 'วันเริ่มงาน', 'วันเริ่ม', 'วันที่เริ่ม', 'เริ่มงาน', 'start', 'start_date'] },
  { key: 'status', required: false, label: 'สถานะ', aliases: ['สถานะ', 'สถานะการทำงาน', 'การทำงาน', 'status', 'active'] },
  { key: 'note', required: false, label: 'หมายเหตุ', aliases: ['หมายเหตุ', 'บันทึก', 'note', 'notes', 'remark', 'remarks'] },
];

const NURSE_IMPORT_ACTIVE_WORDS = ['ปฏิบัติงาน', 'ทำงาน', 'ใช้งาน', 'active', '1', 'true', 'yes', 'y', 'ใช่'];
const NURSE_IMPORT_INACTIVE_WORDS = ['พักใช้งาน', 'พัก', 'inactive', '0', 'false', 'no', 'n', 'ไม่'];

class NursesPage {
  constructor() {
    this.nurses = [];
    this.editingId = null;
    this.editingOriginal = null;
    this.editingLoginUsername = null;
    this._importModal = null;
    this._importing = false;
    this.init();
  }

  async init() {
    await this.loadNurses();
    this.bindEvents();
    this.render();
    if (!this.editingId) this.suggestCode();
  }

  async loadNurses() {
    try {
      const res = await window.nurseApi.getAll();
      this.nurses = res.data || [];
    } catch (error) {
      console.error('Failed to load nurses:', error);
      window.toast.error('โหลดรายชื่อพยาบาลไม่สำเร็จ');
    }
  }

  // ---------- Formatting helpers ----------

  static isIsoDate(value) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(String(value || ''))) return false;
    const [y, m, d] = String(value).split('-').map(Number);
    const dt = new Date(Date.UTC(y, m - 1, d));
    return dt.getUTCFullYear() === y && dt.getUTCMonth() === m - 1 && dt.getUTCDate() === d;
  }

  static formatThaiDate(value) {
    if (!value) return '';
    if (!NursesPage.isIsoDate(value)) return value; // legacy free text: show as stored
    const [y, m, d] = value.split('-').map(Number);
    return `${d}/${m}/${y + 543}`;
  }

  static addMonthsIso(iso, months) {
    const [y, m, d] = iso.split('-').map(Number);
    const target = new Date(Date.UTC(y, m - 1 + months, 1));
    const lastDay = new Date(Date.UTC(target.getUTCFullYear(), target.getUTCMonth() + 1, 0)).getUTCDate();
    const day = Math.min(d, lastDay);
    const mm = String(target.getUTCMonth() + 1).padStart(2, '0');
    return `${target.getUTCFullYear()}-${mm}-${String(day).padStart(2, '0')}`;
  }

  static applyPhoneMask(digits) {
    const parts = digits.startsWith('02') && digits.length <= 9
      ? [digits.slice(0, 2), digits.slice(2, 5), digits.slice(5, 9)]   // 02-xxx-xxxx (Bangkok)
      : [digits.slice(0, 3), digits.slice(3, 6), digits.slice(6, 10)]; // 081-234-5678 / 053-123-456
    return parts.filter(Boolean).join('-');
  }

  // ---------- Events ----------

  bindEvents() {
    const form = document.getElementById('nurseForm');
    if (form) {
      form.addEventListener('submit', (e) => this.handleSubmit(e));
    }

    document.getElementById('resetNurseBtn')?.addEventListener('click', () => this.resetForm());

    const search = document.getElementById('nurseSearch');
    if (search) {
      search.addEventListener('input', UI.debounce(() => this.filterTable(), 300));
    }

    // Code: generate button + live duplicate check
    document.getElementById('nurseCodeGenBtn')?.addEventListener('click', () => {
      this.suggestCode();
      window.toast.info('สร้างรหัส ' + document.getElementById('nId').value + ' ให้แล้ว');
    });

    const checkId = UI.debounce(() => this.validateField('nId'), 250);
    document.getElementById('nId')?.addEventListener('input', checkId);
    document.getElementById('nName')?.addEventListener('input', () => this.validateField('nName'));
    document.getElementById('nDept')?.addEventListener('input', () => this.validateField('nDept'));
    document.getElementById('nUsername')?.addEventListener('input', () => this.validateField('nUsername'));
    document.getElementById('nPassword')?.addEventListener('input', () => this.validateField('nPassword'));

    ['nId', 'nName', 'nDept', 'nUsername', 'nPassword'].forEach((id) => {
      document.getElementById(id)?.addEventListener('blur', () => this.validateField(id));
    });

    // Phone: tel input mask + soft validation
    const phone = document.getElementById('nPhone');
    phone?.addEventListener('input', (e) => this.onPhoneInput(e));
    phone?.addEventListener('blur', () => this.validateField('nPhone'));

    // Start date: selecting a new date replaces the legacy note
    document.getElementById('nStart')?.addEventListener('change', () => {
      document.getElementById('nStartLegacy')?.classList.add('hidden');
    });

    // Excel template download
    document.getElementById('nurseTemplateBtn')?.addEventListener('click', () => this.downloadTemplate());

    this.bindDropzone();
  }

  onPhoneInput(e) {
    const el = e.target;
    const raw = el.value;
    if (/[^\d\s-]/.test(raw)) return; // letters or +: leave as typed
    const digits = raw.replace(/\D/g, '').slice(0, 10);
    const typingAtEnd = el.selectionStart === raw.length;
    el.value = NursesPage.applyPhoneMask(digits);
    if (typingAtEnd && typeof el.setSelectionRange === 'function') {
      el.setSelectionRange(el.value.length, el.value.length);
    }
    this.validateField('nPhone');
  }

  bindDropzone() {
    const dz = document.getElementById('nurseDropzone');
    const input = document.getElementById('nurseImport');
    if (!dz || !input) return;

    const dragClasses = ['border-teal-500', 'bg-teal-100'];
    ['dragenter', 'dragover'].forEach((ev) =>
      dz.addEventListener(ev, (e) => {
        e.preventDefault();
        dz.classList.add(...dragClasses);
      })
    );
    ['dragleave', 'drop'].forEach((ev) =>
      dz.addEventListener(ev, (e) => {
        e.preventDefault();
        dz.classList.remove(...dragClasses);
      })
    );

    dz.addEventListener('drop', (e) => {
      const file = e.dataTransfer?.files?.[0];
      if (file) this.handleImport(file);
    });
    dz.addEventListener('click', (e) => {
      if (e.target !== input) input.click();
    });
    dz.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        input.click();
      }
    });
    input.addEventListener('change', () => {
      const file = input.files?.[0];
      if (file) this.handleImport(file);
      input.value = '';
    });

    document.getElementById('nurseFileClear')?.addEventListener('click', () => {
      document.getElementById('nurseFileStatus')?.classList.add('hidden');
    });
  }

  // ---------- Validation ----------

  setFieldError(fieldId, message) {
    const input = document.getElementById(fieldId);
    const err = document.getElementById(fieldId + 'Error');
    if (!input || !err) return;
    const hasError = Boolean(message);
    err.textContent = message || '';
    err.classList.toggle('hidden', !hasError);
    input.setAttribute('aria-invalid', hasError ? 'true' : 'false');
  }

  clearAllErrors() {
    ['nId', 'nName', 'nDept', 'nPhone', 'nUsername', 'nPassword'].forEach((id) => this.setFieldError(id, ''));
  }

  findDuplicate(idValue) {
    const value = String(idValue || '').trim();
    if (!value) return null;
    return this.nurses.find(
      (n) => String(n.id) === value && String(n.id) !== String(this.editingId || '')
    ) || null;
  }

  validateField(name) {
    const el = document.getElementById(name);
    if (!el) return true;
    const value = el.value.trim();

    const requiredMsg = {
      nId: 'กรุณากรอกรหัสพยาบาล',
      nName: 'กรุณากรอกชื่อ–นามสกุล',
      nDept: 'กรุณากรอกหน่วยงาน',
    }[name];
    if (requiredMsg && !value) {
      this.setFieldError(name, requiredMsg);
      return false;
    }

    if (name === 'nId') {
      const dup = this.findDuplicate(value);
      if (dup) {
        this.setFieldError(name, `รหัสนี้ถูกใช้แล้ว (${dup.name}) — กดปุ่ม "แก้ไข" ที่รายการนั้นแทน`);
        return false;
      }
    }

    if (name === 'nPhone' && value && !/[^\d\s-]/.test(value)) {
      if (value.replace(/\D/g, '').length < 9) {
        this.setFieldError(name, 'เบอร์โทรไม่ครบถ้วน ควรมี 9–10 หลัก');
        return false;
      }
    }

    if (name === 'nUsername') {
      const password = document.getElementById('nPassword')?.value || '';
      if (value && !/^[A-Za-z0-9._-]{3,100}$/.test(value)) {
        this.setFieldError(name, 'ใช้ได้เฉพาะ a-z A-Z 0-9 . _ - (3-100 ตัวอักษร)');
        return false;
      }
      if (!value && this.editingLoginUsername) {
        this.setFieldError(name, 'ห้ามลบชื่อผู้ใช้ของบัญชีเดิม — ใช้สถานะ "พักใช้งาน" เพื่อปิดการเข้าสู่ระบบแทน');
        return false;
      }
      if (value && !this.editingLoginUsername && password.length < 6) {
        this.setFieldError('nPassword', 'บัญชีใหม่ต้องตั้งรหัสผ่านอย่างน้อย 6 ตัวอักษร');
        return false;
      }
      this.setFieldError('nPassword', '');
    }

    if (name === 'nPassword' && value && value.length < 6) {
      this.setFieldError(name, 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร');
      return false;
    }

    this.setFieldError(name, '');
    return true;
  }

  // ---------- Auto code ----------

  suggestCode() {
    const el = document.getElementById('nId');
    if (!el || el.readOnly) return;
    const numericIds = this.nurses
      .map((n) => String(n.id))
      .filter((id) => /^\d+$/.test(id))
      .map((id) => parseInt(id, 10));
    const next = numericIds.length ? Math.max(...numericIds) + 1 : 1001;
    el.value = String(next);
    this.validateField('nId');
  }

  // ---------- Render ----------

  render() {
    this.renderTable();
    this.renderDeptList();
    this.updateFormTitle();
  }

  renderDeptList() {
    const list = document.getElementById('nurseDeptList');
    if (!list) return;
    const depts = [...new Set(this.nurses.map((n) => (n.department || '').trim()).filter(Boolean))].sort();
    list.innerHTML = depts.map((d) => `<option value="${UI.escape(d)}"></option>`).join('');
  }

  renderTable() {
    const container = document.getElementById('nurseDirectoryTable');
    if (!container) return;

    container.innerHTML = this.nurses.map((nurse, index) => `
      <article class="nurse-card" data-id="${nurse.id}">
        <div class="nurse-top">
          <div style="flex:1;min-width:0">
            <div class="nurse-name">${UI.escape(nurse.name)}</div>
            <div class="nurse-sub">รหัส ${UI.escape(nurse.id)} • ${UI.escape(nurse.department)}</div>
          </div>
          <span class="chip ${nurse.active ? 'chip-ok' : 'chip-off'}">
            ${nurse.active ? 'ปฏิบัติงาน' : 'พักใช้งาน'}
          </span>
        </div>
        <div class="nurse-meta">
          ${nurse.phone ? `<span>📞 ${UI.escape(nurse.phone)}</span>` : ''}
          ${nurse.start_text ? `<span>เริ่ม ${UI.escape(NursesPage.formatThaiDate(nurse.start_text))}</span>` : ''}
          ${nurse.end_text ? `<span>ครบ 6 เดือน ${UI.escape(NursesPage.formatThaiDate(nurse.end_text))}</span>` : ''}
          ${nurse.note ? `<span>📝 ${UI.escape(nurse.note)}</span>` : ''}
          ${nurse.login_username ? `<span>🔑 ${UI.escape(nurse.login_username)}</span>` : ''}
        </div>
        <button class="small-btn" data-action="edit" data-index="${index}">แก้ไข</button>
      </article>
    `).join('') || '<p class="empty">ยังไม่มีรายชื่อ — เพิ่มหรือเลือกไฟล์ต้นฉบับเพื่อนำเข้าสู่เซิร์ฟเวอร์</p>';

    container.querySelectorAll('[data-action="edit"]').forEach((btn) => {
      btn.addEventListener('click', () => this.editNurse(this.nurses[btn.dataset.index]));
    });
  }

  filterTable() {
    const query = document.getElementById('nurseSearch')?.value?.toLowerCase() || '';
    document.querySelectorAll('#nurseDirectoryTable .nurse-card').forEach((card) => {
      const text = card.textContent.toLowerCase();
      card.classList.toggle('hidden', !text.includes(query));
    });
  }

  // ---------- Form state ----------

  editNurse(nurse) {
    this.editingId = String(nurse.id);
    this.editingOriginal = nurse;

    const idEl = document.getElementById('nId');
    idEl.value = nurse.id;
    idEl.readOnly = true;
    const genBtn = document.getElementById('nurseCodeGenBtn');
    if (genBtn) genBtn.disabled = true;

    document.getElementById('nName').value = nurse.name || '';
    document.getElementById('nDept').value = nurse.department || '';
    document.getElementById('nPhone').value = nurse.phone || '';
    document.getElementById('nActive').value = nurse.active ? 'true' : 'false';
    document.getElementById('nNote').value = nurse.note || '';
    document.getElementById('nEnd').value = nurse.end_text || '';
    document.getElementById('nUsername').value = nurse.login_username || '';
    document.getElementById('nPassword').value = '';
    this.editingLoginUsername = nurse.login_username || null;
    this.renderLoginStatus();

    const startEl = document.getElementById('nStart');
    startEl.value = NursesPage.isIsoDate(nurse.start_text) ? nurse.start_text : '';

    const legacyHint = document.getElementById('nStartLegacy');
    const legacyText = !NursesPage.isIsoDate(nurse.start_text) && nurse.start_text
      ? `ค่าเดิมในระบบ: ${nurse.start_text} — เลือกวันที่ใหม่จากปฏิทินเพื่อแทนที่`
      : '';
    if (legacyHint) {
      legacyHint.textContent = legacyText;
      legacyHint.classList.toggle('hidden', !legacyText);
    }

    this.clearAllErrors();
    this.updateFormTitle();
    document.getElementById('nurseEditor')?.scrollIntoView({ block: 'start' });
  }

  resetForm() {
    this.editingId = null;
    this.editingOriginal = null;

    const idEl = document.getElementById('nId');
    idEl.value = '';
    idEl.readOnly = false;
    const genBtn = document.getElementById('nurseCodeGenBtn');
    if (genBtn) genBtn.disabled = false;

    document.getElementById('nName').value = '';
    document.getElementById('nDept').value = '';
    document.getElementById('nPhone').value = '';
    document.getElementById('nActive').value = 'true';
    document.getElementById('nStart').value = '';
    document.getElementById('nEnd').value = '';
    document.getElementById('nNote').value = '';
    document.getElementById('nUsername').value = '';
    document.getElementById('nPassword').value = '';
    this.editingLoginUsername = null;
    this.renderLoginStatus();
    document.getElementById('nStartLegacy')?.classList.add('hidden');

    this.clearAllErrors();
    this.updateFormTitle();
    this.suggestCode();
  }

  updateFormTitle() {
    const title = document.getElementById('nurseFormTitle');
    if (title) {
      title.textContent = this.editingId ? 'แก้ไขพยาบาล' : 'เพิ่มพยาบาลใหม่';
    }
    const submitBtn = document.getElementById('nurseSubmitBtn');
    if (submitBtn) {
      submitBtn.textContent = this.editingId ? 'บันทึกการแก้ไข' : 'เพิ่มพยาบาล';
    }
  }

  renderLoginStatus() {
    const el = document.getElementById('nLoginStatus');
    if (!el) return;
    let text = '';
    if (this.editingLoginUsername) {
      text = `🔑 บัญชีปัจจุบัน: ${this.editingLoginUsername} — เว้นช่องรหัสผ่านว่างไว้เพื่อใช้รหัสเดิม`;
    } else if (this.editingId) {
      text = 'ยังไม่มีบัญชีเข้าใช้งาน — กรอกชื่อผู้ใช้และรหัสผ่านเพื่อสร้างบัญชีให้พยาบาลรายนี้';
    }
    el.textContent = text;
    el.classList.toggle('hidden', !text);
  }

  applyServerFieldErrors(errors) {
    const map = { username: 'nUsername', password: 'nPassword' };
    let applied = false;
    Object.entries(map).forEach(([serverField, fieldId]) => {
      const message = errors[serverField];
      if (!message) return;
      this.setFieldError(fieldId, Array.isArray(message) ? message[0] : message);
      applied = true;
    });
    if (!applied) {
      window.toast.error('บันทึกไม่สำเร็จ: ข้อมูลไม่ผ่านการตรวจสอบ');
    }
  }

  // ---------- Submit ----------

  async handleSubmit(event) {
    event.preventDefault();

    const valid = ['nId', 'nName', 'nDept', 'nPhone', 'nUsername', 'nPassword']
      .map((name) => this.validateField(name))
      .every(Boolean);
    if (!valid) {
      window.toast.error('กรุณาตรวจสอบช่องที่แจ้งเตือนสีแดง');
      document.querySelector('#nurseForm [aria-invalid="true"]')?.focus();
      return;
    }

    const startIso = document.getElementById('nStart').value || '';
    const original = this.editingOriginal;
    const data = {
      id: document.getElementById('nId').value.trim(),
      name: document.getElementById('nName').value.trim(),
      department: document.getElementById('nDept').value.trim(),
      phone: document.getElementById('nPhone').value.trim(),
      start_text: startIso || (original ? (original.start_text || '') : ''),
      end_text: startIso
        ? NursesPage.addMonthsIso(startIso, 6)
        : (original ? (original.end_text || '') : ''),
      note: document.getElementById('nNote').value.trim(),
      active: document.getElementById('nActive').value === 'true',
      username: document.getElementById('nUsername').value.trim(),
      password: document.getElementById('nPassword').value,
    };

    const submitBtn = document.getElementById('nurseSubmitBtn');
    UI.setLoading(submitBtn, true);

    try {
      if (this.editingId) {
        await window.nurseApi.update(this.editingId, data);
        window.toast.success('แก้ไขพยาบาลเรียบร้อย');
      } else {
        if (this.findDuplicate(data.id)) {
          this.setFieldError('nId', 'รหัสนี้เพิ่งถูกใช้ไป กรุณาสร้างรหัสใหม่');
          return;
        }
        await window.nurseApi.create(data);
        window.toast.success('เพิ่มพยาบาลเรียบร้อย');
      }
    } catch (error) {
      if (error.status === 409 && error.data?.code === 'USERNAME_EXISTS') {
        this.setFieldError('nUsername', 'ชื่อผู้ใช้นี้ถูกใช้แล้วในระบบ');
      } else if (error.status === 409) {
        this.setFieldError('nId', 'รหัสนี้ถูกใช้แล้วในระบบ');
      } else if (error.status === 422 && error.data?.errors) {
        this.applyServerFieldErrors(error.data.errors);
      } else {
        window.toast.error('บันทึกไม่สำเร็จ: ' + error.message);
      }
      return;
    } finally {
      UI.setLoading(submitBtn, false);
    }

    // resetForm ต้องทำงานหลัง setLoading(false) ไม่งั้นข้อความปุ่มจะถูกทับกลับเป็นค่าเดิม
    this.resetForm();
    await this.loadNurses();
    this.render();
  }

  // ---------- Import ----------

  showFileStatus(file, state, message) {
    const chip = document.getElementById('nurseFileStatus');
    const name = document.getElementById('nurseFileName');
    const meta = document.getElementById('nurseFileMeta');
    if (!chip || !name || !meta) return;

    chip.classList.remove('hidden');
    name.textContent = file ? file.name : '';
    meta.textContent = message || '';

    chip.classList.remove('border-teal-200', 'bg-teal-50', 'border-red-200', 'bg-red-50', 'border-amber-200', 'bg-amber-50');
    meta.classList.remove('text-teal-700', 'text-red-700', 'text-amber-700');
    const style = {
      loading: ['border-amber-200', 'bg-amber-50', 'text-amber-700'],
      warn: ['border-amber-200', 'bg-amber-50', 'text-amber-700'],
      done: ['border-teal-200', 'bg-teal-50', 'text-teal-700'],
      error: ['border-red-200', 'bg-red-50', 'text-red-700'],
    }[state];
    if (style) {
      chip.classList.add(style[0], style[1]);
      meta.classList.add(style[2]);
    }
  }

  // ---------- Import (Excel / CSV) ----------

  static normalizeHeader(value) {
    return String(value ?? '')
      .replace(/\uFEFF/g, '')
      .replace(/[\u2013\u2014]/g, '-')
      .replace(/\u00A0/g, ' ')
      .replace(/[\s._/]/g, '')
      .toLowerCase();
  }

  // ลอง UTF-8 ก่อน ถ้า decode ไม่ผ่าน (CSV เก่าภาษาไทย) จึงถอยไป windows-874
  static decodeCsvBytes(buffer) {
    const bytes = new Uint8Array(buffer);
    if (bytes.length >= 3 && bytes[0] === 0xEF && bytes[1] === 0xBB && bytes[2] === 0xBF) {
      return new TextDecoder('utf-8').decode(bytes.subarray(3));
    }
    if (bytes.length >= 2 && bytes[0] === 0xFF && bytes[1] === 0xFE) {
      return new TextDecoder('utf-16le').decode(bytes.subarray(2));
    }
    if (bytes.length >= 2 && bytes[0] === 0xFE && bytes[1] === 0xFF) {
      return new TextDecoder('utf-16be').decode(bytes.subarray(2));
    }
    try {
      return new TextDecoder('utf-8', { fatal: true }).decode(bytes);
    } catch (error) {
      return new TextDecoder('windows-874').decode(bytes);
    }
  }

  static cellText(value) {
    if (value instanceof Date && !isNaN(value)) {
      return `${value.getDate()}/${value.getMonth() + 1}/${value.getFullYear() + 543}`;
    }
    return String(value ?? '').trim();
  }

  static buildIso(year, month, day) {
    if (!year || year < 1000 || year > 9999 || month < 1 || month > 12 || day < 1 || day > 31) return null;
    const dt = new Date(Date.UTC(year, month - 1, day));
    if (dt.getUTCFullYear() !== year || dt.getUTCMonth() !== month - 1 || dt.getUTCDate() !== day) return null;
    return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
  }

  // อ่านวันได้ทั้ง Date ของ Excel, วว/ดด/ปปปป (ค.ศ. หรือ พ.ศ.) และ YYYY-MM-DD
  static interpretDate(value) {
    if (value instanceof Date && !isNaN(value)) {
      const utcMidnight = value.getUTCHours() === 0 && value.getUTCMinutes() === 0 && value.getUTCSeconds() === 0;
      const y = utcMidnight ? value.getUTCFullYear() : value.getFullYear();
      const m = (utcMidnight ? value.getUTCMonth() : value.getMonth()) + 1;
      const d = utcMidnight ? value.getUTCDate() : value.getDate();
      return { iso: NursesPage.buildIso(y, m, d), warning: '' };
    }
    const text = NursesPage.cellText(value);
    if (!text) return { iso: '', warning: '' };

    let parts = text.match(/^(\d{4})-(\d{1,2})-(\d{1,2})/);
    if (parts) return { iso: NursesPage.buildIso(+parts[1], +parts[2], +parts[3]), warning: '' };

    parts = text.match(/^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{2}|\d{4})/);
    if (parts) {
      let year = +parts[3];
      let warning = '';
      if (parts[3].length === 2) {
        if (year < 50) {
          year += 2000;
        } else {
          warning = `ตีความปี "${parts[3]}" เป็น พ.ศ. ${2500 + year}`;
          year += 2500 - 543;
        }
      } else if (year > 2400) {
        warning = `แปลง พ.ศ. ${year} เป็น ค.ศ. ${year - 543}`;
        year -= 543;
      }
      return { iso: NursesPage.buildIso(year, +parts[2], +parts[1]), warning };
    }
    return { iso: null, warning: '' };
  }

  async parseSpreadsheet(file) {
    const isCsv = /\.csv$/i.test(file.name);
    const buffer = await file.arrayBuffer();
    // CSV อ่านแบบ raw (string ล้วน) เพื่อไม่ให้ SheetJS ตีความวันที่แบบ MM/DD
    // หรือตัด 0 ตัวหน้าเบอร์โทร — วันที่/ตัวเลขจัดการเองด้านล่าง
    const workbook = isCsv
      ? XLSX.read(NursesPage.decodeCsvBytes(buffer), { type: 'string', raw: true })
      : XLSX.read(buffer, { type: 'array', cellDates: true });

    const sheetName = workbook.SheetNames[0];
    if (!sheetName) throw new Error('ไม่พบชีตข้อมูลในไฟล์');
    const rows = XLSX.utils.sheet_to_json(workbook.Sheets[sheetName], {
      header: 1, blankrows: false, defval: '', raw: true,
    });
    if (!rows.length) throw new Error('ไฟล์ว่างเปล่า — ไม่พบข้อมูล');

    // หาแถวหัวตารางใน 10 แถวแรก (ไฟล์จริงอาจมีแถวชื่อเรื่องอยู่ด้านบน)
    let headerRowIndex = -1;
    let columnMap = null;
    for (let r = 0; r < Math.min(rows.length, 10); r++) {
      const found = {};
      rows[r].forEach((cell, col) => {
        const norm = NursesPage.normalizeHeader(cell);
        if (!norm) return;
        NURSE_IMPORT_COLUMNS.forEach((def) => {
          if (found[def.key]) return;
          if (!def.aliases.some((alias) => NursesPage.normalizeHeader(alias) === norm)) return;
          found[def.key] = { col, header: String(cell ?? '').trim() };
        });
      });
      if (NURSE_IMPORT_COLUMNS.filter((def) => def.required).every((def) => found[def.key])) {
        headerRowIndex = r;
        columnMap = found;
        break;
      }
    }

    if (headerRowIndex < 0) {
      const seen = (rows[0] || []).map((cell) => String(cell ?? '').trim()).filter(Boolean);
      throw new Error(
        'ไม่พบหัวคอลัมน์ที่จำเป็น (รหัสพยาบาล, ชื่อ-นามสกุล, หน่วยงาน)'
        + (seen.length ? ` • หัวคอลัมน์ที่พบ: ${seen.join(' / ')}` : '')
        + ' • แนะนำ: กด "ดาวน์โหลดไฟล์ตัวอย่าง (.xlsx)" แล้วใช้หัวคอลัมน์ตามตัวอย่าง'
      );
    }

    const headerRow = rows[headerRowIndex];
    const matchedCols = new Set(Object.values(columnMap).map((m) => m.col));
    const unknownHeaders = headerRow
      .map((cell, col) => (matchedCols.has(col) ? '' : String(cell ?? '').trim()))
      .filter(Boolean);
    const recognized = NURSE_IMPORT_COLUMNS
      .filter((def) => columnMap[def.key])
      .map((def) => `${def.label} ← "${columnMap[def.key].header}"`);
    // ไฟล์ที่แยกคอลัมน์ "ชื่อ" กับ "นามสกุล" ให้ต่อรวมเป็นชื่อ-นามสกุล
    const combineLastname = Boolean(columnMap.lastname)
      && ['ชื่อ', 'name'].includes(NursesPage.normalizeHeader(columnMap.name.header));

    const dataRows = [];
    for (let r = headerRowIndex + 1; r < rows.length; r++) {
      const raw = rows[r] || [];
      if (raw.every((cell) => String(cell ?? '').trim() === '')) continue;
      dataRows.push({ rowNo: r + 1, raw });
    }
    if (!dataRows.length) throw new Error('ไม่พบแถวข้อมูลใต้หัวตาราง — ไฟล์มีแต่หัวคอลัมน์');

    const preview = {
      fileName: file.name,
      sheetName,
      headerRow: headerRowIndex + 1,
      recognized,
      unknownHeaders,
      rows: [],
      ready: 0,
      dupes: 0,
      invalid: 0,
    };

    const seenIds = new Map();
    for (const { rowNo, raw } of dataRows) {
      const cell = (key) => (columnMap[key] ? raw[columnMap[key].col] : '');
      const entry = { rowNo, errors: [], warnings: [], skipReason: '', data: null, values: null };

      const id = NursesPage.cellText(cell('id'));
      let name = NursesPage.cellText(cell('name'));
      if (combineLastname) {
        const lastName = NursesPage.cellText(cell('lastname'));
        if (lastName) name = name ? `${name} ${lastName}` : lastName;
      }
      const department = NursesPage.cellText(cell('department'));
      const note = NursesPage.cellText(cell('note'));

      if (!id) entry.errors.push('ไม่มีรหัสพยาบาล');
      else if (id.length > 100) entry.errors.push('รหัสยาวเกิน 100 ตัวอักษร');
      if (!name) entry.errors.push('ไม่มีชื่อ-นามสกุล');
      else if (name.length > 255) entry.errors.push('ชื่อยาวเกิน 255 ตัวอักษร');
      if (!department) entry.errors.push('ไม่มีหน่วยงาน');
      else if (department.length > 255) entry.errors.push('หน่วยงานยาวเกิน 255 ตัวอักษร');

      // เบอร์โทรที่เป็นตัวเลขล้วน 8-9 หลัก (เซลล์ Excel หรือ CSV) มักเสีย 0 ตัวหน้า — เติมคืน
      let phone = NursesPage.cellText(cell('phone'));
      if (phone) {
        const digits = phone.replace(/\D/g, '');
        const plainNumber = typeof cell('phone') === 'number' || /^\d+$/.test(phone);
        if (plainNumber && digits.length >= 8 && digits.length <= 9 && !digits.startsWith('0')) {
          phone = NursesPage.applyPhoneMask('0' + digits);
          entry.warnings.push('เบอร์โทรเป็นตัวเลขล้วน — ระบบเติม 0 ด้านหน้าให้ (แนะนำตั้งคอลัมน์เป็นข้อความ)');
        } else if (digits.length && digits.length < 9) {
          entry.warnings.push(`เบอร์โทร "${phone}" มี ${digits.length} หลัก — ระบบจะบันทึกตามที่กรอก`);
        }
      }

      const statusText = NursesPage.cellText(cell('status'));
      let active = true;
      if (statusText) {
        const statusNorm = statusText.toLowerCase();
        if (NURSE_IMPORT_ACTIVE_WORDS.includes(statusNorm)) active = true;
        else if (NURSE_IMPORT_INACTIVE_WORDS.includes(statusNorm)) active = false;
        else entry.errors.push(`สถานะ "${statusText}" ไม่รู้จัก — ใช้ "ปฏิบัติงาน" หรือ "พักใช้งาน"`);
      }

      const startRaw = cell('start');
      const startTextRaw = NursesPage.cellText(startRaw);
      let startIso = '';
      if (startTextRaw) {
        const parsed = NursesPage.interpretDate(startRaw);
        if (!parsed.iso) {
          entry.errors.push(`วันเริ่มปฏิบัติงาน "${startTextRaw}" อ่านไม่ได้ — ใช้ วว/ดด/ปปปป (ค.ศ./พ.ศ.) หรือ YYYY-MM-DD`);
        } else {
          startIso = parsed.iso;
          if (parsed.warning) entry.warnings.push(parsed.warning);
        }
      }

      if (!entry.errors.length) {
        if (this.nurses.some((n) => String(n.id) === id)) {
          entry.skipReason = 'มีรหัสนี้อยู่แล้วในระบบ (ข้อมูลเดิมไม่ถูกแก้ไข)';
        } else if (seenIds.has(id)) {
          entry.errors.push(`รหัสซ้ำในไฟล์เดียวกัน (แถว ${seenIds.get(id)})`);
        } else {
          seenIds.set(id, rowNo);
        }
      }

      entry.values = {
        id,
        name,
        department,
        phone,
        start: startIso ? NursesPage.formatThaiDate(startIso) : startTextRaw,
        active,
      };

      if (entry.errors.length) {
        preview.invalid++;
      } else if (entry.skipReason) {
        preview.dupes++;
      } else {
        preview.ready++;
        entry.data = {
          id,
          name,
          department,
          phone,
          start_text: startIso,
          end_text: startIso ? NursesPage.addMonthsIso(startIso, 6) : '',
          note,
          active,
        };
      }

      preview.rows.push(entry);
    }

    return preview;
  }

  showImportPreview(file, preview) {
    const esc = UI.escape;
    const summaryHtml = [
      `<span class="text-slate-600">ทั้งหมด ${preview.rows.length} แถว</span>`,
      `<span class="font-semibold text-teal-700">พร้อมนำเข้า ${preview.ready}</span>`,
      preview.dupes ? `<span class="font-semibold text-amber-700">ข้าม (มีอยู่แล้ว) ${preview.dupes}</span>` : '',
      preview.invalid ? `<span class="font-semibold text-red-700">ผิดพลาด ${preview.invalid}</span>` : '',
    ].filter(Boolean).join('<span class="text-slate-300"> · </span>');

    const rowsHtml = preview.rows.map((row) => {
      const v = row.values || {};
      let badge;
      let note;
      let noteClass = 'text-slate-500';
      let rowClass = '';
      if (row.errors.length) {
        badge = '<span class="chip chip-miss">ผิดพลาด</span>';
        note = row.errors.join(' · ');
        noteClass = 'text-red-700';
        rowClass = 'bg-red-50';
      } else if (row.skipReason) {
        badge = '<span class="chip chip-warn">ข้าม</span>';
        note = row.skipReason;
        noteClass = 'text-amber-700';
        rowClass = 'bg-amber-50';
      } else {
        badge = '<span class="chip chip-ok">พร้อม</span>';
        note = row.warnings.join(' · ');
        if (note) noteClass = 'text-amber-700';
      }
      return `
        <tr class="border-t border-slate-100 ${rowClass}">
          <td class="px-2 py-1.5 text-slate-500">${row.rowNo}</td>
          <td class="px-2 py-1.5">${badge}</td>
          <td class="px-2 py-1.5 font-semibold text-slate-700">${esc(v.id || '–')}</td>
          <td class="px-2 py-1.5">${esc(v.name || '–')}</td>
          <td class="px-2 py-1.5">${esc(v.department || '–')}</td>
          <td class="px-2 py-1.5">${esc(v.phone || '–')}</td>
          <td class="px-2 py-1.5">${esc(v.start || '–')}</td>
          <td class="px-2 py-1.5">${v.active ? 'ปฏิบัติงาน' : 'พักใช้งาน'}</td>
          <td class="px-2 py-1.5 ${noteClass}">${note ? esc(note) : ''}</td>
        </tr>`;
    }).join('');

    const topNotes = [];
    if (preview.unknownHeaders.length) {
      topNotes.push(`คอลัมน์ที่ไม่รู้จัก (จะถูกข้าม): ${preview.unknownHeaders.map(esc).join(', ')}`);
    }

    const content = `
      <div class="text-sm text-slate-700">
        <p class="mb-1 text-xs text-slate-500">ไฟล์ <span class="font-semibold text-slate-700">${esc(preview.fileName)}</span> • ชีต "${esc(preview.sheetName)}" • หัวตารางแถวที่ ${preview.headerRow}</p>
        <p class="mb-2 text-xs">${summaryHtml}</p>
        <p class="mb-2 text-xs text-slate-500">คอลัมน์ที่จับคู่ได้: ${preview.recognized.map(esc).join(' · ')}</p>
        ${topNotes.length ? `<div class="mb-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">${topNotes.map(esc).join('<br>')}</div>` : ''}
        <div class="max-h-[50vh] overflow-auto rounded-lg border border-slate-200">
          <table class="w-full border-collapse text-xs">
            <thead class="sticky top-0 bg-slate-100 text-left text-slate-600">
              <tr>
                <th class="whitespace-nowrap px-2 py-1.5 font-semibold">แถว</th>
                <th class="whitespace-nowrap px-2 py-1.5 font-semibold">ผลตรวจ</th>
                <th class="whitespace-nowrap px-2 py-1.5 font-semibold">รหัส</th>
                <th class="whitespace-nowrap px-2 py-1.5 font-semibold">ชื่อ-นามสกุล</th>
                <th class="whitespace-nowrap px-2 py-1.5 font-semibold">หน่วยงาน</th>
                <th class="whitespace-nowrap px-2 py-1.5 font-semibold">โทรศัพท์</th>
                <th class="whitespace-nowrap px-2 py-1.5 font-semibold">วันเริ่ม</th>
                <th class="whitespace-nowrap px-2 py-1.5 font-semibold">สถานะ</th>
                <th class="whitespace-nowrap px-2 py-1.5 font-semibold">หมายเหตุ / ปัญหา</th>
              </tr>
            </thead>
            <tbody>${rowsHtml}</tbody>
          </table>
        </div>
        <p class="mt-2 text-xs text-slate-500">แถว "ข้าม" = มีรหัสอยู่ในระบบแล้ว (ไม่ทับข้อมูลเดิม) • แถว "ผิดพลาด" จะไม่ถูกนำเข้า • ตรวจรายการให้ถูกต้องก่อนกด "นำเข้า"</p>
      </div>
    `;

    this._importModal?.destroy();
    const modal = new Modal({
      title: `พรีวิวการนำเข้า (${preview.rows.length} แถว)`,
      content,
      size: 'xl',
    });
    modal.render();
    this._importModal = modal;

    const actions = modal.element.querySelector('.sheet-actions');
    actions.innerHTML = '';
    const cancelBtn = UI.create('button', { type: 'button', class: 'small-btn gray flex-1' }, 'ยกเลิก');
    cancelBtn.addEventListener('click', () => modal.destroy());
    const importBtn = UI.create('button', {
      type: 'button',
      class: 'flex-1 disabled:cursor-not-allowed disabled:opacity-50',
    }, preview.ready > 0 ? `นำเข้า ${preview.ready} รายการ` : 'ไม่มีแถวที่พร้อมนำเข้า');
    importBtn.disabled = preview.ready === 0;
    importBtn.addEventListener('click', () => this.runImport(file, preview));
    actions.append(cancelBtn, importBtn);

    modal.open();
  }

  async runImport(file, preview) {
    if (this._importing) {
      window.toast.info('กำลังนำเข้าชุดก่อนหน้าอยู่ — กรุณารอสักครู่');
      return;
    }
    const pending = preview.rows.filter((row) => row.data && !row.errors.length && !row.skipReason);
    if (!pending.length) {
      window.toast.error('ไม่มีแถวที่พร้อมนำเข้า');
      return;
    }

    this._importing = true;
    this._importModal?.destroy();
    this._importModal = null;

    let imported = 0;
    let failed = 0;
    const failureNotes = [];
    try {
      for (let i = 0; i < pending.length; i++) {
        const row = pending[i];
        this.showFileStatus(file, 'loading', `กำลังนำเข้า ${i + 1}/${pending.length} รายการ… (สำเร็จ ${imported})`);
        try {
          await window.nurseApi.create(row.data);
          imported++;
        } catch (error) {
          failed++;
          const fieldErrors = error.data && error.data.errors;
          const why = error.status === 409
            ? (error.data && error.data.code === 'USERNAME_EXISTS' ? 'ชื่อผู้ใช้ซ้ำกับบัญชีอื่น' : 'รหัสซ้ำกับข้อมูลในระบบ')
            : (fieldErrors ? Object.values(fieldErrors).flat().join(' ') : error.message);
          failureNotes.push(`แถว ${row.rowNo}: ${why}`);
        }
      }
    } finally {
      this._importing = false;
    }

    const summaryParts = [`นำเข้าสำเร็จ ${imported} รายชื่อ`];
    if (preview.dupes) summaryParts.push(`ข้ามรหัสที่มีอยู่เดิม ${preview.dupes}`);
    if (preview.invalid) summaryParts.push(`ข้ามแถวผิดพลาด ${preview.invalid}`);
    if (failed) summaryParts.push(`นำเข้าไม่สำเร็จ ${failed}`);

    failureNotes.slice(0, 3).forEach((note) => console.warn('นำเข้าไม่สำเร็จ —', note));

    if (imported > 0) {
      this.showFileStatus(file, failed ? 'warn' : 'done', `${failed ? '⚠' : '✓'} ${summaryParts.join(' · ')}`);
      window.toast.success(imported === pending.length
        ? `นำเข้าสำเร็จครบ ${imported} รายชื่อ`
        : `${summaryParts.join(' · ')} — ดูรายละเอียดในแถบสถานะไฟล์`);
    } else {
      this.showFileStatus(file, 'error', `✗ ${summaryParts.join(' · ')}`);
      window.toast.error(failureNotes.length ? 'นำเข้าไม่สำเร็จ — ' + failureNotes[0] : 'ไม่มีแถวที่นำเข้าสำเร็จ');
    }

    await this.loadNurses();
    this.render();
  }

  downloadTemplate() {
    if (typeof XLSX === 'undefined') {
      window.toast.error('ตัวอ่าน/เขียน Excel ยังโหลดไม่สำเร็จ — กรุณารีเฟรชหน้าแล้วลองใหม่');
      return;
    }

    const headers = ['รหัสพยาบาล', 'ชื่อ-นามสกุล', 'หน่วยงาน', 'โทรศัพท์', 'วันเริ่มปฏิบัติงาน', 'สถานะ', 'หมายเหตุ'];
    const demoRows = [
      ['10001', 'ตัวอย่าง พยาบาลทดสอบ (mock)', 'หน่วยงานตัวอย่าง (mock)', '081-234-5678', '01/05/2568', 'ปฏิบัติงาน', 'แถวตัวอย่าง — ลบก่อนนำเข้าข้อมูลจริง'],
      ['10002', 'ตัวอย่าง พยาบาลทดสอบสอง (mock)', 'หน่วยงานตัวอย่าง (mock)', '02-123-4567', '2025-05-01', 'พักใช้งาน', 'แถวตัวอย่าง — ลบก่อนนำเข้าข้อมูลจริง'],
    ];
    const ws = XLSX.utils.aoa_to_sheet([headers, ...demoRows]);
    ws['!cols'] = [{ wch: 12 }, { wch: 34 }, { wch: 26 }, { wch: 15 }, { wch: 18 }, { wch: 12 }, { wch: 42 }];

    const guideRows = [
      ['คำแนะนำการใช้ไฟล์นำเข้ารายชื่อพยาบาล — ระบบตรวจเช็กรถ Refer / Ambulance'],
      ['1) กรอกข้อมูลในชีต "รายชื่อพยาบาล" โดยไม่แก้หัวคอลัมน์แถวแรก'],
      ['2) คอลัมน์บังคับ 3 ช่อง: รหัสพยาบาล / ชื่อ-นามสกุล / หน่วยงาน — คอลัมน์อื่นเว้นว่างได้'],
      ['3) รหัสพยาบาลต้องไม่ซ้ำกับรายชื่อในระบบ (แถวซ้ำจะถูกข้ามอัตโนมัติ ไม่ทับข้อมูลเดิม)'],
      ['4) วันเริ่มปฏิบัติงาน กรอกได้ทั้ง วว/ดด/ปปปป แบบ พ.ศ. (เช่น 01/05/2568) หรือ ค.ศ. (01/05/2025) หรือ YYYY-MM-DD — ระบบคำนวณวันครบ 6 เดือนให้เอง'],
      ['5) สถานะ ใส่ "ปฏิบัติงาน" หรือ "พักใช้งาน" (เว้นว่าง = ปฏิบัติงาน)'],
      ['6) คอลัมน์โทรศัพท์ แนะนำตั้งรูปแบบเซลล์เป็น "ข้อความ (Text)" ก่อนกรอก เพื่อไม่ให้เลข 0 ตัวหน้าหาย'],
      ['7) ลบแถวตัวอย่าง (รหัส 10001, 10002) ออกก่อนนำเข้าข้อมูลจริง'],
      ['8) บันทึกไฟล์เป็น .xlsx หรือ .csv (UTF-8) แล้วอัปโหลดที่ช่อง "นำเข้ารายชื่อ" ในแท็บพยาบาลเวร'],
      ['9) ระบบจะแสดงตารางพรีวิวให้ตรวจสอบทุกแถวก่อนยืนยันนำเข้า'],
    ];
    const guideWs = XLSX.utils.aoa_to_sheet(guideRows);
    guideWs['!cols'] = [{ wch: 110 }];

    const workbook = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(workbook, ws, 'รายชื่อพยาบาล');
    XLSX.utils.book_append_sheet(workbook, guideWs, 'คำแนะนำ');
    XLSX.writeFile(workbook, 'แบบฟอร์มนำเข้ารายชื่อพยาบาล.xlsx');
    window.toast.success('ดาวน์โหลดไฟล์ตัวอย่างแล้ว — เปิดกรอกข้อมูลใน Excel แล้วนำเข้ากลับได้เลย');
  }

  async handleImport(file) {
    if (!file) return;
    if (this._importing) {
      window.toast.info('กำลังนำเข้าชุดก่อนหน้าอยู่ — กรุณารอสักครู่');
      return;
    }
    if (typeof XLSX === 'undefined') {
      this.showFileStatus(file, 'error', '✗ ตัวอ่าน Excel ยังโหลดไม่สำเร็จ — กรุณารีเฟรชหน้าแล้วลองใหม่');
      return;
    }
    if (!/\.(xlsx|xls|csv)$/i.test(file.name)) {
      this.showFileStatus(file, 'error', '✗ รองรับเฉพาะไฟล์ .xlsx .xls และ .csv เท่านั้น');
      return;
    }
    if (file.size === 0) {
      this.showFileStatus(file, 'error', '✗ ไฟล์ว่างเปล่า (0 byte)');
      return;
    }
    if (file.size > 10 * 1024 * 1024) {
      this.showFileStatus(file, 'error', '✗ ไฟล์ใหญ่เกิน 10 MB — กรุณาแบ่งข้อมูลเป็นไฟล์ย่อย');
      return;
    }

    this.showFileStatus(file, 'loading', `กำลังอ่านไฟล์… (${Math.max(1, Math.round(file.size / 1024))} KB)`);

    try {
      const preview = await this.parseSpreadsheet(file);
      if (preview.rows.length > 2000) {
        throw new Error(`ไฟล์มี ${preview.rows.length} แถว — รองรับสูงสุด 2,000 แถวต่อครั้ง กรุณาแบ่งไฟล์`);
      }
      this.showFileStatus(file, 'loading', `พบ ${preview.rows.length} แถว — ตรวจสอบตารางพรีวิวก่อนกดนำเข้า`);
      this.showImportPreview(file, preview);
    } catch (error) {
      const message = error && error.message ? error.message : 'อ่านไฟล์ไม่สำเร็จ';
      this.showFileStatus(file, 'error', '✗ ' + message);
      window.toast.error('อ่านไฟล์ไม่ได้: ' + message);
    }
  }
}

// Initialize
let nursesPage;
document.addEventListener('DOMContentLoaded', () => {
  if (document.getElementById('tabContentNurses') && !document.getElementById('tabContentNurses').classList.contains('hidden')) {
    nursesPage = new NursesPage();
    window.nursesPage = nursesPage;
  }
});

// Export for tab switching
window.initNurses = () => {
  if (!nursesPage) {
    nursesPage = new NursesPage();
    window.nursesPage = nursesPage;
  } else {
    nursesPage.loadNurses().then(() => nursesPage.render());
  }
};
