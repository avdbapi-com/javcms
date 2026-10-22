<?php
/*
 * This file is part of JavCMS - https://javcms.net
 * Based on MacCMS 10 (Apache-2.0) by magicblack, changed by JavCMS.
 * JavCMS changes: Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors,
 * JavCMS Community License 1.0. The MacCMS parts stay under the Apache License 2.0.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
namespace app\common\model;
use think\Db;

class Cash extends Base {
    // Table name without the prefix.
    protected $name = 'cash';

    // Define timestamp field names
    protected $createTime = '';
    protected $updateTime = '';

    // Automatic field completion.
    protected $auto       = [];
    protected $insert     = [];
    protected $update     = [];


    public function listData($where,$order,$page=1,$limit=20,$start=0)
    {
        if(!is_array($where)){
            $where = json_decode($where,true);
        }
        $limit_str = ($limit * ($page-1) + $start) .",".$limit;
        $total = $this->where($where)->count();
        $list = Db::name('Cash')->where($where)->order($order)->limit($limit_str)->select();

        $user_ids=[];
        foreach($list as $k=>&$v){
            if($v['user_id'] >0){
                $user_ids[$v['user_id']] = $v['user_id'];
            }
        }

        if(!empty($user_ids)){
            $where2=[];
            $where['user_id'] = ['in', $user_ids];
            $order='user_id desc';
            $user_list = model('User')->listData($where2,$order,1,999);
            $user_list = mac_array_rekey($user_list['list'],'user_id');

            foreach($list as $k=>&$v){
                $list[$k]['user_name'] = $user_list[$v['user_id']]['user_name'];
            }
        }

        return ['code'=>1,'msg'=>lang('data_list'),'page'=>$page,'pagecount'=>ceil($total/$limit),'limit'=>$limit,'total'=>$total,'list'=>$list];
    }

    public function infoData($where,$field='*')
    {
        if(empty($where) || !is_array($where)){
            return ['code'=>1001,'msg'=>lang('param_err')];
        }
        $info = $this->field($field)->where($where)->find();

        if(empty($info)){
            return ['code'=>1002,'msg'=>lang('obtain_err')];
        }
        $info = $info->toArray();

        return ['code'=>1,'msg'=>lang('obtain_ok'),'info'=>$info];
    }

    public function saveData($param)
    {
        $data=[];
        $data['cash_money']  = floatval($param['cash_money']);

        if($GLOBALS['config']['user']['cash_status'] !='1'){
            return ['code'=>1005,'msg'=>lang('model/cash/not_open')];
        }

        if($data['cash_money'] < $GLOBALS['config']['user']['cash_min']){
            return ['code'=>1006,'msg'=>lang('model/cash/min_money_err').': '.$GLOBALS['config']['user']['cash_min'] ];
        }

        $tx_points = intval($data['cash_money'] * $GLOBALS['config']['user']['cash_ratio']);
        if($tx_points > $GLOBALS['user']['user_points']){
            return ['code'=>1007,'msg'=>lang('model/cash/mush_money_err')];
        }
        $data['user_id'] = $GLOBALS['user']['user_id'];
        $data['cash_bank_name'] = htmlspecialchars(urldecode(trim($param['cash_bank_name'])));
        $data['cash_bank_no'] = htmlspecialchars(urldecode(trim($param['cash_bank_no'])));
        $data['cash_payee_name'] = htmlspecialchars(urldecode(trim($param['cash_payee_name'])));
        $data['cash_points'] = $tx_points;
        $data['cash_time'] = time();

        $validate = \think\Loader::validate('Cash');
        if(!$validate->check($data)){
            return ['code'=>1001,'msg'=>lang('param_err').': '.$validate->getError() ];
        }

        if($data['user_id']==0 ) {
            return ['code'=>1002,'msg'=>lang('param_err')];
        }
        $res = $this->allowField(true)->insert($data);
        if(false === $res){
            return ['code'=>1004,'msg'=>lang('save_err').': '.$this->getError() ];
        }

        // Update user table
        $update=[];
        $update['user_points'] = $GLOBALS['user']['user_points'] - $tx_points;
        $update['user_points_froze'] = $GLOBALS['user']['user_points_froze'] + $tx_points;

        $where=[];
        $where['user_id'] = $GLOBALS['user']['user_id'];
        $res = model('user')->where($where)->update($update);
        if(false === $res){
            return ['code'=>1005,'msg'=>'Failed to update user points: '.$this->getError() ];
        }

        return ['code'=>1,'msg'=>lang('save_ok')];
    }

    /**
     * r5 (L-2): delete withdrawal requests. A pending request gives its frozen points back to the member first, then the
     * row is deleted, both in one transaction per request. The member never gets back more than is still frozen
     * (user_points_froze is unsigned: froze - cash_points below 0 threw under STRICT mode and left a half-done delete).
     */
    public function delData($where)
    {
        $list = Db::name('Cash')->where($where)->select();

        foreach($list as $k=>$v){
            Db::startTrans();
            try {
                if ((int)$v['cash_status'] === 0 && (int)$v['user_id'] > 0) {
                    $user = Db::name('User')->where('user_id', (int)$v['user_id'])->lock(true)->find();
                    if (!empty($user)) {
                        $froze = max(0, (int)$user['user_points_froze']);
                        $back = min(max(0, (int)$v['cash_points']), $froze);
                        Db::name('User')->where('user_id', (int)$v['user_id'])->update([
                            'user_points' => max(0, (int)$user['user_points']) + $back,
                            'user_points_froze' => $froze - $back,
                        ]);
                    }
                }
                Db::name('Cash')->where('cash_id', (int)$v['cash_id'])->delete();
                Db::commit();
            } catch (\Throwable $e) {
                Db::rollback();
                return ['code'=>1001,'msg'=>lang('del_err')];
            }
        }

        return ['code'=>1,'msg'=>lang('del_ok')];
    }

    public function fieldData($where,$col,$val)
    {
        if(!isset($col) || !isset($val)){
            return ['code'=>1001,'msg'=>lang('param_err')];
        }

        $data = [];
        $data[$col] = $val;
        $res = $this->allowField(true)->where($where)->update($data);
        if($res===false){
            return ['code'=>1001,'msg'=>lang('set_err').': '.$this->getError() ];
        }
        return ['code'=>1,'msg'=>lang('set_ok')];
    }

    public function auditData($where)
    {
        $list = $this->where($where)->select();
        foreach($list as $k=>$v){
            $where2=[];
            $where2['user_id'] = $v['user_id'];

            // Re-check the pending state while updating this exact cash request.
            // This keeps retries and concurrent admin requests idempotent.
            $cash_where=[];
            $cash_where['cash_id'] = $v['cash_id'];
            $cash_where['cash_status'] = 0;

            $update=[];
            $update['cash_status'] = 1;
            $update['cash_time_audit'] = time();
            $res = model('Cash')->where($cash_where)->update($update);
            if($res===false){
                return ['code'=>1001,'msg'=>lang('set_err').': '.$this->getError() ];
            }
            if((int)$res === 0){
                continue;
            }

            // r5 (L-2): never below 0 (unsigned column; a plain setDec threw under STRICT mode after the status update).
            $pts = max(0, (int)$v['cash_points']);
            $res = Db::name('User')->where($where2)->update(['user_points_froze' => new \think\db\Expression('IF(user_points_froze > ' . $pts . ', user_points_froze - ' . $pts . ', 0)')]);
            if(false === $res){
                return ['code'=>1005,'msg'=>'Failed to update user points: '.$this->getError() ];
            }

        }
        return ['code'=>1,'msg'=>'Review completed successfully.'];
    }

}