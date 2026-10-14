// ========================================
// CORE: Auth Module
// ========================================

class AuthManager {
  constructor() {
    this.isAuthenticated = false;
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
      return { success: false, error: error.message };
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
  } else {
    // Show login gate
    const authGate = document.getElementById('authGate');
    if (authGate) authGate.style.display = 'grid';
  }
});

function initializeRoleUI() {
  const user = window.auth.getUser();
  if (!user) return;

  // Update header title with user role
  const headerTitle = document.getElementById('headerTitle');
  if (headerTitle) {
    const roleLabel = user.role === 'admin' ? '👑 ผู้ดูแลระบบ' : '👩‍⚕️ เจ้าหน้าที่';
    headerTitle.textContent = `ระบบตรวจเช็กรถ Refer / Ambulance — ${user.full_name} (${roleLabel})`;
  }

  // Show/hide tabs based on role
  const adminTabs = ['btnTabNurses', 'btnTabStock', 'btnTabUsers']; // Admin-only tabs
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

// Login form handler
document.addEventListener('DOMContentLoaded', () => {
  const loginForm = document.getElementById('authForm');
  if (loginForm) {
    loginForm.addEventListener('submit', async (e) => {
      e.preventDefault();
      
      const username = document.getElementById('systemUsername')?.value?.trim();
      const password = document.getElementById('systemPassword')?.value;
      const errorEl = document.getElementById('authError');
      const submitBtn = loginForm.querySelector('button[type="submit"]');
      
      if (!username || !password) {
        if (errorEl) errorEl.textContent = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
        return;
      }

      if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = 'กำลังเข้าสู่ระบบ...';
      }

      try {
        const result = await window.auth.login(username, password);
        if (result.success) {
          // Success - page will reload via auth.init()
          window.location.reload();
        } else {
          if (errorEl) errorEl.textContent = result.error || 'เข้าสู่ระบบไม่สำเร็จ';
        }
      } catch (error) {
        if (errorEl) errorEl.textContent = 'เกิดข้อผิดพลาด: ' + error.message;
      } finally {
        if (submitBtn) {
          submitBtn.disabled = false;
          submitBtn.textContent = 'เข้าสู่ระบบ';
        }
      }
    });
  }
});