// ========================================
// PAGE: USERS — ส่วน "บัญชีผู้ใช้อื่น" ของหน้าจัดการผู้ใช้ (Admin only)
// ========================================
// หน้าจัดการผู้ใช้รวมเป็นหน้าเดียวกับทะเบียนพยาบาล (tabContentNurses):
//  - บัญชีที่ผูกกับพยาบาล แสดง/จัดการในการ์ดพยาบาล (nurses.js) — ไม่แสดงซ้ำที่นี่
//  - ส่วนนี้จัดการเฉพาะบัญชีที่ไม่ได้ผูกกับรายชื่อพยาบาล เช่น ผู้ดูแลระบบ
// ใช้ API เดิม /api/admin/users ทั้งหมด (server ตรวจสิทธิ์ admin, กันชื่อซ้ำ, กันพยาบาลมี 2 บัญชี)

class UsersPage {
  constructor() {
    this.users = [];
    this.nurses = [];
    this.editingId = null;
    this.init();
  }

  async init() {
    await this.loadData();
    this.bindEvents();
    this.render();
  }

  async loadData() {
    try {
      const [usersRes, nursesRes] = await Promise.all([
        window.adminApi.getUsers(),
        window.nurseApi.getAll(),
      ]);
      this.users = usersRes.data || [];
      this.nurses = nursesRes.data || [];
    } catch (error) {
      console.error('Failed to load users:', error);
      window.toast.error('โหลดข้อมูลผู้ใช้ไม่สำเร็จ');
    }
  }

  bindEvents() {
    document.getElementById('userForm')?.addEventListener('submit', (e) => this.handleSubmit(e));
    document.getElementById('uActiveBtn')?.addEventListener('click', () => {
      this.toggleUser(this.users.find(u => u.id === this.editingId));
    });
    // แตะแถวบัญชี = เปิดหน้าต่างแก้ไข
    document.getElementById('userDirectoryTable')?.addEventListener('click', (e) => {
      const row = e.target.closest('.user-row');
      const user = row && this.users.find(u => u.id === row.dataset.id);
      if (!user) return;
      window.nursesPage?.resetForm();
      this.editUser(user);
      NursesPage.openSheet('other', true);
    });
  }

  /** บัญชีที่ไม่ได้ผูกกับพยาบาลที่มีอยู่ในทะเบียน */
  otherUsers() {
    const nurseIds = new Set(this.nurses.map(n => String(n.id)));
    return this.users.filter(u => !u.nurse_id || !nurseIds.has(String(u.nurse_id)));
  }

  render() {
    this.renderNurseOptions();
    this.renderTable();
    this.updateFormTitle();
  }

  // ผูกได้เฉพาะพยาบาลที่ยังไม่มีบัญชี (+ พยาบาลที่ผูกกับบัญชีที่กำลังแก้อยู่)
  renderNurseOptions() {
    const select = document.getElementById('uNurse');
    if (!select) return;
    const linked = new Set(this.users.filter(u => u.nurse_id && u.id !== this.editingId).map(u => String(u.nurse_id)));
    select.innerHTML = '<option value="">-- ไม่ผูก --</option>' +
      this.nurses.filter(n => n.active && !linked.has(String(n.id))).map(nurse => `
        <option value="${UI.escape(nurse.id)}">${UI.escape(nurse.name)} (${UI.escape(nurse.department)})</option>
      `).join('');
  }

  renderTable() {
    const container = document.getElementById('userDirectoryTable');
    if (!container) return;
    // แถวเดียวกับรายการพยาบาล (แตะเพื่อแก้ไข) — ไม่มีวอร์ด จึงโชว์ชื่อผู้ใช้ + เข้าสู่ระบบล่าสุด
    container.innerHTML = this.otherUsers().map((user) => `
      <button type="button" class="user-row${user.active ? '' : ' is-off'}" data-id="${UI.escape(user.id)}"
        data-role="${UI.escape(user.role)}" data-status="${user.active ? 'active' : 'inactive'}">
        <span class="user-av is-other${user.role === 'admin' ? ' is-admin' : ''}" aria-hidden="true">${UI.escape(NursesPage.initial(user.full_name))}</span>
        <span class="user-who"><b>${UI.escape(user.full_name)}</b>
          <span>🔑 ${UI.escape(user.username)} · ${user.last_login_at ? 'เข้าล่าสุด ' + UI.escape(UI.formatDateTimeThai(user.last_login_at)) : 'ยังไม่เคยเข้าสู่ระบบ'}</span></span>
        <span class="user-side">${NursesPage.rowBadge(true, user)}<span class="user-chev" aria-hidden="true">›</span></span>
      </button>
    `).join('');

    // หัวกลุ่ม / ตัวเลขสรุป / ข้อความว่าง อยู่ในตัวกรองของหน้า (nurses.js)
    if (window.nursesPage) window.nursesPage.filterTable(); else this.filterTable();
  }

  /** ตัวกรองชุดเดียวกับรายการพยาบาล (ค้นหา / ประเภท-บทบาท / สถานะ) — คืนจำนวนที่แสดง */
  filterTable() {
    const query = document.getElementById('nurseSearch')?.value?.toLowerCase().trim() || '';
    const { role, status } = NursesPage.chipFilter();
    const dept = document.getElementById('nurseDeptFilter')?.value || '';
    let shown = 0;
    document.querySelectorAll('#userDirectoryTable .user-row').forEach(card => {
      const matchRole = !role || role === 'other' || card.dataset.role === role;
      const hide = Boolean(dept)   // บัญชีอื่นไม่มีวอร์ด
        || (query && !card.textContent.toLowerCase().includes(query))
        || !matchRole || role === 'nurse' || role === 'nurse-noaccount'
        || (status && card.dataset.status !== status);
      card.classList.toggle('hidden', hide);
      if (!hide) shown++;
    });
    return shown;
  }

  editUser(user) {
    if (!user) return;
    this.editingId = user.id;
    document.getElementById('uUsername').value = user.username;
    document.getElementById('uUsername').readOnly = true;
    document.getElementById('uPassword').value = '';
    document.getElementById('uPassword').placeholder = 'เว้นว่างไว้หากไม่ต้องการเปลี่ยนรหัสผ่าน';
    document.getElementById('uFullName').value = user.full_name;
    document.getElementById('uRole').value = user.role;
    this.renderNurseOptions();
    document.getElementById('uNurse').value = user.nurse_id || '';
    this.renderAccountActions();
    this.updateFormTitle();
  }

  /** ปุ่มเปิด/ปิดบัญชีในหน้าต่างแก้ไข (เดิมเป็นปุ่มบนการ์ด) */
  renderAccountActions() {
    const user = this.editingId ? this.users.find(u => u.id === this.editingId) : null;
    document.getElementById('uAccountActions')?.classList.toggle('hidden', !user);
    if (!user) return;
    const btn = document.getElementById('uActiveBtn');
    if (btn) {
      btn.textContent = user.active ? '🔒 ปิดบัญชี (เข้าสู่ระบบไม่ได้)' : '🔓 เปิดบัญชี';
      btn.classList.toggle('is-danger', Boolean(user.active));
    }
    const last = document.getElementById('uLastLogin');
    if (last) {
      last.textContent = (user.active ? '' : 'บัญชีปิดอยู่ · ')
        + (user.last_login_at ? `เข้าสู่ระบบล่าสุด ${UI.formatDateTimeThai(user.last_login_at)}` : 'ยังไม่เคยเข้าสู่ระบบ');
    }
  }

  resetForm() {
    this.editingId = null;
    document.getElementById('uUsername').value = '';
    document.getElementById('uUsername').readOnly = false;
    document.getElementById('uPassword').value = '';
    document.getElementById('uPassword').placeholder = 'รหัสผ่าน (อย่างน้อย 6 ตัวอักษร)';
    document.getElementById('uFullName').value = '';
    document.getElementById('uRole').value = 'staff';
    this.renderNurseOptions();
    document.getElementById('uNurse').value = '';
    this.renderAccountActions();
    this.updateFormTitle();
  }

  updateFormTitle() {
    const title = document.getElementById('userFormTitle');
    if (title) title.textContent = this.editingId ? 'แก้ไขบัญชีผู้ใช้' : 'เพิ่มบัญชีผู้ใช้ใหม่';
    const submitBtn = document.querySelector('#userForm button[type="submit"]');
    if (submitBtn) submitBtn.textContent = this.editingId ? 'บันทึกการแก้ไข' : 'เพิ่มบัญชี';
    NursesPage.updateSheetTitle();
  }

  async handleSubmit(event) {
    event.preventDefault();
    const data = {
      username: document.getElementById('uUsername').value?.trim(),
      password: document.getElementById('uPassword').value,
      full_name: document.getElementById('uFullName').value?.trim(),
      role: document.getElementById('uRole').value,
      nurse_id: document.getElementById('uNurse').value || null,
    };
    if (!data.username || !data.full_name) {
      window.toast.error('กรอกชื่อผู้ใช้และชื่อ-นามสกุล');
      return;
    }
    if (!this.editingId && (!data.password || data.password.length < 6)) {
      window.toast.error('รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร');
      return;
    }
    const original = this.editingId ? this.users.find(u => u.id === this.editingId) : null;
    if (original && original.role !== data.role) {
      const ok = await window.confirmDialog(
        `เปลี่ยนบทบาทของ ${original.username} จาก ${original.role === 'admin' ? 'Admin' : 'Staff'} เป็น ${data.role === 'admin' ? 'Admin' : 'Staff'}?`,
        { confirmText: 'เปลี่ยนบทบาท' }
      );
      if (!ok) return;
    }

    const submitBtn = document.querySelector('#userForm button[type="submit"]');
    UI.setLoading(submitBtn, true);
    try {
      if (this.editingId) {
        if (!data.password) delete data.password;   // ไม่ส่งรหัสว่าง = ใช้รหัสเดิม
        delete data.username;                        // ชื่อผู้ใช้แก้ไม่ได้ (เป็นตัวตนตอน login)
        await window.adminApi.updateUser(this.editingId, data);
        window.toast.success('แก้ไขบัญชีเรียบร้อย');
      } else {
        await window.adminApi.createUser(data);
        window.toast.success('เพิ่มบัญชีเรียบร้อย');
      }
      UI.setLoading(submitBtn, false);
      NursesPage.closeSheet();   // รวม resetForm ทั้งสองฟอร์ม
      await this.reloadAll();
    } catch (error) {
      window.toast.error('บันทึกไม่สำเร็จ: ' + error.message);
    } finally {
      UI.setLoading(submitBtn, false);
    }
  }

  async toggleUser(user) {
    if (!user) return;
    const action = user.active ? 'ปิดใช้งาน' : 'เปิดใช้งาน';
    // กันปิดบัญชีตัวเองตั้งแต่หน้าจอ (server ตรวจซ้ำ)
    const me = window.auth.getUser();
    if (user.active && me && me.id === user.id) {
      window.toast.error('ไม่สามารถปิดใช้งานบัญชีของตัวเองได้');
      return;
    }
    const ok = await window.confirmDialog(
      `${action}บัญชี ${user.username} (${user.full_name})?` + (user.active ? '\nประวัติการทำงานเดิมยังอยู่ครบ' : ''),
      { confirmText: action }
    );
    if (!ok) return;
    try {
      await window.adminApi.updateUser(user.id, { active: !user.active });
      window.toast.success(`${action}เรียบร้อย`);
      await this.reloadAll();
    } catch (error) {
      window.toast.error(`${action}ไม่สำเร็จ: ` + error.message);
    }
  }

  // การผูกบัญชีกับพยาบาลเปลี่ยนการ์ดพยาบาลด้วย — โหลดใหม่ทั้งสองส่วนของหน้า
  async reloadAll() {
    await this.loadData();
    this.render();
    if (window.nursesPage) {
      await window.nursesPage.loadNurses();
      window.nursesPage.render();
    }
    this.renderAccountActions();   // หน้าต่างแก้ไขยังเปิดอยู่ — ปุ่มเปิด/ปิดบัญชีให้ตรงสถานะใหม่
  }
}

let usersPage;
window.initUsers = () => {
  if (!usersPage) {
    usersPage = new UsersPage();
    window.usersPage = usersPage;
  } else {
    usersPage.loadData().then(() => usersPage.render());
  }
};
