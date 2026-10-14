// ========================================
// CORE: Database Status Indicator
// ========================================
class SystemStatus {
  constructor() {
    this.el = document.getElementById('storageState');
  }

  async refresh() {
    if (!this.el) return;
    try {
      const res = await window.systemApi.health();
      const info = (res && res.data) || {};
      this.set(`เชื่อมต่อฐานข้อมูลของระบบแล้ว (${info.driver}: ${info.database})`, 'ok');
    } catch (error) {
      if (error && error.status === 401) return; // not logged in yet: keep placeholder
      const info = (error && error.data) || {};
      const name = info.database ? ` (${info.database})` : '';
      this.set(`เชื่อมต่อฐานข้อมูลไม่สำเร็จ${name}`, 'error');
    }
  }

  set(message, state) {
    if (!this.el) return;
    this.el.textContent = message;
    this.el.dataset.state = state;
  }
}

window.systemStatus = new SystemStatus();
document.addEventListener('DOMContentLoaded', async () => {
  await window.auth.init();
  if (window.auth.isAuthenticated) {
    window.systemStatus.refresh();
  }
});
