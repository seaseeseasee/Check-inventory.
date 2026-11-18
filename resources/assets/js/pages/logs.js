// ========================================
// PAGE: LOG MANAGEMENT (admin + สิทธิ์ logs.view)
// ========================================
// ข้อมูลทั้งหมดมาจาก /api/logs/* (LogController) — หน้านี้อ่านอย่างเดียว ไม่มีปุ่มแก้/ลบ log
// ทุกค่าที่มาจาก log (ผู้ใช้/รายละเอียด/UA ฯลฯ) เป็นข้อมูลที่ผู้ใช้ภายนอกควบคุมได้ → ผ่าน esc() ก่อนลง innerHTML เสมอ
// เวลา: server ส่ง ISO ลงท้าย Z (UTC) → แสดงเป็นเวลาไทย

(function () {
  const TZ = 'Asia/Bangkok';
  const CATEGORY = {
    activity: 'กิจกรรมผู้ใช้',
    audit: 'การเปลี่ยนแปลงข้อมูล',
    auth: 'การยืนยันตัวตน',
    security: 'ความปลอดภัย',
    system: 'ระบบ',
    data_access: 'การเข้าถึงข้อมูล',
  };
  const STATUS = { SUCCESS: ['สำเร็จ', 'chip-ok'], FAILED: ['ไม่สำเร็จ', 'chip-miss'] };
  const SEVERITY = {
    INFO: ['ปกติ', 'chip-pending'],
    WARNING: ['เตือน', 'chip-warn'],
    ERROR: ['ผิดพลาด', 'chip-miss'],
    CRITICAL: ['วิกฤต', 'chip-cancelled'],
  };
  const COLUMNS = [
    ['created_at', 'วันที่ / เวลา'],
    ['username', 'ผู้ใช้งาน'],
    ['action', 'กิจกรรม'],
    ['module', 'Module'],
    [null, 'รายละเอียด'],
    ['ip_address', 'IP Address'],
    ['status', 'สถานะ'],
  ];

  const fmtDateTime = new Intl.DateTimeFormat('th-TH', {
    timeZone: TZ, day: 'numeric', month: 'short', year: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit',
  });
  const fmtDay = new Intl.DateTimeFormat('th-TH', { timeZone: 'UTC', day: 'numeric', month: 'short' });
  const fmtNum = new Intl.NumberFormat('th-TH');
  const todayTH = () => new Intl.DateTimeFormat('en-CA', { timeZone: TZ }).format(new Date());

  const logApi = {
    me: () => api.get('/logs/me'),
    summary: (days) => api.get(`/logs/summary?days=${days}`),
    list: (qs) => api.get(`/logs?${qs}`),
    options: () => api.get('/logs/options'),
    get: (id) => api.get(`/logs/events/${encodeURIComponent(id)}`),
    archives: () => api.get('/logs/archives'),
    permissions: () => api.get('/logs/permissions'),
    setPermissions: (userId, permissions) => api.put(`/logs/permissions/${encodeURIComponent(userId)}`, { permissions }),
  };

  const when = (iso) => (iso ? fmtDateTime.format(new Date(iso)) : '—');
  const chip = (map, key) => {
    const [label, cls] = map[key] || [key, 'chip-pending'];
    return `<span class="chip ${cls}">${esc(label)}</span>`;
  };

  class LogsPage {
    constructor() {
      this.perms = [];
      this.labels = {};
      this.sub = 'overview';
      this.days = 7;
      this.options = null;
      this.state = {};          // ต่อมุมมอง: all / security
      this.loadedOnce = false;
    }

    has(p) { return this.perms.includes(p); }

    /** เรียกหลัง login (auth:ready) — เปิดเมนูเฉพาะผู้ที่มีสิทธิ์ */
    async checkAccess(user) {
      const btn = document.getElementById('btnTabLogs');
      if (!btn) return;
      btn.hidden = true;
      if (!user || user.role !== 'admin') return;
      try {
        const res = await logApi.me();
        this.perms = res.data?.permissions || [];
        this.labels = res.data?.labels || {};
        btn.hidden = !this.has('logs.view');
      } catch (e) {
        btn.hidden = true;   // ระบบ log ไม่พร้อม → ไม่แสดงเมนู ไม่รบกวนงานหลัก
      }
    }

    init() {
      if (!this.has('logs.view')) return;
      this.renderSeg();
      this.show(this.sub);
    }

    subs() {
      const list = [['overview', 'ภาพรวม'], ['all', 'ประวัติทั้งหมด']];
      if (this.has('logs.security')) list.push(['security', 'ความปลอดภัย']);
      if (this.has('archives.manage') || this.has('archives.download')) list.push(['archive', 'Archive']);
      list.push(['access', 'สิทธิ์ผู้ดู Log']);
      return list;
    }

    renderSeg() {
      const seg = document.getElementById('logsSeg');
      seg.innerHTML = this.subs().map(([id, label]) =>
        `<button type="button" class="seg-btn${id === this.sub ? ' on' : ''}" role="tab" aria-selected="${id === this.sub}" data-sub="${id}">${esc(label)}</button>`
      ).join('');
      if (!seg.dataset.bound) {
        seg.dataset.bound = '1';
        seg.addEventListener('click', (e) => {
          const b = e.target.closest('[data-sub]');
          if (b) this.show(b.dataset.sub);
        });
      }
    }

    show(sub) {
      if (!this.subs().some(([id]) => id === sub)) sub = 'overview';
      this.sub = sub;
      document.querySelectorAll('#logsSeg [data-sub]').forEach(b => {
        b.classList.toggle('on', b.dataset.sub === sub);
        b.setAttribute('aria-selected', String(b.dataset.sub === sub));
      });
      const body = document.getElementById('logsBody');
      body.innerHTML = '<div class="logs-loading">กำลังโหลด…</div>';
      if (sub === 'overview') return this.loadOverview();
      if (sub === 'all' || sub === 'security') return this.loadTableView(sub);
      if (sub === 'archive') return this.loadArchive();
      if (sub === 'access') return this.loadAccess();
    }

    fail(error, what) {
      const msg = error?.status === 403 ? 'คุณไม่มีสิทธิ์ดูส่วนนี้' : (error?.message || 'โหลดข้อมูลไม่สำเร็จ');
      document.getElementById('logsBody').innerHTML =
        `<div class="empty">${esc(what)}: ${esc(msg)}</div>`;
    }

    // ------------------------------------------------------------ ภาพรวม

    async loadOverview() {
      let data;
      try {
        data = (await logApi.summary(this.days)).data;
      } catch (e) { return this.fail(e, 'โหลดภาพรวมไม่สำเร็จ'); }
      const c = data.cards;
      const card = (label, value, tone = '', hint = '') =>
        `<div class="logs-stat ${tone}"><b>${value === null ? '—' : fmtNum.format(value)}</b><span>${esc(label)}</span>${hint ? `<small>${esc(hint)}</small>` : ''}</div>`;
      const range = `${this.days} วันล่าสุด`;
      const cards = [
        card('Log ทั้งหมดในฐานข้อมูล', c.total),
        card('กิจกรรมวันนี้', c.today),
        card('Login สำเร็จ', c.login_success, '', range),
        card('Login ไม่สำเร็จ', c.login_failed, c.login_failed ? 'warn' : '', range),
        card('เพิ่มข้อมูล', c.creates, '', range),
        card('แก้ไขข้อมูล', c.updates, '', range),
        card('ลบข้อมูล', c.deletes, '', range),
        card('System Error', c.system_errors, c.system_errors ? 'bad' : '', range),
      ];
      if (c.security_events !== null) cards.push(card('เหตุการณ์ความปลอดภัย', c.security_events, c.security_events ? 'warn' : '', range + ' (นับรวมครั้งที่ซ้ำ)'));

      const body = document.getElementById('logsBody');
      body.innerHTML = `
        ${archiveAlert(data.archive_alert)}
        <div class="logs-toolbar">
          <div class="seg logs-range" role="group" aria-label="ช่วงเวลา">
            <button type="button" class="seg-btn${this.days === 7 ? ' on' : ''}" data-days="7">7 วัน</button>
            <button type="button" class="seg-btn${this.days === 30 ? ' on' : ''}" data-days="30">30 วัน</button>
          </div>
        </div>
        <div class="logs-stats">${cards.join('')}</div>
        <div class="logs-charts">
          <section class="logs-chart"><h3>จำนวนกิจกรรมรายวัน</h3><div class="logs-chart-body" data-chart="daily"></div></section>
          <section class="logs-chart"><h3>แนวโน้ม Error รายวัน</h3><div class="logs-chart-body" data-chart="errors"></div></section>
          <section class="logs-chart"><h3>สัดส่วนประเภท Log</h3><div class="logs-chart-body" data-chart="categories"></div></section>
          <section class="logs-chart"><h3>กิจกรรมแยกตามผู้ใช้งาน (10 อันดับ)</h3><div class="logs-chart-body" data-chart="users"></div></section>
        </div>`;
      body.querySelector('.logs-range').addEventListener('click', (e) => {
        const b = e.target.closest('[data-days]');
        if (b && Number(b.dataset.days) !== this.days) { this.days = Number(b.dataset.days); this.loadOverview(); }
      });

      Charts.columns(body.querySelector('[data-chart="daily"]'), data.daily.map(d => ({ label: d.date, value: d.total })), { unit: 'รายการ' });
      Charts.columns(body.querySelector('[data-chart="errors"]'), data.daily.map(d => ({ label: d.date, value: d.errors })), { unit: 'รายการ', tone: 'bad' });
      const catTotal = data.categories.reduce((s, r) => s + r.count, 0) || 1;
      Charts.bars(body.querySelector('[data-chart="categories"]'), data.categories.map(r => ({
        label: CATEGORY[r.category] || r.category, value: r.count, note: `${Math.round(r.count * 100 / catTotal)}%`,
      })));
      Charts.bars(body.querySelector('[data-chart="users"]'), data.top_users.map(r => ({ label: r.username || r.user_id, value: r.count })));
    }

    // ------------------------------------------------------------ ตาราง (ประวัติทั้งหมด / ความปลอดภัย)

    viewState(view) {
      if (!this.state[view]) {
        this.state[view] = { q: '', category: '', user_id: '', module: '', status: '', severity: '', from: '', to: '',
          page: 1, per_page: 50, sort: 'created_at', dir: 'desc' };
      }
      return this.state[view];
    }

    query(view, extra = {}) {
      const s = { ...this.viewState(view), ...extra };
      const p = new URLSearchParams();
      Object.entries(s).forEach(([k, v]) => { if (v !== '' && v !== null && v !== undefined) p.set(k, String(v)); });
      if (view === 'security') p.set('view', 'security');
      return p.toString();
    }

    async loadTableView(view) {
      if (!this.options) {
        try { this.options = (await logApi.options()).data; } catch (e) { this.options = { modules: [], users: [] }; }
      }
      const s = this.viewState(view);
      const opt = (value, label, current) => `<option value="${esc(value)}"${value === current ? ' selected' : ''}>${esc(label)}</option>`;
      const cats = Object.entries(CATEGORY).filter(([k]) => k !== 'security' || this.has('logs.security'));
      const body = document.getElementById('logsBody');
      body.innerHTML = `
        ${view === 'security' ? `<div class="logs-quick" role="group" aria-label="ตัวกรองด่วน">
          <button type="button" class="chip-btn" data-quick='{"action":"auth.login_failed"}'>Login ล้มเหลว</button>
          <button type="button" class="chip-btn" data-quick='{"action":"security.bruteforce_account"}'>ไล่เดารหัส (บัญชี)</button>
          <button type="button" class="chip-btn" data-quick='{"action":"security.forbidden"}'>เข้าถึงโดยไม่มีสิทธิ์</button>
          <button type="button" class="chip-btn" data-quick='{"action":"security.permission_change"}'>เปลี่ยนสิทธิ์ผู้ใช้</button>
          <button type="button" class="chip-btn" data-quick='{"severity":"CRITICAL"}'>ระดับวิกฤต</button>
          <button type="button" class="chip-btn" data-quick='{}'>ทั้งหมด</button>
        </div>` : ''}
        <form class="logs-filters" autocomplete="off">
          <label class="logs-f logs-f-q"><span>ค้นหา</span><input type="search" name="q" value="${esc(s.q)}" maxlength="100" placeholder="รายละเอียด, กิจกรรม, ผู้ใช้, IP, Event ID"></label>
          ${view === 'all' ? `<label class="logs-f"><span>ประเภท Log</span><select name="category">${opt('', 'ทั้งหมด', s.category)}${cats.map(([k, v]) => opt(k, v, s.category)).join('')}</select></label>` : ''}
          <label class="logs-f"><span>ผู้ใช้งาน</span><select name="user_id">${opt('', 'ทั้งหมด', s.user_id)}${(this.options.users || []).map(u => opt(u.user_id, u.username || u.user_id, s.user_id)).join('')}</select></label>
          <label class="logs-f"><span>Module</span><select name="module">${opt('', 'ทั้งหมด', s.module)}${(this.options.modules || []).map(m => opt(m, m, s.module)).join('')}</select></label>
          <label class="logs-f"><span>สถานะ</span><select name="status">${opt('', 'ทั้งหมด', s.status)}${Object.entries(STATUS).map(([k, [v]]) => opt(k, v, s.status)).join('')}</select></label>
          <label class="logs-f"><span>ระดับ</span><select name="severity">${opt('', 'ทั้งหมด', s.severity)}${Object.entries(SEVERITY).map(([k, [v]]) => opt(k, v, s.severity)).join('')}</select></label>
          <label class="logs-f"><span>ตั้งแต่วันที่</span><input type="date" name="from" value="${esc(s.from)}" max="${todayTH()}"></label>
          <label class="logs-f"><span>ถึงวันที่</span><input type="date" name="to" value="${esc(s.to)}" max="${todayTH()}"></label>
          <div class="logs-f-actions">
            <button type="submit" class="small-btn">ค้นหา</button>
            <button type="button" class="small-btn gray" data-act="reset">ล้างตัวกรอง</button>
            ${this.has('logs.export') ? '<button type="button" class="small-btn gray" data-act="export">ส่งออก CSV</button>' : ''}
          </div>
        </form>
        <div class="logs-result" data-view="${view}"><div class="logs-loading">กำลังโหลด…</div></div>`;

      const form = body.querySelector('.logs-filters');
      form.addEventListener('submit', (e) => {
        e.preventDefault();
        const fd = new FormData(form);
        ['q', 'category', 'user_id', 'module', 'status', 'severity', 'from', 'to'].forEach(k => { if (fd.has(k)) s[k] = String(fd.get(k) || '').trim(); });
        if (s.from && s.to && s.from > s.to) { window.toast.warning('วันที่เริ่มต้องไม่อยู่หลังวันที่สิ้นสุด'); return; }
        s.page = 1;
        this.loadRows(view);
      });
      form.querySelector('[data-act="reset"]').addEventListener('click', () => {
        delete this.state[view];
        this.loadTableView(view);
      });
      form.querySelector('[data-act="export"]')?.addEventListener('click', (e) => this.exportCsv(view, e.currentTarget));
      body.querySelector('.logs-quick')?.addEventListener('click', (e) => {
        const b = e.target.closest('[data-quick]');
        if (!b) return;
        const q = JSON.parse(b.dataset.quick);
        Object.assign(s, { action: q.action || '', severity: q.severity || '', page: 1 });
        body.querySelectorAll('.logs-quick .chip-btn').forEach(x => x.classList.toggle('on', x === b));
        this.loadRows(view);
      });
      this.loadRows(view);
    }

    async loadRows(view) {
      const box = document.querySelector(`.logs-result[data-view="${view}"]`);
      if (!box) return;
      const s = this.viewState(view);
      box.classList.add('is-loading');
      let res;
      try {
        res = (await logApi.list(this.query(view))).data;
      } catch (e) {
        box.classList.remove('is-loading');
        box.innerHTML = `<div class="empty">${esc(e?.message || 'โหลดข้อมูลไม่สำเร็จ')}</div>`;
        return;
      }
      box.classList.remove('is-loading');
      const pages = Math.max(1, Math.ceil(res.total / res.per_page));
      if (s.page > pages) { s.page = pages; }

      const head = COLUMNS.map(([key, label]) => {
        if (!key) return `<th scope="col">${esc(label)}</th>`;
        const active = s.sort === key;
        const aria = active ? (s.dir === 'asc' ? 'ascending' : 'descending') : 'none';
        return `<th scope="col" aria-sort="${aria}"><button type="button" class="logs-sort${active ? ' on' : ''}" data-sort="${key}">${esc(label)}<span aria-hidden="true">${active ? (s.dir === 'asc' ? ' ▲' : ' ▼') : ''}</span></button></th>`;
      }).join('');

      const rows = res.rows.map(r => `
        <tr data-id="${esc(r.event_id)}" tabindex="0" class="sev-${esc(r.severity.toLowerCase())}">
          <td data-label="วันที่ / เวลา"><span class="logs-time">${esc(when(r.created_at))}</span>${r.repeat_count > 1 ? `<small class="logs-repeat">ซ้ำ ${fmtNum.format(r.repeat_count)} ครั้ง</small>` : ''}</td>
          <td data-label="ผู้ใช้งาน">${r.username ? esc(r.username) : '<span class="muted">ไม่ระบุตัวตน</span>'}</td>
          <td data-label="กิจกรรม"><span class="logs-cat">${esc(CATEGORY[r.category] || r.category)}</span><code>${esc(r.action)}</code></td>
          <td data-label="Module">${esc(r.module)}</td>
          <td data-label="รายละเอียด" class="logs-desc">${esc(r.description || '—')}</td>
          <td data-label="IP Address"><code>${esc(r.ip_address || '—')}</code></td>
          <td data-label="สถานะ">${chip(STATUS, r.status)}${r.severity !== 'INFO' ? ' ' + chip(SEVERITY, r.severity) : ''}</td>
        </tr>`).join('');

      const from = res.total ? (res.page - 1) * res.per_page + 1 : 0;
      const to = Math.min(res.total, res.page * res.per_page);
      box.innerHTML = `
        <div class="logs-count">${!res.total ? 'ไม่พบรายการตามเงื่อนไข'
          : res.total_capped ? `แสดง ${fmtNum.format(from)}–${fmtNum.format(to)} จากมากกว่า ${fmtNum.format(res.total)} รายการ — เลือกช่วงวันที่หรือตัวกรองเพื่อให้แคบลง`
          : `แสดง ${fmtNum.format(from)}–${fmtNum.format(to)} จาก ${fmtNum.format(res.total)} รายการ`}</div>
        ${res.total ? `<div class="logs-table-wrap"><table class="logs-table">
          <caption class="sr-only">ประวัติ Log — แตะแถวเพื่อดูรายละเอียด</caption>
          <thead><tr>${head}</tr></thead><tbody>${rows}</tbody></table></div>` : ''}
        <nav class="logs-pager" aria-label="เปลี่ยนหน้า">
          <button type="button" class="small-btn gray" data-page="${res.page - 1}"${res.page <= 1 ? ' disabled' : ''}>‹ ก่อนหน้า</button>
          <span>หน้า ${fmtNum.format(res.page)} / ${fmtNum.format(pages)}</span>
          <button type="button" class="small-btn gray" data-page="${res.page + 1}"${res.page >= pages ? ' disabled' : ''}>ถัดไป ›</button>
          <label class="logs-perpage">แถวต่อหน้า <select>${[20, 50, 100].map(n => `<option${n === res.per_page ? ' selected' : ''}>${n}</option>`).join('')}</select></label>
        </nav>`;

      box.querySelector('thead')?.addEventListener('click', (e) => {
        const b = e.target.closest('[data-sort]');
        if (!b) return;
        if (s.sort === b.dataset.sort) s.dir = s.dir === 'asc' ? 'desc' : 'asc';
        else { s.sort = b.dataset.sort; s.dir = b.dataset.sort === 'created_at' ? 'desc' : 'asc'; }
        s.page = 1;
        this.loadRows(view);
      });
      const open = (e) => {
        const tr = e.target.closest('tr[data-id]');
        if (tr && (e.type === 'click' || e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); this.openDetail(tr.dataset.id); }
      };
      box.querySelector('tbody')?.addEventListener('click', open);
      box.querySelector('tbody')?.addEventListener('keydown', open);
      box.querySelector('.logs-pager').addEventListener('click', (e) => {
        const b = e.target.closest('[data-page]');
        if (b && !b.disabled) { s.page = Number(b.dataset.page); this.loadRows(view); box.scrollIntoView({ block: 'start', behavior: 'smooth' }); }
      });
      box.querySelector('.logs-perpage select').addEventListener('change', (e) => { s.per_page = Number(e.target.value); s.page = 1; this.loadRows(view); });
    }

    async exportCsv(view, btn) {
      UI.setLoading(btn, true);
      try {
        const base = document.querySelector('meta[name="api-base"]')?.content || '/api';
        const qs = this.query(view, { page: '', per_page: '', sort: '', dir: '' });
        const res = await fetch(`${base}/logs/export?${qs}`, { credentials: 'same-origin', cache: 'no-store' });
        if (!res.ok) {
          let msg = 'ส่งออกไม่สำเร็จ';
          try { msg = (await res.json()).error || msg; } catch (_) { /* ไม่ใช่ JSON */ }
          throw new Error(msg);
        }
        const blob = await res.blob();
        const name = /filename="([^"]+)"/.exec(res.headers.get('Content-Disposition') || '')?.[1] || 'logs.csv';
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = name;
        document.body.appendChild(a);
        a.click();
        setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
        window.toast.success('ส่งออก CSV แล้ว (การส่งออกถูกบันทึกไว้ใน Log)');
      } catch (e) {
        window.toast.error(e.message || 'ส่งออกไม่สำเร็จ');
      } finally {
        UI.setLoading(btn, false);
      }
    }

    // ------------------------------------------------------------ รายละเอียด

    async openDetail(eventId) {
      let r;
      try { r = (await logApi.get(eventId)).data; } catch (e) { window.toast.error(e.message || 'เปิดรายละเอียดไม่สำเร็จ'); return; }
      const row = (label, value, raw = false) => `<div class="logs-kv"><dt>${esc(label)}</dt><dd>${raw ? value : esc(value ?? '—')}</dd></div>`;
      const who = r.username ? `${r.username}${r.user_role ? ` (${r.user_role})` : ''}` : 'ไม่ระบุตัวตน';
      let changes = '';
      if (r.has_changes) {
        changes = r.can_view_changes
          ? `<h4>ข้อมูลก่อนและหลังการเปลี่ยนแปลง</h4>${diffTable(r.old_value, r.new_value)}`
          : '<p class="logs-note">มีข้อมูลก่อน/หลังแก้ไข แต่คุณไม่มีสิทธิ์ "ดูข้อมูลก่อน/หลังแก้ไข"</p>';
      }
      const ctx = r.context && Object.keys(r.context).length
        ? `<h4>ข้อมูลประกอบ</h4><pre class="logs-pre">${esc(JSON.stringify(r.context, null, 2))}</pre>` : '';
      const html = `
        <dl class="logs-detail">
          ${row('Event ID', `<code>${esc(r.event_id)}</code>`, true)}
          ${row('ผู้ดำเนินการ', who)}
          ${row('วันที่และเวลา', when(r.created_at) + (r.repeat_count > 1 ? ` — ซ้ำ ${r.repeat_count} ครั้ง ถึง ${when(r.last_seen_at)}` : ''))}
          ${row('ประเภท', CATEGORY[r.category] || r.category)}
          ${row('กิจกรรม', `<code>${esc(r.action)}</code>`, true)}
          ${row('Module', r.module)}
          ${row('Record', [r.record_type, r.record_id].filter(Boolean).join(' · ') || '—')}
          ${row('IP Address', r.ip_address)}
          ${row('Browser / Device', r.user_agent)}
          ${row('คำขอ', [r.http_method, r.path, r.http_status ? `HTTP ${r.http_status}` : ''].filter(Boolean).join(' ') || '—')}
          ${row('สถานะ', chip(STATUS, r.status), true)}
          ${row('ระดับ', chip(SEVERITY, r.severity), true)}
          ${row('รายละเอียด', r.description)}
          ${r.chain_seq ? row('Hash chain', `ลำดับที่ ${r.chain_seq} · <code class="logs-hash">${esc((r.row_hash || '').slice(0, 16))}…</code>`, true) : ''}
        </dl>
        ${changes}${ctx}`;
      const modal = new Modal({ title: 'รายละเอียด Log', content: html, size: 'xl', onClose: () => setTimeout(() => modal.destroy(), 0) });
      modal.open();
      const cancel = modal.element.querySelector('.sheet-actions button');
      if (cancel) cancel.textContent = 'ปิด';
    }

    // ------------------------------------------------------------ Archive (ระบบย้าย Log เก่า — Phase 4)

    async loadArchive() {
      let data;
      try { data = (await logApi.archives()).data; } catch (e) { return this.fail(e, 'โหลดรายการ Archive ไม่สำเร็จ'); }
      const ARCH_STATUS = {
        purged: ['เก็บแล้ว', 'chip-ok'], backed_up: ['สำรองแล้ว รอลบจาก DB', 'chip-info'], verified: ['ตรวจแล้ว รอสำรอง', 'chip-info'],
        exporting: ['กำลังสร้าง', 'chip-pending'], failed: ['ล้มเหลว', 'chip-miss'], destroyed: ['ทำลายตามอายุแล้ว', 'chip-off'],
      };
      const RUN_STATUS = { success: ['สำเร็จ', 'chip-ok'], partial: ['สำเร็จบางส่วน', 'chip-warn'], failed: ['ล้มเหลว', 'chip-miss'], running: ['กำลังทำงาน', 'chip-info'], requested: ['รอคิว', 'chip-pending'] };
      const canRead = this.has('archives.download') || this.has('archives.manage');
      const usable = (f) => ['verified', 'backed_up', 'purged'].includes(f.status);
      const files = data.files.map(f => `
        <tr data-arch="${esc(f.id)}">
          <td data-label="เดือน">${esc(f.period)}</td>
          <td data-label="ประเภท">${esc(CATEGORY[f.category] || f.category)}</td>
          <td data-label="ช่วงวันที่">${f.from_at ? `${esc(when(f.from_at))}<br>– ${esc(when(f.to_at))}` : '—'}</td>
          <td data-label="จำนวน">${fmtNum.format(f.row_count)}</td>
          <td data-label="ขนาด">${f.size_bytes ? esc(bytes(f.size_bytes)) : '—'}</td>
          <td data-label="SHA-256"><code class="logs-hash" title="${esc(f.sha256 || '')}">${esc((f.sha256 || '—').slice(0, 12))}${f.sha256 ? '…' : ''}</code></td>
          <td data-label="สถานะ">${chip(ARCH_STATUS, f.status)}${f.error_message ? `<small class="logs-err">${esc(f.error_message)}</small>` : ''}</td>
          <td data-label="" class="logs-arch-act">${usable(f) ? `
            ${canRead && (f.category !== 'security' || this.has('logs.security')) ? '<button type="button" class="small-btn gray" data-arch-act="search">ค้นหา</button>' : ''}
            ${this.has('archives.manage') ? '<button type="button" class="small-btn gray" data-arch-act="verify">ตรวจ Hash</button>' : ''}
            ${this.has('archives.download') ? '<button type="button" class="small-btn gray" data-arch-act="download">ดาวน์โหลด</button>' : ''}` : ''}
          </td></tr>`).join('');
      const runs = data.runs.map(r => {
        let summary = '';
        try { const s = JSON.parse(r.summary || '{}'); summary = Object.entries(s.files || {}).map(([k, v]) => `${k}: ${v}`).join('\n'); } catch (_) { /* ข้อมูลเก่า */ }
        return `<li><span>${esc(when(r.started_at || r.requested_at))}</span> ${chip(RUN_STATUS, r.status)}
          ${summary ? `<details><summary>รายละเอียด</summary><pre class="logs-pre">${esc(summary)}</pre></details>` : ''}</li>`;
      }).join('');
      const disk = (d, label) => d && d.total
        ? `<div class="logs-stat${d.free / d.total < 0.1 ? ' bad' : ''}"><b class="logs-stat-text">${esc(bytes(d.free))} ว่าง</b><span>${esc(label)}</span><small>จาก ${esc(bytes(d.total))}${d.exists ? '' : ' · ยังไม่มีโฟลเดอร์'}</small></div>`
        : `<div class="logs-stat"><b class="logs-stat-text">—</b><span>${esc(label)}</span></div>`;

      const body = document.getElementById('logsBody');
      body.innerHTML = `
        ${archiveAlert(data.alert)}
        <div class="logs-stats">
          <div class="logs-stat"><b>${fmtNum.format(data.db.rows)}</b><span>Log ในฐานข้อมูลตอนนี้</span></div>
          <div class="logs-stat"><b class="logs-stat-text">${esc(when(data.db.oldest_at))}</b><span>Log ที่เก่าที่สุดในฐานข้อมูล</span></div>
          <div class="logs-stat"><b>${fmtNum.format(data.files.filter(usable).length)}</b><span>ไฟล์ Archive ที่ใช้งานได้</span><small>${esc(bytes(data.archived_bytes || 0))}</small></div>
          ${disk(data.disk?.main, 'พื้นที่ที่เก็บไฟล์หลัก')}
          ${disk(data.disk?.backup, 'พื้นที่ที่เก็บสำเนา')}
        </div>
        <p class="logs-note">นโยบาย: เก็บ Log ${fmtNum.format(data.policy.retention_days)} วันล่าสุดในฐานข้อมูล เดือนที่เก่ากว่านั้นย้ายเป็นไฟล์ JSON.GZ แยกตามประเภทและเดือน
          มี SHA-256 และสำเนาอีกที่ ไฟล์เก็บ ${fmtNum.format(data.policy.keep_years)} ปีแล้วทำลาย ระบบย้ายรันตามตารางเวลาของเครื่องเซิร์ฟเวอร์</p>
        ${files ? `<div class="logs-table-wrap"><table class="logs-table logs-table-plain logs-arch">
          <thead><tr><th>เดือน</th><th>ประเภท</th><th>ช่วงวันที่</th><th>จำนวน</th><th>ขนาด</th><th>SHA-256</th><th>สถานะ</th><th><span class="sr-only">คำสั่ง</span></th></tr></thead>
          <tbody>${files}</tbody></table></div>` : '<div class="empty">ยังไม่มีไฟล์ Archive</div>'}
        <div id="logsArchSearch"></div>
        <h3 class="logs-h3">ประวัติการทำ Archive</h3>
        ${runs ? `<ul class="logs-runs">${runs}</ul>` : '<div class="empty">ยังไม่เคยรัน</div>'}`;

      body.querySelector('.logs-arch')?.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-arch-act]');
        if (!btn) return;
        const f = data.files.find(x => String(x.id) === btn.closest('[data-arch]').dataset.arch);
        if (btn.dataset.archAct === 'search') return this.archiveSearchPanel(f, 1);
        if (btn.dataset.archAct === 'download') return this.download(`/logs/archives/${f.id}/download`, btn, 'ดาวน์โหลดไฟล์ archive แล้ว (ถูกบันทึกไว้ใน Log)');
        UI.setLoading(btn, true);
        try {
          const r = (await api.get(`/logs/archives/${f.id}/verify`)).data;
          const line = (k, label) => `${label}: ${r.copies[k].ok ? 'ถูกต้อง' : r.copies[k].message}`;
          await window.alertDialog(`${line('main', 'ไฟล์หลัก')}\n${line('backup', 'สำเนา')}\n\nSHA-256 ที่บันทึกไว้:\n${r.expected}`,
            { title: r.ok ? 'ตรวจ Hash ผ่านทั้งสองสำเนา' : 'พบปัญหาความถูกต้องของไฟล์' });
        } catch (err) {
          window.toast.error(err.message || 'ตรวจไม่สำเร็จ');
        } finally { UI.setLoading(btn, false); }
      });
    }

    /** ค้นหาใน archive 1 ไฟล์ — server อ่านไฟล์แบบ stream (ตรวจ hash ก่อนทุกครั้ง) */
    async archiveSearchPanel(file, page, filters = { q: '' }) {
      const box = document.getElementById('logsArchSearch');
      box.innerHTML = `<section class="logs-chart logs-arch-search">
        <h3>ค้นหาใน Archive: ${esc(CATEGORY[file.category] || file.category)} ${esc(file.period)}</h3>
        <form class="logs-filters" autocomplete="off">
          <label class="logs-f logs-f-q"><span>ค้นหา</span><input type="search" name="q" value="${esc(filters.q)}" maxlength="100" placeholder="รายละเอียด, กิจกรรม, ผู้ใช้, IP"></label>
          <div class="logs-f-actions"><button type="submit" class="small-btn">ค้นหา</button></div>
        </form>
        <div class="logs-result"><div class="logs-loading">กำลังอ่านไฟล์…</div></div></section>`;
      box.scrollIntoView({ block: 'start', behavior: 'smooth' });
      box.querySelector('form').addEventListener('submit', (e) => {
        e.preventDefault();
        this.archiveSearchPanel(file, 1, { q: String(new FormData(e.target).get('q') || '').trim() });
      });
      const out = box.querySelector('.logs-result');
      let res;
      try {
        res = (await api.get(`/logs/archives/${file.id}/search?` + new URLSearchParams({ q: filters.q, page }))).data;
      } catch (e) { out.innerHTML = `<div class="empty">${esc(e.message || 'อ่านไฟล์ไม่สำเร็จ')}</div>`; return; }
      const pages = Math.max(1, Math.ceil(res.total / res.per_page));
      this.archiveRows = res.rows;
      out.innerHTML = `
        <div class="logs-count">${res.total ? `พบ ${fmtNum.format(res.total)}${res.truncated ? '+' : ''} รายการ (อ่านจาก${res.source === 'main' ? 'ไฟล์หลัก' : 'สำเนา'})` : 'ไม่พบรายการ'}</div>
        ${res.rows.length ? `<div class="logs-table-wrap"><table class="logs-table"><thead><tr>
          <th>วันที่ / เวลา</th><th>ผู้ใช้งาน</th><th>กิจกรรม</th><th>รายละเอียด</th><th>สถานะ</th></tr></thead><tbody>
          ${res.rows.map((r, i) => `<tr data-idx="${i}" tabindex="0">
            <td data-label="วันที่ / เวลา"><span class="logs-time">${esc(when(r.created_at))}</span></td>
            <td data-label="ผู้ใช้งาน">${esc(r.username || 'ไม่ระบุตัวตน')}</td>
            <td data-label="กิจกรรม"><span class="logs-cat">${esc(CATEGORY[r.category] || r.category)}</span><code>${esc(r.action)}</code></td>
            <td data-label="รายละเอียด" class="logs-desc">${esc(r.description || '—')}</td>
            <td data-label="สถานะ">${chip(STATUS, r.status)}</td></tr>`).join('')}
          </tbody></table></div>
        <nav class="logs-pager"><button type="button" class="small-btn gray" data-p="${page - 1}"${page <= 1 ? ' disabled' : ''}>‹ ก่อนหน้า</button>
          <span>หน้า ${page} / ${pages}</span>
          <button type="button" class="small-btn gray" data-p="${page + 1}"${page >= pages ? ' disabled' : ''}>ถัดไป ›</button></nav>` : ''}`;
      out.querySelector('.logs-pager')?.addEventListener('click', (e) => {
        const b = e.target.closest('[data-p]');
        if (b && !b.disabled) this.archiveSearchPanel(file, Number(b.dataset.p), filters);
      });
      const open = (e) => {
        const tr = e.target.closest('tr[data-idx]');
        if (tr && (e.type === 'click' || e.key === 'Enter')) this.showArchivedRow(this.archiveRows[Number(tr.dataset.idx)], res.can_view_changes);
      };
      out.querySelector('tbody')?.addEventListener('click', open);
      out.querySelector('tbody')?.addEventListener('keydown', open);
    }

    showArchivedRow(r, canChanges) {
      const row = (label, value, raw = false) => `<div class="logs-kv"><dt>${esc(label)}</dt><dd>${raw ? value : esc(value ?? '—')}</dd></div>`;
      const changes = r.has_changes ? (canChanges ? `<h4>ข้อมูลก่อนและหลังการเปลี่ยนแปลง</h4>${diffTable(r.old_value, r.new_value)}`
        : '<p class="logs-note">มีข้อมูลก่อน/หลังแก้ไข แต่คุณไม่มีสิทธิ์ "ดูข้อมูลก่อน/หลังแก้ไข"</p>') : '';
      const html = `<p class="logs-note">รายการนี้อ่านจากไฟล์ archive (ไม่อยู่ในฐานข้อมูลแล้ว)</p><dl class="logs-detail">
        ${row('Event ID', `<code>${esc(r.event_id)}</code>`, true)}${row('ผู้ดำเนินการ', r.username || 'ไม่ระบุตัวตน')}
        ${row('วันที่และเวลา', when(r.created_at))}${row('กิจกรรม', `<code>${esc(r.action)}</code>`, true)}${row('Module', r.module)}
        ${row('Record', [r.record_type, r.record_id].filter(Boolean).join(' · ') || '—')}${row('IP Address', r.ip_address)}
        ${row('Browser / Device', r.user_agent)}${row('สถานะ', chip(STATUS, r.status), true)}${row('ระดับ', chip(SEVERITY, r.severity), true)}
        ${row('รายละเอียด', r.description)}</dl>${changes}`;
      const modal = new Modal({ title: 'รายละเอียด Log (Archive)', content: html, size: 'xl', onClose: () => setTimeout(() => modal.destroy(), 0) });
      modal.open();
      const cancel = modal.element.querySelector('.sheet-actions button');
      if (cancel) cancel.textContent = 'ปิด';
    }

    /** ดาวน์โหลดไฟล์จาก API (GET + session cookie) — ผิดพลาดแสดงข้อความจาก server */
    async download(endpoint, btn, okMessage) {
      UI.setLoading(btn, true);
      try {
        const base = document.querySelector('meta[name="api-base"]')?.content || '/api';
        const res = await fetch(base + endpoint, { credentials: 'same-origin', cache: 'no-store' });
        if (!res.ok) {
          let msg = 'ดาวน์โหลดไม่สำเร็จ';
          try { msg = (await res.json()).error || msg; } catch (_) { /* ไม่ใช่ JSON */ }
          throw new Error(msg);
        }
        const blob = await res.blob();
        const name = /filename="([^"]+)"/.exec(res.headers.get('Content-Disposition') || '')?.[1] || 'download';
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = name;
        document.body.appendChild(a);
        a.click();
        setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
        window.toast.success(okMessage);
      } catch (e) {
        window.toast.error(e.message || 'ดาวน์โหลดไม่สำเร็จ');
      } finally {
        UI.setLoading(btn, false);
      }
    }

    // ------------------------------------------------------------ สิทธิ์ผู้ดู Log

    async loadAccess() {
      let data;
      try { data = (await logApi.permissions()).data; } catch (e) { return this.fail(e, 'โหลดรายชื่อผู้ดูแลไม่สำเร็จ'); }
      const perms = Object.entries(data.labels);
      const mine = new Set(data.mine);
      const rows = data.users.map(u => {
        const self = u.id === data.me;
        const have = new Set(u.permissions);
        const boxes = perms.map(([p, label]) => {
          const disabled = self || !mine.has(p);
          return `<label class="logs-perm${disabled ? ' is-off' : ''}"><input type="checkbox" value="${esc(p)}"${have.has(p) ? ' checked' : ''}${disabled ? ' disabled' : ''}> ${esc(label)}</label>`;
        }).join('');
        return `<li class="logs-access-row" data-user="${esc(u.id)}">
          <div class="logs-access-who"><b>${esc(u.full_name)}</b><span>${esc(u.username)}${u.active ? '' : ' · ปิดใช้งาน'}${self ? ' · บัญชีของคุณ' : ''}</span></div>
          <div class="logs-access-perms">${boxes}</div>
          ${self ? '<small class="muted">แก้สิทธิ์ของตัวเองไม่ได้</small>' : '<button type="button" class="small-btn" data-save>บันทึกสิทธิ์</button>'}
        </li>`;
      }).join('');
      const body = document.getElementById('logsBody');
      body.innerHTML = `
        <p class="logs-note">ให้สิทธิ์ได้เฉพาะบัญชีผู้ดูแลระบบ และให้หรือถอนได้เฉพาะสิทธิ์ที่คุณถืออยู่เอง — ทุกการเปลี่ยนแปลงถูกบันทึกในหมวดความปลอดภัย</p>
        <ul class="logs-access">${rows || '<li class="empty">ไม่มีบัญชีผู้ดูแลระบบ</li>'}</ul>`;
      body.querySelector('.logs-access').addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-save]');
        if (!btn) return;
        const li = btn.closest('[data-user]');
        const chosen = [...li.querySelectorAll('input[type="checkbox"]:checked')].map(i => i.value);
        UI.setLoading(btn, true);
        try {
          const res = await logApi.setPermissions(li.dataset.user, chosen);
          const { added = [], removed = [] } = res.data || {};
          window.toast.success(added.length || removed.length ? 'บันทึกสิทธิ์แล้ว' : 'ไม่มีการเปลี่ยนแปลง');
        } catch (err) {
          window.toast.error(err.message || 'บันทึกสิทธิ์ไม่สำเร็จ');
        } finally {
          UI.setLoading(btn, false);
          this.loadAccess();
        }
      });
    }
  }

  // ------------------------------------------------------------ helpers

  /** แถบเตือนเมื่อ archive มีปัญหา — ระบบไม่มีอีเมล จึงเตือนในหน้าจอจนกว่าจะแก้ */
  function archiveAlert(a) {
    if (!a) return '';
    const parts = [];
    if (a.last_run_status && a.last_run_status !== 'success') parts.push(`รอบล่าสุด (${when(a.last_run_at)}) ${a.last_run_status === 'partial' ? 'สำเร็จบางส่วน' : 'ล้มเหลว'}`);
    if (a.failed_files) parts.push(`มีไฟล์ค้างสถานะล้มเหลว ${a.failed_files} ไฟล์`);
    return `<div class="logs-alert" role="alert"><b>Archive มีปัญหา:</b> ${esc(parts.join(' · '))} —
      ข้อมูลต้นฉบับยังอยู่ในฐานข้อมูลครบ ดูสาเหตุที่หมวด "ระบบ" (system.archive_failed) แล้วรันใหม่</div>`;
  }

  function bytes(n) {
    const u = ['B', 'KB', 'MB', 'GB'];
    let i = 0;
    while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
    return `${n.toFixed(i ? 1 : 0)} ${u[i]}`;
  }

  function show(v) {
    if (v === null || v === undefined || v === '') return '<span class="muted">—</span>';
    if (v === true) return 'ใช่';
    if (v === false) return 'ไม่ใช่';
    if (typeof v === 'object') return `<code class="logs-json">${esc(JSON.stringify(v))}</code>`;
    return esc(String(v));
  }

  /** ตารางเทียบก่อน/หลัง — แถวที่ค่าต่างกันเน้นสี (สร้าง/ลบ มีแค่ฝั่งเดียว) */
  function diffTable(oldV, newV) {
    const o = oldV || {};
    const n = newV || {};
    const keys = [...new Set([...Object.keys(o), ...Object.keys(n)])];
    if (!keys.length) return '<p class="logs-note">ไม่มีรายละเอียดฟิลด์</p>';
    const rows = keys.map(k => {
      const changed = JSON.stringify(o[k]) !== JSON.stringify(n[k]);
      return `<tr class="${changed ? 'is-changed' : ''}"><th scope="row">${esc(k)}</th>
        <td class="logs-old">${k in o ? show(o[k]) : '<span class="muted">(ไม่มี)</span>'}</td>
        <td class="logs-new">${k in n ? show(n[k]) : '<span class="muted">(ไม่มี)</span>'}</td></tr>`;
    }).join('');
    return `<div class="logs-table-wrap"><table class="logs-diff"><thead><tr><th scope="col">ฟิลด์</th><th scope="col">ก่อน</th><th scope="col">หลัง</th></tr></thead><tbody>${rows}</tbody></table></div>`;
  }

  /**
   * กราฟแบบเบา (SVG/HTML ล้วน ไม่มี library — CSP ไม่อนุญาตสคริปต์ภายนอก)
   * ทุกกราฟมี series เดียว: ชื่อกราฟบอกว่าแสดงอะไร ไม่ต้องมีคำอธิบายสี; ตัวเลขอยู่ใน tooltip/ป้ายกำกับ
   */
  const Charts = {
    columns(el, points, { unit = '', tone = '' } = {}) {
      if (!points.length) { el.innerHTML = '<div class="empty">ไม่มีข้อมูล</div>'; return; }
      const max = Math.max(1, ...points.map(p => p.value));
      // วาดตามความกว้างจริงของกล่อง (ไม่ยืด viewBox) — ตัวอักษรบนแกนจึงไม่เพี้ยนบนจอแคบ
      const W = Math.max(260, Math.round(el.clientWidth || 600)), H = 180, pad = { l: 34, r: 6, t: 10, b: 24 };
      el._chart = { points, opts: { unit, tone } };
      Charts.watch(el);
      const iw = W - pad.l - pad.r, ih = H - pad.t - pad.b;
      const slot = iw / points.length;
      const bw = Math.max(3, Math.min(28, slot - 2));       // ช่องว่างระหว่างแท่ง ≥ 2px
      const every = Math.ceil(points.length / 8);
      const y = (v) => pad.t + ih - (v / max) * ih;
      const bars = points.map((p, i) => {
        const x = pad.l + i * slot + (slot - bw) / 2;
        const top = y(p.value);
        const h = pad.t + ih - top;
        const r = Math.min(4, bw / 2, h);
        const label = `${fmtDay.format(new Date(p.label + 'T00:00:00Z'))}: ${fmtNum.format(p.value)} ${unit}`;
        // ปลายแท่งโค้ง 4px เฉพาะด้านบน ฐานเรียบติดแกน
        const shape = p.value > 0
          ? `<path class="logs-bar${tone ? ' ' + tone : ''}" d="M${x},${pad.t + ih} V${top + r} Q${x},${top} ${x + r},${top} H${x + bw - r} Q${x + bw},${top} ${x + bw},${top + r} V${pad.t + ih} Z"/>` : '';
        const tick = i % every === 0 || i === points.length - 1
          ? `<text class="logs-axis" x="${x + bw / 2}" y="${H - 6}" text-anchor="middle">${esc(fmtDay.format(new Date(p.label + 'T00:00:00Z')))}</text>` : '';
        return `<g class="logs-col" tabindex="0" role="img" aria-label="${esc(label)}" data-tip="${esc(label)}">
          <rect class="logs-hit" x="${pad.l + i * slot}" y="${pad.t}" width="${slot}" height="${ih}"/>${shape}${tick}</g>`;
      }).join('');
      el.innerHTML = `<div class="logs-svg-wrap"><svg viewBox="0 0 ${W} ${H}" width="${W}" height="${H}" class="logs-svg">
          <line class="logs-grid" x1="${pad.l}" x2="${W - pad.r}" y1="${pad.t}" y2="${pad.t}"/>
          <line class="logs-base" x1="${pad.l}" x2="${W - pad.r}" y1="${pad.t + ih}" y2="${pad.t + ih}"/>
          <text class="logs-axis" x="${pad.l - 6}" y="${pad.t + 4}" text-anchor="end">${esc(fmtNum.format(max))}</text>
          <text class="logs-axis" x="${pad.l - 6}" y="${pad.t + ih}" text-anchor="end">0</text>
          ${bars}</svg><div class="logs-tip" hidden></div></div>`;
      Charts.tooltip(el);
    },

    bars(el, items) {
      if (!items.length) { el.innerHTML = '<div class="empty">ไม่มีข้อมูล</div>'; return; }
      const max = Math.max(1, ...items.map(i => i.value));
      el.innerHTML = `<ul class="logs-hbars">${items.map(i => `
        <li><span class="logs-hbar-label" title="${esc(i.label)}">${esc(i.label)}</span>
          <span class="logs-hbar-track"><span class="logs-hbar" style="width:${Math.max(1, (i.value / max) * 100).toFixed(1)}%"></span></span>
          <span class="logs-hbar-value">${fmtNum.format(i.value)}${i.note ? ` <small>${esc(i.note)}</small>` : ''}</span></li>`).join('')}</ul>`;
    },

    /** วาดใหม่เมื่อความกว้างกล่องเปลี่ยน (หมุนจอ / ย่อหน้าต่าง) */
    watch(el) {
      if (el._watched || typeof ResizeObserver === 'undefined') return;
      el._watched = true;
      let last = el.clientWidth;
      const redraw = UI.debounce(() => {
        if (!el.isConnected || !el._chart || Math.abs(el.clientWidth - last) < 8) return;
        last = el.clientWidth;
        Charts.columns(el, el._chart.points, el._chart.opts);
      }, 150);
      new ResizeObserver(redraw).observe(el);
    },

    tooltip(el) {
      const wrap = el.querySelector('.logs-svg-wrap');
      const tip = wrap.querySelector('.logs-tip');
      const showTip = (g) => {
        const box = g.querySelector('.logs-hit').getBoundingClientRect();
        const outer = wrap.getBoundingClientRect();
        tip.textContent = g.dataset.tip;
        tip.hidden = false;
        const left = Math.min(Math.max(0, box.left - outer.left + box.width / 2 - tip.offsetWidth / 2), outer.width - tip.offsetWidth);
        tip.style.left = `${left}px`;
        g.classList.add('on');
      };
      const hide = (g) => { tip.hidden = true; g?.classList.remove('on'); };
      wrap.addEventListener('pointerover', (e) => { const g = e.target.closest('.logs-col'); if (g) showTip(g); });
      wrap.addEventListener('pointerout', (e) => { const g = e.target.closest('.logs-col'); if (g) hide(g); });
      wrap.addEventListener('focusin', (e) => { const g = e.target.closest('.logs-col'); if (g) showTip(g); });
      wrap.addEventListener('focusout', (e) => { const g = e.target.closest('.logs-col'); if (g) hide(g); });
    },
  };

  const page = new LogsPage();
  window.logsPage = page;
  window.initLogs = () => page.init();
  window.addEventListener('auth:ready', (e) => page.checkAccess(e.detail));
})();
