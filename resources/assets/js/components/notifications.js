// ========================================
// COMPONENTS: Notification Floating Button
// ========================================

class NotificationCenter {
  constructor() {
    this.fab = document.getElementById('notifyFab');
    this.badge = document.getElementById('notifyBadge');
    this.openStatuses = ['PENDING', 'IN_PROGRESS', 'PARTIAL'];
    this.refreshTimer = null;
  }

  init() {
    if (!this.fab) return;

    this.fab.addEventListener('click', () => {
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
      const res = await window.restockApi.getAll();
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
    this.fab.setAttribute('aria-label', count > 0 ? `การแจ้งเตือน ${count} รายการ` : 'การแจ้งเตือน');
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
