// ========================================
// COMPONENTS: Table
// ========================================

class DataTable {
  constructor(container, options = {}) {
    this.container = typeof container === 'string' ? UI.$(container) : container;
    this.columns = options.columns || [];
    this.data = options.data || [];
    this.keyField = options.keyField || 'id';
    this.sortable = options.sortable !== false;
    this.searchable = options.searchable !== false;
    this.pagination = options.pagination || false;
    this.pageSize = options.pageSize || 10;
    this.currentPage = 1;
    this.sortColumn = null;
    this.sortDirection = 'asc';
    this.onRowClick = options.onRowClick || null;
    this.rowActions = options.rowActions || [];
    this.render();
  }

  render() {
    if (!this.container) return;
    
    this.container.innerHTML = '';
    
    const wrapper = UI.create('div', { class: 'overflow-x-auto' });
    const table = UI.create('table', { class: 'w-full border-collapse' });
    const thead = UI.create('thead');
    const tbody = UI.create('tbody');
    
    // Header
    const headerRow = UI.create('tr');
    this.columns.forEach(col => {
      const th = UI.create('th', {
        class: 'px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider bg-gray-50 border-b border-gray-200',
        style: col.width ? `width: ${col.width}` : '',
      }, col.label);
      
      if (this.sortable && col.sortable !== false) {
        th.style.cursor = 'pointer';
        th.addEventListener('click', () => this.handleSort(col.key));
        if (this.sortColumn === col.key) {
          th.textContent += this.sortDirection === 'asc' ? ' ↑' : ' ↓';
        }
      }
      headerRow.appendChild(th);
    });
    
    // Actions column
    if (this.rowActions.length > 0) {
      const th = UI.create('th', {
        class: 'px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider bg-gray-50 border-b border-gray-200',
        style: 'width: 120px;',
      }, 'การดำเนินการ');
      headerRow.appendChild(th);
    }
    
    thead.appendChild(headerRow);
    table.appendChild(thead);
    
    // Body
    this.renderRows(tbody);
    table.appendChild(tbody);
    wrapper.appendChild(table);
    this.container.appendChild(wrapper);
    
    // Pagination
    if (this.pagination) {
      this.renderPagination();
    }
  }

  renderRows(tbody) {
    let data = this.getFilteredData();
    data = this.getSortedData(data);
    
    if (this.pagination) {
      const start = (this.currentPage - 1) * this.pageSize;
      data = data.slice(start, start + this.pageSize);
    }
    
    tbody.innerHTML = '';
    
    if (data.length === 0) {
      const colCount = this.columns.length + (this.rowActions.length > 0 ? 1 : 0);
      const tr = UI.create('tr');
      const td = UI.create('td', {
        class: 'px-4 py-8 text-center text-gray-500',
        colspan: colCount,
      }, 'ไม่พบข้อมูล');
      tr.appendChild(td);
      tbody.appendChild(tr);
      return;
    }
    
    data.forEach((row, index) => {
      const tr = UI.create('tr', {
        class: 'hover:bg-gray-50 transition-colors',
        'data-id': row[this.keyField],
      });
      
      if (this.onRowClick) {
        tr.style.cursor = 'pointer';
        tr.addEventListener('click', (e) => {
          if (!e.target.closest('button, a, input, select')) {
            this.onRowClick(row, index);
          }
        });
      }
      
      this.columns.forEach(col => {
        const td = UI.create('td', {
          class: 'px-4 py-3 text-sm text-gray-900 border-b border-gray-100',
        });
        
        let value = row[col.key];
        if (col.format) {
          value = col.format(value, row);
        } else if (value === null || value === undefined) {
          value = '-';
        }
        
        td.textContent = value;
        tr.appendChild(td);
      });
      
      // Actions
      if (this.rowActions.length > 0) {
        const td = UI.create('td', {
          class: 'px-4 py-3 text-sm border-b border-gray-100',
        });
        
        const actionContainer = UI.create('div', { class: 'flex items-center gap-2' });
        this.rowActions.forEach(action => {
          const btn = UI.create('button', {
            type: 'button',
            class: `text-xs px-2 py-1 rounded ${action.class || 'text-blue-600 hover:text-blue-800'}`,
            onclick: (e) => {
              e.stopPropagation();
              action.handler(row, index);
            },
          }, action.label);
          actionContainer.appendChild(btn);
        });
        td.appendChild(actionContainer);
        tr.appendChild(td);
      }
      
      tbody.appendChild(tr);
    });
  }

  getFilteredData() {
    // Implement search filter if needed
    return this.data;
  }

  getSortedData(data) {
    if (!this.sortColumn) return data;
    
    return [...data].sort((a, b) => {
      const aVal = a[this.sortColumn];
      const bVal = b[this.sortColumn];
      
      if (aVal === null || aVal === undefined) return 1;
      if (bVal === null || bVal === undefined) return -1;
      
      let result = 0;
      if (aVal < bVal) result = -1;
      else if (aVal > bVal) result = 1;
      
      return this.sortDirection === 'asc' ? result : -result;
    });
  }

  handleSort(columnKey) {
    if (this.sortColumn === columnKey) {
      this.sortDirection = this.sortDirection === 'asc' ? 'desc' : 'asc';
    } else {
      this.sortColumn = columnKey;
      this.sortDirection = 'asc';
    }
    this.render();
  }

  setData(data) {
    this.data = data;
    this.currentPage = 1;
    this.render();
  }

  setPage(page) {
    this.currentPage = page;
    this.render();
  }

  renderPagination() {
    const totalPages = Math.ceil(this.getFilteredData().length / this.pageSize);
    if (totalPages <= 1) return;
    
    const pagination = UI.create('div', { class: 'flex justify-center gap-2 mt-4' });
    
    // Previous
    const prevBtn = UI.create('button', {
      type: 'button',
      class: `px-3 py-1 text-sm rounded ${this.currentPage === 1 ? 'text-gray-300 cursor-not-allowed' : 'text-gray-700 hover:bg-gray-100'}`,
      disabled: this.currentPage === 1,
      onclick: () => this.setPage(this.currentPage - 1),
    }, 'ก่อนหน้า');
    pagination.appendChild(prevBtn);
    
    // Page numbers
    for (let i = 1; i <= totalPages; i++) {
      if (i === 1 || i === totalPages || (i >= this.currentPage - 1 && i <= this.currentPage + 1)) {
        const pageBtn = UI.create('button', {
          type: 'button',
          class: `w-8 h-8 rounded ${i === this.currentPage ? 'bg-teal-600 text-white' : 'text-gray-700 hover:bg-gray-100'}`,
          onclick: () => this.setPage(i),
        }, i);
        pagination.appendChild(pageBtn);
      } else if (i === this.currentPage - 2 || i === this.currentPage + 2) {
        const ellipsis = UI.create('span', { class: 'flex items-center px-2 text-gray-400' }, '...');
        pagination.appendChild(ellipsis);
      }
    }
    
    // Next
    const nextBtn = UI.create('button', {
      type: 'button',
      class: `px-3 py-1 text-sm rounded ${this.currentPage === totalPages ? 'text-gray-300 cursor-not-allowed' : 'text-gray-700 hover:bg-gray-100'}`,
      disabled: this.currentPage === totalPages,
      onclick: () => this.setPage(this.currentPage + 1),
    }, 'ถัดไป');
    pagination.appendChild(nextBtn);
    
    this.container.appendChild(pagination);
  }
}

// Export
window.DataTable = DataTable;