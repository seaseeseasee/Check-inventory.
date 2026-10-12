<?php
declare(strict_types=1);
define('REFER_TEST_MODE',true);
require __DIR__.'/../public/api.php';
function check(bool $condition):void {if(!$condition)throw new RuntimeException('Test failed');}
$s=array_fill_keys(TABLES,[]);
$s['vehicles']=[['id'=>'v1','name'=>'รถทดสอบ A'],['id'=>'v2','name'=>'รถทดสอบ B']];
$s['nurses']=[['id'=>'TEST001','name'=>'บุคลากรสมมติ','dept'=>'TEST','phone'=>'','start'=>'','end'=>'','note'=>'<script>test</script>','active'=>true]];
$s['items']=[['id'=>'i1','carId'=>'v1','name'=>'อุปกรณ์สมมติ','standard'=>'1 ชุด','expire'=>'01/01/2570','isBattery'=>false,'active'=>true],['id'=>'i2','carId'=>'v2','name'=>'อุปกรณ์สมมติ','standard'=>'2','expire'=>'02/02/2571','isBattery'=>false,'active'=>true]];
$c=['id'=>'c1','carId'=>'v1','car'=>'รถทดสอบ A','nurseId'=>'TEST001','userName'=>'บุคลากรสมมติ','shift'=>'เช้า','startedAt'=>'2026-09-12T08:00:00.000Z','completedAt'=>'2026-09-12T08:15:00.000Z','results'=>[array_merge($s['items'][0],['status'=>'Checked','missing'=>'','batteryLevel'=>null])]];
$s['checks']=[$c];$s['metadata']=[['id'=>'schema','version'=>4]];validateState($s);
$bad=$s;$bad['items'][0]['carId']='missing';try{validateState($bad);throw new RuntimeException('Accepted invalid FK');}catch(InvalidArgumentException $e){}
$bad=$s;$bad['checks'][0]['results'][0]['status']='ยังไม่ตรวจ';try{validateState($bad);throw new RuntimeException('Accepted incomplete check');}catch(InvalidArgumentException $e){}
$bad=$s;$bad['checks'][0]['results'][0]['isBattery']=true;try{validateState($bad);throw new RuntimeException('Accepted missing battery');}catch(InvalidArgumentException $e){}
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
// Column-equivalent SQLite fixture exercises PHP mapping; MySQL integration remains a separate deployment check.
$db->exec('CREATE TABLE refer_vehicles(id TEXT PRIMARY KEY,name TEXT); CREATE TABLE refer_nurses(id TEXT PRIMARY KEY,name TEXT,department TEXT,phone TEXT,active INTEGER,start_text TEXT,end_text TEXT,note TEXT); CREATE TABLE refer_items(id TEXT PRIMARY KEY,car_id TEXT,name TEXT,standard TEXT,expiry_text TEXT,is_battery INTEGER,active INTEGER); CREATE TABLE refer_checks(id TEXT PRIMARY KEY,car_id TEXT,nurse_id TEXT,car_name TEXT,nurse_name TEXT,shift_name TEXT,started_at TEXT,completed_at TEXT); CREATE TABLE refer_drafts(id TEXT PRIMARY KEY,car_id TEXT,nurse_id TEXT,car_name TEXT,nurse_name TEXT,shift_name TEXT,started_at TEXT); CREATE TABLE refer_check_items(check_id TEXT,item_id TEXT,sort_order INTEGER,name TEXT,standard TEXT,expiry_text TEXT,is_battery INTEGER,status TEXT,detail TEXT,battery_level INTEGER); CREATE TABLE refer_draft_items(draft_id TEXT,item_id TEXT,sort_order INTEGER,name TEXT,standard TEXT,expiry_text TEXT,is_battery INTEGER,status TEXT,detail TEXT,battery_level INTEGER); CREATE TABLE refer_metadata(id TEXT PRIMARY KEY,payload TEXT);');
$db->beginTransaction();writeState($db,$s);$db->commit();$got=loadState($db);validateState($got);check(count($got['checks'])===1);check($got['items'][1]['expire']==='02/02/2571');check($got['nurses'][0]['note']==='<script>test</script>');check($got['checks'][0]['results'][0]['standard']==='1 ชุด');
$draft=$c;unset($draft['completedAt']);$draft['id']='d1';$draft['carId']='v2';$s['drafts']=[$draft];validateState($s);$db->beginTransaction();writeState($db,$s);$db->rollBack();check(count(loadState($db)['drafts'])===0);
$db->beginTransaction();writeState($db,$s);$db->commit();check(count(loadState($db)['drafts'])===1);
echo "PASS: PHP validation, incomplete-check rejection, battery validation, Thai text, SQL column mapping, complete checks, draft round-trip, car-specific expiry, transaction rollback.\n";

