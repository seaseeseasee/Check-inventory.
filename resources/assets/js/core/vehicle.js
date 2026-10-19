// ========================================
// CORE: VEHICLE REFERENCE (ทะเบียนรถ)
// ========================================
// จุดเดียวที่แปลง "รถ" (object หรือ car_id) เป็นข้อความสำหรับแสดงบนหน้าจอ
// เดิมทุกหน้า copy ตารางทะเบียนแบบ hardcode ไว้เอง (มีแค่ car-1..3) ทำให้รถที่
// เพิ่มทีหลังแสดงเป็นรหัส id เช่น car-ae70699ef685 หลุดขึ้นมาบนหน้าจอ

window.vehicleRef = (function () {
  // รถ 3 คันแรก: ฐานข้อมูลเดิมเก็บทะเบียนไว้ในคอลัมน์ name ส่วน plate เป็น NULL
  const LEGACY_PLATE = {
    'car-1': '3ชฏ3250',
    'car-2': 'ฆง8434',
    'car-3': '5กล1034',
  };

  const UNKNOWN_LABEL = 'รถไม่ทราบทะเบียน';

  const byId = new Map();
  let loading = null;
  let loaded = false;

  function text(value) {
    if (value === null || value === undefined) return '';
    return String(value).trim();
  }

  // เก็บแถวรถที่โหลดมาจาก API ไว้ใน cache กลาง (ทุกหน้าเรียกใช้ได้)
  function remember(vehicles) {
    if (!Array.isArray(vehicles)) return;
    for (const vehicle of vehicles) {
      const id = text(vehicle && vehicle.id);
      if (!id) continue;
      byId.set(id, {
        id: id,
        name: text(vehicle.name),
        plate: text(vehicle.plate),
        status: text(vehicle.status),
      });
    }
  }

  // โหลดทะเบียนรถทุกคันครั้งเดียว (หน้าที่ไม่ได้ fetch /api/vehicles เองต้องใช้)
  function ensure(force) {
    if (loaded && !force) return Promise.resolve(true);
    if (loading) return loading;
    if (!window.vehicleApi) return Promise.resolve(false);
    loading = window.vehicleApi.getAll()
      .then((res) => {
        remember(res && res.data);
        loaded = true;
        return true;
      })
      .catch((error) => {
        console.warn('[vehicleRef] โหลดทะเบียนรถไม่สำเร็จ', error);
        return false;
      })
      .then((ok) => {
        loading = null;
        return ok;
      });
    return loading;
  }

  // รับทั้ง object ({id, name, plate}) และ car_id plain string
  function rowFor(ref) {
    if (ref && typeof ref === 'object') {
      const id = text(ref.id);
      const hint = { id: id, name: text(ref.name), plate: text(ref.plate), status: text(ref.status) };
      const cached = id ? byId.get(id) : null;
      if (!cached) return hint;
      // object ที่หน้าส่งมาอาจมีแค่บางฟิลด์ (เช่น car_id + car_name) — ที่ว่างจึงใช้ของ cache แทน
      return {
        id: id || cached.id,
        name: hint.name || cached.name,
        plate: hint.plate || cached.plate,
        status: hint.status || cached.status,
      };
    }
    const id = text(ref);
    return byId.get(id) || { id: id, name: '', plate: '', status: '' };
  }

  // ลำดับการแสดงผล: plate จากฐานข้อมูล → ทะเบียนเดิมของ 3 คันแรก → name → ไม่แสดง id
  function plate(ref) {
    const row = rowFor(ref);
    if (row.plate) return row.plate;
    if (LEGACY_PLATE[row.id]) return LEGACY_PLATE[row.id];
    if (row.name) return row.name;
    console.warn('[vehicleRef] ไม่พบทะเบียนของรถ id:', row.id);
    return UNKNOWN_LABEL;
  }

  return { remember, ensure, rowFor, plate, isLoaded: () => loaded };
})();

// Global helper ที่ทุกหน้าเรียก: รับ object รถหรือ car_id ก็ได้
window.vehiclePlate = function (ref) {
  return window.vehicleRef.plate(ref);
};
