// ========================================
// COMPONENTS: Notifications (ตัวเลขบนปุ่มแมวลอย + แถวแจ้งเตือนในกล่องช่วยเหลือ)
// ========================================

class NotificationCenter {
  constructor() {
    this.fab = document.getElementById('helpCatBtn');
    this.badge = document.getElementById('notifyBadge');
    this.goBtn = document.getElementById('notifyGo');
    this.text = document.getElementById('notifyText');
    this.openStatuses = ['PENDING', 'IN_PROGRESS', 'PARTIAL'];
    this.refreshTimer = null;
  }

  init() {
    if (!this.fab) return;

    this.fab.addEventListener('click', () => this.refresh());
    this.goBtn?.addEventListener('click', () => {
      window.helpCat?.close();
      if (typeof switchTab === 'function') switchTab('restock');
      this.refresh();
    });

    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) this.refresh();
    });

    this.refreshTimer = setInterval(() => {
      if (!document.hidden) this.refresh();
    }, 60000);
  }

  async refresh() {
    if (!window.auth?.isAuthenticated) return;
    try {
      // เดิมเรียก /api/restock ซึ่งสำหรับผู้ดูแลระบบเท่านั้น → บัญชี staff ได้ 400/403 แล้ว badges ไม่เคยขึ้น
      // ใช้เส้นทางตามสิทธิ์เดียวกันกับหน้าเติมของ (staff → /api/staff/restock)
      const res = (window.auth.isAdmin && window.auth.isAdmin())
        ? await window.restockApi.getAll()
        : await window.staffApi.getRestockRequests();
      const open = (res.data || []).filter(r => this.openStatuses.includes(r.status));
      this.setCount(open.length);
    } catch (error) {
      console.warn('Notification badge refresh failed:', error);
    }
  }

  setCount(count) {
    if (!this.badge || !this.fab) return;
    this.badge.textContent = count > 99 ? '99+' : String(count);
    this.badge.classList.toggle('hidden', count === 0);
    this.fab.setAttribute('aria-label', count > 0 ? `ขอความช่วยเหลือ · รอเติม ${count} รายการ` : 'ขอความช่วยเหลือ');
    if (this.text) this.text.textContent = count > 0 ? `รอเติม ${count} รายการ` : 'ไม่มีรายการรอเติม';
    this.goBtn?.classList.toggle('has-items', count > 0);
  }
}

window.notificationCenter = new NotificationCenter();

// Start once the auth session resolves (the login gate may be covering the page)
document.addEventListener('DOMContentLoaded', async () => {
  await window.auth.init();
  window.notificationCenter.init();
  if (window.auth.isAuthenticated) {
    window.notificationCenter.refresh();
  }
});
