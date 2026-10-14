// ========================================
// PAGE: LOGIN
// ========================================

class LoginPage {
  constructor() {
    this.form = UI.$('#authForm');
    this.passwordInput = UI.$('#systemPassword');
    this.errorEl = UI.$('#authError');
    this.vehicles = [];
    this.nurses = [];
    this.init();
  }

  async init() {
    this.bindEvents();
    await this.loadData();
    this.renderVehicleSelection();
    this.renderNurseSelect();
    
    // Check if already authenticated
    if (window.auth.isAuthenticated) {
      this.redirectToDashboard();
    }
  }

  bindEvents() {
    if (this.form) {
      this.form.addEventListener('submit', (e) => this.handleSubmit(e));
    }
    
    if (this.passwordInput) {
      this.passwordInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          this.handleSubmit(e);
        }
      });
    }
  }

  async loadData() {
    try {
      // Load data from API
      const [vehiclesRes, nursesRes] = await Promise.all([
        window.vehicleApi.getAll(),
        window.nurseApi.getAll(),
      ]);
      
      this.vehicles = vehiclesRes.data || [];
      this.nurses = nursesRes.data || [];
    } catch (error) {
      console.error('Failed to load login data:', error);
      // Use fallback data
      this.vehicles = [
        { id: 'car-1', name: 'รถ Refer (3ชฏ3250)' },
        { id: 'car-2', name: 'รถ Refer (ฆง8434)' },
        { id: 'car-3', name: 'รถ Refer (5กล1034)' },
      ];
      this.nurses = [];
    }
  }

  renderVehicleSelection() {
    const container = UI.$('.car-grid');
    if (!container) return;
    
    container.innerHTML = this.vehicles.map((vehicle, index) => `
      <button type="button" 
        class="car-symbol" 
        data-car-id="${vehicle.id}" 
        aria-pressed="false" 
        aria-label="เลือกรถ ${this.getPlate(vehicle.id)}"
        onclick="loginPage.selectVehicle('${vehicle.id}')">
        <img src="${this.getVehicleIcon(vehicle.id)}" alt="" width="64" height="64">
        <span>${this.getPlate(vehicle.id)}</span>
        <span class="car-selected-mark">✓</span>
      </button>
    `).join('');
  }

  renderNurseSelect() {
    const select = UI.$('#userName');
    if (!select) return;
    
    select.innerHTML = '<option value="">-- เลือกรายชื่อพยาบาล --</option>' +
      this.nurses.map(nurse => `
        <option value="${nurse.id}">${UI.escape(nurse.name)} (${UI.escape(nurse.department)})</option>
      `).join('');
  }

  getPlate(carId) {
    const plates = {
      'car-1': '3ชฏ3250',
      'car-2': 'ฆง8434',
      'car-3': '5กล1034',
    };
    return plates[carId] || carId;
  }

  getVehicleIcon(carId) {
    // Return base64 encoded SVG or default icon
    return 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><text y=".9em" font-size="90">🚑</text></svg>';
  }

  selectVehicle(carId) {
    // Update UI
    document.querySelectorAll('.car-symbol').forEach(btn => {
      btn.setAttribute('aria-pressed', btn.dataset.carId === carId);
    });
    
    // Update hidden select
    const carSelect = UI.$('#car');
    if (carSelect) {
      const vehicle = this.vehicles.find(v => v.id === carId);
      if (vehicle) carSelect.value = vehicle.name;
    }
  }

  async handleSubmit(event) {
    event.preventDefault();
    
    const password = this.passwordInput?.value?.trim();
    if (!password) {
      this.showError('กรุณากรอกรหัสผ่าน');
      return;
    }

    const nurseId = UI.$('#userName')?.value;
    const shift = UI.$('#shift')?.value;
    const car = UI.$('#car')?.value;

    if (!nurseId || !shift || !car) {
      this.showError('กรุณาเลือกพยาบาล เวร และรถให้ครบ');
      return;
    }

    UI.setLoading(this.form.querySelector('button[type="submit"]'), true);
    this.hideError();

    try {
      const result = await window.authApi.login(password);
      if (result.ok) {
        // Store selected values in sessionStorage for next page
        sessionStorage.setItem('selectedNurseId', nurseId);
        sessionStorage.setItem('selectedShift', shift);
        sessionStorage.setItem('selectedCar', car);
        
        this.redirectToDashboard();
      }
    } catch (error) {
      this.showError(error.message || 'เข้าสู่ระบบไม่ได้ กรุณาตรวจรหัสผ่านและการเชื่อมต่อ');
    } finally {
      UI.setLoading(this.form.querySelector('button[type="submit"]'), false);
    }
  }

  showError(message) {
    if (this.errorEl) {
      this.errorEl.textContent = message;
      this.errorEl.style.display = 'block';
    }
  }

  hideError() {
    if (this.errorEl) {
      this.errorEl.textContent = '';
      this.errorEl.style.display = 'none';
    }
  }

  redirectToDashboard() {
    window.location.href = '/';
  }
}

// Initialize on DOM ready
let loginPage;
document.addEventListener('DOMContentLoaded', () => {
  if (UI.$('#authForm')) {
    loginPage = new LoginPage();
    window.loginPage = loginPage;
  }
});