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
namespace app\common\taglib;
use think\template\TagLib;
use think\Db;

class Maccms extends Taglib {

	protected $tags = [
	    'link'=> ['attr'=>'order,by,type,not,start,num,cachetime'],
        'area'=> ['attr'=>'order,start,num'],
        'lang'=> ['attr'=>'order,start,num'],
        'year'=> ['attr'=>'order,start,num'],
        'class'=> ['attr'=>'order,start,num'],
        'version'=> ['attr'=>'order,start,num'],
        'state'=> ['attr'=>'order,start,num'],
        'letter'=> ['attr'=>'order,start,num'],
        'type' => ['attr' =>'order,by,start,num,id,ids,not,parent,flag,mid,format,cachetime,hide_empty'],
        'comment'=>['attr' =>'order,by,start,num,paging,pageurl,id,pid,rid,mid,uid,half'],
        'gbook'=>['attr' =>'order,by,start,num,paging,pageurl,rid,uid,half'],
        'role'=>['attr' =>'order,by,start,num,paging,pageurl,id,ids,not,rid,actor,name,level,letter,half,timeadd,timehits,time,cachetime'],
        'actor'=>['attr' =>'order,by,start,num,paging,pageurl,id,ids,not,area,sex,name,level,letter,type,typenot,starsign,blood,half,timeadd,timehits,time,cachetime,vod_id'],
        'topic' => ['attr' =>'order,by,start,num,id,ids,not,paging,pageurl,class,tag,half,timeadd,timehits,time,cachetime'],
        'art' => ['attr' =>'order,by,start,num,id,ids,not,paging,pageurl,type,typenot,class,tag,level,letter,half,rel,timeadd,timehits,time,hitsmonth,hitsweek,hitsday,hits,cachetime'],
        'vod' => ['attr' =>'order,by,start,num,id,ids,not,paging,pageurl,type,typenot,class,tag,area,lang,year,level,letter,half,rel,version,state,tv,weekday,timeadd,timehits,time,hitsmonth,hitsweek,hitsday,hits,isend,cachetime'],
        'website'=>['attr' =>'order,by,start,num,paging,pageurl,id,ids,not,area,lang,name,level,letter,type,typenot,half,timeadd,timehits,time,cachetime'],
        'foreach' => ['attr'=>'name,id,key'],
        'for' => ['attr'=>'start,end,comparison,step,name'],
    ];

    public function tagFor($tag,$content)
    {
        if(empty($tag['start'])){
            $tag['start'] = 1;
        }
        if(empty($tag['end'])){
            $tag['end'] = 5;
        }
        if(empty($tag['comparison'])){
            $tag['comparison'] = 'elt';
        }
        if(empty($tag['step'])){
            $tag['step'] = 1;
        }
        if(empty($tag['name'])){
            $tag['name'] = 'i';
        }

        $parse='';
        $parse .= '{for start="'.$tag['start'].'" end="'.$tag['end'].'" comparison="'.$tag['comparison'].'" step="'.$tag['step'].'" name="'.$tag['name'].'"}';
        $parse .= $content;
        $parse .= '{/for}';

        return $parse;
    }

    public function tagForeach($tag,$content)
    {
        if(empty($tag['id'])){
            $tag['id'] = 'vo';
        }
        if(empty($tag['key'])){
            $tag['key'] = 'key';
        }
        // Forward optional foreach tag attributes.
        // https://github.com/magicblack/maccms10/issues/984
        $parse_addon = '';
        if(!empty($tag['offset'])){
            $parse_addon .= ' offset="'.$tag['offset'].'"';
        }
        if(!empty($tag['length'])){
            $parse_addon .= ' length="'.$tag['length'].'"';
        }
        if(!empty($tag['mod'])){
            $parse_addon .= ' mod="'.$tag['mod'].'"';
        }
        if(!empty($tag['empty'])){
            $parse_addon .= ' empty="'.$tag['empty'].'"';
        }
        $parse='';
        $parse .= '{foreach name="'.$tag['name'].'" id="'.$tag['id'].'" key="'.$tag['key'].'"' . $parse_addon . '}';
        $parse .= $content;
        $parse .= '{/foreach}';
        
        return $parse;
    }

    public function tagArea($tag,$content)
    {
        if(empty($tag['id'])){
            $tag['id'] = 'vo';
        }
        if(empty($tag['key'])){
            $tag['key'] = 'key';
        }

        $parse = '<?php ';
        $parse .= '$__TAG__ = \'' . json_encode($tag) . '\';';
        $parse .= '$__LIST__ = model("Extend")->areaData($__TAG__);';
        $parse .= ' ?>';
        $parse .= '{volist name="__LIST__[\'list\']" id="'.$tag['id'].'" key="'.$tag['key'].'"';
        if(!empty($tag['offset'])){
            $parse .= ' offset="'.$tag['offset'].'"';
        }
        if(!empty($tag['length'])){
            $parse .= ' length="'.$tag['length'].'"';
        }
        if(!empty($tag['mod'])){
            $parse .= ' mod="'.$tag['mod'].'"';
        }
        if(!empty($tag['empty'])){
            $parse .= ' empty="'.$tag['empty'].'"';
        }
        $parse .= '}';
        $parse .= $content;
        $parse .= '{/volist}';

        return $parse;
    }

    public function tagLang($tag,$content)
    {
        if(empty($tag['id'])){
            $tag['id'] = 'vo';
        }
        if(empty($tag['key'])){
            $tag['key'] = 'key';
        }

        $parse = '<?php ';
        $parse .= '$__TAG__ = \'' . json_encode($tag) . '\';';
        $parse .= '$__LIST__ = model("Extend")->langData($__TAG__);';
        $parse .= ' ?>';
        $parse .= '{volist name="__LIST__[\'list\']" id="'.$tag['id'].'" key="'.$tag['key'].'"';
        if(!empty($tag['offset'])){
            $parse .= ' offset="'.$tag['offset'].'"';
        }
        if(!empty($tag['length'])){
            $parse .= ' length="'.$tag['length'].'"';
        }
        if(!empty($tag['mod'])){
            $parse .= ' mod="'.$tag['mod'].'"';
        }
        if(!empty($tag['empty'])){
            $parse .= ' empty="'.$tag['empty'].'"';
        }
        $parse .= '}';
        $parse .= $content;
        $parse .= '{/volist}';

        return $parse;
    }

    public function tagClass($tag,$content)
    {
        if(empty($tag['id'])){
            $tag['id'] = 'vo';
        }
        if(empty($tag['key'])){
            $tag['key'] = 'key';
        }

        $parse = '<?php ';
        $parse .= '$__TAG__ = \'' . json_encode($tag) . '\';';
        $parse .= '$__LIST__ = model("Extend")->classData($__TAG__);';
        $parse .= ' ?>';
        $parse .= '{volist name="__LIST__[\'list\']" id="'.$tag['id'].'" key="'.$tag['key'].'"';
        if(!empty($tag['offset'])){
            $parse .= ' offset="'.$tag['offset'].'"';
        }
        if(!empty($tag['length'])){
            $parse .= ' length="'.$tag['length'].'"';
        }
        if(!empty($tag['mod'])){
            $parse .= ' mod="'.$tag['mod'].'"';
        }
        if(!empty($tag['empty'])){
            $parse .= ' empty="'.$tag['empty'].'"';
        }
        $parse .= '}';
        $parse .= $content;
        $parse .= '{/volist}';

        return $parse;
    }

    public function tagYear($tag,$content)
    {
        if(empty($tag['id'])){
            $tag['id'] = 'vo';
        }
        if(empty($tag['key'])){
            $tag['key'] = 'key';
        }

        $parse = '<?php ';
        $parse .= '$__TAG__ = \'' . json_encode($tag) . '\';';
        $parse .= '$__LIST__ = model("Extend")->YearData($__TAG__);';
        $parse .= ' ?>';
        $parse .= '{volist name="__LIST__[\'list\']" id="'.$tag['id'].'" key="'.$tag['key'].'"';
        if(!empty($tag['offset'])){
            $parse .= ' offset="'.$tag['offset'].'"';
        }
        if(!empty($tag['length'])){
            $parse .= ' length="'.$tag['length'].'"';
        }
        if(!empty($tag['mod'])){
            $parse .= ' mod="'.$tag['mod'].'"';
        }
        if(!empty($tag['empty'])){
            $parse .= ' empty="'.$tag['empty'].'"';
        }
        $parse .= '}';
        $parse .= $content;
        $parse .= '{/volist}';

        return $parse;
    }

    public function tagVersion($tag,$content)
    {
        if(empty($tag['id'])){
            $tag['id'] = 'vo';
        }
        if(empty($tag['key'])){
            $tag['key'] = 'key';
        }

        $parse = '<?php ';
        $parse .= '$__TAG__ = \'' . json_encode($tag) . '\';';
        $parse .= '$__LIST__ = model("Extend")->versionData($__TAG__);';
        $parse .= ' ?>';
        $parse .= '{volist name="__LIST__[\'list\']" id="'.$tag['id'].'" key="'.$tag['key'].'"';
        if(!empty($tag['offset'])){
            $parse .= ' offset="'.$tag['offset'].'"';
        }
        if(!empty($tag['length'])){
            $parse .= ' length="'.$tag['length'].'"';
        }
        if(!empty($tag['mod'])){
            $parse .= ' mod="'.$tag['mod'].'"';
        }
        if(!empty($tag['empty'])){
            $parse .= ' empty="'.$tag['empty'].'"';
        }
        $parse .= '}';
        $parse .= $content;
        $parse .= '{/volist}';

        return $parse;
    }

    public function tagState($tag,$content)
    {
        if(empty($tag['id'])){
            $tag['id'] = 'vo';
        }
        if(empty($tag['key'])){
            $tag['key'] = 'key';
        }

        $parse = '<?php ';
        $parse .= '$__TAG__ = \'' . json_encode($tag) . '\';';
        $parse .= '$__LIST__ = model("Extend")->stateData($__TAG__);';
        $parse .= ' ?>';
        $parse .= '{volist name="__LIST__[\'list\']" id="'.$tag['id'].'" key="'.$tag['key'].'"';
        if(!empty($tag['offset'])){
            $parse .= ' offset="'.$tag['offset'].'"';
        }
        if(!empty($tag['length'])){
            $parse .= ' length="'.$tag['length'].'"';
        }
        if(!empty($tag['mod'])){
            $parse .= ' mod="'.$tag['mod'].'"';
        }
        if(!empty($tag['empty'])){
            $parse .= ' empty="'.$tag['empty'].'"';
        }
        $parse .= '}';
        $parse .= $content;
        $parse .= '{/volist}';

        return $parse;
    }

    public function tagLetter($tag,$content)
    {
        if(empty($tag['id'])){
            $tag['id'] = 'vo';
        }
        if(empty($tag['key'])){
            $tag['key'] = 'key';
        }

        $parse = '<?php ';
        $parse .= '$__TAG__ = \'' . json_encode($tag) . '\';';
        $parse .= '$__LIST__ = model("Extend")->letterData($__TAG__);';
        $parse .= ' ?>';
        $parse .= '{volist name="__LIST__[\'list\']" id="'.$tag['id'].'" key="'.$tag['key'].'"';
        if(!empty($tag['offset'])){
            $parse .= ' offset="'.$tag['offset'].'"';
        }
        if(!empty($tag['length'])){
            $parse .= ' length="'.$tag['length'].'"';
        }
        if(!empty($tag['mod'])){
            $parse .= ' mod="'.$tag['mod'].'"';
        }
        if(!empty($tag['empty'])){
            $parse .= ' empty="'.$tag['empty'].'"';
        }
        $parse .= '}';
        $parse .= $content;
        $parse .= '{/volist}';

        return $parse;
    }

    public function tagLink($tag,$content)
    {
        if(empty($tag['id'])){
            $tag['id'] = 'vo';
        }
        if(empty($tag['key'])){
            $tag['key'] = 'key';
        }

        $parse = '<?php ';
        $parse .= '$__TAG__ = \'' . json_encode($tag) . '\';';
        $parse .= '$__LIST__ = model("Link")->listCacheData($__TAG__);';
        $parse .= ' ?>';
        $parse .= '{volist name="__LIST__[\'list\']" id="'.$tag['id'].'" key="'.$tag['key'].'"';
        if(!empty($tag['offset'])){
            $parse .= ' offset="'.$tag['offset'].'"';
        }
        if(!empty($tag['length'])){
            $parse .= ' length="'.$tag['length'].'"';
        }
        if(!empty($tag['mod'])){
            $parse .= ' mod="'.$tag['mod'].'"';
        }
        if(!empty($tag['empty'])){
            $parse .= ' empty="'.$tag['empty'].'"';
        }
        $parse .= '}';
        $parse .= $content;
        $parse .= '{/volist}';

        return $parse;
    }

    public function tagType($tag,$content)
    {
        if(empty($tag['id'])){
            $tag['id'] = 'vo';
        }
        if(empty($tag['key'])){
            $tag['key'] = 'key';
        }

        $parse = '<?php ';
        $parse .= '$__TAG__ = \'' . json_encode($tag) . '\';';
        $parse .= '$__LIST__ = model("Type")->listCacheData($__TAG__);';
        $parse .= ' ?>';
        $parse .= '{volist name="__LIST__[\'list\']" id="'.$tag['id'].'" key="'.$tag['key'].'"';
        if(!empty($tag['offset'])){
            $parse .= ' offset="'.$tag['offset'].'"';
        }
        if(!empty($tag['length'])){
            $parse .= ' length="'.$tag['length'].'"';
        }
        if(!empty($tag['mod'])){
            $parse .= ' mod="'.$tag['mod'].'"';
        }
        if(!empty($tag['empty'])){
            $parse .= ' empty="'.$tag['empty'].'"';
        }
        $parse .= '}';
        $parse .= $content;
        $parse .= '{/volist}';

        return $parse;
    }

    public function tagComment($tag,$content)
    {
        if(empty($tag['id'])){
            $tag['id'] = 'vo';
        }
        if(empty($tag['key'])){
            $tag['key'] = 'key';
        }

        $parse = '<?php ';
        $parse .= '$__TAG__ = \'' . json_encode($tag) . '\';';
        $parse .= '$__LIST__ = model("Comment")->listCacheData($__TAG__);';
        if($tag['paging']=='yes'){
            $parse .= '$__PAGING__ = mac_page_param($__LIST__[\'total\'],$__LIST__[\'limit\'],$__LIST__[\'page\'],$__LIST__[\'pageurl\'],$__LIST__[\'half\']);';
        }
        $parse .= ' ?>';
        $parse .= '{volist name="__LIST__[\'list\']" id="'.$tag['id'].'" key="'.$tag['key'].'"';
        if(!empty($tag['offset'])){
            $parse .= ' offset="'.$tag['offset'].'"';
        }
        if(!empty($tag['length'])){
            $parse .= ' length="'.$tag['length'].'"';
        }
        if(!empty($tag['mod'])){
            $parse .= ' mod="'.$tag['mod'].'"';
        }
        if(!empty($tag['empty'])){
            $parse .= ' empty="'.$tag['empty'].'"';
        }
        $parse .= '}';
        $parse .= $content;
        $parse .= '{/volist}';

        return $parse;
    }

    public function tagGbook($tag,$content)
    {
        if(empty($tag['id'])){
            $tag['id'] = 'vo';
        }
        if(empty($tag['key'])){
            $tag['key'] = 'key';
        }

        $parse = '<?php ';
        $parse .= '$__TAG__ = \'' . json_encode($tag) . '\';';
        $parse .= '$__LIST__ = model("Gbook")->listCacheData($__TAG__);';
        if($tag['paging']=='yes'){
            $parse .= '$__PAGING__ = mac_page_param($__LIST__[\'total\'],$__LIST__[\'limit\'],$__LIST__[\'page\'],$__LIST__[\'pageurl\'],$__LIST__[\'half\']);';
        }
        $parse .= ' ?>';
        $parse .= '{volist name="__LIST__[\'list\']" id="'.$tag['id'].'" key="'.$tag['key'].'"';
        if(!empty($tag['offset'])){
            $parse .= ' offset="'.$tag['offset'].'"';
        }
        if(!empty($tag['length'])){
            $parse .= ' length="'.$tag['length'].'"';
        }
        if(!empty($tag['mod'])){
            $parse .= ' mod="'.$tag['mod'].'"';
        }
        if(!empty($tag['empty'])){
            $parse .= ' empty="'.$tag['empty'].'"';
        }
        $parse .= '}';
        $parse .= $content;
        $parse .= '{/volist}';

        return $parse;
    }

    public function tagTopic($tag,$content)
    {
        if(empty($tag['id'])){
            $tag['id'] = 'vo';
        }
        if(empty($tag['key'])){
            $tag['key'] = 'key';
        }

        $parse = '<?php ';
        $parse .= '$__TAG__ = \'' . json_encode($tag) . '\';';
        $parse .= '$__LIST__ = model("Topic")->listCacheData($__TAG__);';
        if($tag['paging']=='yes'){
            $parse .= '$__PAGING__ = mac_page_param($__LIST__[\'total\'],$__LIST__[\'limit\'],$__LIST__[\'page\'],$__LIST__[\'pageurl\'],$__LIST__[\'half\']);';
        }
        $parse .= ' ?>';
        $parse .= '{volist name="__LIST__[\'list\']" id="'.$tag['id'].'" key="'.$tag['key'].'"';
        if(!empty($tag['offset'])){
            $parse .= ' offset="'.$tag['offset'].'"';
        }
        if(!empty($tag['length'])){
            $parse .= ' length="'.$tag['length'].'"';
        }
        if(!empty($tag['mod'])){
            $parse .= ' mod="'.$tag['mod'].'"';
        }
        if(!empty($tag['empty'])){
            $parse .= ' empty="'.$tag['empty'].'"';
        }
        $parse .= '}';
        $parse .= $content;
        $parse .= '{/volist}';

        return $parse;
    }

    public function tagActor($tag,$content)
    {
        if(empty($tag['id'])){
            $tag['id'] = 'vo';
        }
        if(empty($tag['key'])){
            $tag['key'] = 'key';
        }

        $parse = '<?php ';
        $parse .= '$__TAG__ = \'' . json_encode($tag) . '\';';
        $parse .= '$__LIST__ = model("Actor")->listCacheData($__TAG__);';
        if($tag['paging']=='yes'){
            $parse .= '$__PAGING__ = mac_page_param($__LIST__[\'total\'],$__LIST__[\'limit\'],$__LIST__[\'page\'],$__LIST__[\'pageurl\'],$__LIST__[\'half\']);';
        }
        $parse .= ' ?>';
        $parse .= '{volist name="__LIST__[\'list\']" id="'.$tag['id'].'" key="'.$tag['key'].'"';
        if(!empty($tag['offset'])){
            $parse .= ' offset="'.$tag['offset'].'"';
        }
        if(!empty($tag['length'])){
            $parse .= ' length="'.$tag['length'].'"';
        }
        if(!empty($tag['mod'])){
            $parse .= ' mod="'.$tag['mod'].'"';
        }
        if(!empty($tag['empty'])){
            $parse .= ' empty="'.$tag['empty'].'"';
        }
        $parse .= '}';
        $parse .= $content;
        $parse .= '{/volist}';

        return $parse;
    }

    public function tagRole($tag,$content)
    {
        if(empty($tag['id'])){
            $tag['id'] = 'vo';
        }
        if(empty($tag['key'])){
            $tag['key'] = 'key';
        }

        $parse = '<?php ';
        $parse .= '$__TAG__ = \'' . json_encode($tag) . '\';';
        $parse .= '$__LIST__ = model("Role")->listCacheData($__TAG__);';
        if($tag['paging']=='yes'){
            $parse .= '$__PAGING__ = mac_page_param($__LIST__[\'total\'],$__LIST__[\'limit\'],$__LIST__[\'page\'],$__LIST__[\'pageurl\'],$__LIST__[\'half\']);';
        }
        $parse .= ' ?>';
        $parse .= '{volist name="__LIST__[\'list\']" id="'.$tag['id'].'" key="'.$tag['key'].'"';
        if(!empty($tag['offset'])){
            $parse .= ' offset="'.$tag['offset'].'"';
        }
        if(!empty($tag['length'])){
            $parse .= ' length="'.$tag['length'].'"';
        }
        if(!empty($tag['mod'])){
            $parse .= ' mod="'.$tag['mod'].'"';
        }
        if(!empty($tag['empty'])){
            $parse .= ' empty="'.$tag['empty'].'"';
        }
        $parse .= '}';
        $parse .= $content;
        $parse .= '{/volist}';

        return $parse;
    }

    public function tagArt($tag,$content)
    {
        if(empty($tag['id'])){
            $tag['id'] = 'vo';
        }
        if(empty($tag['key'])){
            $tag['key'] = 'key';
        }

        $parse = '<?php ';
        $parse .= '$__TAG__ = \'' . json_encode($tag) . '\';';
        $parse .= '$__LIST__ = model("Art")->listCacheData($__TAG__);';
        if($tag['paging']=='yes'){
            $parse .= '$__PAGING__ = mac_page_param($__LIST__[\'total\'],$__LIST__[\'limit\'],$__LIST__[\'page\'],$__LIST__[\'pageurl\'],$__LIST__[\'half\']);';
        }
        $parse .= ' ?>';
        $parse .= '{volist name="__LIST__[\'list\']" id="'.$tag['id'].'" key="'.$tag['key'].'"';
        if(!empty($tag['offset'])){
            $parse .= ' offset="'.$tag['offset'].'"';
        }
        if(!empty($tag['length'])){
            $parse .= ' length="'.$tag['length'].'"';
        }
        if(!empty($tag['mod'])){
            $parse .= ' mod="'.$tag['mod'].'"';
        }
        if(!empty($tag['empty'])){
            $parse .= ' empty="'.$tag['empty'].'"';
        }
        $parse .= '}';
        $parse .= $content;
        $parse .= '{/volist}';

        return $parse;
    }

    public function tagVod($tag,$content)
    {
        if(empty($tag['id'])){
            $tag['id'] = 'vo';
        }
        if(empty($tag['key'])){
            $tag['key'] = 'key';
        }

        $parse = '<?php ';
        $parse .= '$__TAG__ = \'' . json_encode($tag) . '\';';
        $parse .= '$__LIST__ = model("Vod")->listCacheData($__TAG__);';
        $parse .= '$__LIST__["list"] = \\app\\common\\util\\AvdbLanguage::localizeMany($__LIST__["list"] ?? []);';
        if($tag['paging']=='yes'){
            $parse .= '$__PAGING__ = mac_page_param($__LIST__[\'total\'],$__LIST__[\'limit\'],$__LIST__[\'page\'],$__LIST__[\'pageurl\'],$__LIST__[\'half\']);';
        }
        $parse .= ' ?>';
        $parse .= '{volist name="__LIST__[\'list\']" id="'.$tag['id'].'" key="'.$tag['key'].'"';
        if(!empty($tag['offset'])){
            $parse .= ' offset="'.$tag['offset'].'"';
        }
        if(!empty($tag['length'])){
            $parse .= ' length="'.$tag['length'].'"';
        }
        if(!empty($tag['mod'])){
            $parse .= ' mod="'.$tag['mod'].'"';
        }
        if(!empty($tag['empty'])){
            $parse .= ' empty="'.$tag['empty'].'"';
        }
        $parse .= '}';
        $parse .= $content;
        $parse .= '{/volist}';

        return $parse;
    }

    public function tagWebsite($tag,$content)
    {
        if(empty($tag['id'])){
            $tag['id'] = 'vo';
        }
        if(empty($tag['key'])){
            $tag['key'] = 'key';
        }

        $parse = '<?php ';
        $parse .= '$__TAG__ = \'' . json_encode($tag) . '\';';
        $parse .= '$__LIST__ = model("Website")->listCacheData($__TAG__);';
        if($tag['paging']=='yes'){
            $parse .= '$__PAGING__ = mac_page_param($__LIST__[\'total\'],$__LIST__[\'limit\'],$__LIST__[\'page\'],$__LIST__[\'pageurl\'],$__LIST__[\'half\']);';
        }
        $parse .= ' ?>';
        $parse .= '{volist name="__LIST__[\'list\']" id="'.$tag['id'].'" key="'.$tag['key'].'"';
        if(!empty($tag['offset'])){
            $parse .= ' offset="'.$tag['offset'].'"';
        }
        if(!empty($tag['length'])){
            $parse .= ' length="'.$tag['length'].'"';
        }
        if(!empty($tag['mod'])){
            $parse .= ' mod="'.$tag['mod'].'"';
        }
        if(!empty($tag['empty'])){
            $parse .= ' empty="'.$tag['empty'].'"';
        }
        $parse .= '}';
        $parse .= $content;
        $parse .= '{/volist}';

        return $parse;
    }
}
