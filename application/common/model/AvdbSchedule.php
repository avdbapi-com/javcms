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
use app\common\util\ImportProviders;
use app\common\util\XvidClient;

/**
 * Managed callbacks are independent of sessions. Only save installs schema.
 *
 * 1.2.0 (XVID-SCALE-BRIEF D-I10, D-I7, D-I8; lane P step 9): one schedule row per owner holds `options.plans`, one
 * plan per provider: {provider, options (AvdbImport::normalizeOptions), interval_minutes, next_run_at,
 * presence_next_at, last_success_at, enabled}. Old rows without plans are read as one 'avdb' plan. The scheduler starts
 * only incremental and presence jobs, one job at a time (the existing fence): the plan with the earliest due
 * next_run_at first, otherwise a due presence sweep (weekly up to 200,000 mapped ids, above that a rotating quarter of
 * the windows per week; a recheck 24 h after a sweep that found missing ids). Schedules never START bulk scopes (an
 * unattended trigger never begins a multi-GB job), but every tick also steps a running web job of the admin page
 * (AvdbImport::tickWeb, XVID-NOLIMIT-BRIEF A3). Once a snapshot import stored the xvidexport feed cursors, the hourly
 * xvid plan runs the changes + removed feeds (mode feed) instead of recent(h) and presence sweeps (A4).
 * The top-level keys of `options` stay the AVDB plan's options (1.1.0 reads them); a schedule without an AVDB plan
 * stores mode 'xvid_only' there, which 1.1.0 refuses (it pauses instead of importing).
 */
class AvdbSchedule
{
    private $importer;
    private $bridge;
    private $authorize;
    private $clock;
    /** D-I8: weekly sweeps; above this many mapped ids a rotating quarter of the windows per week. */
    public const PRESENCE_FULL_MAX = 200000;
    public const PRESENCE_EVERY = 604800;
    public const RECHECK_DELAY = 86460;

    public function __construct($importer=null,$bridge=null,$authorize=null,$clock=null)
    {
        $this->importer=$importer ?: new AvdbImport();
        $this->bridge=$bridge ?: new \app\common\util\AvdbScheduleClient();
        $this->authorize=$authorize;
        $this->clock=$clock ?: function(){return time();};
    }
    private function now(){return (int)call_user_func($this->clock);}
    private function owner($owner)
    {
        if ((!is_int($owner)&&!is_string($owner)) || !preg_match('/^[1-9][0-9]*$/D',(string)$owner) || (float)$owner>2147483647) { throw new \RuntimeException('Schedule owner is invalid.'); }
        return (int)$owner;
    }
    private function allowed($owner)
    {
        if ($this->authorize) { return (bool)call_user_func($this->authorize,$owner); }
        $admin=Db::name('admin')->where('admin_id',$owner)->field('admin_id,admin_status,admin_auth')->find();
        if (!$admin || (int)$admin['admin_status']!==1) { return false; }
        if ((int)$admin['admin_id']===1) { return true; }
        $auth=explode(',',strtolower((string)$admin['admin_auth']));
        foreach (['avdbimport/index','avdbimport/start','avdbimport/step','avdbimport/schedulesave'] as $permission) { if (!in_array($permission,$auth,true)) { return false; } }
        return true;
    }
    private function authorize($owner){if (!$this->allowed($owner)) { throw new \RuntimeException('Schedule permission denied.'); }}
    private function ready()
    {
        try { Db::name('avdb_import_schedule')->limit(1)->field('client_id')->select(); return true; }
        catch (\Throwable $e) { return false; }
    }
    private function install()
    {
        if ($this->ready()) { return; }
        $prefix=(string)config('database.prefix');
        if (!preg_match('/^[A-Za-z0-9_]{0,64}$/D',$prefix)) { throw new \RuntimeException('Schedule schema is invalid.'); }
        $sql=file_get_contents(APP_PATH.'install/sql/avdb-schedule.sql');
        foreach (explode(';',str_replace('javcms_',$prefix,$sql)) as $statement) { if (trim($statement)!=='') { Db::execute($statement); } }
    }
    private function row($owner){return Db::name('avdb_import_schedule')->where('admin_id',$owner)->find();}
    /** Plans of a schedule row (old rows: one 'avdb' plan from the legacy options). */
    public static function plans(array $row)
    {
        $options=json_decode((string)($row['options'] ?? ''),true); $options=is_array($options) ? $options : [];
        if (isset($options['plans']) && is_array($options['plans'])) { return array_values(array_filter($options['plans'],'is_array')); }
        if (!$options) { return []; }
        $legacy=$options; unset($legacy['plans'],$legacy['current']);
        return [['provider'=>'avdb','options'=>$legacy,'interval_minutes'=>(int)($row['interval_minutes'] ?? 1440),'next_run_at'=>(int)($row['next_run_at'] ?? 0),'presence_next_at'=>(int)($row['next_run_at'] ?? 0)+self::PRESENCE_EVERY,'last_success_at'=>(int)($row['last_run_at'] ?? 0),'enabled'=>1]];
    }
    private function output($row)
    {
        $r=['ready'=>$this->ready(),'enabled'=>false,'state'=>'off','client_id'=>'','interval_minutes'=>1440,'epoch'=>0,'next_run_at'=>0,'last_run_at'=>0,'job_id'=>'','options'=>[],'plans'=>[],'error'=>'','next_poll_seconds'=>60];
        if (!$row) { return $r; }
        foreach (['state','client_id','job_id','error'] as $key) { $r[$key]=(string)$row[$key]; }
        foreach (['interval_minutes','epoch','next_run_at','last_run_at'] as $key) { $r[$key]=(int)$row[$key]; }
        $r['enabled']=(bool)$row['enabled'];
        $r['options']=json_decode($row['options'],true) ?: [];
        foreach (self::plans($row) as $plan) { $r['plans'][]=['provider'=>(string)$plan['provider'],'mode'=>(string)($plan['options']['mode'] ?? ''),'interval_minutes'=>(int)$plan['interval_minutes'],'next_run_at'=>(int)$plan['next_run_at'],'presence_next_at'=>(int)($plan['presence_next_at'] ?? 0),'last_success_at'=>(int)($plan['last_success_at'] ?? 0),'enabled'=>(bool)($plan['enabled'] ?? 1)]; }
        $r['next_poll_seconds']=$r['state']==='running'?1:max(1,min(60,$r['next_run_at']-$this->now()));
        return $r;
    }
    public function status($owner)
    {
        $owner=$this->owner($owner); return $this->output($this->ready()?$this->row($owner):null);
    }
    private function callback($url)
    {
        if (!is_string($url) || strlen($url)>2048 || preg_match('/[\x00-\x20\x7f]/',$url) || !filter_var($url,FILTER_VALIDATE_URL)) { throw new \RuntimeException('Public callback URL is invalid.'); }
        $p=parse_url($url);
        if (($p['scheme']??'')!=='https' || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['fragment']) || isset($p['query']) || !preg_match('~/api\.php/avdbschedule/index$~D',$p['path']??'')) { throw new \RuntimeException('Public HTTPS callback is required.'); }
        return $url;
    }
    /**
     * One plan's options: AVDB keeps "all matching videos" (1.1.0) or incremental; other providers run incremental
     * syncs only. Bulk scopes (recent / category / windows / selected / pages) are refused in schedules (D-I10).
     */
    public static function normalizePlan(array $plan,$defaultInterval)
    {
        $provider=$plan['provider'] ?? ($plan['options']['provider'] ?? 'avdb');
        if (!is_string($provider) || !ImportProviders::isKey($provider)) { throw new \RuntimeException(ImportProviders::text('provider_unknown','Unknown import provider.')); }
        $options=isset($plan['options']) && is_array($plan['options']) ? $plan['options'] : array_diff_key($plan,array_flip(['provider','interval_minutes','enabled','next_run_at','presence_next_at','last_success_at']));
        $options['provider']=$provider; $options['runner']='web';
        $caps=ImportProviders::get($provider)->capabilities();
        $mode=$options['mode'] ?? (($caps['stable_cursor'] ?? '')==='export' ? 'all' : 'incremental');
        if (($caps['stable_cursor'] ?? '')==='export' ? !in_array($mode,['all','incremental'],true) : $mode!=='incremental') { throw new \RuntimeException(ImportProviders::text('schedule_bulk_refused','Start the whole catalog from the import page; it continues by itself.')); }
        $options['mode']=$mode;
        $normalized=AvdbImport::normalizeOptions($options);
        $interval=$plan['interval_minutes'] ?? $defaultInterval;
        if ((!is_int($interval)&&!is_string($interval)) || !preg_match('/^[1-9][0-9]*$/D',(string)$interval) || (float)$interval>10080) { throw new \RuntimeException('Interval must be 1 to 10080 minutes.'); }
        $enabled=$plan['enabled'] ?? 1; if (!in_array($enabled,[0,1,'0','1',false,true],true)) { throw new \RuntimeException('Invalid plan switch.'); }
        return ['provider'=>$provider,'options'=>$normalized,'interval_minutes'=>(int)$interval,'enabled'=>(int)$enabled];
    }
    public function save($owner,array $options,$interval_minutes,$callback_url)
    {
        $owner=$this->owner($owner); $this->authorize($owner);
        if ((!is_int($interval_minutes)&&!is_string($interval_minutes)) || !preg_match('/^[1-9][0-9]*$/D',(string)$interval_minutes) || (float)$interval_minutes>10080) { throw new \RuntimeException('Interval must be 1 to 10080 minutes.'); }
        $input=isset($options['plans']) && is_array($options['plans']) ? array_values($options['plans']) : [['provider'=>$options['provider'] ?? 'avdb','options'=>$options]];
        if (!isset($options['plans']) && ($options['mode']??'')!=='all' && ($options['mode']??'')!=='incremental') { throw new \RuntimeException('Automatic imports require all matching videos.'); }
        if (!$input || count($input)>count(ImportProviders::keys())) { throw new \RuntimeException(ImportProviders::text('schedule_one_plan','Choose one plan per provider.')); }
        $plans=[]; $seen=[];
        foreach ($input as $plan) { if (!is_array($plan)) { throw new \RuntimeException('Invalid automatic import plan.'); } $p=self::normalizePlan($plan,$interval_minutes); if (isset($seen[$p['provider']])) { throw new \RuntimeException(ImportProviders::text('schedule_one_plan','Choose one plan per provider.')); } $seen[$p['provider']]=true; $plans[]=$p; }
        $url=$this->callback($callback_url); $this->install(); $now=$this->now();
        $old=$this->row($owner); $oldPlans=[]; if ($old) { foreach (self::plans($old) as $op) { $oldPlans[(string)$op['provider']]=$op; } }
        foreach ($plans as &$p) { $op=$oldPlans[$p['provider']] ?? []; $p['next_run_at']=$now; $p['presence_next_at']=(int)($op['presence_next_at'] ?? 0) ?: $now+self::PRESENCE_EVERY; $p['last_success_at']=(int)($op['last_success_at'] ?? 0); } unset($p);
        $stored=self::storeOptions($plans);
        $minInterval=min(array_map(function ($p) { return (int)$p['interval_minutes']; },$plans));
        Db::startTrans();
        try {
            $old=Db::name('avdb_import_schedule')->where('admin_id',$owner)->lock(true)->find();
            $row=['admin_id'=>$owner,'client_id'=>$old?$old['client_id']:bin2hex(random_bytes(16)),'secret'=>$old?$old['secret']:bin2hex(random_bytes(32)),'callback_url'=>$url,'options'=>json_encode($stored,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),'interval_minutes'=>$minInterval,'enabled'=>0,'state'=>'off','epoch'=>$old?(int)$old['epoch']+1:1,'last_sequence'=>$old?(int)$old['last_sequence']:0,'lease_token'=>'','lease_until'=>0,'job_id'=>'','next_run_at'=>$now,'last_run_at'=>$old?(int)$old['last_run_at']:0,'error'=>'','created_at'=>$old?(int)$old['created_at']:$now,'updated_at'=>$now];
            $row['management_timestamp']=max($now,$old?(int)$old['management_timestamp']+1:0);
            if ($old) { Db::name('avdb_import_schedule')->where('client_id',$row['client_id'])->update($row); }
            else { Db::name('avdb_import_schedule')->insert($row); }
            Db::commit();
        } catch (\Throwable $e) { Db::rollback(); throw $e; }
        try {
            $response=$this->bridge->register($row['client_id'],$url,$row['secret'],$minInterval,$row['management_timestamp']);
            if (!is_array($response) || (int)($response['code']??0)!==1 || empty($response['data']['registered'])) { throw new \RuntimeException('Registration failed.'); }
            Db::name('avdb_import_schedule')->where(['client_id'=>$row['client_id'],'epoch'=>$row['epoch'],'state'=>'off'])->update(['enabled'=>1,'state'=>'idle','error'=>'','updated_at'=>$this->now()]);
        } catch (\Throwable $e) {
            Db::name('avdb_import_schedule')->where(['client_id'=>$row['client_id'],'epoch'=>$row['epoch']])->update(['enabled'=>0,'state'=>'off','error'=>'Managed service unavailable. Save again to enable.','updated_at'=>$this->now()]);
        }
        return $this->status($owner);
    }
    /** Stored JSON: the AVDB plan's options at the top level (1.1.0 reads them) + plans; no AVDB plan -> mode 'xvid_only'. */
    private static function storeOptions(array $plans,array $extra = [])
    {
        $top=['mode'=>'xvid_only'];
        foreach ($plans as $p) { if ($p['provider']==='avdb' && ($p['options']['mode'] ?? '')==='all') { $top=$p['options']; unset($top['provider'],$top['type'],$top['runner'],$top['placement'],$top['override_tombstones'],$top['image_hosts_extra']); } }
        return $top+['plans'=>array_values($plans)]+$extra;
    }
    private function control($owner,$state)
    {
        $owner=$this->owner($owner); $this->authorize($owner);
        if (!$this->ready()) { return $this->status($owner); }
        Db::startTrans();
        try {
            $row=Db::name('avdb_import_schedule')->where('admin_id',$owner)->lock(true)->find();
            if ($row) {
                $enabled=in_array($state,['idle','running'],true)?1:0;
                $changes=['state'=>$state,'enabled'=>$enabled,'epoch'=>(int)$row['epoch']+(in_array($state,['off','paused'],true)?1:0),'lease_token'=>'','lease_until'=>0,'updated_at'=>$this->now()];
                if ($state==='off') { $row['management_timestamp']=max($this->now(),(int)$row['management_timestamp']+1); $changes['management_timestamp']=$row['management_timestamp']; }
                Db::name('avdb_import_schedule')->where('client_id',$row['client_id'])->update($changes);
            }
            Db::commit();
        } catch (\Throwable $e) { Db::rollback(); throw $e; }
        if ($row && $state==='off') { try { $this->bridge->disable($row['client_id'],$row['callback_url'],$row['secret'],(int)$row['interval_minutes'],$row['management_timestamp']); } catch (\Throwable $e) { /* Local off already committed. */ } }
        return $this->status($owner);
    }
    public function off($owner){return $this->control($owner,'off');}
    public function pause($owner){return $this->control($owner,'paused');}
    public function resume($owner)
    {
        $owner=$this->owner($owner); $this->authorize($owner);
        if (!$this->ready()) { return $this->status($owner); }
        $observed=$this->row($owner); $observedId=$observed?$observed['job_id']:'';
        Db::startTrans();
        try {
            // Importer commits lock job before schedule. Preserve that lock order.
            $job=$observedId!==''?Db::name('avdb_import_job')->where(['job_id'=>$observedId,'admin_id'=>$owner])->lock(true)->find():null;
            $row=Db::name('avdb_import_schedule')->where('admin_id',$owner)->lock(true)->find();
            if ($row && $row['state']==='paused') {
                if ($row['job_id']!==$observedId) { throw new \RuntimeException('Schedule changed. Refresh before resuming.'); }
                VodCleanup::assertWritesAllowed();
                if ($row['job_id']!=='') {
                    // This is an explicit administrator action. A pause during a
                    // fetch makes the old step stop with a paused durable job.
                    if (!$job || !in_array($job['state'],array_merge(AvdbImport::RUN_STATES,AvdbImport::PAUSE_STATES),true) || ($job['phase']??'')==='rollback') { throw new \RuntimeException('Review the stopped import job before resuming.'); }
                    if ((int)($job['lock_until']??0)>$this->now()) { throw new \RuntimeException('The previous import step is still finishing. Try resuming again shortly.'); }
                    if ((int)($job['retry_at']??0)>$this->now()) { throw new \RuntimeException('Provider rate limit is still active. Try resuming again shortly.'); }
                    VodCleanup::assertImportJobAllowed($job);
                    $options=json_decode($job['options'],true) ?: [];
                    if (($options['_schedule_client_id']??'')!==$row['client_id']) { throw new \RuntimeException('The import is not owned by this schedule.'); }
                    $options['_schedule_epoch']=(int)$row['epoch'];
                    Db::name('avdb_import_job')->where('job_id',$row['job_id'])->update(['options'=>json_encode($options,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),'state'=>AvdbImport::stateFor('running',(string)($job['provider'] ?? 'avdb')),'error'=>'','retry_at'=>0,'lock_token'=>'','lock_until'=>0,'updated_at'=>$this->now()]);
                }
                $changes=['enabled'=>1,'state'=>$row['job_id']!==''?'running':'idle','error'=>'','updated_at'=>$this->now()];
                // Plans paused by pauseAll (C12) come back with the schedule.
                $stored=json_decode((string)$row['options'],true);
                if (is_array($stored) && isset($stored['plans']) && is_array($stored['plans'])) { foreach ($stored['plans'] as $i=>$plan) { if (empty($plan['disabled_by_remove'])) { $stored['plans'][$i]['enabled']=1; } } $changes['options']=json_encode($stored,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
                Db::name('avdb_import_schedule')->where('client_id',$row['client_id'])->update($changes);
            }
            Db::commit();
        } catch (\Throwable $e) { Db::rollback(); throw $e; }
        return $this->status($owner);
    }
    /** "Remove all from <provider>" (D-I13) disables that provider's plan in every schedule. Returns plans changed. */
    public static function disablePlan($provider,$why)
    {
        $n=0;
        try { $rows=Db::name('avdb_import_schedule')->select(); } catch (\Throwable $e) { return 0; }
        foreach ($rows as $row) {
            $plans=self::plans($row); $changed=false;
            foreach ($plans as $i=>$plan) { if ((string)$plan['provider']===(string)$provider && !empty($plan['enabled'])) { $plans[$i]['enabled']=0; $plans[$i]['disabled_by_remove']=1; $changed=true; $n++; } }
            if (!$changed) { continue; }
            $stored=self::storeOptions($plans);
            $set=['options'=>json_encode($stored,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'updated_at'=>time()];
            if (!array_filter($plans,function ($p) { return !empty($p['enabled']); })) { $set+=['enabled'=>0,'state'=>'paused','epoch'=>(int)$row['epoch']+1,'error'=>mb_substr((string)$why,0,255,'UTF-8')]; }
            Db::name('avdb_import_schedule')->where('client_id',$row['client_id'])->update($set);
        }
        return $n;
    }
    private function signature($action,$client,$sequence,$timestamp,$secret)
    {
        return hash_hmac('sha256',implode("\n",['avdb-schedule-v1',$action,$client,(string)$sequence,(string)$timestamp]),$secret);
    }
    private function payload(array $p)
    {
        if (count($p)!==5 || !isset($p['action'],$p['client_id'],$p['sequence'],$p['timestamp'],$p['signature']) || !in_array($p['action'],['probe','run'],true) || !is_string($p['client_id']) || !preg_match('/^[a-f0-9]{32}$/D',$p['client_id']) || !is_string($p['signature']) || !preg_match('/^[a-f0-9]{64}$/D',$p['signature'])) { throw new \RuntimeException('Invalid schedule callback.'); }
        foreach (['sequence','timestamp'] as $key) { if (!is_int($p[$key]) || $p[$key]<1) { throw new \RuntimeException('Invalid schedule callback.'); } }
        if (abs($this->now()-(int)$p['timestamp'])>300) { throw new \RuntimeException('Expired schedule callback.'); }
    }
    /** Site-specific minute for incremental plans (D-I7 "a random minute per site"). */
    private static function siteMinute()
    {
        return hexdec(substr(hash('sha256',(string)config('database.database').'|'.(string)config('database.prefix').'|'.(defined('ROOT_PATH')?ROOT_PATH:'')),0,4))%60;
    }
    public static function nextRun($now,$intervalMinutes)
    {
        $next=(int)$now+60*(int)$intervalMinutes;
        if ((int)$intervalMinutes%60===0) { $next=(int)(floor($next/3600)*3600)+60*self::siteMinute(); if ($next<(int)$now+60) { $next+=3600; } }
        return $next;
    }
    /**
     * Next job of a schedule (pure apart from the presence size query): ['plan'=>index,'kind'=>'run'|'presence',
     * 'options'=>job options] or null when nothing is due. Round robin: the earliest due next_run_at of the enabled
     * plans, else the earliest due presence_next_at.
     */
    public function pick(array $plans,$now)
    {
        $best=null;
        foreach ($plans as $i=>$plan) { if (empty($plan['enabled']) || (int)$plan['next_run_at']>$now) { continue; } if ($best===null || (int)$plan['next_run_at']<(int)$plans[$best]['next_run_at']) { $best=$i; } }
        if ($best!==null) {
            $o=$plans[$best]['options'];
            // A4: after a snapshot import the xvidexport feeds carry every change and removal from the stored cursors
            if (($o['mode'] ?? '')==='incremental' && AvdbImport::feedState((string)$plans[$best]['provider'])) { $o['mode']='feed'; unset($o['hours']); }
            elseif (($o['mode'] ?? '')==='incremental') { $o['hours']=XvidClient::canonicalHours((int)$plans[$best]['last_success_at'] ? $now-(int)$plans[$best]['last_success_at'] : 168*3600); }
            return ['plan'=>$best,'kind'=>'run','options'=>$o];
        }
        // presence sweeps are not needed for a provider whose removed feed runs hourly (A4, L14)
        foreach ($plans as $i=>$plan) { if (empty($plan['enabled']) || (int)($plan['presence_next_at'] ?? 0)<1 || (int)$plan['presence_next_at']>$now || AvdbImport::feedState((string)$plan['provider'])) { continue; } if ($best===null || (int)$plan['presence_next_at']<(int)$plans[$best]['presence_next_at']) { $best=$i; } }
        if ($best===null) { return null; }
        $provider=(string)$plans[$best]['provider'];
        $recheck=(bool)Db::name('avdb_import_map')->where('provider',$provider)->where('state',0)->where('vod_id','>',0)->where('missed_at','>',0)->where('missed_at','<=',$now-AvdbImport::RECHECK_AFTER)->field('remote_id')->find();
        $mapped=(int)Db::name('avdb_import_map')->where('provider',$provider)->where('vod_id','>',0)->count();
        $parts=$mapped>self::PRESENCE_FULL_MAX ? 4 : 1;
        $o=['provider'=>$provider,'mode'=>'presence','recheck'=>$recheck?1:0,'parts'=>$recheck?1:$parts,'part'=>$recheck?0:(int)(floor($now/self::PRESENCE_EVERY)%$parts),'create_categories'=>0,'runner'=>'web'];
        return ['plan'=>$best,'kind'=>'presence','options'=>AvdbImport::normalizeOptions($o)];
    }
    public function receive(array $payload)
    {
        $this->payload($payload);
        if (!$this->ready()) { throw new \RuntimeException('Schedule is unavailable.'); }
        $row=Db::name('avdb_import_schedule')->where('client_id',$payload['client_id'])->find();
        if (!$row || !hash_equals($this->signature($payload['action'],$payload['client_id'],$payload['sequence'],$payload['timestamp'],$row['secret']),$payload['signature'])) { throw new \RuntimeException('Invalid schedule callback.'); }
        if ($payload['action']==='probe') { return ['client_id'=>$row['client_id'],'sequence'=>(int)$payload['sequence'],'timestamp'=>(int)$payload['timestamp'],'proof'=>$this->signature('proof',$row['client_id'],$payload['sequence'],$payload['timestamp'],$row['secret'])]; }
        $now=$this->now(); $token=bin2hex(random_bytes(16));
        // Consume authenticated sequence even when disabled or busy; duplicates never advance.
        $accepted=Db::name('avdb_import_schedule')->where(['client_id'=>$row['client_id'],'epoch'=>$row['epoch'],'last_sequence'=>['lt',(int)$payload['sequence']]])->update(['last_sequence'=>(int)$payload['sequence'],'updated_at'=>$now]);
        if (!$accepted) { return $this->output($this->row((int)$row['admin_id'])); }
        return $this->tick($row,$token);
    }
    /**
     * One scheduler pass for a schedule row (managed callback receive(), or tools/import.php --tick without the managed
     * trigger). Steps the running job; otherwise starts the next due plan job.
     */
    public function tick(array $row,$token = null)
    {
        $now=$this->now(); $token=$token ?: bin2hex(random_bytes(16));
        $held=Db::name('avdb_import_schedule')->where(['client_id'=>$row['client_id'],'epoch'=>$row['epoch'],'enabled'=>1,'state'=>['in',['idle','running']],'lease_until'=>['elt',$now]])->update(['lease_token'=>$token,'lease_until'=>$now+600]);
        if (!$held) { return $this->output($this->row((int)$row['admin_id'])); }
        $fence=['client_id'=>$row['client_id'],'epoch'=>$row['epoch'],'lease_token'=>$token,'enabled'=>1];
        $plans=self::plans($row); $stored=json_decode((string)$row['options'],true); $stored=is_array($stored)?$stored:[]; $current=$stored['current'] ?? null;
        $stepped=false;
        try {
            if (!$this->allowed((int)$row['admin_id'])) { Db::name('avdb_import_schedule')->where($fence)->update(['enabled'=>0,'state'=>'off','epoch'=>(int)$row['epoch']+1,'error'=>'Owner permission is no longer available.']); return $this->status((int)$row['admin_id']); }
            VodCleanup::assertWritesAllowed();
            $update=[]; $finishedPlan=null;
            if ($row['job_id']!=='') {
                $job=$this->importer->status((int)$row['admin_id'],$row['job_id']);
                if ($job['state']==='running') {
                    $result=$this->importer->stepScheduled((int)$row['admin_id'],$row['job_id'],$row['client_id'],(int)$row['epoch']); $stepped=true;
                    $update=['state'=>'running','error'=>''];
                    if ($result['state']==='complete') { $update=['state'=>'idle','job_id'=>'','next_run_at'=>$this->now()+60*(int)$row['interval_minutes'],'error'=>'']; $finishedPlan=$result; }
                    elseif ($result['state']!=='running') { $update=['enabled'=>0,'state'=>'paused','error'=>'Import requires administrator attention.']; }
                } elseif ($job['state']==='complete') { $update=['state'=>'idle','job_id'=>'','next_run_at'=>$now+60*(int)$row['interval_minutes'],'error'=>'']; $finishedPlan=$job; }
                else { $update=['enabled'=>0,'state'=>'paused','error'=>'Import requires administrator attention.']; }
            } else {
                // A process may have died after job creation but before recording its ID.
                // Recover that durable job rather than creating a duplicate cycle.
                $recovered=null;
                try {
                    foreach (Db::name('avdb_import_job')->where('admin_id',(int)$row['admin_id'])->order('created_at desc')->limit(100)->select() as $candidate) {
                        $o=json_decode($candidate['options'],true) ?: [];
                        if (($o['_schedule_client_id']??'')===$row['client_id'] && (int)($o['_schedule_epoch']??0)===(int)$row['epoch'] && (int)$candidate['created_at']>=(int)$row['next_run_at'] && !in_array($candidate['state'],AvdbImport::FINISHED_JOB_STATES,true)) { $recovered=$candidate; break; }
                    }
                } catch (\Throwable $e) { /* The first import has no job schema yet. */ }
                if ($recovered) {
                    $update=['state'=>'running','job_id'=>$recovered['job_id'],'last_run_at'=>(int)$recovered['created_at'],'error'=>''];
                } elseif ($next=$this->pick($plans,$now)) {
                    $result=$this->importer->startScheduled((int)$row['admin_id'],$next['options'],$row['client_id'],(int)$row['epoch']);
                    $current=['plan'=>$next['plan'],'kind'=>$next['kind'],'job'=>$result['id']];
                    // The plan's next time moves at the start: a failed job is not retried in a loop.
                    if ($next['kind']==='run') { $plans[$next['plan']]['next_run_at']=self::nextRun($now,(int)$plans[$next['plan']]['interval_minutes']); }
                    else { $plans[$next['plan']]['presence_next_at']=$now+self::PRESENCE_EVERY; }
                    $update=['state'=>'running','job_id'=>$result['id'],'last_run_at'=>$now,'error'=>''];
                }
            }
            if ($finishedPlan && is_array($current) && isset($plans[$current['plan']])) {
                $i=$current['plan'];
                if ($current['kind']==='run') { $plans[$i]['last_success_at']=$this->now(); }
                elseif (!empty($finishedPlan['presence']) && empty($finishedPlan['options']['recheck']) && (int)$finishedPlan['presence']['missing']>0 && ($finishedPlan['presence']['sweep_state'] ?? '')==='ok') { $plans[$i]['presence_next_at']=$this->now()+self::RECHECK_DELAY; }
                $current=null;
            }
            if ($update) {
                $due=array_filter(array_map(function ($p) { return empty($p['enabled']) ? 0 : min((int)$p['next_run_at'],(int)($p['presence_next_at'] ?: PHP_INT_MAX)); },$plans));
                if (!isset($update['next_run_at']) && $due) { $update['next_run_at']=min($due); } elseif ($due) { $update['next_run_at']=min($due); }
                if (isset($stored['plans']) || count($plans)>0) { $update['options']=json_encode(self::storeOptions($plans,$current?['current'=>$current]:[]),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); }
                $update['updated_at']=$this->now(); Db::name('avdb_import_schedule')->where($fence)->update($update);
            }
        } catch (\Throwable $e) {
            if ($e->getMessage()==='A manual import is active. Finish or stop it before automatic imports.' && $row['job_id']==='') {
                Db::name('avdb_import_schedule')->where($fence)->update(['state'=>'idle','next_run_at'=>$this->now()+60,'error'=>'Waiting for the manual import to finish.','updated_at'=>$this->now()]);
                return $this->status((int)$row['admin_id']);
            }
            // No automatic retry of a failed/paused/cleanup-sealed import job.
            Db::name('avdb_import_schedule')->where($fence)->update(['enabled'=>0,'state'=>'paused','error'=>'Automatic import stopped. Review the import job before resuming.','updated_at'=>$this->now()]);
        } finally { Db::name('avdb_import_schedule')->where(['client_id'=>$row['client_id'],'epoch'=>$row['epoch'],'lease_token'=>$token])->update(['lease_token'=>'','lease_until'=>0]); }
        // A3: the schedule also continues a running web job of the admin page (whole catalog), when this tick did not step
        // a schedule job itself; tickWeb keeps its own rules (30 s spacing, 60 s admin-poll rule, storage, load).
        if (!$stepped && method_exists($this->importer,'tickWeb')) { try { $this->importer->tickWeb(8); } catch (\Throwable $e) { error_log('[AVDB] schedule tickWeb: '.get_class($e)); } }
        return $this->status((int)$row['admin_id']);
    }
}
