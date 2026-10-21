// ========================================
// CORE: API Client
// ========================================

class ApiClient {
  constructor(baseUrl) {
    if (!baseUrl) {
      const meta = document.querySelector('meta[name="api-base"]');
      baseUrl = meta && meta.content ? meta.content : '/api';
    }
    this.baseUrl = baseUrl;
    this.csrfToken = null;
  }

  async request(endpoint, options = {}) {
    const url = `${this.baseUrl}${endpoint}`;
    const method = (options.method || 'GET').toUpperCase();
    
    const headers = {
      'Content-Type': 'application/json',
      'Cache-Control': 'no-store',
      ...options.headers,
    };

    // Add CSRF token for state-changing requests
    if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
      const token = this.getCsrfToken();
      if (token) {
        headers['X-CSRF-Token'] = token;
      }
    }

    const config = {
      method,
      headers,
      cache: 'no-store',
      credentials: 'same-origin',
      ...options,
    };

    if (options.body && typeof options.body !== 'string') {
      config.body = JSON.stringify(options.body);
    }

    try {
      const response = await fetch(url, config);
      
      // Update CSRF token from response header
      const newCsrf = response.headers.get('X-CSRF-Token');
      if (newCsrf) {
        this.csrfToken = newCsrf;
      }

      const data = await response.json();
      
      if (!response.ok) {
        let message = data.error || 'Request failed';
        // Append per-field validation details when they're not already
        // part of the message (covers older/generic 422 responses).
        if (data.errors && typeof data.errors === 'object') {
          const details = Object.values(data.errors).flat().filter(Boolean);
          if (details.length && !details.some((d) => message.includes(d))) {
            message += ': ' + details.join(' • ');
          }
        }
        const error = new Error(message);
        error.status = response.status;
        error.data = data;
        throw error;
      }

      return data;
    } catch (error) {
      if (error.status) throw error;
      
      // Network error
      const networkError = new Error('Network error: ' + error.message);
      networkError.status = 0;
      throw networkError;
    }
  }

  getCsrfToken() {
    if (!this.csrfToken) {
      const meta = document.querySelector('meta[name="csrf-token"]');
      if (meta) this.csrfToken = meta.content;
    }
    return this.csrfToken;
  }

  setCsrfToken(token) {
    this.csrfToken = token;
  }

  // Convenience methods
  get(endpoint) {
    return this.request(endpoint, { method: 'GET' });
  }

  post(endpoint, data) {
    return this.request(endpoint, { method: 'POST', body: data });
  }

  put(endpoint, data) {
    return this.request(endpoint, { method: 'PUT', body: data });
  }

  patch(endpoint, data) {
    return this.request(endpoint, { method: 'PATCH', body: data });
  }

  delete(endpoint, data) {
    // DELETE ที่มี body (จำเป็นสำหรับ { mode: 'disable' } ของคำสั่งลบรถ/อุปกรณ์)
    return this.request(endpoint, { method: 'DELETE', body: data });
  }
}

// Export singleton instance
window.api = new ApiClient();

// Auth-specific helpers
window.authApi = {
  login: (username, password) => api.post('/auth/login', { username, password }),
  logout: () => api.post('/auth/logout'),
  me: () => api.get('/auth/me'),
};

// System helpers
window.systemApi = {
  health: () => api.get('/health'),
};

// Dashboard helpers
window.dashboardApi = {
  getStats: () => api.get('/dashboard'),
  getVehicles: () => api.get('/dashboard/stats'),
};

// Nurse helpers
window.nurseApi = {
  getAll: () => api.get('/nurses'),
  create: (data) => api.post('/nurses', data),
  update: (id, data) => api.put(`/nurses/${id}`, data),
  delete: (id) => api.delete(`/nurses/${id}`),
};

// Ward / department helpers (ทะเบียนวอร์ดของตารางพยาบาล)
// อ่าน: ทุก role ที่ login / เขียน: server ตรวจ role admin — หน้าจอจึงซ่อนปุ่มเฉพาะ admin เท่านั้น
window.departmentApi = {
  getAll: () => api.get('/departments'),
  create: (data) => api.post('/departments', data),
  update: (id, data) => api.put(`/departments/${id}`, data),
  remove: (id, data) => api.delete(`/departments/${id}`, data || { mode: 'auto' }),
};

// Vehicle helpers
// เส้นทาง /api/vehicles* เป็นชุดที่หน้า "ทะเบียนรถและอุปกรณ์ประจำรถ" ใช้ (ข้าง server ตรวจ role admin ทุกคำสั่งเขียน)
window.vehicleApi = {
  getAll: () => api.get('/vehicles'),
  get: (id) => api.get(`/vehicles/${id}`),
  create: (data) => api.post('/vehicles', data),
  update: (id, data) => api.put(`/vehicles/${id}`, data),
  remove: (id, data) => api.delete(`/vehicles/${id}`, data || { mode: 'auto' }),
  updateItem: (itemId, data) => api.put(`/items/${itemId}`, data),
  deleteItem: (itemId, data) => api.delete(`/items/${itemId}`, data || { mode: 'auto' }),
  addItem: (vehicleId, data) => api.post(`/vehicles/${vehicleId}/items`, data),
  copyItems: (vehicleId, data) => api.post(`/vehicles/${vehicleId}/items/copy`, data),
};

// Inspection helpers
window.inspectionApi = {
  getAll: () => api.get('/inspections'),
  get: (id) => api.get(`/inspections/${id}`),
  start: (data) => api.post('/inspections/start', data),
  saveDraft: (data) => api.put('/inspections/draft', data),
  submit: (data) => api.post('/inspections/submit', data),
};

// Restock helpers
// หมายเหตุ: เส้นทาง createDraft/updateDraft/submit/issue/fill/fulfil ถูกถอดออกจากเซิร์ฟเวอร์แล้ว
// งานเติมอุปกรณ์ทั้งหมดใช้ staffApi.*Restock (/api/staff/restock/*) ซึ่งมีตรวจสิทธิ์เจ้าของงาน
window.restockApi = {
  getAll: () => api.get('/restock'),
  get: (id) => api.get(`/restock/${id}`),
  getForVehicle: (carId) => api.get(`/vehicles/${carId}/restock`),
  getActiveByCheck: (checkId) => api.get(`/checks/${checkId}/restock`),
  cancel: (id, data) => api.post(`/restock/${id}/cancel`, data),
};

// Admin API helpers
window.adminApi = {
  dashboard: () => api.get('/admin/dashboard'),
  getUsers: () => api.get('/admin/users'),
  createUser: (data) => api.post('/admin/users', data),
  updateUser: (id, data) => api.put(`/admin/users/${id}`, data),
  deleteUser: (id) => api.delete(`/admin/users/${id}`),
  
  getVehicles: () => api.get('/admin/vehicles'),
  createVehicle: (data) => api.post('/admin/vehicles', data),
  getVehicle: (id) => api.get(`/admin/vehicles/${id}`),
  updateVehicle: (id, data) => api.put(`/admin/vehicles/${id}`, data),
  deleteVehicle: (id) => api.delete(`/admin/vehicles/${id}`),
  
  getNurses: () => api.get('/admin/nurses'),
  createNurse: (data) => api.post('/admin/nurses', data),
  updateNurse: (id, data) => api.put(`/admin/nurses/${id}`, data),
  deleteNurse: (id) => api.delete(`/admin/nurses/${id}`),
  
  getVehicleItems: (vehicleId) => api.get(`/admin/vehicles/${vehicleId}/items`),
  addVehicleItem: (vehicleId, data) => api.post(`/admin/vehicles/${vehicleId}/items`, data),
  updateItem: (itemId, data) => api.put(`/admin/items/${itemId}`, data),
  deleteItem: (itemId) => api.delete(`/admin/items/${itemId}`),
  
  getRestockRequests: () => api.get('/admin/restock'),
  getRestockStats: () => api.get('/admin/restock/stats'),
};

// Staff API helpers
window.staffApi = {
  dashboard: () => api.get('/staff/dashboard'),
  getVehicles: () => api.get('/staff/vehicles'),
  getVehicle: (id) => api.get(`/staff/vehicles/${id}`),
  startInspection: (data) => api.post('/staff/inspections/start', data),
  saveDraft: (data) => api.put('/staff/inspections/draft', data),
  submitInspection: (data) => api.post('/staff/inspections/submit', data),
  getNurses: () => api.get('/staff/nurses'),
  
  getRestockRequests: () => api.get('/staff/restock'),
  getMyRestockRequests: () => api.get('/staff/restock/my'),
  getRestockRequest: (id) => api.get(`/staff/restock/${id}`),
  cancelRestock: (id, data) => api.post(`/staff/restock/${id}/cancel`, data),
  // ตรวจเช็คตามเวร
  getShiftStatus: (date) => api.get(`/staff/inspections/shifts${date ? '?date=' + encodeURIComponent(date) : ''}`),
  getDraftInfo: (carId) => api.get(`/staff/inspections/draft?car=${encodeURIComponent(carId)}`),
  // เติมของเข้ารถโดยตรง (ไม่มีคลังกลาง)
  getRefillSheet: (carId) => api.get(`/staff/refill/${encodeURIComponent(carId)}`),
  refill: (carId, data) => api.post(`/staff/refill/${encodeURIComponent(carId)}`, data),
  
  getInspections: (params = {}) => {
    const searchParams = new URLSearchParams(params);
    return api.get(`/staff/history/inspections?${searchParams}`);
  },
  getRestockHistory: (params = {}) => {
    const searchParams = new URLSearchParams(params);
    return api.get(`/staff/history/restock?${searchParams}`);
  },
};

// History API helpers
window.historyApi = {
  getInspections: (params = {}) => {
    const searchParams = new URLSearchParams(params);
    return api.get(`/history/inspections?${searchParams}`);
  },
  getInspection: (id) => api.get(`/history/inspections/${id}`),
  getRestock: (params = {}) => {
    const searchParams = new URLSearchParams(params);
    return api.get(`/history/restock?${searchParams}`);
  },
  clearInspections: () => api.post('/history/inspections/clear'),
};

