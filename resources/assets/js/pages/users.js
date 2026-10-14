// ========================================
// PAGE: USERS (Admin only)
// ========================================

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
    // Form submit
    const form = document.getElementById('userForm');
    if (form) {
      form.addEventListener('submit', (e) => this.handleSubmit(e));
    }
    
    // Reset button
    const resetBtn = document.querySelector('#userForm button[type="button"]');
    if (resetBtn) {
      resetBtn.addEventListener('click', () => this.resetForm());
    }
    
    // Search
    const search = document.getElementById('userSearch');
    if (search) {
      search.addEventListener('input', UI.debounce(() => this.filterTable(), 300));
    }
  }

  render() {
    this.renderNurseOptions();
    this.renderTable();
    this.updateFormTitle();
  }

  renderNurseOptions() {
    const select = document.getElementById('uNurse');
    if (!select) return;
    
    select.innerHTML = '<option value="">-- ไม่เชื่อมโยง --</option>' +
      this.nurses.filter(n => n.active).map(nurse => `
        <option value="${nurse.id}">${UI.escape(nurse.name)} (${UI.escape(nurse.department)})</option>
      `).join('');
  }

  renderTable() {
    const container = document.getElementById('userDirectoryTable');
    if (!container) return;
    
    container.innerHTML = this.users.map((user, index) => `
      <article class="nurse-card" data-id="${user.id}">
        <div class="nurse-top">
          <div style="flex:1;min-width:0">
            <div class="nurse-name">${UI.escape(user.full_name)}</div>
            <div class="nurse-sub">Username: ${UI.escape(user.username)} • ${user.role === 'admin' ? '👑 Admin' : '👨‍⚕️ Staff'}</div>
          </div>
          <span class="chip ${user.active ? 'chip-ok' : 'chip-off'}">
            ${user.active ? 'ใช้งาน' : 'ปิดใช้งาน'}
          </span>
        </div>
        <div class="nurse-meta">
          ${user.nurse_id ? `<span>พยาบาล: ${UI.escape(this.getNurseName(user.nurse_id))}</span>` : ''}
          ${user.last_login_at ? `<span>เข้าสู่ระบบล่าสุด: ${UI.formatDateTimeThai(user.last_login_at)}</span>` : ''}
        </div>
        <div style="display: flex; gap: 8px;">
          <button class="small-btn" data-action="edit" data-index="${index}">แก้ไข</button>
          <button class="small-btn ${user.active ? 'danger' : 'ok'}" data-action="toggle" data-index="${index}">
            ${user.active ? 'ปิดใช้งาน' : 'เปิดใช้งาน'}
          </button>
        </div>
      </article>
    `).join('') || '<p class="empty">ยังไม่มีผู้ใช้ในระบบ</p>';
    
    // Bind buttons
    container.querySelectorAll('[data-action="edit"]').forEach(btn => {
      btn.addEventListener('click', () => this.editUser(this.users[btn.dataset.index]));
    });
    
    container.querySelectorAll('[data-action="toggle"]').forEach(btn => {
      btn.addEventListener('click', () => this.toggleUser(this.users[btn.dataset.index]));
    });
  }

  getNurseName(nurseId) {
    const nurse = this.nurses.find(n => n.id === nurseId);
    return nurse ? nurse.name : nurseId;
  }

  filterTable() {
    const query = document.getElementById('userSearch')?.value?.toLowerCase() || '';
    document.querySelectorAll('#userDirectoryTable .nurse-card').forEach(card => {
      const text = card.textContent.toLowerCase();
      card.classList.toggle('hidden', !text.includes(query));
    });
  }

  editUser(user) {
    this.editingId = user.id;
    
    document.getElementById('uUsername').value = user.username;
    document.getElementById('uUsername').readOnly = true;
    document.getElementById('uPassword').value = '';
    document.getElementById('uPassword').placeholder = 'เว้นว่างไว้หากไม่ต้องการเปลี่ยนรหัสผ่าน';
    document.getElementById('uFullName').value = user.full_name;
    document.getElementById('uRole').value = user.role;
    document.getElementById('uNurse').value = user.nurse_id || '';
    
    this.updateFormTitle();
    document.getElementById('userEditor')?.scrollIntoView({ block: 'start' });
  }

  resetForm() {
    this.editingId = null;
    document.getElementById('uUsername').value = '';
    document.getElementById('uUsername').readOnly = false;
    document.getElementById('uPassword').value = '';
    document.getElementById('uPassword').placeholder = 'รหัสผ่าน (อย่างน้อย 6 ตัวอักษร)';
    document.getElementById('uFullName').value = '';
    document.getElementById('uRole').value = 'staff';
    document.getElementById('uNurse').value = '';
    this.updateFormTitle();
  }

  updateFormTitle() {
    const title = document.getElementById('userFormTitle');
    if (title) {
      title.textContent = this.editingId ? 'แก้ไขผู้ใช้' : 'เพิ่มผู้ใช้ใหม่';
    }
    const submitBtn = document.querySelector('#userForm button[type="submit"]');
    if (submitBtn) {
      submitBtn.textContent = this.editingId ? 'บันทึกการแก้ไข' : 'เพิ่มผู้ใช้';
    }
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
    
    const submitBtn = document.querySelector('#userForm button[type="submit"]');
    UI.setLoading(submitBtn, true);
    
    try {
      if (this.editingId) {
        // For update, don't send password if empty
        if (!data.password) {
          delete data.password;
        }
        await window.adminApi.updateUser(this.editingId, data);
        window.toast.success('แก้ไขผู้ใช้เรียบร้อย');
      } else {
        await window.adminApi.createUser(data);
        window.toast.success('เพิ่มผู้ใช้เรียบร้อย');
      }
      
      this.resetForm();
      await this.loadData();
      this.render();
    } catch (error) {
      window.toast.error('บันทึกไม่สำเร็จ: ' + error.message);
    } finally {
      UI.setLoading(submitBtn, false);
    }
  }

  async toggleUser(user) {
    const action = user.active ? 'ปิดใช้งาน' : 'เปิดใช้งาน';
    
    // Block self-deactivation early (server enforces this too, as a safety net)
    const me = window.auth.getUser();
    if (user.active && me && me.id === user.id) {
      window.toast.error('ไม่สามารถปิดใช้งานบัญชีของตัวเองได้');
      return;
    }
    
    if (!confirm(`ยืนยัน${action}ผู้ใช้ ${user.full_name}?`)) return;
    
    try {
      await window.adminApi.updateUser(user.id, { active: !user.active });
      window.toast.success(`${action}เรียบร้อย`);
      await this.loadData();
      this.render();
    } catch (error) {
      window.toast.error(`${action}ไม่สำเร็จ: ` + error.message);
    }
  }
}

// Initialize
let usersPage;
document.addEventListener('DOMContentLoaded', () => {
  if (document.getElementById('tabContentUsers') && !document.getElementById('tabContentUsers').classList.contains('hidden')) {
    usersPage = new UsersPage();
    window.usersPage = usersPage;
  }
});

// Export for tab switching
window.initUsers = () => {
  if (!usersPage) {
    usersPage = new UsersPage();
    window.usersPage = usersPage;
  } else {
    usersPage.loadData().then(() => usersPage.render());
  }
};