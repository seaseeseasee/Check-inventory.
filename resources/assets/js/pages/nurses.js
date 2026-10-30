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
    this.departments = [];
    this.editingId = null;
    this.editingOriginal = null;
    this.editingLoginUsername = null;
    this.pendingDeptId = null;
    this._importModal = null;
    this._importing = false;
    this._wardModal = null;
    this._wardFormModal = null;
    this.init();
  }

  async init() {
    await Promise.all([this.loadNurses(), this.loadDepartments()]);
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

  // ทะเบียนวอร์ดอ่านได้ทุก role ที่ login (หน้า dropdown ใช้ร่วมกัน) แต่เขียนได้เฉพาะ admin
  async loadDepartments() {
    try {
      const res = await window.departmentApi.getAll();
      this.departments = res.data || [];
    } catch (error) {
      console.error('โหลดทะเบียนวอร์ดไม่สำเร็จ:', error);
      this.departments = [];
      window.toast.error('โหลดทะเบียนวอร์ดไม่สำเร็จ');
    }
  }

  isWardOn(ward) {
    return ward?.active === true || ward?.active === 1 || ward?.active === '1';
  }

  wardNameSet() {
    return new Set(this.departments.map((d) => String(d.name || '').trim().toLowerCase()));
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

  /** เบอร์โทร → ชื่อผู้ใช้ตัวเลขล้วน (เหมือน User::normalizePhone ฝั่ง server) — null ถ้าไม่ใช่เบอร์ไทย */
  static phoneUsername(raw) {
    const s = String(raw || '').trim();
    if (!s || /[^\d\s\-()]/.test(s)) return null;
    const digits = s.replace(/\D/g, '');
    return /^0\d{8,9}$/.test(digits) ? digits : null;
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
    document.getElementById('nurseMenuBtn')?.addEventListener('click', (e) => {
      e.stopPropagation();
      NursesPage.setNurseMenu(document.getElementById('nurseMenu')?.classList.contains('hidden'));
    });
    document.getElementById('nurseDeleteBtn')?.addEventListener('click', () => {
      NursesPage.setNurseMenu(false);
      this.deleteNurse();
    });
    // กดที่อื่น / Esc = ปิดเมนู
    document.addEventListener('click', (e) => {
      if (!e.target.closest?.('#nurseMenu')) NursesPage.setNurseMenu(false);
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && !document.getElementById('nurseMenu')?.classList.contains('hidden')) {
        NursesPage.setNurseMenu(false);
        document.getElementById('nurseMenuBtn')?.focus();
      }
    });

    const search = document.getElementById('nurseSearch');
    if (search) {
      search.addEventListener('input', UI.debounce(() => this.filterTable(), 300));
    }

    document.getElementById('nurseDeptFilter')?.addEventListener('change', () => this.filterTable());

    // แท็บกรอง (ประเภท/บทบาท/สถานะ) — เก็บค่าที่เลือกไว้บน #userChips ให้ทั้งสองรายการอ่านร่วมกัน
    const chips = document.getElementById('userChips');
    chips?.addEventListener('click', (e) => {
      const btn = e.target.closest('button[data-role]');
      if (!btn) return;
      const off = btn.getAttribute('aria-pressed') === 'true';   // กดหมวดเดิมซ้ำ = ยกเลิกตัวกรอง (แสดงทั้งหมด)
      chips.dataset.role = off ? '' : btn.dataset.role;
      chips.dataset.status = off ? '' : btn.dataset.status;
      chips.querySelectorAll('button').forEach((b) => b.setAttribute('aria-pressed', String(!off && b === btn)));
      this.filterTable();
    });

    // แตะแถวพยาบาล = เปิดหน้าต่างแก้ไข
    document.getElementById('nurseDirectoryTable')?.addEventListener('click', (e) => {
      const row = e.target.closest('.user-row');
      const nurse = row && this.nurses[Number(row.dataset.index)];
      if (!nurse) return;
      window.usersPage?.resetForm();
      this.editNurse(nurse);
      NursesPage.openSheet('nurse', true);
    });

    document.getElementById('userAddBtn')?.addEventListener('click', () => {
      this.resetForm();
      window.usersPage?.resetForm();
      NursesPage.openSheet('nurse', false);
    });
    document.querySelectorAll('#userKind [data-kind]').forEach((btn) => {
      btn.addEventListener('click', () => NursesPage.setSheetKind(btn.dataset.kind));
    });

    // เมนู ⋯ : นำเข้า / ไฟล์ตัวอย่าง / จัดการวอร์ด — ปิดเมื่อเลือกคำสั่งหรือแตะนอกเมนู
    const more = document.getElementById('usersMore');
    document.addEventListener('click', (e) => {
      if (more?.open && (!more.contains(e.target) || e.target.closest('.users-more-item'))) more.open = false;
    });
    document.getElementById('userImportOpenBtn')?.addEventListener('click', () => NursesPage.openImport());

    // สวิตช์ "เปิดบัญชีให้คนนี้" (เฉพาะพยาบาลที่ยังไม่มีบัญชี)
    document.getElementById('nAccountToggle')?.addEventListener('click', () => {
      const on = document.getElementById('nAccountToggle').getAttribute('aria-checked') !== 'true';
      this.setAccountSwitch(on);
      if (on) {
        const user = document.getElementById('nUsername');
        user.value = NursesPage.phoneUsername(document.getElementById('nPhone').value) || '';
        user.dataset.auto = user.value ? '1' : '';
        user.focus();
      }
    });
    document.getElementById('nRoleBtn')?.addEventListener('click', () => this.editingOriginal && this.changeRole(this.editingOriginal));
    document.getElementById('nAccountActiveBtn')?.addEventListener('click', () => this.editingOriginal && this.toggleAccount(this.editingOriginal));

    document.getElementById('wardManagerBtn')?.addEventListener('click', () => this.openWardManager());
    document.getElementById('deptQuickAddBtn')?.addEventListener('click', () => this.openQuickAddWard());

    // Code: generate button + live duplicate check
    document.getElementById('nurseCodeGenBtn')?.addEventListener('click', () => {
      this.suggestCode();
      window.toast.info('สร้างรหัส ' + document.getElementById('nId').value + ' ให้แล้ว');
    });

    const checkId = UI.debounce(() => this.validateField('nId'), 250);
    document.getElementById('nId')?.addEventListener('input', checkId);
    document.getElementById('nName')?.addEventListener('input', () => this.validateField('nName'));
    document.getElementById('nDept')?.addEventListener('change', () => this.validateField('nDept'));
    document.getElementById('nUsername')?.addEventListener('input', () => this.validateField('nUsername'));
    document.getElementById('nPassword')?.addEventListener('input', () => this.validateField('nPassword'));

    ['nId', 'nName', 'nDept', 'nUsername', 'nPassword'].forEach((id) => {
      document.getElementById(id)?.addEventListener('blur', () => this.validateField(id));
    });

    // Phone: tel input mask + soft validation
    const phone = document.getElementById('nPhone');
    phone?.addEventListener('input', (e) => {
      this.onPhoneInput(e);
      // บัญชีใหม่: ชื่อผู้ใช้ = เบอร์โทร (ใส่ให้อัตโนมัติจนกว่าผู้ดูแลจะพิมพ์ชื่อผู้ใช้เอง)
      const user = document.getElementById('nUsername');
      const switchOn = document.getElementById('nAccountToggle')?.getAttribute('aria-checked') === 'true';
      if (user && switchOn && !this.editingLoginUsername && (user.value === '' || user.dataset.auto === '1')) {
        user.value = NursesPage.phoneUsername(e.target.value) || '';
        user.dataset.auto = user.value ? '1' : '';
      }
    });
    document.getElementById('nUsername')?.addEventListener('input', (e) => { e.target.dataset.auto = ''; });
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
      nDept: 'กรุณาเลือกหน่วยงาน / วอร์ด',
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
      const isPhone = NursesPage.phoneUsername(value) !== null;
      if (value && !isPhone && !/^[A-Za-z0-9._-]{3,100}$/.test(value)) {
        this.setFieldError(name, 'ใช้เบอร์โทรศัพท์ (เช่น 0812345678) หรือ a-z A-Z 0-9 . _ - (3-100 ตัวอักษร)');
        return false;
      }
      if (!value && this.editingLoginUsername) {
        this.setFieldError(name, 'ห้ามลบชื่อผู้ใช้ของบัญชีเดิม — ใช้สถานะ "พักใช้งาน" เพื่อปิดการเข้าสู่ระบบแทน');
        return false;
      }
      // ชื่อผู้ใช้เป็นเบอร์โทร: เว้นรหัสได้ (รหัสเริ่มต้น = เบอร์โทร) — ชื่อแบบอื่นต้องตั้งรหัสเริ่มต้นเอง
      if (value && !this.editingLoginUsername && !isPhone && password.length < 6) {
        this.setFieldError('nPassword', 'ชื่อผู้ใช้ไม่ใช่เบอร์โทร — ต้องตั้งรหัสผ่านเริ่มต้นอย่างน้อย 6 ตัวอักษร');
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
    // แถบบน (ค้นหา/ตัวกรอง) ติดใต้หัวเว็บ — ความสูงหัวเว็บต่างกันตามจอ จึงวัดจริง
    const appTop = document.querySelector('.app-top');
    if (appTop) document.documentElement.style.setProperty('--app-top-h', appTop.offsetHeight + 'px');
    this.renderTable();
    this.renderDeptSelect();
    this.renderDeptFilter();
    this.renderWardBadge();
    this.updateFormTitle();
  }

  renderWardBadge() {
    const badge = document.getElementById('wardManagerCount');
    if (badge) badge.textContent = String(this.departments.length);
  }

  // ตัวเลือกวอร์ดของฟอร์มพยาบาล — ค่ามาจากทะเบียน master เท่านั้น
  // choice = { id, legacy } (ไม่ต้องส่ง = เก็บค่าที่เลือกอยู่เดิม)
  renderDeptSelect(choice = null) {
    const select = document.getElementById('nDept');
    if (!select) return;

    if (!choice) {
      const raw = select.value || '';
      choice = raw.startsWith('legacy:')
        ? { id: '', legacy: raw.slice(7) }
        : { id: raw, legacy: '' };
    }
    if (this.pendingDeptId) {
      choice = { id: this.pendingDeptId, legacy: '' };
      this.pendingDeptId = null;
    }

    const on = [];
    const off = [];
    this.departments.forEach((d) => (this.isWardOn(d) ? on : off).push(d));
    // วอร์ดที่ปิดใช้งาน: แสดงเฉพาะรายการที่พยาบาลรายนี้สังกัดอยู่เดิม เพื่อไม่ให้เผลอย้ายคนเข้าวอร์ดพัก
    if (choice.id) {
      const keep = off.findIndex((d) => String(d.id) === String(choice.id));
      if (keep >= 0) on.push(off.splice(keep, 1)[0]);
    }

    const option = (d, suffix) => `<option value="${UI.escape(d.id)}">${UI.escape(d.name)} · ${Number(d.nurse_count) || 0} คน${suffix}</option>`;
    const html = ['<option value="">— เลือกหน่วยงาน / วอร์ด —</option>'];
    on.forEach((d) => html.push(option(d, this.isWardOn(d) ? '' : ' (พักใช้งาน)')));
    if (choice.legacy) {
      html.push(`<optgroup label="ค่าเดิมในทะเบียน (ยังไม่มีในรายการวอร์ด)">`
        + `<option value="legacy:${UI.escape(choice.legacy)}">${UI.escape(choice.legacy)}</option></optgroup>`);
    }

    select.innerHTML = html.join('');
    select.value = choice.legacy ? 'legacy:' + choice.legacy : (choice.id || '');
  }

  renderDeptFilter() {
    const select = document.getElementById('nurseDeptFilter');
    if (!select) return;
    const current = select.value;
    const names = [...new Set([
      ...this.departments.map((d) => String(d.name || '').trim()).filter(Boolean),
      ...this.nurses.map((n) => String(n.department || '').trim()).filter(Boolean),
    ])].sort((a, b) => a.localeCompare(b, 'th'));
    select.innerHTML = '<option value="">ทุกวอร์ด</option>'
      + names.map((name) => `<option value="${UI.escape(name)}">${UI.escape(name)}</option>`).join('');
    if (names.includes(current)) select.value = current;
  }

  renderTable() {
    const container = document.getElementById('nurseDirectoryTable');
    if (!container) return;

    // 1 คน = 1 แถว (แตะเพื่อแก้ไข) — data-* ใช้กับตัวกรอง, ค้นหาข้อความใช้ textContent ทั้งแถว
    container.innerHTML = this.nurses.map((nurse, index) => {
      const acc = nurse.account || null;
      const roleKey = acc ? acc.role : 'nurse-noaccount';
      const statusKey = nurse.active && (!acc || acc.active) ? 'active' : 'inactive';
      const sub = [
        `รหัส ${UI.escape(nurse.id)}`,
        UI.escape((nurse.department || '').trim() || 'ไม่ระบุวอร์ด'),
        acc ? `🔑 ${UI.escape(acc.username)}` : '',
        nurse.phone && (!acc || acc.username !== NursesPage.phoneUsername(nurse.phone)) ? `📞 ${UI.escape(nurse.phone)}` : '',
      ].filter(Boolean).join(' · ');
      return `
      <button type="button" class="user-row${statusKey === 'inactive' ? ' is-off' : ''}" data-index="${index}" data-id="${UI.escape(nurse.id)}"
        data-dept="${UI.escape((nurse.department || '').trim())}" data-role="${roleKey}" data-status="${statusKey}">
        <span class="user-av${acc?.role === 'admin' ? ' is-admin' : ''}" aria-hidden="true">${UI.escape(NursesPage.initial(nurse.name))}</span>
        <span class="user-who"><b>${UI.escape(nurse.name)}</b><span>${sub}</span></span>
        <span class="user-side">${NursesPage.rowBadge(nurse.active, acc)}<span class="user-chev" aria-hidden="true">›</span></span>
      </button>`;
    }).join('') || '<p class="empty">ยังไม่มีรายชื่อพยาบาล — กด “+ เพิ่มผู้ใช้” หรือนำเข้าจากไฟล์ที่เมนู ⋯</p>';

    this.filterTable();
  }

  /** ตัวอักษรแรกของชื่อสำหรับวงกลม (ข้ามสระหน้า เ แ โ ใ ไ) */
  static initial(name) {
    const chars = [...String(name || '').trim()];
    return chars.find((c) => !'เแโใไ'.includes(c)) || '?';
  }

  /** ป้ายด้านขวาของแถว: พัก/ปิด > Admin > Staff > ยังไม่มีบัญชี */
  static rowBadge(personActive, acc) {
    if (!personActive) return '<span class="user-badge is-off">พักใช้งาน</span>';
    if (acc && !acc.active) return '<span class="user-badge is-off">ปิดบัญชี</span>';
    if (acc?.role === 'admin') return '<span class="user-badge is-admin">👑 Admin</span>';
    if (acc) return '<span class="user-badge is-staff">Staff</span>';
    return '<span class="user-badge is-none">ยังไม่มีบัญชี</span>';
  }

  /** ตัวเลขสรุปบนแถบบน (นับจากข้อมูลทั้งหมด ไม่ขึ้นกับตัวกรอง) */
  renderUserStats() {
    const el = document.getElementById('userStats');
    if (!el) return;
    const others = window.usersPage ? window.usersPage.otherUsers() : [];
    const accounts = [...this.nurses.map((n) => n.account).filter(Boolean), ...others];
    const noAccount = this.nurses.filter((n) => !n.account).length;
    const off = this.nurses.filter((n) => !n.active || (n.account && !n.account.active)).length
      + others.filter((u) => !u.active).length;
    const stat = (value, label, attn = false) =>
      `<div class="users-stat${attn ? ' is-attn' : ''}"><b>${value}</b><span>${label}</span></div>`;
    el.innerHTML = stat(this.nurses.length, 'พยาบาล')
      + stat(accounts.filter((a) => a.role === 'admin').length, 'Admin')
      + stat(noAccount, 'ยังไม่มีบัญชี', noAccount > 0)
      + stat(off, 'พัก / ปิด');
  }

  // ---------- หน้าต่างเพิ่ม/แก้ไข + นำเข้า ----------

  static openSheet(kind, editing) {
    const sheet = document.getElementById('userSheet');
    if (!sheet) return;
    NursesPage.setSheetKind(kind);
    document.getElementById('userKind')?.classList.toggle('hidden', editing);   // แก้ไข = ประเภทเปลี่ยนไม่ได้
    sheet.classList.remove('hidden');
    sheet.querySelectorAll('.user-form-body').forEach((el) => { el.scrollTop = 0; });
    document.body.classList.add('users-sheet-open');
    setTimeout(() => (kind === 'nurse' ? document.getElementById('nName') : document.getElementById('uFullName'))?.focus(), 50);
  }

  static setSheetKind(kind) {
    const nurse = kind !== 'other';
    document.querySelectorAll('#userKind [data-kind]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.kind === kind)));
    document.getElementById('nurseForm')?.classList.toggle('hidden', !nurse);
    document.getElementById('userForm')?.classList.toggle('hidden', nurse);
    NursesPage.updateSheetTitle();
  }

  static updateSheetTitle() {
    const title = document.getElementById('userSheetTitle');
    if (!title) return;
    const nurse = !document.getElementById('nurseForm')?.classList.contains('hidden');
    const editing = nurse ? window.nursesPage?.editingId : window.usersPage?.editingId;
    title.textContent = editing ? (nurse ? 'แก้ไขพยาบาล' : 'แก้ไขบัญชีผู้ใช้') : 'เพิ่มผู้ใช้';
    // เมนู ⋮ (ลบพยาบาล) มีเฉพาะตอนแก้ไขพยาบาลที่มีอยู่แล้ว
    document.getElementById('nurseMenuBtn')?.classList.toggle('hidden', !(nurse && editing));
    NursesPage.setNurseMenu(false);
  }

  static setNurseMenu(open) {
    document.getElementById('nurseMenu')?.classList.toggle('hidden', !open);
    document.getElementById('nurseMenuBtn')?.setAttribute('aria-expanded', String(Boolean(open)));
    if (open) document.getElementById('nurseDeleteBtn')?.focus();
  }

  static closeSheet() {
    document.getElementById('userSheet')?.classList.add('hidden');
    document.body.classList.remove('users-sheet-open');
    window.nursesPage?.resetForm();
    window.usersPage?.resetForm();
  }

  static openImport() {
    document.getElementById('userImportSheet')?.classList.remove('hidden');
    document.body.classList.add('users-sheet-open');
  }

  static closeImport() {
    document.getElementById('userImportSheet')?.classList.add('hidden');
    document.body.classList.remove('users-sheet-open');
  }

  /** สวิตช์ "เปิดบัญชีให้คนนี้" — ปิด = ล้างชื่อผู้ใช้/รหัสผ่าน (บันทึกแล้วไม่สร้างบัญชี) */
  setAccountSwitch(on) {
    document.getElementById('nAccountToggle')?.setAttribute('aria-checked', String(on));
    document.getElementById('nAccountFields')?.classList.toggle('hidden', !on);
    if (!on) {
      document.getElementById('nUsername').value = '';
      document.getElementById('nPassword').value = '';
      this.setFieldError('nUsername', '');
      this.setFieldError('nPassword', '');
    }
  }

  /** ส่วนบัญชีในหน้าต่าง: ยังไม่มีบัญชี = สวิตช์ | มีบัญชีแล้ว = ช่องรหัสผ่าน + ปุ่มเปลี่ยนบทบาท/ปิดบัญชี */
  renderAccountSection() {
    const acc = this.editingOriginal?.account || null;
    document.getElementById('nAccountSwitchRow')?.classList.toggle('hidden', Boolean(acc));
    document.getElementById('nAccountHint')?.classList.toggle('hidden', Boolean(acc));
    const actions = document.getElementById('nAccountActions');
    actions?.classList.toggle('hidden', !acc);
    if (acc) {
      document.getElementById('nAccountFields')?.classList.remove('hidden');
      // ตัวหนังสือ เปิด/ปิด ข้างสวิตช์ — ไม่ต้องอาศัยสีอย่างเดียว
      const setSwitch = (btnId, stateId, on) => {
        document.getElementById(btnId)?.setAttribute('aria-checked', String(on));
        const state = document.getElementById(stateId);
        if (state) state.textContent = on ? 'เปิด' : 'ปิด';
      };
      setSwitch('nAccountActiveBtn', 'nAccountActiveState', Boolean(acc.active));
      setSwitch('nRoleBtn', 'nRoleState', acc.role === 'admin');
      const last = document.getElementById('nLastLogin');
      if (last) {
        last.textContent = acc.last_login_at
          ? `เข้าสู่ระบบล่าสุด ${UI.formatDateTimeThai(acc.last_login_at)}`
          : 'ยังไม่เคยเข้าสู่ระบบ';
      }
    } else {
      // แก้ไขพยาบาลที่ยังไม่มีบัญชี: ปิดสวิตช์ไว้ก่อน | เพิ่มคนใหม่: เปิดไว้ (ชื่อผู้ใช้ = เบอร์โทรอัตโนมัติ)
      this.setAccountSwitch(!this.editingId);
    }
  }

  // ---------- บัญชีของพยาบาล (ใช้ API ผู้ใช้เดิม — server ตรวจสิทธิ์ admin / กันปิดบัญชีตัวเอง / admin คนสุดท้าย) ----------

  async toggleAccount(nurse) {
    const acc = nurse.account;
    if (!acc) return;
    const action = acc.active ? 'ปิดใช้งาน' : 'เปิดใช้งาน';
    const ok = await window.confirmDialog(
      `${action}บัญชี ${acc.username} ของ ${nurse.name}?\n` +
      (acc.active ? 'ผู้ใช้จะเข้าสู่ระบบไม่ได้ — ประวัติการตรวจเช็คและข้อมูลเดิมยังอยู่ครบ' : 'ผู้ใช้จะกลับมาเข้าสู่ระบบได้'),
      { confirmText: action }
    );
    if (!ok) return;
    try {
      await window.adminApi.updateUser(acc.id, { active: !acc.active });
      window.toast.success(`${action}บัญชีเรียบร้อย`);
      await this.reloadAll();
    } catch (error) {
      window.toast.error(`${action}ไม่สำเร็จ: ` + error.message);
    }
  }

  async changeRole(nurse) {
    const acc = nurse.account;
    if (!acc) return;
    const next = acc.role === 'admin' ? 'staff' : 'admin';
    const ok = await window.confirmDialog(
      `เปลี่ยนบทบาทบัญชี ${acc.username} (${nurse.name}) จาก ${acc.role === 'admin' ? 'Admin' : 'Staff'} เป็น ${next === 'admin' ? 'Admin (ผู้ดูแลระบบ — จัดการผู้ใช้/รถ/ล็อตได้ทั้งหมด)' : 'Staff'}?`,
      { confirmText: 'เปลี่ยนบทบาท' }
    );
    if (!ok) return;
    try {
      await window.adminApi.updateUser(acc.id, { role: next });
      window.toast.success('เปลี่ยนบทบาทเรียบร้อย');
      await this.reloadAll();
    } catch (error) {
      window.toast.error('เปลี่ยนบทบาทไม่สำเร็จ: ' + error.message);
    }
  }

  // หน้าเดียวมีสองรายการ (พยาบาล + บัญชีอื่น) — โหลดใหม่ทั้งคู่เพื่อให้ข้อมูลตรงกัน
  async reloadAll() {
    await this.loadNurses();
    this.render();
    if (window.usersPage) {
      await window.usersPage.loadData();
      window.usersPage.render();
    }
    // หน้าต่างแก้ไขยังเปิดอยู่ (เช่น เพิ่งเปลี่ยนบทบาท/ปิดบัญชี) — อัปเดตส่วนบัญชีให้ตรงข้อมูลใหม่
    if (this.editingId) {
      this.editingOriginal = this.nurses.find((n) => String(n.id) === String(this.editingId)) || this.editingOriginal;
      this.renderAccountSection();
    }
  }

  /** ตัวกรองที่เลือกจากแท็บ (ใช้ร่วมกับ users.js) */
  static chipFilter() {
    const chips = document.getElementById('userChips');
    return { role: chips?.dataset.role || '', status: chips?.dataset.status || '' };
  }

  filterTable() {
    const query = document.getElementById('nurseSearch')?.value?.toLowerCase().trim() || '';
    const dept = document.getElementById('nurseDeptFilter')?.value || '';
    const { role, status } = NursesPage.chipFilter();
    let shown = 0;

    document.querySelectorAll('#nurseDirectoryTable .user-row').forEach((row) => {
      const matchDept = !dept || row.dataset.dept === dept;
      const matchText = !query || row.textContent.toLowerCase().includes(query);
      const matchRole = !role || role === 'nurse' || row.dataset.role === role;
      const matchStatus = !status || row.dataset.status === status;
      const hide = !(matchDept && matchText && matchRole && matchStatus && role !== 'other');
      row.classList.toggle('hidden', hide);
      if (!hide) shown++;
    });
    // บัญชีที่ไม่ใช่พยาบาลอยู่หน้าเดียวกัน — ใช้ตัวกรองชุดเดียวกัน
    const others = window.usersPage ? window.usersPage.filterTable() : 0;
    const filtering = Boolean(query || dept || role || status);

    // หัวกลุ่มซ่อนเมื่อกลุ่มนั้นไม่มีแถวให้แสดงระหว่างกรอง
    document.getElementById('nurseGroupTitle')?.classList.toggle('hidden', filtering && shown === 0);
    document.getElementById('nurseDirectoryTable')?.classList.toggle('hidden', filtering && shown === 0);
    document.getElementById('otherGroupTitle')?.classList.toggle('hidden', others === 0);
    document.getElementById('userDirectoryTable')?.classList.toggle('hidden', others === 0);
    document.getElementById('usersEmpty')?.classList.toggle('hidden', !(filtering && shown + others === 0));

    const note = document.getElementById('nurseFilterNote');
    if (note) {
      const text = filtering ? `พบพยาบาล ${shown} คน · บัญชีอื่น ${others} บัญชี${dept ? ` (วอร์ด ${dept})` : ''}` : '';
      note.textContent = text;
      note.classList.toggle('hidden', !text);
    }
    this.renderUserStats();
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
    document.getElementById('nPhone').value = nurse.phone || '';
    document.getElementById('nActive').value = nurse.active ? 'true' : 'false';
    document.getElementById('nNote').value = nurse.note || '';
    document.getElementById('nEnd').value = nurse.end_text || '';
    document.getElementById('nUsername').value = nurse.login_username || '';
    document.getElementById('nPassword').value = '';
    this.editingLoginUsername = nurse.login_username || null;
    this.renderLoginStatus();

    // ผูกกับวอร์ดจาก FK เดิม ถ้าแถวนี้ยังไม่มี FK (ข้อมูลเก่า) ให้เลือกด้วยชื่อเดิมและแจ้งเป็น legacy option
    this.renderDeptSelect({
      id: String(nurse.department_id || ''),
      legacy: nurse.department_id ? '' : String(nurse.department || '').trim(),
    });

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

    // มีวันเริ่ม/หมายเหตุ/พักใช้งาน หรือค่าเดิมแบบเก่า → กาง "ข้อมูลเพิ่มเติม" ให้เห็นเลย
    const extra = document.getElementById('nurseExtra');
    if (extra) extra.open = Boolean(nurse.start_text || nurse.note || !nurse.active);

    this.clearAllErrors();
    this.renderAccountSection();
    this.updateFormTitle();
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
    this.renderDeptSelect({ id: '', legacy: '' });
    const extra = document.getElementById('nurseExtra');
    if (extra) extra.open = false;

    this.clearAllErrors();
    this.renderAccountSection();
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
    NursesPage.updateSheetTitle();
  }

  renderLoginStatus() {
    const el = document.getElementById('nLoginStatus');
    if (!el) return;
    let text = '';
    if (this.editingLoginUsername) {
      text = `🔑 บัญชีปัจจุบัน: ${this.editingLoginUsername} — เว้นช่องรหัสผ่านว่างไว้เพื่อใช้รหัสเดิม`;
    } else if (this.editingId) {
      text = 'ยังไม่มีบัญชีเข้าใช้งาน — กรอกเบอร์โทรเป็นชื่อผู้ใช้เพื่อสร้างบัญชี (รหัสเริ่มต้น = เบอร์โทร ผู้ใช้จะถูกบังคับเปลี่ยนตอนเข้าครั้งแรก)';
    }
    el.textContent = text;
    el.classList.toggle('hidden', !text);
  }

  applyServerFieldErrors(errors) {
    const map = { username: 'nUsername', password: 'nPassword', department_id: 'nDept', department: 'nDept' };
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
    const pickedDept = document.getElementById('nDept').value;
    // ค่า "legacy:" = ข้อมูลเก่าที่ยังไม่ผูก FK — ส่งชื่อเดิมไปให้เซิร์ฟเวอร์จับคู่กับทะเบียนวอร์ด
    const legacyDept = pickedDept.startsWith('legacy:') ? pickedDept.slice(7) : '';

    const data = {
      id: document.getElementById('nId').value.trim(),
      name: document.getElementById('nName').value.trim(),
      department_id: legacyDept ? '' : pickedDept,
      department: legacyDept,
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

    // ปิดหน้าต่าง (รวม resetForm) หลัง setLoading(false) ไม่งั้นข้อความปุ่มจะถูกทับกลับเป็นค่าเดิม
    await this.closeAndReload();
  }

  async closeAndReload() {
    NursesPage.closeSheet();
    await Promise.all([this.loadNurses(), this.loadDepartments()]);
    this.render();
    // บัญชีที่สร้าง/แก้จากฟอร์มพยาบาลมีผลกับรายการบัญชีอื่นและตัวเลือก "ผูกกับพยาบาล" ด้วย
    if (window.usersPage) {
      await window.usersPage.loadData();
      window.usersPage.render();
    }
  }

  // เซิร์ฟเวอร์เป็นผู้ตัดสินจริง (นับประวัติ + FK) — มีประวัติแล้วลบไม่ได้ เสนอ "พักใช้งาน" แทน
  async deleteNurse() {
    const nurse = this.editingOriginal;
    if (!nurse) return;
    const account = nurse.login_username ? ` และบัญชีเข้าใช้งาน ${nurse.login_username}` : '';
    const ok = await window.confirmDialog(
      `ลบ “${nurse.name}”${account} ถาวรหรือไม่? ย้อนกลับไม่ได้`,
      { title: 'ลบพยาบาล', confirmText: 'ลบถาวร' }
    );
    if (!ok) return;

    const btn = document.getElementById('nurseDeleteBtn');
    UI.setLoading(btn, true);
    try {
      const res = await window.nurseApi.delete(nurse.id, { mode: 'delete' });
      // เซิร์ฟเวอร์เวอร์ชันเก่า (ยังไม่อัป NurseController.php) ไม่รู้จัก mode=delete → พักใช้งานแทนแล้วตอบ 200 เฉย ๆ
      if (res?.data?.mode !== 'deleted') {
        window.toast.error(`ยังลบ “${nurse.name}” ไม่ได้ — เซิร์ฟเวอร์ยังไม่ได้อัปเดต NurseController.php จึงเปลี่ยนเป็นพักใช้งานแทน`);
      } else {
        window.toast.success(`ลบ “${nurse.name}” แล้ว`);
      }
    } catch (error) {
      if (error.status === 409 && error.data?.suggestion === 'disable') {
        UI.setLoading(btn, false);
        // พักใช้งานอยู่แล้ว → ไม่มีอะไรให้ทำต่อ แค่บอกเหตุผล
        if (!nurse.active) {
          window.toast.error(error.message);
          return;
        }
        const disable = await window.confirmDialog(
          `${error.message}\n\nกด "พักใช้งานแทน" เพื่อปิดการเข้าสู่ระบบและซ่อนจากรายชื่อผู้ตรวจ โดยประวัติเดิมยังอยู่ครบ`,
          { title: 'ลบไม่ได้เพราะมีประวัติ', confirmText: 'พักใช้งานแทน' }
        );
        if (!disable) return;
        try {
          await window.nurseApi.delete(nurse.id, { mode: 'disable' });
          window.toast.success(`พักใช้งาน “${nurse.name}” แล้ว`);
        } catch (disableError) {
          window.toast.error('พักใช้งานไม่สำเร็จ: ' + disableError.message);
          return;
        }
      } else {
        window.toast.error('ลบไม่สำเร็จ: ' + error.message);
        return;
      }
    } finally {
      UI.setLoading(btn, false);
    }
    await this.closeAndReload();
  }

  // ---------- ทะเบียนวอร์ด (Ward / Department management) ----------

  // โหลดทั้งทะเบียนวอร์ดและรายชื่อพยาบาลใหม่ แล้วเรนเดอร์ modal จัดการวอร์ดให้ตรงข้อมูลจริง
  // (จำนวนสังกัดและชื่อที่ cascade เปลี่ยนมีผลกับทั้งสองฝั่ง จึงต้องอ่านพร้อมกัน)
  async refreshWards() {
    await Promise.all([this.loadDepartments(), this.loadNurses()]);
    this.render();
    if (this._wardModal) this.renderWardList();
  }

  openWardManager() {
    this._wardModal?.destroy();
    const modal = new Modal({
      title: 'จัดการวอร์ด / หน่วยงาน',
      content: '<div id="wardManagerBody" class="ward-manager"></div>',
      size: 'lg',
      onClose: () => { this._wardModal = null; },
    });
    modal.render();
    this._wardModal = modal;

    const actions = modal.element.querySelector('.sheet-actions');
    actions.innerHTML = '';
    const closeBtn = UI.create('button', { type: 'button', class: 'small-btn gray flex-1' }, 'ปิด');
    closeBtn.addEventListener('click', () => modal.destroy());
    const addBtn = UI.create('button', { type: 'button', class: 'flex-1' }, '+ เพิ่มวอร์ดใหม่');
    addBtn.addEventListener('click', () => this.openWardForm('create', null));
    actions.append(closeBtn, addBtn);

    modal.open();
    this.renderWardList();
    this.refreshWards();
  }

  renderWardList() {
    const body = this._wardModal?.element?.querySelector('#wardManagerBody');
    if (!body) return;

    if (!this.departments.length) {
      body.innerHTML = '<p class="empty">ยังไม่มีวอร์ดในทะเบียน — กด "+ เพิ่มวอร์ดใหม่" เพื่อเริ่มใช้งาน</p>';
      return;
    }

    body.innerHTML = `
      <p class="mb-3 text-xs leading-relaxed text-slate-500">
        แก้ชื่อแล้วระบบจะเปลี่ยนชื่อในรายชื่อพยาบาลที่สังกัดให้ทั้งหมด (อยู่ใน transaction เดียวกัน) •
        วอร์ดที่มีพยาบาลสังกัดอยู่จะถูกให้ลบไม่ได้ เพื่อไม่ให้สังกัดหลุดลอย — ใช้ "พักใช้งาน" แทน
        วอร์ดที่พักใช้งานจะหายจากตัวเลือกรายชื่อใหม่ แต่สังกัดเดิมของพยาบาลยังแสดงอยู่
      </p>
      <div class="ward-list">
        ${this.departments.map((ward, index) => `
          <div class="ward-row${this.isWardOn(ward) ? '' : ' is-off'}">
            <div class="min-w-0 flex-1">
              <div class="ward-name">
                ${UI.escape(ward.name)}
                ${this.isWardOn(ward) ? '' : '<span class="chip chip-off">พักใช้งาน</span>'}
              </div>
              <div class="ward-sub">
                พยาบาลสังกัด ${Number(ward.nurse_count) || 0} คน${ward.notes ? ' • ' + UI.escape(ward.notes) : ''}
              </div>
            </div>
            <div class="ward-actions">
              <button type="button" class="small-btn" data-ward-action="edit" data-index="${index}">แก้ไข</button>
              <button type="button" class="small-btn gray" data-ward-action="toggle" data-index="${index}">
                ${this.isWardOn(ward) ? 'พักใช้งาน' : 'เปิดใช้งาน'}
              </button>
              <button type="button" class="small-btn red" data-ward-action="delete" data-index="${index}">ลบ</button>
            </div>
          </div>`).join('')}
      </div>`;

    body.querySelectorAll('[data-ward-action]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const ward = this.departments[Number(btn.dataset.index)];
        if (!ward) return;
        if (btn.dataset.wardAction === 'edit') this.openWardForm('edit', ward);
        else if (btn.dataset.wardAction === 'toggle') this.toggleWard(ward);
        else this.deleteWard(ward);
      });
    });
  }

  // ปุ่ม "+" ข้าง dropdown — เพิ่มวอร์ดแล้วเลือกให้เลยในฟอร์มพยาบาล
  openQuickAddWard() {
    this.pendingDeptId = null;
    this.openWardForm('create', null, (ward) => { this.pendingDeptId = ward.id; });
  }

  openWardForm(mode, ward, onSaved = null) {
    const editing = mode === 'edit';
    this._wardFormModal?.destroy();

    const modal = new Modal({
      title: editing ? `แก้ไขวอร์ด “${ward.name}”` : 'เพิ่มวอร์ดใหม่',
      content: `
        <form id="wardForm" class="grid gap-3" novalidate>
          <div>
            <label class="!mt-0" for="wName">ชื่อวอร์ด / หน่วยงาน <span class="text-red-500" aria-hidden="true">*</span></label>
            <input id="wName" type="text" maxlength="100" placeholder="เช่น ER, ICU, OPD, IPD" autocomplete="off"
                   value="${editing ? UI.escape(ward.name) : ''}">
            <p class="mt-1 text-xs leading-relaxed text-slate-500">ห้ามซ้ำกับวอร์ดอื่น (ระบบเทียบแบบไม่สนตัวพิมพ์เล็ก-ใหญ่และช่องว่างหัว-ท้าย)</p>
            ${editing ? `<p class="mt-1 text-xs leading-relaxed text-amber-700">เปลี่ยนชื่อ → ระบบจะแก้ชื่อในรายชื่อพยาบาลที่สังกัดวอร์ดนี้ ${Number(ward.nurse_count) || 0} คนให้อัตโนมัติ</p>` : ''}
            <p class="mt-1 hidden text-xs font-semibold text-red-600" id="wNameError" role="alert"></p>
          </div>
          <div>
            <label class="!mt-0" for="wNotes">รายละเอียดเพิ่มเติม</label>
            <textarea id="wNotes" rows="2" maxlength="500" placeholder="เช่น รับผิดชอบรถ Refer คันที่ 1-2">${editing ? UI.escape(ward.notes || '') : ''}</textarea>
          </div>
          <label class="ward-active-toggle">
            <input id="wActive" type="checkbox">
            <span>เปิดใช้งาน — ถ้าปิด วอร์ดจะไม่อยู่ในตัวเลือกรายชื่อใหม่ แต่สังกัดเดิมของพยาบาลยังอยู่ครบ</span>
          </label>
        </form>`,
      size: 'md',
      onClose: () => { this._wardFormModal = null; },
    });
    modal.render();
    this._wardFormModal = modal;

    modal.element.querySelector('#wActive').checked = !editing || this.isWardOn(ward);

    const actions = modal.element.querySelector('.sheet-actions');
    actions.innerHTML = '';
    const cancelBtn = UI.create('button', { type: 'button', class: 'small-btn gray flex-1' }, 'ยกเลิก');
    cancelBtn.addEventListener('click', () => modal.destroy());
    const saveBtn = UI.create('button', { type: 'button', class: 'flex-1' }, editing ? 'บันทึกการแก้ไข' : 'เพิ่มวอร์ด');
    saveBtn.addEventListener('click', () => this.submitWardForm(modal, saveBtn, mode, ward, onSaved));
    actions.append(cancelBtn, saveBtn);

    modal.open();
  }

  setWardNameError(message) {
    const err = document.getElementById('wNameError');
    const input = document.getElementById('wName');
    if (!err || !input) return;
    err.textContent = message || '';
    err.classList.toggle('hidden', !message);
    input.setAttribute('aria-invalid', message ? 'true' : 'false');
  }

  async submitWardForm(modal, saveBtn, mode, ward, onSaved) {
    const name = (document.getElementById('wName')?.value || '').trim();
    if (!name) { this.setWardNameError('กรุณากรอกชื่อวอร์ด'); return; }
    if (name.length > 100) { this.setWardNameError('ชื่อวอร์ดยาวเกิน 100 ตัวอักษร'); return; }

    const payload = {
      name,
      notes: (document.getElementById('wNotes')?.value || '').trim(),
      active: Boolean(document.getElementById('wActive')?.checked),
    };

    UI.setLoading(saveBtn, true);
    let saved;
    try {
      const res = mode === 'edit'
        ? await window.departmentApi.update(ward.id, payload)
        : await window.departmentApi.create(payload);
      saved = res.data || { name };
    } catch (error) {
      UI.setLoading(saveBtn, false);
      if (error.status === 409 && error.data?.reason === 'name_exists') {
        this.setWardNameError('มีวอร์ดชื่อนี้อยู่แล้ว: ' + (error.data?.existing?.name || name));
      } else if (error.status === 422 && error.data?.errors) {
        const messages = Object.values(error.data.errors).flat().filter(Boolean);
        this.setWardNameError(messages[0] || error.message);
      } else {
        window.toast.error('บันทึกวอร์ดไม่สำเร็จ: ' + error.message);
      }
      return;
    }

    modal.destroy();
    this._wardFormModal = null;

    if (onSaved) onSaved(saved);

    const renamed = mode === 'edit' && String(saved.name) !== String(ward.name);
    window.toast.success(
      mode === 'edit'
        ? `บันทึกวอร์ด “${saved.name}” แล้ว` + (renamed ? ` · อัปเดตชื่อในรายชื่อพยาบาล ${Number(saved.nurses_updated) || 0} คน` : '')
        : `เพิ่มวอร์ด “${saved.name}” แล้ว`
    );
    await this.refreshWards();
  }

  async toggleWard(ward) {
    const on = this.isWardOn(ward);
    try {
      const res = await window.departmentApi.update(ward.id, { active: !on });
      const name = res.data?.name || ward.name;
      window.toast.success(on
        ? `พักใช้งานวอร์ด “${name}” แล้ว — สังกัดเดิมของพยาบาลยังอยู่ครบ`
        : `เปิดใช้งานวอร์ด “${name}” แล้ว`);
      await this.refreshWards();
    } catch (error) {
      window.toast.error('เปลี่ยนสถานะวอร์ดไม่สำเร็จ: ' + error.message);
    }
  }

  // เซิร์ฟเวอร์เป็นผู้ตัดสินจริง (FK RESTRICT + นับจำนวนสังกัด) — ด้านหน้าแค่เตือนไว้ก่อน
  async deleteWard(ward) {
    const count = Number(ward.nurse_count) || 0;
    if (count > 0) {
      const disable = await window.confirmDialog(
        `วอร์ด “${ward.name}” มีพยาบาลสังกัดอยู่ ${count} คน จึงลบไม่ได้เพื่อไม่ให้สังกัดหลุดลอย\n\n`
        + 'กด "พักใช้งานแทน" เพื่อให้วอร์ดหายจากตัวเลือกรายชื่อใหม่ โดยข้อมูลเดิมยังอยู่ครบ',
        { title: 'ลบวอร์ดไม่ได้', confirmText: 'พักใช้งานแทน' }
      );
      if (disable) await this.toggleWard(ward);
      return;
    }

    const ok = await window.confirmDialog(
      `ลบวอร์ด “${ward.name}” ถาวรหรือไม่? วอร์ดนี้ไม่มีพยาบาลสังกัด จึงไม่มีข้อมูลอื่นถูกกระทบ และย้อนกลับไม่ได้`,
      { title: 'ลบวอร์ด', confirmText: 'ลบถาวร' }
    );
    if (!ok) return;

    try {
      await window.departmentApi.remove(ward.id, { mode: 'auto' });
      window.toast.success(`ลบวอร์ด “${ward.name}” แล้ว`);
    } catch (error) {
      // 409 = มีพยาบาลสังกัดเข้ามาพอดีระหว่างที่กรอก → เสนอทางปลอดภัย
      if (error.status === 409 && error.data?.reason === 'department_in_use') {
        const fallback = error.data?.suggestion === 'disable' && await window.confirmDialog(
          `${error.message}\n\nเปลี่ยนเป็น "พักใช้งาน" แทนหรือไม่?`,
          { title: 'ลบไม่ได้เพราะมีพยาบาลสังกัด', confirmText: 'พักใช้งานแทน' }
        );
        if (fallback) await this.toggleWard({ ...ward, active: true });
        return;
      }
      window.toast.error('ลบวอร์ดไม่สำเร็จ: ' + error.message);
      return;
    }
    await this.refreshWards();
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

    chip.classList.remove('border-teal-200', 'bg-teal-50', 'status-critical', 'border-amber-200', 'bg-amber-50');
    meta.classList.remove('text-teal-700', 'status-critical', 'text-amber-700');
    const style = {
      loading: ['border-amber-200', 'bg-amber-50', 'text-amber-700'],
      warn: ['border-amber-200', 'bg-amber-50', 'text-amber-700'],
      done: ['border-teal-200', 'bg-teal-50', 'text-teal-700'],
      error: ['status-critical', 'status-critical', 'status-critical'],   // แดงทึบ ตัวอักษรขาว (status.css)
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
      newWards: new Set(),
    };

    const knownWards = this.wardNameSet();
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
      else if (!knownWards.has(department.toLowerCase())) {
        entry.warnings.push('ยังไม่มีในทะเบียนวอร์ด — ระบบจะเพิ่มเป็นวอร์ดใหม่ให้อัตโนมัติ');
        preview.newWards.add(department);
      }

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
    if (preview.newWards.size) {
      topNotes.push(`หน่วยงานใหม่ที่จะถูกบันทึกลงทะเบียนวอร์ด ${preview.newWards.size} รายการ: `
        + [...preview.newWards].map(esc).join(', '));
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

    await Promise.all([this.loadNurses(), this.loadDepartments()]);
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

// ปุ่มในหน้าต่าง (data-call) — ปิดหน้าต่างเพิ่ม/แก้ไข, ปิดหน้าต่างนำเข้า, ดาวน์โหลดไฟล์ตัวอย่าง
window.closeUserSheet = () => NursesPage.closeSheet();
window.closeUserImport = () => NursesPage.closeImport();
window.downloadNurseTemplate = () => window.nursesPage?.downloadTemplate();
