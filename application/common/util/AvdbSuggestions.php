<?php
/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
namespace app\common\util;

use think\Cache;

/** Read-only full provider dictionaries; never scans catalog pages or installs tables. */
class AvdbSuggestions
{
    private $client;
    private $cache;
    const FIELDS = ['actor','director','writer','category','tag','year','from'];

    /** Client/cache injection is reserved for isolated fixtures. */
    public function __construct($client = null, $cache = null)
    {
        if ($cache !== null && !is_callable($cache)) { throw new \InvalidArgumentException('Invalid suggestion cache.'); }
        $this->client = $client ?: new AvdbClient();
        $this->cache = $cache;
    }

    public static function normalize(array $options)
    {
        if (array_diff(array_keys($options), ['field','q','pg','pagesize'])) { throw new \InvalidArgumentException('Unsupported suggestion option.'); }
        $field=$options['field'] ?? '';
        if (!is_string($field) || !in_array($field,self::FIELDS,true)) { throw new \InvalidArgumentException('Unsupported suggestion field.'); }
        $q=$options['q'] ?? '';
        if (!is_string($q) || strlen($q)>128 || preg_match('/[\x00-\x1f\x7f]/',$q) || !preg_match('//u',$q)) { throw new \InvalidArgumentException('Suggestion search must be valid text of at most128 bytes.'); }
        $out=['field'=>$field,'q'=>trim($q)];
        foreach (['pg'=>[1,1000000],'pagesize'=>[20,50]] as $key=>$bounds) {
            $v=$options[$key] ?? $bounds[0];
            if ((!is_int($v)&&!is_string($v)) || !preg_match('/^[1-9][0-9]*$/D',(string)$v) || (float)$v>$bounds[1]) { throw new \InvalidArgumentException('Invalid suggestion '.$key.'.'); }
            $out[$key]=(int)$v;
        }
        return $out;
    }

    public function suggest(array $options)
    {
        $options=self::normalize($options);
        $identity=hash('sha256',json_encode($options,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        // Only64 cache files/keys, even for arbitrary typing searches. Collision is a miss.
        $key='avdb_suggestions_v1_'.(hexdec(substr($identity,0,4))%64);
        $saved=$this->cached($key);
        if (is_array($saved) && ($saved['identity'] ?? '')===$identity && isset($saved['expires'],$saved['data']) && $saved['expires']>time()) { return $saved['data']; }
        $data=self::validate($this->client->fetchOptions($options),$options);
        $ttl=$data['complete'] ? 300 : 15;
        $this->cached($key,['identity'=>$identity,'expires'=>time()+$ttl,'data'=>$data],$ttl);
        return $data;
    }

    private function cached($key,$value=null,$ttl=null)
    {
        if ($this->cache) { return call_user_func($this->cache,$key,$value,$ttl); }
        if ($ttl===null) { return Cache::get($key); }
        return Cache::set($key,$value,$ttl);
    }

    private static function validate($data,array $options)
    {
        if (!is_array($data) || !isset($data['items'],$data['has_more'],$data['page'],$data['pagesize'],$data['source'],$data['complete'],$data['updated_at'],$data['warning']) || !is_array($data['items']) || count($data['items'])>$options['pagesize'] || !is_bool($data['has_more']) || !is_bool($data['complete']) || $data['source']!=='provider' || $data['page']!==$options['pg'] || $data['pagesize']!==$options['pagesize'] || !is_int($data['updated_at']) || $data['updated_at']<0 || !is_string($data['warning']) || strlen($data['warning'])>512 || preg_match('/[\x00-\x1f\x7f<>]/',$data['warning']) || !preg_match('//u',$data['warning'])) { throw new \RuntimeException('AVDB returned invalid filter suggestions.'); }
        foreach ($data['items'] as $item) {
            if (!is_string($item) || $item==='' || strlen($item)>2048 || preg_match('/[\x00-\x1f\x7f]/',$item) || !preg_match('//u',$item)) { throw new \RuntimeException('AVDB returned invalid filter values.'); }
        }
        return ['items'=>array_values($data['items']),'has_more'=>$data['has_more'],'page'=>$data['page'],'pagesize'=>$data['pagesize'],'source'=>'provider','complete'=>$data['complete'],'updated_at'=>$data['updated_at'],'warning'=>$data['warning']];
    }
}
