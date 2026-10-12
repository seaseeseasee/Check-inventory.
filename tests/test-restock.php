<?php
declare(strict_types=1);
define('REFER_TEST_MODE',true);
require __DIR__.'/../public/api.php';
function check(bool $condition):void {if(!$condition)throw new RuntimeException('Test failed');}
$s=array_fill_keys(TABLES,[]);
$s['vehicles']=[['id'=>'v1','name'=>'รถทดสอบ A'],['id'=>'v2','name'=>'รถทดสอบ B']];
$s['nurses']=[['id'=>'TEST001','name'=>'บุคลากรสมมติ','dept'=>'TEST','phone'=>'','start'=>'','end'=>'','note'=>'','active'=>true]];
$s['items']=[['id'=>'i1','carId'=>'v1','name'=>'อุปกรณ์สมมติ','standard'=>'2','expire'=>'01/01/2570','isBattery'=>false,'active'=>true],['id'=>'i2','carId'=>'v2','name'=>'ถุงมือสมมติ','standard'=>'1 ชุด','expire'=>'02/02/2571','isBattery'=>false,'active'=>true]];
$c=['id'=>'c1','carId'=>'v1','car'=>'รถทดสอบ A','nurseId'=>'TEST001','userName'=>'บุคลากรสมมติ','shift'=>'เช้า','startedAt'=>'2026-10-12T08:00:00.000Z','completedAt'=>'2026-10-12T08:15:00.000Z','results'=>[array_merge($s['items'][0],['status'=>'ขาด','missing'=>'2','batteryLevel'=>null])]];
$s['checks']=[$c];$s['metadata']=[['id'=>'schema','version'=>5]];
function mkAct(string $type,string $at,array $lines=[],string $note=''):array{return ['type'=>$type,'at'=>$at,'by'=>'TEST001','byName'=>'บุคลากรสมมติ','note'=>$note,'lines'=>$lines];}
function mkRestock(array $over=[]):array{return array_merge(['id'=>'r1','no'=>'RT-20261012-001','carId'=>'v1','car'=>'รถทดสอบ A','checkId'=>'c1','createdBy'=>'TEST001','createdByName'=>'บุคลากรสมมติ','createdAt'=>'2026-10-12T09:00:00.000Z','reason'=>'ตรวจพบของขาด','note'=>'','status'=>'fulfilled','items'=>[['itemId'=>'i1','name'=>'อุปกรณ์สมมติ','standard'=>'2','actualQty'=>'0','qtyRequested'=>'2','qtyIssued'=>'2','qtyFilled'=>'2']],'actions'=>[mkAct('create','2026-10-12T09:00:00.000Z',[],'ตรวจพบของขาด'),mkAct('submit','2026-10-12T09:05:00.000Z'),mkAct('issue','2026-10-12T10:00:00.000Z',[['itemId'=>'i1','qty'=>'2']],'จ่ายจากคลัง'),mkAct('fill','2026-10-12T10:30:00.000Z',[['itemId'=>'i1','qty'=>'2']],'เติมลงรถ'),mkAct('fulfil','2026-10-12T10:35:00.000Z',[],'ครบแล้ว')]],$over);}
function expectReject(array $s):void{try{validateState($s);throw new RuntimeException('Accepted invalid restock state');}catch(InvalidArgumentException $e){}}
$s['restocks']=[mkRestock()];
validateState($s);
// สถานะ/การกระทำ
expectReject(array_merge($s,['restocks'=>[mkRestock(['status'=>'draft'])]])); // draft ต้องจบด้วย create เท่านั้น (ยังมี issue/fill/fulfil ต่อ)
expectReject(array_merge($s,['restocks'=>[mkRestock(['status'=>'submitted','actions'=>[mkAct('create','2026-10-12T09:00:00.000Z')]])]]));
expectReject(array_merge($s,['restocks'=>[mkRestock(['status'=>'draft','actions'=>[mkAct('create','2026-10-12T09:00:00.000Z'),mkAct('cancel','2026-10-12T09:01:00.000Z')]])]]])); // cancel ต้องมีเหตุผล
validateState(array_merge($s,['restocks'=>[mkRestock(['status'=>'draft','actions'=>[mkAct('create','2026-10-12T09:00:00.000Z'),mkAct('cancel','2026-10-12T09:01:00.000Z',[],'ยกเลิกเพราะขอซ้ำ')]])]]));
validateState(array_merge($s,['restocks'=>[mkRestock(['status'=>'partial','actions'=>[mkAct('create','2026-10-12T09:00:00.000Z'),mkAct('submit','2026-10-12T09:05:00.000Z'),mkAct('issue','2026-10-12T10:00:00.000Z',[['itemId'=>'i1','qty'=>'1']],'จ่ายบางส่วน')]])]]));
expectReject(array_merge($s,['restocks'=>[mkRestock(['actions'=>[mkAct('submit','2026-10-12T09:00:00.000Z')]])]])); // action แรกต้องเป็น create
// โครงสร้าง/FK
expectReject(array_merge($s,['restocks'=>[mkRestock(),mkRestock(['id'=>'r2','no'=>'RT-20261012-002'])]])); // id ซ้ำ
expectReject(array_merge($s,['restocks'=>[mkRestock(['carId'=>'nope'])]]));
expectReject(array_merge($s,['restocks'=>[mkRestock(['createdBy'=>'nope'])]]));
expectReject(array_merge($s,['restocks'=>[mkRestock(['actions'=>[mkAct('create','2026-10-12T09:00:00.000Z'),mkAct('submit','2026-10-12T09:05:00.000Z',[['itemId'=>'nope','qty'=>'1']])]])]])); // line อ้าง itemId ที่ไม่มี
expectReject(array_merge($s,['restocks'=>[mkRestock(['items'=>[['itemId'=>'i1','name'=>'อุปกรณ์สมมติ','standard'=>'2','actualQty'=>'','qtyRequested'=>str_repeat('9',101),'qtyIssued'=>'','qtyFilled'=>'']]])]]));
// กันคำขอซ้ำจากรอบตรวจเดียวกัน (checkId เดียวกัน ต้องมีได้คำขอเดียวที่ไม่ใช่ cancelled)
expectReject(array_merge($s,['restocks'=>[mkRestock(),mkRestock(['id'=>'r2','no'=>'RT-20261012-002','status'=>'draft','actions'=>[mkAct('create','2026-10-12T11:00:00.000Z')]])]]));
validateState(array_merge($s,['restocks'=>[mkRestock(),mkRestock(['id'=>'r2','no'=>'RT-20261012-002','status'=>'cancelled','actions'=>[mkAct('create','2026-10-12T11:00:00.000Z'),mkAct('cancel','2026-10-12T11:05:00.000Z',[],'ขอซ้ำ')]])]]));
// ไคลเอนต์เก่า (ไม่มีคีย์ restocks) ต้องผ่าน validation และไม่ถือว่าผิด
$oldClient=$s;unset($oldClient['restocks']);validateState($oldClient);
// SQLite: ตาราง restock ยังไม่มี + ข้อมูล restock ไม่ว่าง -> ปฏิเสธ, ข้อมูลว่าง -> ผ่าน
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE refer_vehicles(id TEXT PRIMARY KEY,name TEXT); CREATE TABLE refer_nurses(id TEXT PRIMARY KEY,name TEXT,department TEXT,phone TEXT,active INTEGER,start_text TEXT,end_text TEXT,note TEXT); CREATE TABLE refer_items(id TEXT PRIMARY KEY,car_id TEXT,name TEXT,standard TEXT,expiry_text TEXT,is_battery INTEGER,active INTEGER); CREATE TABLE refer_checks(id TEXT PRIMARY KEY,car_id TEXT,nurse_id TEXT,car_name TEXT,nurse_name TEXT,shift_name TEXT,started_at TEXT,completed_at TEXT); CREATE TABLE refer_drafts(id TEXT PRIMARY KEY,car_id TEXT,nurse_id TEXT,car_name TEXT,nurse_name TEXT,shift_name TEXT,started_at TEXT); CREATE TABLE refer_check_items(check_id TEXT,item_id TEXT,sort_order INTEGER,name TEXT,standard TEXT,expiry_text TEXT,is_battery INTEGER,status TEXT,detail TEXT,battery_level INTEGER); CREATE TABLE refer_draft_items(draft_id TEXT,item_id TEXT,sort_order INTEGER,name TEXT,standard TEXT,expiry_text TEXT,is_battery INTEGER,status TEXT,detail TEXT,battery_level INTEGER); CREATE TABLE refer_metadata(id TEXT PRIMARY KEY,payload TEXT);');
$db->beginTransaction();
try{writeState($db,$s);throw new RuntimeException('Accepted restock rows with tables missing');}catch(RuntimeException $e){check($e->getMessage()!=='Accepted restock rows with tables missing');$db->rollBack();}
$empty=$s;$empty['restocks']=[];
$db->beginTransaction();writeState($db,$empty);$db->commit();check(count(loadState($db)['restocks'])===0);
// SQLite: round-trip เต็มกระบวนการ
$db->exec('CREATE TABLE refer_restock_requests(id TEXT PRIMARY KEY,no TEXT,car_id TEXT,car_name TEXT,check_id TEXT,created_by TEXT,created_name TEXT,reason TEXT,note TEXT,status TEXT,created_at TEXT); CREATE TABLE refer_restock_items(request_id TEXT,item_id TEXT,sort_order INTEGER,name TEXT,standard TEXT,actual_qty TEXT,qty_requested TEXT,qty_issued TEXT,qty_filled TEXT); CREATE TABLE refer_restock_actions(request_id TEXT,seq INTEGER,type TEXT,at TEXT,by_id TEXT,by_name TEXT,note TEXT,lines TEXT);');
$db->beginTransaction();writeState($db,$s);$db->commit();
$got=loadState($db);validateState($got);
check(count($got['restocks'])===1);check($got['restocks'][0]['no']==='RT-20261012-001');check($got['restocks'][0]['status']==='fulfilled');
check(count($got['restocks'][0]['actions'])===5);check($got['restocks'][0]['actions'][2]['lines']===[['itemId'=>'i1','qty'=>'2']]);
check($got['restocks'][0]['items'][0]['qtyIssued']==='2');check($got['restocks'][0]['items'][0]['standard']==='2');
// ไคลเอนต์เก่า PUT ทั้ง state (ไม่มีคีย์ restocks) -> แถว restock เดิมต้องถูกเก็บไว้
$db->beginTransaction();writeState($db,$oldClient);$db->commit();
$kept=loadState($db);check(count($kept['restocks'])===1);check($kept['restocks'][0]['no']==='RT-20261012-001');
// rollback ต้องไม่เปลี่ยนแปลง
$db->beginTransaction();$mut=$s;$mut['restocks'][0]['no']='RT-CHANGED';writeState($db,$mut);$db->rollBack();
check(loadState($db)['restocks'][0]['no']==='RT-20261012-001');
echo "PASS: restock validation (lifecycle/status-consistency/cancel-note/FK/lines/limits/duplicate-check-id/legacy-client), SQLite round-trip, old-client row preservation, transaction rollback.\n";
