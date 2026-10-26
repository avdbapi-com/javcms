<?php
/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Based on MacCMS 10 (Apache-2.0) by magicblack, changed by JavCMS.
 * JavCMS changes: Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors,
 * JavCMS Community License 1.0. The MacCMS parts stay under the Apache License 2.0.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
namespace app\common\model;
use think\Db;

class Card extends Base {
    // Table name without the prefix.
    protected $name = 'card';

    // Define timestamp field names
    protected $createTime = '';
    protected $updateTime = '';

    // Automatic field completion.
    protected $auto       = [];
    protected $insert     = [];
    protected $update     = [];

    public function getCardUseStatusTextAttr($val,$data)
    {
        $arr = [0=>lang('not_used'),1=>lang('used')];
        return $arr[$data['card_use_status']];
    }

    public function getCardSaleStatusTextAttr($val,$data)
    {
        $arr = [0=>lang('not_sale'),1=>lang('sold')];
        return $arr[$data['card_sale_status']];
    }

    public function listData($where,$order,$page,$limit=20)
    {
        $total = $this->where($where)->count();
        $list = Db::name('Card')->where($where)->order($order)->page($page)->limit($limit)->select();
        foreach($list as $k=>$v){
            if($v['user_id'] >0){
                $user = model('User')->infoData(['user_id'=>$v['user_id']]);
                $list[$k]['user'] = $user['info'];
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

    public function saveData($data)
    {
        $validate = \think\Loader::validate('Card');
        if(!$validate->check($data)){
            return ['code'=>1001,'msg'=>lang('param_err').': '.$validate->getError() ];
        }

        if(!empty($data['card_id'])){
            $where=[];
            $where['card_id'] = ['eq',$data['card_id']];
            $res = $this->allowField(true)->where($where)->update($data);
        }
        else{
            $data['card_add_time'] = time();
            $res = $this->allowField(true)->insert($data);
        }
        if(false === $res){
            return ['code'=>1002,'msg'=>lang('save_err').': '.$this->getError() ];
        }
        return ['code'=>1,'msg'=>lang('save_ok')];
    }

    public function saveAllData($num,$money,$point,$role_no,$role_pwd)
    {
        $data=[];
        $t = time();
        for($i=1;$i<=$num;$i++){
            $card_no = mac_get_rndstr(16,$role_no);
            $card_pwd = mac_get_rndstr(8,$role_pwd);

            $data[$card_no] = ['card_no'=>$card_no,'card_pwd'=>$card_pwd,'card_money'=>$money,'card_points'=>$point,'card_add_time'=>$t];
        }
        $data = array_values($data);
        $res = $this->allowField(true)->insertAll($data);
        if(false === $res){
            return ['code'=>1002,'msg'=>lang('save_err').': '.$this->getError() ];
        }
        return ['code'=>1,'msg'=>lang('save_ok')];
    }


    public function delData($where)
    {
        $res = $this->where($where)->delete();
        if($res===false){
            return ['code'=>1001,'msg'=>lang('del_err').': '.$this->getError() ];
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

    public function useData($card_no,$card_pwd,$user_info)
    {
        if ((!is_string($card_no) && !is_int($card_no)) || (!is_string($card_pwd) && !is_int($card_pwd))
            || empty($card_no) || empty($card_pwd) || !is_array($user_info) || (int)($user_info['user_id'] ?? 0) < 1) {
            return ['code'=>1001, 'msg'=>lang('param_err')];
        }
        $userId = (int)$user_info['user_id'];
        $connection = null; $started = false; $failure = 1004;
        try {
            $connection = $this->getQuery()->getConnection();
            $users = new \think\db\Query($connection);
            $logs = new \think\db\Query($connection);
            $users->name('user'); $logs->name('plog');
            // Fail closed on nontransactional storage; never promise atomic credit there.
            $tables = [$this->getQuery()->getTable(), $users->getTable(), $logs->getTable()];
            $engines = $connection->query('SELECT TABLE_NAME AS table_name, ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (?,?,?)', $tables);
            if (count($engines) !== 3) throw new \RuntimeException('Nontransactional redemption storage');
            foreach ($engines as $engine) if (strtolower((string)$engine['engine']) !== 'innodb') throw new \RuntimeException('Nontransactional redemption storage');
            $connection->startTrans(); $started = true;
            $where = ['card_no'=>(string)$card_no, 'card_pwd'=>(string)$card_pwd, 'card_use_status'=>0];
            $info = $this->where($where)->lock(true)->find();
            if (!$info) { $failure=1002; throw new \RuntimeException('Card unavailable'); }
            $failure=1003;
            $account = $users->where(['user_id'=>$userId, 'user_status'=>1])->lock(true)->find();
            $pointsValue = (string)$info['card_points'];
            if (!$account || !preg_match('/^[0-9]+$/D', $pointsValue) || (float)$pointsValue > 4294967295) throw new \RuntimeException('Invalid redemption account or amount');
            $points = (int)$pointsValue;
            if ((float)$account['user_points'] + $points > 4294967295) throw new \RuntimeException('Points overflow');
            $failure=1004;
            $claimed = $this->where(['card_id'=>(int)$info['card_id'], 'card_use_status'=>0])->update([
                'card_sale_status'=>1, 'card_use_status'=>1, 'card_use_time'=>time(), 'user_id'=>$userId
            ]);
            if ($claimed !== 1) throw new \RuntimeException('Card claim failed');
            $failure=1003;
            // A zero-value card is valid legacy data: claim/log it without a no-op UPDATE.
            if ($points > 0 && $users->where(['user_id'=>$userId, 'user_status'=>1])->setInc('user_points',$points) !== 1) throw new \RuntimeException('Points credit failed');
            if ($logs->insert(['user_id'=>$userId, 'plog_type'=>1, 'plog_points'=>$points, 'plog_time'=>time()]) !== 1) throw new \RuntimeException('Points log failed');
            $connection->commit(); $started=false;
            return ['code'=>1, 'msg'=>lang('model/card/used_card_ok',[$points])];
        } catch (\Throwable $error) {
            if ($started && $connection) { try { $connection->rollback(); } catch (\Throwable $ignored) {} }
            $keys=[1002=>'model/card/not_found',1003=>'model/card/update_user_points_err',1004=>'model/card/update_card_status_err'];
            return ['code'=>$failure, 'msg'=>lang($keys[$failure])];
        }
    }

}