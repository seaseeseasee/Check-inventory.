// ========================================
// COMPONENTS: Alert/Toast
// ========================================

class AlertManager {
  constructor() {
    this.container = null;
    this.alerts = [];
    this.init();
  }

  init() {
    this.container = document.getElementById('alert-container');
    if (!this.container) {
      this.container = UI.create('div', {
        id: 'alert-container',
        class: 'fixed top-4 right-4 z-[1200] flex flex-col gap-2 w-[calc(100vw-2rem)] max-w-sm pointer-events-none',
      });
      document.body.appendChild(this.container);
    }
  }

  show(message, type = 'info', duration = 5000) {
    const alertId = 'alert-' + UI.uid();
    const typeClasses = {
      success: 'bg-green-50 border-green-200 text-green-800',
      error: 'bg-red-50 border-red-200 text-red-800',
      warning: 'bg-yellow-50 border-yellow-200 text-yellow-800',
      info: 'bg-blue-50 border-blue-200 text-blue-800',
    };

    const icons = {
      success: '✓',
      error: '✕',
      warning: '⚠',
      info: 'ℹ',
    };

    const alert = UI.create('div', {
      id: alertId,
      class: `alert pointer-events-auto ${typeClasses[type] || typeClasses.info} border px-4 py-3 rounded-lg shadow-lg flex items-start gap-3 animate-slide-in`,
      role: 'alert',
    }, [
      UI.create('span', { class: 'flex-shrink-0 mt-0.5' }, icons[type] || icons.info),
      UI.create('div', { class: 'flex-1 text-sm' }, message),
      UI.create('button', {
        type: 'button',
        class: 'm-0 w-auto min-h-0 flex-shrink-0 text-current opacity-50 hover:opacity-100',
        onclick: () => this.dismiss(alertId),
        'aria-label': 'Close',
      }, '✕'),
    ]);

    this.container.appendChild(alert);
    this.alerts.push({ id: alertId, element: alert });

    if (duration > 0) {
      setTimeout(() => this.dismiss(alertId), duration);
    }

    return alertId;
  }

  success(message, duration) {
    return this.show(message, 'success', duration);
  }

  error(message, duration) {
    return this.show(message, 'error', duration);
  }

  warning(message, duration) {
    return this.show(message, 'warning', duration);
  }

  info(message, duration) {
    return this.show(message, 'info', duration);
  }

  dismiss(alertId) {
    const alert = this.alerts.find(a => a.id === alertId);
    if (alert) {
      alert.element.style.animation = 'slideOut 0.2s ease forwards';
      setTimeout(() => {
        if (alert.element.parentNode) {
          alert.element.parentNode.removeChild(alert.element);
        }
        this.alerts = this.alerts.filter(a => a.id !== alertId);
      }, 200);
    }
  }

  dismissAll() {
    this.alerts.forEach(alert => this.dismiss(alert.id));
  }
}

// Add animation styles
const style = document.createElement('style');
style.textContent = `
  @keyframes slideIn {
    from { opacity: 0; transform: translateX(100%); }
    to { opacity: 1; transform: translateX(0); }
  }
  @keyframes slideOut {
    from { opacity: 1; transform: translateX(0); }
    to { opacity: 0; transform: translateX(100%); }
  }
  .animate-slide-in { animation: slideIn 0.3s ease; }
`;
document.head.appendChild(style);

// Export singleton
window.alerts = new AlertManager();

// Convenience methods
window.toast = {
  success: (msg, dur) => window.alerts.success(msg, dur),
  error: (msg, dur) => window.alerts.error(msg, dur),
  warning: (msg, dur) => window.alerts.warning(msg, dur),
  info: (msg, dur) => window.alerts.info(msg, dur),
};