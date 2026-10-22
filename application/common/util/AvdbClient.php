<?php
/*
 * This file is part of JavCMS - https://javcms.net
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
namespace app\common\util;

/** Fixed native AVDB connection. Transport injection is for isolated fixtures only. */
class AvdbClient
{
    const MAX_BODY = 8388608;
    const MAX_OPTION_VALUE = 2048; // Provider VARCHAR(500) permits up to 2000 UTF-8 bytes.
    private $transport;

    public function __construct($transport = null)
    {
        if ($transport !== null && !is_callable($transport)) { throw new \InvalidArgumentException('Invalid AVDB transport.'); }
        $this->transport = $transport;
    }

    public static function languages()
    {
        return ['en'=>'English','vi'=>'Vietnamese','th'=>'Thai','id'=>'Indonesian','ko'=>'Korean','zh'=>'Chinese','ja'=>'Japanese','fr'=>'French','de'=>'German','es'=>'Spanish','pt'=>'Portuguese','ru'=>'Russian','ar'=>'Arabic','tr'=>'Turkish','hi'=>'Hindi','ms'=>'Malay','tl'=>'Filipino'];
    }

    public function normalizeFilters(array $filters)
    {
        $allowed = ['wd','code','t','actor','director','writer','category','year','h','isend','from','sort_direction','pg','pagesize','ids','ac'];
        $out = ['ac'=>'videolist','pg'=>1,'pagesize'=>20];
        foreach ($filters as $key=>$value) {
            if (!in_array($key, $allowed, true)) { throw new \InvalidArgumentException('Unsupported AVDB filter: ' . $key); }
            if ($key === 'ids' && is_array($value)) { $value = implode(',', $value); }
            if (!is_scalar($value) && $value !== null) { throw new \InvalidArgumentException('Invalid AVDB filter: ' . $key); }
            $value = trim((string)$value);
            if ($value === '') { continue; }
            if (in_array($key, ['pg','pagesize','t','h'], true)) {
                $max = ['pg'=>1000000,'pagesize'=>100,'t'=>2147483647,'h'=>876000][$key];
                if (!ctype_digit($value) || (float)$value < 1 || (float)$value > $max) { throw new \InvalidArgumentException('Invalid AVDB number: ' . $key); }
                $value = (int)$value;
            } elseif ($key === 'isend') {
                if ($value !== '0' && $value !== '1') { throw new \InvalidArgumentException('Completion must be 0 or 1.'); }
                $value = (int)$value;
            } elseif ($key === 'year') {
                if (strlen($value)>100) { throw new \InvalidArgumentException('Year / release date selection exceeds 100 bytes.'); }
                if (strpos($value, ',')!==false || preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value)) {
                    $years=array_unique(explode(',', $value));
                    foreach ($years as $year) {
                        if (!preg_match('/^(\d{4})(?:-(\d{2})-(\d{2}))?$/D',$year,$token) ||
                            (int)$token[1]<1900 || (int)$token[1]>(int)date('Y')+3 ||
                            (isset($token[2]) && !checkdate((int)$token[2],(int)$token[3],(int)$token[1]))) {
                            throw new \InvalidArgumentException('Use valid years or release dates, for example 2020,2021-05-06.');
                        }
                    }
                    $out[$key]=implode(',', $years); continue;
                }
                if (!preg_match('/^(\d{4})(?:-(\d{4}))?$/D', $value, $m)) { throw new \InvalidArgumentException('Use a year or year range, for example 2020-2026.'); }
                $a = (int)$m[1]; $b = isset($m[2]) ? (int)$m[2] : $a;
                if (min($a,$b) < 1900 || max($a,$b) > (int)date('Y')+3) { throw new \InvalidArgumentException('Year is outside the supported range.'); }
                $value = isset($m[2]) ? min($a,$b).'-'.max($a,$b) : (string)$a;
            } elseif ($key === 'sort_direction') {
                if (!in_array($value, ['asc','desc'], true)) { throw new \InvalidArgumentException('Order must be asc or desc.'); }
            } elseif ($key === 'ac') {
                // Always fetch complete metadata without provider detail view-counter writes.
                if (!in_array($value, ['list','videolist','detail'], true)) { throw new \InvalidArgumentException('Unsupported AVDB action.'); }
                $value = $value === 'list' ? 'list' : 'videolist';
            } elseif ($key === 'ids') {
                if (!preg_match('/^[1-9]\d*(?:,[1-9]\d*)*$/D', $value)) { throw new \InvalidArgumentException('Video IDs must be positive integers.'); }
                $ids = array_unique(explode(',', $value));
                if (count($ids)>100) { throw new \InvalidArgumentException('Fetch at most 100 video IDs at once.'); }
                foreach ($ids as $id) { if ((float)$id>2147483647) { throw new \InvalidArgumentException('Invalid video ID.'); } }
                $value = implode(',', $ids);
            } else {
                $maxBytes=in_array($key,['wd','actor','director','writer','category'],true)?2048:256;
                if (strlen($value)>$maxBytes || !preg_match('//u',$value) || preg_match('/[\x00-\x1F\x7F]/', $value)) { throw new \InvalidArgumentException('Search filter is too long or contains invalid characters.'); }
                if ($key === 'from' && !preg_match('/^[a-zA-Z0-9_-]+(?:,[a-zA-Z0-9_-]+)*$/D', $value)) { throw new \InvalidArgumentException('Invalid playback source.'); }
            }
            $out[$key]=$value;
        }
        return $out;
    }

    /** Import screen defaults to the native 1,000-row page; legacy fetch stays bounded to 100. */
    public function normalizeImportFilters(array $filters)
    {
        $tag=$filters['tag'] ?? null; unset($filters['tag']);
        if ($tag!==null && (!is_scalar($tag) || strlen((string)$tag)>2048 || !preg_match('//u',(string)$tag) || preg_match('/[\x00-\x1F\x7F]/',(string)$tag))) { throw new \InvalidArgumentException('Invalid import tag filter.'); }
        $tag=trim((string)$tag);
        $size=$filters['pagesize'] ?? 1000;
        if (!is_scalar($size) || !preg_match('/^[1-9][0-9]*$/D',(string)$size) || !in_array((int)$size,[10,20,50,100,1000],true)) { throw new \InvalidArgumentException('Choose an import page size of 10, 20, 50, 100 or 1000.'); }
        unset($filters['pagesize']); $out=$this->normalizeFilters($filters); $out['pagesize']=(int)$size; if ($tag!=='') { $out['tag']=$tag; } return $out;
    }

    public function fetch(array $filters, $locale = 'en')
    {
        if (!is_string($locale) || !isset(self::languages()[$locale])) { throw new \InvalidArgumentException('Unsupported AVDB language.'); }
        $filters = $this->normalizeFilters($filters);
        $url = 'https://avdbapi.com/' . ($locale === 'en' ? '' : $locale.'/') . 'api.php/provide1/vod?' . http_build_query($filters, '', '&', PHP_QUERY_RFC3986);
        $data = $this->fetchJson($url);
        if (!isset($data['list']) || !is_array($data['list'])) { throw new \RuntimeException('AVDB returned malformed or unsuccessful JSON.'); }
        foreach (['page','pagecount','limit','total'] as $key) {
            if (!isset($data[$key]) || !is_scalar($data[$key]) || !preg_match('/^\d+$/D',(string)$data[$key]) || (float)$data[$key]>PHP_INT_MAX) { throw new \RuntimeException('AVDB returned invalid pagination.'); }
            $data[$key]=(int)$data[$key];
        }
        if (count($data['list'])>100 || $data['page']<1 || $data['limit']<1 || $data['limit']>100) { throw new \RuntimeException('AVDB returned unbounded pagination.'); }
        foreach ($data['list'] as $row) {
            if (!is_array($row) || !isset($row['vod_id'],$row['vod_name']) || !is_scalar($row['vod_id']) || !preg_match('/^[1-9]\d*$/D',(string)$row['vod_id']) || (float)$row['vod_id']>2147483647 || !is_string($row['vod_name'])) { throw new \RuntimeException('AVDB returned an invalid video row.'); }
            foreach ($row as $value) { if (!is_scalar($value) && $value!==null) { throw new \RuntimeException('AVDB returned nested video metadata.'); } }
        }
        if (isset($data['class']) && !is_array($data['class'])) { throw new \RuntimeException('AVDB returned invalid categories.'); }
        $data['class']=$data['class'] ?? [];
        return $data;
    }

    /** English import uses the provider default page size (currently 1,000); previews retain the 100-row bound. */
    public function fetchImport(array $filters, $locale = 'en')
    {
        if ($locale !== 'en') return $this->fetch($filters, $locale);
        $rawIds=$filters['ids'] ?? null; unset($filters['ids']);
        $hasTag=array_key_exists('tag',$filters);
        if ($hasTag) { $filters=$this->normalizeImportFilters($filters); }
        else { unset($filters['pagesize']); $filters=$this->normalizeFilters($filters); }
        $requestedSize=$filters['pagesize']; $hasTag=isset($filters['tag']) && $filters['tag']!=='';
        if ($rawIds!==null) {
            if (is_array($rawIds)) { foreach($rawIds as$id)if(!is_int($id)&&!is_string($id))throw new \InvalidArgumentException('Invalid import ID.'); $rawIds=implode(',', $rawIds); }
            if (!is_string($rawIds) || strlen($rawIds)>11000 || !preg_match('/^[1-9]\d*(?:,[1-9]\d*)*$/D',$rawIds)) throw new \InvalidArgumentException('Invalid import IDs.');
            $ids=array_values(array_unique(explode(',',$rawIds)));
            if (count($ids)>1000) throw new \InvalidArgumentException('Fetch at most 1,000 import IDs.');
            foreach($ids as$id)if((float)$id>2147483647)throw new \InvalidArgumentException('Invalid import ID.');
            $filters['ids']=implode(',',$ids);
        }
        unset($filters['pagesize']); if ($hasTag && $requestedSize<1000) { $filters['pagesize']=$requestedSize; }
        // IDs are already positive integers. Literal commas keep native 1,000-ID
        // requests below common server URI limits without changing query values.
        $validatedIds=$filters['ids'] ?? null; unset($filters['ids']);
        $query=http_build_query($filters,'','&',PHP_QUERY_RFC3986);
        if ($validatedIds!==null) { $query.='&ids='.$validatedIds; }
        $data=$this->fetchJson('https://avdbapi.com/api.php/'.($hasTag?'avdbcatalog':'provide1').'/vod?'.$query);
        if (!isset($data['list']) || !is_array($data['list'])) { throw new \RuntimeException('AVDB returned malformed or unsuccessful JSON.'); }
        foreach (['page','pagecount','limit','total'] as $key) {
            if (!isset($data[$key]) || !is_scalar($data[$key]) || !preg_match('/^\d+$/D',(string)$data[$key]) || (float)$data[$key]>PHP_INT_MAX) { throw new \RuntimeException('AVDB returned invalid pagination.'); }
            $data[$key]=(int)$data[$key];
        }
        if (count($data['list'])>1000 || $data['page']<1 || $data['limit']<1 || $data['limit']>1000) { throw new \RuntimeException('AVDB returned unbounded pagination.'); }
        foreach ($data['list'] as $row) {
            if (!is_array($row) || !isset($row['vod_id'],$row['vod_name']) || !is_scalar($row['vod_id']) || !preg_match('/^[1-9]\d*$/D',(string)$row['vod_id']) || (float)$row['vod_id']>2147483647 || !is_string($row['vod_name'])) { throw new \RuntimeException('AVDB returned an invalid video row.'); }
            foreach ($row as $value) { if (!is_scalar($value) && $value!==null) { throw new \RuntimeException('AVDB returned nested video metadata.'); } }
        }
        if (isset($data['class']) && !is_array($data['class'])) { throw new \RuntimeException('AVDB returned invalid categories.'); }
        $data['class']=$data['class'] ?? [];
        // The caller must chunk remaining requested IDs using this actual reported limit.
        $data['reported_limit']=$data['limit'];
        return $data;
    }

    /** One bounded GET for every requested non-English locale; no English fallback payloads. */
    public function fetchTranslations(array $ids, array $locales)
    {
        if (!$ids || count($ids)>100 || !$locales || count($locales)>16) throw new \InvalidArgumentException('Invalid translation batch.');
        $requested=[];foreach($ids as$id){if((!is_int($id)&&!is_string($id))||!preg_match('/^[1-9]\d*$/D',(string)$id)||(float)$id>2147483647)throw new \InvalidArgumentException('Invalid translation ID.');$requested[(int)$id]=true;}
        $locales=array_values(array_unique($locales));foreach($locales as$l)if(!is_string($l)||$l==='en'||!isset(self::languages()[$l]))throw new \InvalidArgumentException('Invalid translation locale.');
        $data=$this->fetchJson('https://avdbapi.com/api.php/avdbtranslations/index?'.http_build_query(['ids'=>implode(',',array_keys($requested)),'locales'=>implode(',',$locales)],'','&',PHP_QUERY_RFC3986));
        return $this->translationBundle($data,$requested,$locales);
    }

    /** Stable full export; legacy filtered, selected and page imports keep their endpoints. */
    public function fetchExport(array $params)
    {
        foreach($params as$key=>$value)if(!in_array($key,['cursor','upper_id','limit'],true))throw new \InvalidArgumentException('Invalid full export parameter.');
        $query=[];
        foreach(['cursor'=>0,'upper_id'=>0,'limit'=>1000]as$key=>$default){
            $value=$params[$key]??$default;
            if((!is_int($value)&&!is_string($value))||!preg_match('/^(?:0|[1-9][0-9]*)$/D',(string)$value)||(float)$value>($key==='limit'?1000:2147483647)||($key==='limit'&&(int)$value<1))throw new \InvalidArgumentException('Invalid full export number.');
            $query[$key]=(int)$value;
        }
        if($query['cursor']>0&&($query['upper_id']<1||$query['cursor']>$query['upper_id']))throw new \InvalidArgumentException('Invalid full export checkpoint.');
        $data=$this->fetchJson('https://avdbapi.com/api.php/avdbexport/vod?'.http_build_query($query,'','&',PHP_QUERY_RFC3986));
        if(($data['export_policy']??null)!=='stable-vod-id-desc-v1'||!isset($data['list'],$data['upper_id'],$data['next_cursor'],$data['has_more'],$data['limit'])||!is_array($data['list'])||array_values($data['list'])!==$data['list']||count($data['list'])>$query['limit']||!is_bool($data['has_more'])||$data['has_more']!==(bool)$data['list'])throw new \RuntimeException('AVDB returned invalid full export metadata.');
        foreach(['upper_id','next_cursor','limit']as$key)if(!is_int($data[$key])||$data[$key]<0||$data[$key]>($key==='limit'?1000:2147483647))throw new \RuntimeException('AVDB returned invalid full export bounds.');
        if($data['limit']!==$query['limit']||($query['upper_id']>0&&$data['upper_id']!==$query['upper_id'])||!array_key_exists('total',$data)||($data['total']!==null&&(!is_int($data['total'])||$data['total']<0||$data['total']>2147483647))||($query['cursor']===0&&$data['total']===null))throw new \RuntimeException('AVDB full export snapshot changed.');
        $previous=$query['cursor']>0?$query['cursor']:$data['upper_id']+1;
        foreach($data['list']as$row){
            if(!is_array($row)||!isset($row['vod_id'],$row['vod_name'])||(!is_int($row['vod_id'])&&!is_string($row['vod_id']))||!preg_match('/^[1-9][0-9]*$/D',(string)$row['vod_id'])||(float)$row['vod_id']>2147483647||!is_string($row['vod_name'])||(int)$row['vod_id']>=$previous||(int)$row['vod_id']>$data['upper_id'])throw new \RuntimeException('AVDB returned invalid full export identity order.');
            foreach($row as$value)if(!is_scalar($value)&&$value!==null)throw new \RuntimeException('AVDB returned nested full export metadata.');
            $previous=(int)$row['vod_id'];
        }
        if(($data['list']&&$data['next_cursor']!==$previous)||(!$data['list']&&$data['next_cursor']!==$query['cursor']))throw new \RuntimeException('AVDB full export cursor did not match its rows.');
        $data['exhausted']=!$data['has_more'];$data['reported_limit']=$data['limit'];return $data;
    }

    /** Larger bounded bundles only for the new full-export job path. */
    public function fetchExportTranslations(array $ids,array $locales)
    {
        if(!$ids||count($ids)>500||!$locales||count($locales)>16)throw new \InvalidArgumentException('Invalid full export translation batch.');
        $requested=[];foreach($ids as$id){if((!is_int($id)&&!is_string($id))||!preg_match('/^[1-9][0-9]*$/D',(string)$id)||(float)$id>2147483647)throw new \InvalidArgumentException('Invalid translation ID.');$requested[(int)$id]=true;}
        $locales=array_values(array_unique($locales));foreach($locales as$locale)if(!is_string($locale)||$locale==='en'||!isset(self::languages()[$locale]))throw new \InvalidArgumentException('Invalid translation locale.');
        try{
            $data=$this->fetchJson('https://avdbapi.com/api.php/avdbtranslationbatch/index?'.http_build_query(['ids'=>implode(',',array_keys($requested)),'locales'=>implode(',',$locales)],'','&',PHP_QUERY_RFC3986));
        }catch(\RuntimeException $error){
            if($error->getMessage()==='AVDB is unavailable (HTTP 413).'||$error->getMessage()==='AVDB response exceeds the size limit.')throw new AvdbClientException('AVDB import translation batch is too large; retry with a smaller checkpoint batch.',413,0);
            throw $error;
        }
        return $this->translationBundle($data,$requested,$locales);
    }

    private function translationBundle(array $data,array $requested,array $locales)
    {
        if (($data['source']??null)!=='provider'||($data['quality_policy']??null)!=='stored-title-script-v1'||!isset($data['items'])||!is_array($data['items'])||count($data['items'])!==count($requested)) throw new \RuntimeException('AVDB returned invalid translation metadata.');
        $out=[];
        foreach($data['items']as$id=>$item){
            if(!preg_match('/^[1-9]\d*$/D',(string)$id)||!isset($requested[(int)$id])||!is_array($item))throw new \RuntimeException('AVDB returned an unexpected translation ID.');
            foreach(['translations','rejected_locales','unavailable_locales','quality_reasons','rejected_title_sha256']as$k)if(!isset($item[$k])||!is_array($item[$k])||count($item[$k])>16)throw new \RuntimeException('AVDB returned invalid translation classification.');
            foreach($item['translations']as$l=>$payload){
                if(!in_array($l,$locales,true)||!is_array($payload)||!$payload||count($payload)>4)throw new \RuntimeException('AVDB returned invalid localized fields.');
                foreach($payload as$k=>$v){$max=$k==='vod_content'?65535:255;if(!in_array($k,['vod_name','vod_en','vod_content','vod_blurb'],true)||!is_string($v)||!preg_match('//u',$v)||mb_strlen($v,'UTF-8')>$max)throw new \RuntimeException('AVDB returned unbounded localized text.');}
            }
            foreach(['rejected_locales','quality_reasons']as$k)foreach($item[$k]as$l=>$reason)if(!in_array($l,$locales,true)||!is_string($reason)||strlen($reason)>128||!preg_match('/^[a-z_]+$/D',$reason))throw new \RuntimeException('AVDB returned invalid quality reasons.');
            foreach($item['rejected_title_sha256']as$l=>$hash)if(!in_array($l,$locales,true)||!isset($item['rejected_locales'][$l])||!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash))throw new \RuntimeException('AVDB returned invalid rejected-title hash.');
            if(array_values($item['unavailable_locales'])!==$item['unavailable_locales'])throw new \RuntimeException('AVDB returned invalid unavailable locales.');
            foreach($item['unavailable_locales']as$l)if(!is_string($l)||!in_array($l,$locales,true))throw new \RuntimeException('AVDB returned an unexpected unavailable locale.');
            foreach($locales as$l){if(!isset($item['translations'][$l])&&!isset($item['rejected_locales'][$l])&&!in_array($l,$item['unavailable_locales'],true))throw new \RuntimeException('AVDB omitted a requested locale classification.');if(isset($item['translations'][$l])&&(!isset($item['quality_reasons'][$l])||in_array($l,$item['unavailable_locales'],true)))throw new \RuntimeException('AVDB returned conflicting translation classification.');}
            $out[(int)$id]=array_intersect_key($item,array_flip(['translations','rejected_locales','unavailable_locales','quality_reasons','rejected_title_sha256']));
        }
        return $out;
    }

    /** Public provider snapshot values; fixed endpoint, never a supplied URL. */
    public function fetchOptions(array $params)
    {
        foreach ($params as $key=>$value) {
            if (!in_array($key,['field','q','pg','pagesize'],true) || !is_scalar($value)) { throw new \InvalidArgumentException('Invalid AVDB options parameter.'); }
        }
        $field=$params['field'] ?? '';
        if (!is_string($field) || !in_array($field,['actor','director','writer','category','tag','year','from'],true)) { throw new \InvalidArgumentException('Unsupported AVDB options field.'); }
        $q=(string)($params['q'] ?? '');
        if (strlen($q)>128 || !preg_match('//u',$q) || preg_match('/[\x00-\x1F\x7F]/',$q)) { throw new \InvalidArgumentException('Invalid AVDB options prefix.'); }
        $filters=['field'=>$field,'q'=>$q,'pg'=>1,'pagesize'=>20];
        foreach (['pg'=>1000000,'pagesize'=>50] as $key=>$max) {
            $value=(string)($params[$key] ?? $filters[$key]);
            if (!ctype_digit($value) || (float)$value<1 || (float)$value>$max) { throw new \InvalidArgumentException('Invalid AVDB options pagination.'); }
            $filters[$key]=(int)$value;
        }
        $data=$this->fetchJson('https://avdbapi.com/api.php/avdboptions/index?'.http_build_query($filters,'','&',PHP_QUERY_RFC3986),true);
        if (!is_int($data['code'])) { throw new \RuntimeException('AVDB returned an invalid options result code.'); }
        foreach (['page','pagesize','updated_at'] as $key) {
            if (!isset($data[$key]) || !is_int($data[$key])) { throw new \RuntimeException('AVDB returned invalid options pagination.'); }
        }
        if ($data['page']!==$filters['pg'] || $data['pagesize']!==$filters['pagesize'] || $data['updated_at']<0 ||
            !isset($data['items'],$data['has_more'],$data['complete'],$data['source'],$data['warning']) ||
            !is_array($data['items']) || array_values($data['items'])!==$data['items'] || count($data['items'])>$filters['pagesize'] ||
            !is_bool($data['has_more']) || !is_bool($data['complete']) || $data['source']!=='provider' ||
            !is_string($data['warning']) || strlen($data['warning'])>512) { throw new \RuntimeException('AVDB returned malformed options metadata.'); }
        foreach ($data['items'] as $value) {
            if (!is_string($value) || strlen($value)>self::MAX_OPTION_VALUE || !preg_match('//u',$value) || preg_match('/[\x00-\x1F\x7F]/',$value)) { throw new \RuntimeException('AVDB returned an invalid options value.'); }
        }
        if ((int)$data['code']===503 && ($data['complete'] || $data['has_more'] || count($data['items'])!==0)) { throw new \RuntimeException('AVDB returned invalid preparing metadata.'); }
        return array_intersect_key($data,array_flip(['items','page','pagesize','has_more','source','complete','updated_at','warning']));
    }

    private function fetchJson($url, $allowPreparing = false)
    {
        $options = ['connect_timeout'=>5,'timeout'=>15,'max_body'=>self::MAX_BODY];
        $response = $this->transport ? call_user_func($this->transport, $url, $options) : $this->request($url, $options);
        if (!is_array($response) || !isset($response['status'],$response['body']) || !is_string($response['body'])) { throw new \RuntimeException('Invalid AVDB transport response.'); }
        if ((int)$response['status'] === 429) {
            $wait = 60;
            foreach (($response['headers'] ?? []) as $key=>$value) {
                if (strtolower((string)$key) === 'retry-after') {
                    $value = trim((string)$value);
                    $seconds = ctype_digit($value) ? (int)$value : max(1, (int)strtotime($value)-time());
                    $wait = max(1,min(3600,$seconds));
                }
            }
            throw new AvdbClientException('AVDB rate limit reached. Retry in '.$wait.' seconds.', 429, $wait);
        }
        $preparing=$allowPreparing && (int)$response['status']===503;
        if ((int)$response['status'] !== 200 && !$preparing) { throw new \RuntimeException('AVDB is unavailable (HTTP '.(int)$response['status'].').'); }
        if (strlen($response['body'])>self::MAX_BODY) { throw new \RuntimeException('AVDB response exceeds the size limit.'); }
        $data = json_decode($response['body'], true, 64);
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE || !isset($data['code']) || !is_scalar($data['code']) || (string)$data['code']!==($preparing?'503':'1')) { throw new \RuntimeException('AVDB returned malformed or unsuccessful JSON.'); }
        return $data;
    }

    private function request($url, array $options)
    {
        if (!function_exists('curl_init')) { throw new \RuntimeException('The PHP cURL extension is required for AVDB import.'); }
        $ch = curl_init($url); $body = ''; $headers = []; $overflow = false;
        // Honor PHP administrator CA configuration; no admin-request path is accepted.
        $configuredCa = ini_get('curl.cainfo');
        if (is_string($configuredCa) && $configuredCa !== '') { curl_setopt($ch, CURLOPT_CAINFO, $configuredCa); }
        $curlVersion = curl_version();
        // Windows OpenSSL gained native trust-store support in libcurl 7.71.0.
        // It adds OS-trusted roots without weakening peer or hostname checks.
        // Official curl.h defines this bit as 1<<4; older PHP may lack the name.
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLOPT_SSL_OPTIONS') &&
            ($curlVersion['version_number'] ?? 0) >= 0x074700 &&
            preg_match('/^(OpenSSL|LibreSSL|BoringSSL|AWS-LC)\//', $curlVersion['ssl_version'] ?? '')) {
            curl_setopt($ch, CURLOPT_SSL_OPTIONS, defined('CURLSSLOPT_NATIVE_CA') ? constant('CURLSSLOPT_NATIVE_CA') : 16);
        }
        curl_setopt_array($ch, [CURLOPT_CONNECTTIMEOUT=>$options['connect_timeout'],CURLOPT_TIMEOUT=>$options['timeout'],CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_ENCODING=>'',CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_USERAGENT=>'JAVCMS-AVDB-Import/1.0',CURLOPT_HEADERFUNCTION=>function($handle,$line)use(&$headers){$pair=explode(':',$line,2);if(count($pair)===2){$headers[strtolower(trim($pair[0]))]=trim($pair[1]);}return strlen($line);},CURLOPT_WRITEFUNCTION=>function($handle,$chunk)use(&$body,&$overflow){if(strlen($body)+strlen($chunk)>self::MAX_BODY){$overflow=true;return 0;}$body.=$chunk;return strlen($chunk);}]);
        $ok=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $errno=curl_errno($ch); if (PHP_VERSION_ID < 80000) { curl_close($ch); } unset($ch);
        if ($overflow) { throw new \RuntimeException('AVDB response exceeds the size limit.'); }
        if ($ok === false) {
            $message = 'Could not connect securely to AVDB (cURL '.$errno.'). Check connectivity and the PHP certificate trust store.';
            // F56: certificate problems (no CA bundle for PHP) get a next step a beginner can pass to the host.
            if (in_array((int)$errno, [35, 51, 58, 59, 60, 77, 83], true)) {
                $help = function_exists('lang') ? lang('admin/ux/tools/avdb_ca_help') : '';
                $message .= ' ' . (is_string($help) && $help !== '' && $help !== 'admin/ux/tools/avdb_ca_help' ? $help : 'Ask your host to set a CA certificate bundle for PHP (php.ini: curl.cainfo).');
            }
            throw new \RuntimeException($message);
        }
        return ['status'=>$status,'headers'=>$headers,'body'=>$body];
    }
}

class AvdbClientException extends \RuntimeException
{
    public $retryAfter;
    public function __construct($message, $code, $retryAfter) { parent::__construct($message,$code); $this->retryAfter=$retryAfter; }
    public function getRetryAfter() { return $this->retryAfter; }
}
