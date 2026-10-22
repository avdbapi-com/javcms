<?php
/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
namespace app\common\model;
use think\Db;
use think\Cache;
use app\common\util\VodDeletion;
use app\common\util\VodCleanupReferences;

/** Resumable metadata-only catalog cleanup. Never reads or deletes uploaded/static files. */
class VodCleanup
{
    private $schema=[];
    private static $cleanupDepth=0;
    const PHRASE='DELETE ALL VIDEOS';
    private function fields($name)
    {
        if (!array_key_exists($name,$this->schema)) {
            $table=Db::name($name)->getTable();
            $rows=Db::query('SELECT c.COLUMN_NAME,t.ENGINE FROM information_schema.COLUMNS c JOIN information_schema.TABLES t ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME WHERE c.TABLE_SCHEMA=DATABASE() AND c.TABLE_NAME=:name',['name'=>$table],true);
            if ($rows && strcasecmp($rows[0]['ENGINE'],'InnoDB')!==0) { throw new \RuntimeException('vod/cleanup_schema'); }
            $this->schema[$name]=array_column($rows,'COLUMN_NAME');
        }
        return $this->schema[$name];
    }
    private function optional($name,array $required)
    {
        $fields=$this->fields($name);
        if ($fields && array_diff($required,$fields)) { throw new \RuntimeException('vod/cleanup_schema'); }
        return (bool)$fields;
    }
    private function owner($owner)
    {
        if ((!is_int($owner)&&!is_string($owner)) || !preg_match('/^[1-9][0-9]*$/D',(string)$owner)) { throw new \RuntimeException('vod/cleanup_auth'); }
        return (int)$owner;
    }
    private function signingKey($owner)
    {
        $hash=Db::name('admin')->where('admin_id',$this->owner($owner))->value('admin_pwd');
        if (!is_string($hash) || $hash==='') { throw new \RuntimeException('vod/cleanup_auth'); }
        return hash('sha256',$hash.'|'.config('database.database').'|'.config('database.prefix').'|'.str_replace('\\','/',ROOT_PATH));
    }
    private function countScope($upper=null)
    {
        if (!$this->optional('vod',['vod_id','vod_recycle_time'])) { throw new \RuntimeException('vod/cleanup_schema'); }
        $q=Db::name('vod'); if ($upper!==null) { $q->where('vod_id','<=',$upper); }
        $r=$q->field('COUNT(*) AS videos,COALESCE(MAX(vod_id),0) AS upper_id,COALESCE(SUM(vod_recycle_time=0),0) AS active,COALESCE(SUM(vod_recycle_time<>0),0) AS recycled')->find();
        return array_map('intval',$r);
    }
    private function active()
    {
        if (!$this->optional('vod_cleanup_job',['job_id','admin_id','active_slot','state'])) { return null; }
        return Db::name('vod_cleanup_job')->where('active_slot',1)->find();
    }
    public static function withCleanupLock(callable $operation)
    {
        self::$cleanupDepth++;try{return VodDeletion::withCleanupLock($operation);}finally{self::$cleanupDepth--;}
    }
    public static function isCleanupContext(){return self::$cleanupDepth>0;}
    public static function assertWritesAllowed()
    {
        $m=new self(); if ($m->active()) { throw new \RuntimeException('vod/cleanup_busy'); }
    }
    public static function assertImportJobAllowed(array $job)
    {
        $options=is_array($job['options'] ?? null)?$job['options']:json_decode((string)($job['options'] ?? '{}'),true);
        // A cleanup job id (32 hex) seals the job. 1.2.0 also stores 'provider:<key>' on jobs of providers other than
        // 'avdb' (D-I14): 1.1.0 reads any value as sealed and never runs them; 1.2.0 runs them.
        $sealed=$options['_vod_cleanup_sealed'] ?? '';
        if (is_string($sealed) ? preg_match('/^[a-f0-9]{32}$/D',$sealed) : !empty($sealed)) { throw new \RuntimeException('vod/cleanup_import_sealed'); }
    }
    public function preview($owner)
    {
        $owner=$this->owner($owner); $counts=$this->countScope(); $now=time();
        $payload=['v'=>1,'owner'=>$owner,'counts'=>$counts,'iat'=>$now,'exp'=>$now+600,'nonce'=>bin2hex(random_bytes(16))];
        $body=rtrim(strtr(base64_encode(json_encode($payload)),'+/','-_'),'=');
        $ticket=$body.'.'.hash_hmac('sha256',$body,$this->signingKey($owner)); $active=$this->active();
        return ['ticket'=>$ticket,'expires_at'=>$payload['exp'],'confirmation_phrase'=>self::PHRASE,'counts'=>$counts,'images_kept'=>true,'irreversible'=>true,'busy'=>(bool)$active,'active_job'=>$active && (int)$active['admin_id']===$owner?$this->output($active):null];
    }
    private function ticket($owner,$ticket)
    {
        if (!is_string($ticket) || strlen($ticket)>2500 || !preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D',$ticket,$parts)) { throw new \RuntimeException('vod/cleanup_ticket'); }
        if (!hash_equals(hash_hmac('sha256',$parts[1],$this->signingKey($owner)),$parts[2])) { throw new \RuntimeException('vod/cleanup_ticket'); }
        $json=base64_decode(strtr($parts[1],'-_','+/'),true); $p=json_decode($json,true);
        if (!is_array($p)||($p['v']??null)!==1||($p['owner']??null)!==$owner||!isset($p['counts']['videos'],$p['counts']['upper_id'],$p['exp'],$p['nonce'])) { throw new \RuntimeException('vod/cleanup_ticket'); }
        if ((int)$p['exp']<time()) { throw new \RuntimeException('vod/cleanup_expired'); }
        return $p;
    }
    private function ensureSchema()
    {
        $prefix=(string)config('database.prefix'); if (!preg_match('/^[A-Za-z0-9_]{0,64}$/D',$prefix)) { throw new \RuntimeException('vod/cleanup_schema'); }
        if (!$this->optional('vod_cleanup_job',['job_id','admin_id','active_slot','state','phase','upper_id','item_upper','cursor','version','options','ticket_nonce'])) {
            $file=APP_PATH.'install/sql/vod-cleanup.sql'; if (!is_file($file)) { throw new \RuntimeException('vod/cleanup_schema'); }
            $sql=file_get_contents($file); if (substr_count($sql,'CREATE TABLE')!==1 || strpos($sql,'javcms_vod_cleanup_job')===false) { throw new \RuntimeException('vod/cleanup_schema'); }
            foreach(explode(';',str_replace('javcms_',$prefix,$sql)) as $statement){if(trim($statement)!==''){Db::execute($statement);}}
            unset($this->schema['vod_cleanup_job']);
        }
        $this->optional('vod_cleanup_job',['job_id','admin_id','active_slot','state','phase','upper_id','item_upper','cursor','version','options','ticket_nonce']);
    }
    private function rejectImports()
    {
        if(!$this->optional('avdb_import_job',['job_id','state','lock_until','lock_token'])){return;}
        $busy=Db::name('avdb_import_job')->where('state','in',AvdbImport::RUN_STATES)->whereOr(function($q){$q->where('lock_token','<>','')->where('lock_until','>',time());})->lock(true)->find();
        if($busy){throw new \RuntimeException('vod/cleanup_import_busy');}
    }
    /** $tombstones 'keep' (default: identities an administrator deleted earlier stay deleted) or 'forget' (IMP 9). */
    public function start($owner,$ticket,$phrase,$tombstones='keep')
    {
        $owner=$this->owner($owner); if($phrase!==self::PHRASE){throw new \RuntimeException('vod/cleanup_phrase');}$p=$this->ticket($owner,$ticket);
        if(!in_array($tombstones,['keep','forget'],true)){throw new \RuntimeException('vod/cleanup_ticket');}
        return self::withCleanupLock(function()use($owner,$p,$tombstones){
            $active=$this->active();if($active){$a=json_decode($active['options'],true);if((int)$active['admin_id']===$owner && ($a['nonce']??'')===$p['nonce']){return $this->output($active);}throw new \RuntimeException('vod/cleanup_busy');}
            if($this->optional('vod_cleanup_job',['ticket_nonce']) && Db::name('vod_cleanup_job')->where(['admin_id'=>$owner,'ticket_nonce'=>$p['nonce']])->find()){throw new \RuntimeException('vod/cleanup_ticket');}
            $current=$this->countScope((int)$p['counts']['upper_id']);if($current['videos']!==$p['counts']['videos']||$current['upper_id']!==$p['counts']['upper_id']){throw new \RuntimeException('vod/cleanup_changed');}if(!$current['videos']){throw new \RuntimeException('vod/cleanup_empty');}
            // Validate imports before DDL, then recheck under the job transaction.
            $this->rejectImports();$this->ensureSchema();Db::startTrans();
            try{$this->rejectImports();$id=bin2hex(random_bytes(16));$itemUpper=$this->optional('avdb_import_item',['item_id'])?(int)Db::name('avdb_import_item')->max('item_id'):0;
                $options=['nonce'=>$p['nonce'],'tombstones'=>$tombstones,'imports_cursor'=>'','maps_provider'=>'','maps_remote'=>0,'ref_cursor'=>0,'cache_rows'=>[],'cache_offset'=>0,'counts'=>$p['counts']];
                Db::name('vod_cleanup_job')->insert(['job_id'=>$id,'admin_id'=>$owner,'ticket_nonce'=>$p['nonce'],'active_slot'=>1,'state'=>'running','phase'=>'imports','upper_id'=>$current['upper_id'],'item_upper'=>$itemUpper,'cursor'=>0,'version'=>1,'total_videos'=>$current['videos'],'deleted_videos'=>0,'deleted_relations'=>0,'options'=>json_encode($options),'error'=>'','created_at'=>time(),'updated_at'=>time()]);Db::commit();
            }catch(\Throwable $e){Db::rollback();throw $e;}return $this->status($owner,$id);
        });
    }
    private function job($owner,$id,$lock=false)
    {
        $owner=$this->owner($owner);if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id)||!$this->optional('vod_cleanup_job',['job_id','admin_id','state'])){throw new \RuntimeException('vod/cleanup_job_missing');}
        $q=Db::name('vod_cleanup_job')->where(['job_id'=>$id,'admin_id'=>$owner]);if($lock){$q->lock(true);}$row=$q->find();if(!$row){throw new \RuntimeException('vod/cleanup_job_missing');}return $row;
    }
    private function output($j)
    {
        $o=json_decode($j['options'],true);return ['id'=>$j['job_id'],'job_id'=>$j['job_id'],'owner'=>(int)$j['admin_id'],'state'=>$j['state'],'phase'=>$j['phase'],'upper_id'=>(int)$j['upper_id'],'total_videos'=>(int)$j['total_videos'],'deleted_videos'=>(int)$j['deleted_videos'],'deleted_relations'=>(int)$j['deleted_relations'],'version'=>(int)$j['version'],'images_kept'=>true,'irreversible'=>true,'newer_videos_remaining'=>$o['newer_videos_remaining']??null,'scope_missing'=>$o['scope_missing']??0,'error'=>$j['error']];
    }
    public function status($owner,$id){return $this->output($this->job($owner,$id));}
    public function pause($owner,$id){return $this->control($owner,$id,'paused');}
    public function resume($owner,$id){return $this->control($owner,$id,'running');}
    public function cancel($owner,$id){return $this->control($owner,$id,'cancelled');}
    private function control($owner,$id,$state)
    {
        return self::withCleanupLock(function()use($owner,$id,$state){Db::startTrans();try{$j=$this->job($owner,$id,true);if(in_array($j['state'],['complete','cancelled'],true)){Db::commit();return $this->output($j);}$options=json_decode($j['options'],true);if($j['phase']==='cache'){$this->invalidateRows(array_slice($options['cache_rows'],(int)$options['cache_offset']));$options['cache_rows']=[];$options['cache_offset']=0;$j['phase']='videos';$j['options']=json_encode($options);}$update=['phase'=>$j['phase'],'options'=>$j['options'],'state'=>$state,'version'=>(int)$j['version']+1,'updated_at'=>time()];if($state==='cancelled'){$update['active_slot']=null;}$j=array_merge($j,$update);Db::name('vod_cleanup_job')->where('job_id',$id)->update($update);Db::commit();return $this->output($j);}catch(\Throwable $e){Db::rollback();throw $e;}});
    }
    public function step($owner,$id,$expectedVersion=null)
    {
        if($expectedVersion!==null && (!is_scalar($expectedVersion)||!preg_match('/^[1-9][0-9]*$/D',(string)$expectedVersion))){throw new \RuntimeException('vod/cleanup_ticket');}
        return self::withCleanupLock(function()use($owner,$id,$expectedVersion){Db::startTrans();try{$j=$this->job($owner,$id,true);if($j['state']!=='running'||($expectedVersion!==null&&(int)$expectedVersion!==(int)$j['version'])){Db::commit();return $this->output($j);}$o=json_decode($j['options'],true);if(!is_array($o)){throw new \RuntimeException('vod/cleanup_schema');}$this->runPhase($j,$o);$j['version']=(int)$j['version']+1;$j['updated_at']=time();$j['options']=json_encode($o,JSON_THROW_ON_ERROR);Db::name('vod_cleanup_job')->where('job_id',$id)->update(array_diff_key($j,['job_id'=>true]));Db::commit();return $this->output($j);}catch(\Throwable $e){Db::rollback();throw $e;}});
    }
    /** 1.2.0: the search table, edge copies, counts and the page map follow a finished cleanup (C1 markRebuild, C5 purgeRange, C4, C14). */
    private static function afterCleanup($upper)
    {
        // C14: every list page changes after a cleanup (ListPageMap::dirty from 0 = rebuild the page map)
        foreach ([['FtIndex','markRebuild',['cleanup']],['EdgeCache','purgeRange',[1,(int)$upper]],['ScaleCounts','markDirty',['cleanup']],['ListPageMap','dirty',[0,[]]]] as $c) {
            try { $class='app\\common\\util\\'.$c[0]; if (class_exists($class) && method_exists($class,$c[1])) { call_user_func_array([$class,$c[1]],$c[2]); } } catch (\Throwable $e) { error_log('[VodCleanup] '.$c[0].'::'.$c[1].': '.get_class($e)); }
        }
    }
    private function invalidateRows(array $rows)
    {
        $model=new Vod();foreach($rows as $row){$model->invalidateDetailCache($row);}$model->clearListCache();Cache::rm('vod_search_top_result_v2_50000');Cache::rm($GLOBALS['config']['app']['cache_flag'].'_data_count');
    }
    private function runPhase(array &$j,array &$o)
    {
        $upper=(int)$j['upper_id'];$limit=1000;
        if($j['phase']==='imports'){
            if(!$this->optional('avdb_import_job',['job_id','options','state','discovery_done','lock_token','lock_until'])){$j['phase']='items';return;}
            $rows=Db::name('avdb_import_job')->where('job_id','>',$o['imports_cursor'])->order('job_id')->limit(100)->lock(true)->select();foreach($rows as $row){if(in_array($row['state'],AvdbImport::RUN_STATES,true)||($row['lock_token']!==''&&(int)$row['lock_until']>time())){throw new \RuntimeException('vod/cleanup_import_busy');}$links=json_decode($row['options'],true);if(!is_array($links)){throw new \RuntimeException('vod/cleanup_schema');}$keep=[];foreach((array)($links['existing_links']??[]) as $remote=>$local){if(!is_scalar($local)||!preg_match('/^[1-9][0-9]*$/D',(string)$remote)||!preg_match('/^[1-9][0-9]*$/D',(string)$local)){throw new \RuntimeException('vod/cleanup_schema');}if((int)$local>$upper){$keep[$remote]=$local;}}$links['existing_links']=$keep;$links['_vod_cleanup_sealed']=$j['job_id'];Db::name('avdb_import_job')->where('job_id',$row['job_id'])->update(['discovery_done'=>1,'state'=>'cancelled','lock_token'=>'','lock_until'=>0,'options'=>json_encode($links),'error'=>'Video catalog cleanup stopped this import. Deleted history cannot be retried or undone; start a new import.']);$o['imports_cursor']=$row['job_id'];}if(count($rows)<100){$j['phase']='items';$j['cursor']=0;}return;
        }
        if($j['phase']==='items'){
            if(!$this->optional('avdb_import_item',['item_id','job_id','remote_id','vod_id'])){$j['phase']='maps';$j['cursor']=0;return;}
            $rows=Db::name('avdb_import_item')->where('item_id','>',(int)$j['cursor'])->where('item_id','<=',(int)$j['item_upper'])->field('item_id,job_id,remote_id,vod_id')->order('item_id')->limit($limit)->lock(true)->select();$delete=[];$linksCache=[];$hasMaps=$this->optional('avdb_import_map',['provider','remote_id','vod_id']);$byRemote=[];$jobProvider=[];if($rows && $hasMaps){$hasProvider=in_array('provider',$this->fields('avdb_import_job'),true);foreach(array_unique(array_column($rows,'job_id')) as $jid){$jobProvider[$jid]=$hasProvider?(string)(Db::name('avdb_import_job')->where('job_id',$jid)->value('provider')?:'avdb'):'avdb';}foreach(Db::name('avdb_import_map')->where('remote_id','in',array_column($rows,'remote_id'))->field('provider,remote_id,vod_id')->select() as $map){$byRemote[$map['provider'].':'.(int)$map['remote_id']]=(int)$map['vod_id'];}}
            foreach($rows as $row){$j['cursor']=(int)$row['item_id'];$local=(int)$row['vod_id'];if($local>$upper){continue;}if(!$local){$mapped=$byRemote[($jobProvider[$row['job_id']]??'avdb').':'.(int)$row['remote_id']]??0;if($mapped>$upper){continue;}if(!isset($linksCache[$row['job_id']])){$job=$this->optional('avdb_import_job',['job_id','options'])?Db::name('avdb_import_job')->where('job_id',$row['job_id'])->field('options')->find():null;$linksCache[$row['job_id']]=$job?(array)(json_decode($job['options'],true)['existing_links']??[]):[];}if((int)($linksCache[$row['job_id']][$row['remote_id']]??0)>$upper){continue;}}$delete[]=(int)$row['item_id'];}
            if($delete){$j['deleted_relations']+=(int)Db::name('avdb_import_item')->where('item_id','in',$delete)->delete();}if(count($rows)<$limit){$j['phase']='maps';$j['cursor']=0;}return;
        }
        if($j['phase']==='maps'){
            if(!$this->optional('avdb_import_map',['provider','remote_id','vod_id'])){$j['phase']='videos';return;}
            $hasState=in_array('state',$this->fields('avdb_import_map'),true);$forget=($o['tombstones']??'keep')==='forget';
            $rows=Db::name('avdb_import_map')->where(function($q)use($o){$q->where('provider','>',$o['maps_provider'])->whereOr(function($s)use($o){$s->where('provider',$o['maps_provider'])->where('remote_id','>',$o['maps_remote']);});})->field('provider,remote_id,vod_id'.($hasState?',state':''))->order('provider,remote_id')->limit($limit)->lock(true)->select();
            $zero=[];foreach($rows as $row){if(!(int)$row['vod_id']&&(int)($row['state']??0)!==2){$zero[]=(int)$row['remote_id'];}}$retained=[];if($zero&&$this->optional('avdb_import_item',['remote_id'])){$retained=array_fill_keys(array_map('intval',Db::name('avdb_import_item')->where('remote_id','in',$zero)->column('remote_id')),true);}$targets=[];
            foreach($rows as $row){$o['maps_provider']=$row['provider'];$o['maps_remote']=(int)$row['remote_id'];$target=(int)$row['vod_id']>0&&(int)$row['vod_id']<=$upper;if(!(int)$row['vod_id']){$target=(int)($row['state']??0)===2?$forget:!isset($retained[(int)$row['remote_id']]);}if($target){$targets[$row['provider']][]=(int)$row['remote_id'];}}foreach($targets as $provider=>$remoteIds){$j['deleted_relations']+=(int)Db::name('avdb_import_map')->where('provider',$provider)->where('remote_id','in',$remoteIds)->delete();}if(count($rows)<$limit){$j['phase']='videos';$j['cursor']=0;}return;
        }
        if($j['phase']==='videos'){
            $rows=Db::name('vod')->where('vod_id','>',(int)$j['cursor'])->where('vod_id','<=',$upper)->field('vod_id,vod_en,vod_sub')->order('vod_id')->limit($limit)->lock(true)->select();$ids=array_map('intval',array_column($rows,'vod_id'));if(!$ids){$j['phase']='relations';$o['relation_index']=0;return;}
            foreach(['avdb_translation','translate','translate_lang','vod_translation'] as $table){if($this->optional($table,['vod_id'])){$j['deleted_relations']+=(int)Db::name($table)->where('vod_id','in',$ids)->delete();}}
            foreach(['comment'=>['comment_mid','comment_rid'],'ulog'=>['ulog_mid','ulog_rid']] as $table=>$columns){if($this->optional($table,$columns)){$j['deleted_relations']+=(int)Db::name($table)->where($columns[0],1)->where($columns[1],'in',$ids)->delete();}}if($this->optional('role',['role_rid'])){$j['deleted_relations']+=(int)Db::name('role')->where('role_rid','in',$ids)->delete();}
            $j['deleted_videos']+=(int)Db::name('vod')->where('vod_id','in',$ids)->delete();$j['cursor']=max($ids);$o['cache_rows']=$rows;$o['cache_offset']=0;$j['phase']='cache';return;
        }
        if($j['phase']==='relations'){
            $relations=[['avdb_translation','vod_id',null],['translate','vod_id',null],['translate_lang','vod_id',null],['vod_translation','vod_id',null],['comment','comment_rid','comment_mid'],['ulog','ulog_rid','ulog_mid'],['role','role_rid',null]];
            $index=(int)($o['relation_index']??0);if($index>=count($relations)){$j['phase']='topic';$o['ref_cursor']=0;return;}[$table,$field,$mid]=$relations[$index];$required=[$field];if($mid){$required[]=$mid;}if(!$this->optional($table,$required)){$o['relation_index']=$index+1;return;}$q=Db::name($table)->where($field,'>',0)->where($field,'<=',$upper);if($mid){$q->where($mid,1);}$n=(int)$q->limit(1000)->delete();$j['deleted_relations']+=$n;if($n===0){$o['relation_index']=$index+1;}return;
        }
        if($j['phase']==='cache'){
            $rows=array_slice($o['cache_rows'],(int)$o['cache_offset'],1000);$this->invalidateRows($rows);$o['cache_offset']+=count($rows);if($o['cache_offset']>=count($o['cache_rows'])){$o['cache_rows']=[];$o['cache_offset']=0;$j['phase']='videos';}return;
        }
        if(in_array($j['phase'],['topic','art','vod','search'],true)){
            $r=VodCleanupReferences::step($j['phase'],$o['ref_cursor'],$upper,100);$o['ref_cursor']=$r['next_cursor'];$j['deleted_relations']+=(int)$r['rows_changed'];if($r['done']){$next=['topic'=>'art','art'=>'vod','vod'=>'search','search'=>'final'];$j['phase']=$next[$j['phase']];$o['ref_cursor']=$j['phase']==='search'?'':0;}return;
        }
        if($j['phase']==='final'){
            foreach([['avdb_translation','vod_id',null],['translate','vod_id',null],['translate_lang','vod_id',null],['vod_translation','vod_id',null],['comment','comment_rid','comment_mid'],['ulog','ulog_rid','ulog_mid'],['role','role_rid',null]] as $relation){
                [$table,$field,$mid]=$relation;$required=[$field];if($mid){$required[]=$mid;}if(!$this->optional($table,$required)){continue;}$q=Db::name($table)->where($field,'>',0)->where($field,'<=',$upper);if($mid){$q->where($mid,1);}if($q->field($field)->limit(1)->find()){$j['phase']='relations';$o['relation_index']=0;return;}
            }
            if(Db::name('vod')->where('vod_id','<=',$upper)->count() || ($this->optional('avdb_import_item',['vod_id']) && Db::name('avdb_import_item')->where('vod_id','>',0)->where('vod_id','<=',$upper)->field('item_id')->limit(1)->find()) || ($this->optional('avdb_import_map',['vod_id']) && Db::name('avdb_import_map')->where('vod_id','>',0)->where('vod_id','<=',$upper)->field('remote_id')->limit(1)->find())){throw new \RuntimeException('vod/cleanup_changed');}$o['newer_videos_remaining']=(int)Db::name('vod')->where('vod_id','>',$upper)->count();$o['scope_missing']=max(0,(int)$j['total_videos']-(int)$j['deleted_videos']);$j['state']='complete';$j['active_slot']=null;self::afterCleanup($upper);return;
        }
        throw new \RuntimeException('vod/cleanup_schema');
    }
}
