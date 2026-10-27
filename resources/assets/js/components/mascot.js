// ========================================
// COMPONENTS: Mascot (ปุ่มแมวช่วยเหลือ + แจ้งผลสำเร็จ)
// ========================================

class HelpCatWidget {
  constructor() {
    this.root = document.getElementById('helpCat');
    this.btn = document.getElementById('helpCatBtn');
    this.panel = document.getElementById('helpCatPanel');
    this.pinned = false;   // เปิดด้วยการคลิก → ไม่ปิดเองเมื่อเมาส์ออก
    if (this.root && this.btn && this.panel) this.bind();
  }

  bind() {
    this.btn.addEventListener('click', () => {
      this.pinned = !(this.pinned && this.isOpen());
      this.toggle(this.pinned);
    });
    document.getElementById('helpCatClose')?.addEventListener('click', () => this.close());

    // ชี้เมาส์ค้างเพื่อดูคำแนะนำ (เฉพาะอุปกรณ์ที่มีเมาส์ จอสัมผัสใช้การแตะ)
    if (window.matchMedia?.('(hover: hover)').matches) {
      this.root.addEventListener('mouseenter', () => { if (!this.isOpen()) this.toggle(true); });
      this.root.addEventListener('mouseleave', () => { if (!this.pinned) this.toggle(false); });
    }

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && this.isOpen()) { this.close(); this.btn.focus(); }
    });
    document.addEventListener('click', (e) => {
      if (this.isOpen() && !this.root.contains(e.target)) this.close();
    });
  }

  isOpen() {
    return !this.panel.hidden;
  }

  toggle(open) {
    this.panel.hidden = !open;
    this.btn.setAttribute('aria-expanded', String(open));
  }

  close() {
    this.pinned = false;
    this.toggle(false);
  }
}

// แสดงกล่องบันทึกสำเร็จพร้อมรูปแมว — ปิดด้วยปุ่มตกลง, Esc หรือแตะพื้นหลัง
function mascotSuccess(title, detail = '') {
  const overlay = document.getElementById('mascotSuccess');
  if (!overlay) {
    window.toast.success(title + (detail ? '\n' + detail : ''), 8000);
    return;
  }
  document.getElementById('mascotSuccessTitle').textContent = title;
  document.getElementById('mascotSuccessText').textContent = detail;
  const ok = document.getElementById('mascotSuccessOk');
  const close = () => {
    overlay.classList.add('hidden');
    document.removeEventListener('keydown', onKey);
  };
  const onKey = (e) => { if (e.key === 'Escape') close(); };
  ok.onclick = close;
  overlay.onclick = (e) => { if (e.target === overlay) close(); };
  document.addEventListener('keydown', onKey);
  overlay.classList.remove('hidden');
  ok.focus();
}

window.mascotSuccess = mascotSuccess;
document.addEventListener('DOMContentLoaded', () => { window.helpCat = new HelpCatWidget(); });
