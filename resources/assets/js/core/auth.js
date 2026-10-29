// ========================================
// CORE: Auth Module
// ========================================

class AuthManager {
  constructor() {
    this.isAuthenticated = false;
    this.mustChangePassword = false;   // login แล้วแต่ยังใช้รหัสเริ่มต้น → ใช้งานได้แค่หน้าเปลี่ยนรหัสผ่าน
    this.user = null;
    this.sessionExpires = 0;
    this.checkInterval = null;
    this.initPromise = null;
  }

  async init() {
    // Share a single session probe between all boot listeners
    if (!this.initPromise) {
      this.initPromise = this.performInit();
    }
    return this.initPromise;
  }

  async performInit() {
    try {
      const result = await window.authApi.me();
      if (result.ok && result.data.authenticated && result.data.user?.must_change_password) {
        // ยังไม่นับว่า "เข้าใช้งานได้" — ทุกหน้ารอ init() === true ก่อนโหลดข้อมูล จึงไม่มีหน้าไหนเริ่มทำงาน
        this.mustChangePassword = true;
        this.user = result.data.user;
        return false;
      }
      if (result.ok && result.data.authenticated) {
        this.isAuthenticated = true;
        this.user = result.data.user;
        // Server sends UNIX seconds; compare against Date.now() in milliseconds
        this.sessionExpires = (result.data.session_expires || 0) * 1000;
        this.persistCurrentUser();
        return true;
      }
      this.isAuthenticated = false;
      this.clearPersistedUser();
      return false;
    } catch (error) {
      this.isAuthenticated = false;
      return false;
    }
  }

  async login(username, password) {
    try {
      const result = await window.authApi.login(username, password);
      if (result.ok) {
        const user = result.data?.user || null;
        this.isAuthenticated = true;
        this.user = user;
        this.sessionExpires = Date.now() + 8 * 60 * 60 * 1000; // 8 hours
        this.persistCurrentUser();
        return { success: true, user };
      }
      return { success: false, error: result.error };
    } catch (error) {
      return { success: false, error: error.message, status: error.status, data: error.data };
    }
  }

  async logout() {
    try {
      await window.authApi.logout();
    } catch (error) {
      console.warn('Logout error:', error);
    } finally {
      this.isAuthenticated = false;
      this.user = null;
      this.sessionExpires = 0;
      this.clearPersistedUser();
      this.stopSessionCheck();
      window.location.reload();
    }
  }

  persistCurrentUser() {
    try {
      const u = this.user || {};
      localStorage.setItem('currentUser', JSON.stringify({
        id: u.id || null,
        username: u.username || null,
        nurse_id: u.nurse_id || null,
        nurse_name: u.full_name || null,
        full_name: u.full_name || null,
        role: u.role || null,
      }));
    } catch (error) {
      // localStorage can be unavailable (private mode) — the server session stays the source of truth
    }
  }

  clearPersistedUser() {
    try {
      localStorage.removeItem('currentUser');
    } catch (error) {
      // ignore
    }
  }

  checkSession() {
    if (!this.isAuthenticated) return false;
    if (this.sessionExpires && Date.now() > this.sessionExpires) {
      this.isAuthenticated = false;
      return false;
    }
    return true;
  }

  startSessionCheck(intervalMs = 30000) {
    this.checkInterval = setInterval(() => {
      if (!this.checkSession()) {
        this.handleSessionExpired();
      }
    }, intervalMs);
  }

  stopSessionCheck() {
    if (this.checkInterval) {
      clearInterval(this.checkInterval);
      this.checkInterval = null;
    }
  }

  handleSessionExpired() {
    this.stopSessionCheck();
    this.isAuthenticated = false;
    this.user = null;
    // No separate /login route: reload the SPA shell so the login gate appears.
    // An absolute "/login" redirect 404s on subdirectory installs (e.g. /emer/public).
    window.location.reload();
  }

  isAdmin() {
    return this.user?.role === 'admin';
  }

  isStaff() {
    return this.user?.role === 'staff' || this.user?.role === 'admin';
  }

  getUser() {
    return this.user;
  }
}

// Export singleton
window.auth = new AuthManager();

// Auto-initialize on page load
document.addEventListener('DOMContentLoaded', async () => {
  const authenticated = await window.auth.init();
  if (authenticated) {
    window.auth.startSessionCheck();
    // Hide auth gate
    const authGate = document.getElementById('authGate');
    if (authGate) authGate.style.display = 'none';
    
    // Initialize role-based UI
    initializeRoleUI();
  } else if (window.auth.mustChangePassword) {
    // เข้าสู่ระบบครั้งแรก (รหัสเริ่มต้น) → บังคับอยู่หน้าเปลี่ยนรหัสผ่านอย่างเดียว
    const authGate = document.getElementById('authGate');
    if (authGate) authGate.style.display = 'none';
    showPasswordGate(window.auth.getUser());
  } else {
    // Show login gate
    const authGate = document.getElementById('authGate');
    if (authGate) authGate.style.display = 'grid';
  }
});

// API ตอบ 401 ระหว่างใช้งาน = หมดเวลาเข้าสู่ระบบ → โหลดหน้าใหม่ไปหน้าเข้าสู่ระบบ
// (ต้องโหลดใหม่: CSRF token ในหน้าเดิมผูกกับ session เก่า เข้าสู่ระบบทับหน้าเดิมจะโดนปฏิเสธ)
// รายการที่กำลังทำซึ่งเก็บไว้ในเบราว์เซอร์ (เช่น รายการรอเพิ่มลงรถ) จะถูกกู้คืนหลังเข้าสู่ระบบ
const AUTH_EXPIRED_KEY = 'emer.authExpiredAt';
window.addEventListener('auth:expired', () => {
  if (!window.auth.isAuthenticated) return;   // ยิงครั้งเดียว แม้หลาย request ได้ 401 พร้อมกัน
  window.auth.isAuthenticated = false;
  try { sessionStorage.setItem(AUTH_EXPIRED_KEY, String(Date.now())); } catch (error) { /* แค่ไม่มีข้อความแจ้ง */ }
  window.location.reload();
});
document.addEventListener('DOMContentLoaded', async () => {
  if (await window.auth.init()) return;
  let at = 0;
  try { at = Number(sessionStorage.getItem(AUTH_EXPIRED_KEY) || 0); sessionStorage.removeItem(AUTH_EXPIRED_KEY); } catch (error) { /* ignore */ }
  const errorEl = document.getElementById('authError');
  if (errorEl && at && Date.now() - at < 60000) {
    errorEl.textContent = 'หมดเวลาการเข้าสู่ระบบ กรุณาเข้าสู่ระบบอีกครั้ง — รายการที่กำลังทำยังอยู่';
  }
});

// ---------- หน้าบังคับเปลี่ยนรหัสผ่าน ----------
// กติกาเดียวกับ User::passwordProblems() ฝั่ง server (server ตรวจซ้ำเสมอ)
function passwordChecks(pw, username) {
  const digits = pw.replace(/\D/g, '');
  const userDigits = String(username || '').replace(/\D/g, '');
  return {
    len: pw.length >= 8 && pw.length <= 72,
    phone: pw !== '' && !/\s/.test(pw) && pw.toLowerCase() !== String(username || '').toLowerCase()
      && !(digits.length >= 9 && digits === userDigits),
  };
}

function showPasswordGate(user) {
  const gate = document.getElementById('pwGate');
  const form = document.getElementById('pwForm');
  if (!gate || !form) return;
  document.querySelector('.app-top')?.setAttribute('hidden', '');
  document.querySelector('.container')?.setAttribute('hidden', '');
  gate.hidden = false;
  const username = user?.username || '';
  document.getElementById('pwUsername').value = username;   // ให้ตัวจัดการรหัสผ่านของเบราว์เซอร์จับคู่บัญชีถูก
  const intro = document.getElementById('pwIntro');
  if (intro && user?.full_name) intro.textContent = `สวัสดี ${user.full_name} — คุณเข้าสู่ระบบด้วยรหัสผ่านเริ่มต้น เพื่อความปลอดภัย กรุณาตั้งรหัสผ่านใหม่ก่อนเริ่มใช้งาน`;

  const fields = ['pwCurrent', 'pwNew', 'pwConfirm'].map(id => document.getElementById(id));
  const [current, next, confirm] = fields;
  const errorEl = document.getElementById('pwError');
  const renderRules = () => {
    const checks = passwordChecks(next.value, username);
    document.querySelectorAll('#pwRules [data-rule]').forEach(li => {
      const ok = checks[li.dataset.rule];
      li.classList.toggle('ok', ok);
      li.setAttribute('aria-label', `${ok ? 'ผ่าน' : 'ยังไม่ผ่าน'}: ${li.textContent}`);
    });
    return Object.values(checks).every(Boolean);
  };
  next.addEventListener('input', renderRules);
  renderRules();
  document.getElementById('pwShow')?.addEventListener('change', (e) => {
    fields.forEach(f => { f.type = e.target.checked ? 'text' : 'password'; });
  });
  current.focus();

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    errorEl.textContent = '';
    fields.forEach(f => f.removeAttribute('aria-invalid'));
    const fail = (field, message) => {
      errorEl.textContent = message;
      field.setAttribute('aria-invalid', 'true');
      field.focus();
    };
    if (!current.value) return fail(current, 'กรุณากรอกรหัสผ่านปัจจุบัน');
    if (!renderRules()) return fail(next, 'รหัสผ่านใหม่ยังไม่ผ่านเงื่อนไขด้านบน');
    if (next.value === current.value) return fail(next, 'รหัสผ่านใหม่ต้องไม่ซ้ำกับรหัสผ่านเดิม');
    if (next.value !== confirm.value) return fail(confirm, 'ยืนยันรหัสผ่านไม่ตรงกับรหัสผ่านใหม่');

    const btn = form.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.textContent = 'กำลังบันทึก...';
    try {
      await window.authApi.changePassword({
        current_password: current.value, new_password: next.value, confirm_password: confirm.value,
      });
      btn.textContent = 'เปลี่ยนรหัสผ่านสำเร็จ ✓';
      window.location.reload();   // โหลดแอปใหม่ — คราวนี้ init() ผ่าน เข้าใช้งานได้ตามปกติ
    } catch (error) {
      const errs = error.data?.errors || {};
      const field = errs.current_password ? current : (errs.new_password ? next : (errs.confirm_password ? confirm : null));
      const msg = [errs.current_password, errs.new_password, errs.confirm_password].flat().filter(Boolean)[0];
      if (error.status === 401) { window.location.reload(); return; }   // session หมดอายุ → กลับหน้า login
      if (field) fail(field, msg); else errorEl.textContent = error.message || 'เปลี่ยนรหัสผ่านไม่สำเร็จ';
      btn.disabled = false;
      btn.textContent = 'บันทึกรหัสผ่านใหม่';
    }
  });
}

function initializeRoleUI() {
  const user = window.auth.getUser();
  if (!user) return;

  // Update header title with user role
  const headerTitle = document.getElementById('headerTitle');
  if (headerTitle) {
    const roleLabel = user.role === 'admin' ? '👑 ผู้ดูแลระบบ' : '👩‍⚕️ เจ้าหน้าที่';
    // ชื่อระบบสั้น + บรรทัดผู้ใช้แยก — บนมือถือหัวเว็บจะไม่สูงจนบังเนื้อหา
    headerTitle.textContent = '';
    const title = document.createElement('span');
    title.className = 'header-name';
    title.textContent = 'ตรวจเช็กรถ Refer';
    const who = document.createElement('span');
    who.className = 'header-user';
    who.textContent = `${user.full_name} · ${roleLabel}`;
    headerTitle.append(title, who);
  }

  // Show/hide tabs based on role
  const adminTabs = ['btnTabNurses', 'btnTabStock']; // Admin-only tabs (จัดการผู้ใช้ = btnTabNurses)
  const staffTabs = ['btnTabCheck', 'btnTabRestock']; // Staff tabs
  
  if (user.role === 'admin') {
    // Admin sees all tabs
    adminTabs.forEach(id => {
      const el = document.getElementById(id);
      if (el) el.style.display = '';
    });
  } else {
    // Staff only sees staff tabs
    adminTabs.forEach(id => {
      const el = document.getElementById(id);
      if (el) el.style.display = 'none';
    });
  }

  // Redirect to appropriate dashboard
  const currentTab = document.querySelector('.tab-btn.active')?.id;
  if (!currentTab || currentTab === 'btnTabDashboard') {
    if (user.role === 'admin') {
      // Admin dashboard will be loaded
    } else {
      // Staff dashboard
    }
  }
}

// ข้อความตอบกลับหน้าเข้าสู่ระบบ: แปลรหัสสถานะจาก server เป็นภาษาที่ผู้ใช้เข้าใจ (server ส่งข้อความภาษาอังกฤษสั้น ๆ)
function loginErrorMessage(result) {
  const serverMsg = String(result.error || '');
  switch (result.status) {
    case 401: return 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง (ชื่อผู้ใช้คือเบอร์โทรศัพท์)';
    case 403:
      if (/deactivated/i.test(serverMsg)) return 'บัญชีนี้ถูกปิดใช้งาน กรุณาติดต่อผู้ดูแลระบบ';
      if (/csrf|origin/i.test(serverMsg)) return 'หน้าเว็บหมดอายุ กรุณาโหลดหน้าใหม่ (ปัดลงเพื่อรีเฟรช) แล้วลองอีกครั้ง';
      return 'ไม่มีสิทธิ์เข้าสู่ระบบ กรุณาติดต่อผู้ดูแลระบบ';
    case 429: return 'ใส่รหัสผ่านผิดหลายครั้งเกินไป ระบบล็อกไว้ชั่วคราว — กรุณารอประมาณ 15 นาทีแล้วลองใหม่';
    case 0: return 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ กรุณาตรวจสอบอินเทอร์เน็ตแล้วลองใหม่';
    default:
      if (result.status >= 500) return 'ระบบขัดข้องชั่วคราว กรุณาลองใหม่อีกครั้ง หากยังไม่ได้ให้ติดต่อผู้ดูแลระบบ';
      return serverMsg || 'เข้าสู่ระบบไม่สำเร็จ กรุณาลองใหม่';
  }
}

// Login form handler
document.addEventListener('DOMContentLoaded', () => {
  const loginForm = document.getElementById('authForm');
  if (loginForm) {
    const passwordEl = document.getElementById('systemPassword');
    const errorEl = document.getElementById('authError');
    const showMessage = (text, ok = false) => {
      if (!errorEl) return;
      errorEl.textContent = text;
      errorEl.classList.toggle('is-ok', ok);
    };

    document.getElementById('systemPasswordShow')?.addEventListener('change', (e) => {
      if (passwordEl) passwordEl.type = e.target.checked ? 'text' : 'password';
    });
    // เริ่มพิมพ์ใหม่ = ล้างข้อความผิดพลาดเดิม
    loginForm.addEventListener('input', () => { if (errorEl?.textContent) showMessage(''); });

    loginForm.addEventListener('submit', async (e) => {
      e.preventDefault();

      const username = document.getElementById('systemUsername')?.value?.trim();
      const password = passwordEl?.value;
      const submitBtn = loginForm.querySelector('button[type="submit"]');

      if (!username || !password) {
        showMessage('กรุณากรอกชื่อผู้ใช้และรหัสผ่าน');
        return;
      }

      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'กำลังเข้าสู่ระบบ...';
      }

      let success = false;
      try {
        const result = await window.auth.login(username, password);
        if (result.success) {
          success = true;
          showMessage('เข้าสู่ระบบสำเร็จ กำลังเปิดระบบ...', true);
          // Success - page will reload via auth.init()
          window.location.reload();
        } else {
          showMessage(loginErrorMessage(result));
          if (result.status === 401 && passwordEl) {
            passwordEl.value = '';
            passwordEl.focus();
          }
        }
      } catch (error) {
        showMessage(loginErrorMessage({ status: 0 }));
      } finally {
        // สำเร็จแล้วไม่ปลดปุ่ม — กันกดซ้ำระหว่างหน้ากำลังโหลดใหม่
        if (submitBtn && !success) {
          submitBtn.disabled = false;
          submitBtn.textContent = 'เข้าสู่ระบบ';
        }
      }
    });
  }
});