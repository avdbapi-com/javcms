<?php
/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors.
 * License: JavCMS Community License 1.0 - free to use, copy, change and share, not for sale.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
namespace app\common\util;

/** Optional cover downloader. Constructing this utility never fetches an image. */
class AvdbImages
{
    const MAX_BODY = 8388608;
    private $root;
    private $transport;
    private $resolver;
    private static $windowsDnsCache = [];

    public function __construct($root = null, $transport = null, $resolver = null)
    {
        $root = $root === null ? (defined('ROOT_PATH') ? ROOT_PATH : '') : $root;
        $resolved = is_string($root) ? realpath($root) : false;
        if ($resolved === false || !is_dir($resolved)) { throw new \RuntimeException('Image destination is unavailable.'); }
        if (($transport !== null && !is_callable($transport)) || ($resolver !== null && !is_callable($resolver))) { throw new \InvalidArgumentException('Invalid image test transport.'); }
        $this->root = rtrim(str_replace('\\', '/', $resolved), '/');
        $this->transport = $transport;
        $this->resolver = $resolver;
    }

    public static function publicIp($ip)
    {
        if (!is_string($ip) || !filter_var($ip, FILTER_VALIDATE_IP)) { return false; }
        $packed = inet_pton($ip);
        if (strlen($packed) === 16) {
            // Only global unicast; reject mapped IPv4, documentation and tunnels.
            if ((ord($packed[0]) & 0xe0) !== 0x20 || substr($packed, 0, 4) === hex2bin('20010db8') || (substr($packed,0,2)===hex2bin('2001') && ord($packed[2])<2) || substr($packed, 0, 2) === hex2bin('2002') || (substr($packed,0,2)===hex2bin('3fff') && (ord($packed[2]) & 0xf0)===0)) { return false; }
            return true;
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) { return false; }
        $number = unpack('N', $packed)[1];
        foreach ([['0.0.0.0',8],['10.0.0.0',8],['100.64.0.0',10],['127.0.0.0',8],['169.254.0.0',16],['172.16.0.0',12],['192.0.0.0',24],['192.0.2.0',24],['192.88.99.0',24],['192.168.0.0',16],['198.18.0.0',15],['198.51.100.0',24],['203.0.113.0',24],['224.0.0.0',3]] as $range) {
            $base = unpack('N', inet_pton($range[0]))[1]; $mask = (0xffffffff << (32-$range[1])) & 0xffffffff;
            if (($number & $mask) === ($base & $mask)) { return false; }
        }
        return true;
    }

    public function validateUrl($url)
    {
        if (!is_string($url) || strlen($url)>2048 || preg_match('/[\x00-\x20\x7f\\\\<>"\']/', $url) || preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|7f)/i', $url)) { throw new \InvalidArgumentException('Invalid remote image URL.'); }
        $parts = parse_url($url);
        if (!$parts || !isset($parts['scheme'],$parts['host']) || !in_array(strtolower($parts['scheme']), ['http','https'], true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) { throw new \InvalidArgumentException('Use a public HTTP or HTTPS image URL without credentials.'); }
        $scheme = strtolower($parts['scheme']); $host = strtolower(trim($parts['host'], '[]')); $port = $scheme === 'https' ? 443 : 80;
        if (isset($parts['port']) && (int)$parts['port'] !== $port) { throw new \InvalidArgumentException('Image URL uses an unsupported port.'); }
        if (filter_var($host,FILTER_VALIDATE_IP)) { $ips = [$host]; }
        else {
            if (strlen($host)>253 || strpos($host,'.') === false || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/D',$host)) { throw new \InvalidArgumentException('Invalid image hostname.'); }
            if ($this->resolver) { $ips = call_user_func($this->resolver,$host); }
            else {
                $ips=[]; foreach ((array)@dns_get_record($host,DNS_A | DNS_AAAA) as $record) { if(isset($record['ip']))$ips[]=$record['ip'];if(isset($record['ipv6']))$ips[]=$record['ipv6']; }
                if (PHP_OS_FAMILY === 'Windows' && (!$ips || count(array_filter($ips, [self::class,'publicIp'])) !== count($ips))) {
                    $ips = $this->windowsDnsWithoutHosts($host);
                }
            }
        }
        if (!is_array($ips) || !$ips || count($ips)>32) { throw new \RuntimeException('Image hostname could not be resolved safely.'); }
        foreach ($ips as $ip) { if (!self::publicIp($ip)) { throw new \RuntimeException('Private or reserved image destinations are not allowed.'); } }
        return ['url'=>$url,'host'=>$host,'port'=>$port,'ip'=>reset($ips)];
    }

    /** Native DNS lookup bypasses development hosts overrides, never IP validation. */
    private function windowsDnsWithoutHosts($host)
    {
        if (isset(self::$windowsDnsCache[$host]) && self::$windowsDnsCache[$host]['until']>microtime(true)) { return self::$windowsDnsCache[$host]['answers']; }
        if (!function_exists('proc_open') || !function_exists('proc_get_status') || !function_exists('proc_terminate')) { throw new \RuntimeException('Public image DNS lookup is unavailable.'); }
        $systemRoot = realpath((string)getenv('SystemRoot'));
        $executable = $systemRoot ? realpath($systemRoot . '/System32/WindowsPowerShell/v1.0/powershell.exe') : false;
        if (!$systemRoot || !$executable || !is_file($executable) || strcasecmp(str_replace('\\','/',$executable), str_replace('\\','/',$systemRoot).'/System32/WindowsPowerShell/v1.0/powershell.exe') !== 0) { throw new \RuntimeException('Trusted public image DNS resolver is unavailable.'); }
        // The validated hostname travels over stdin, never as shell source.
        $script = '$ErrorActionPreference="Stop"; try { $imageDnsHost=[Console]::In.ReadToEnd().Trim(); if($imageDnsHost.Length -gt 253 -or $imageDnsHost -notmatch "^[a-z0-9.-]+$") { exit 2 }; $imageDnsAnswers=@(Resolve-DnsName -Name $imageDnsHost -Type A_AAAA -DnsOnly -NoHostsFile -ErrorAction Stop | Where-Object { $_.Type -eq 1 -or $_.Type -eq 28 } | ForEach-Object { $_.IPAddress }); if($imageDnsAnswers.Count -lt 1 -or $imageDnsAnswers.Count -gt 32) { exit 3 }; ConvertTo-Json -InputObject $imageDnsAnswers -Compress } catch { exit 4 }';
        $stdout = tmpfile(); $stderr = tmpfile();
        if (!$stdout || !$stderr) { if(is_resource($stdout))fclose($stdout);if(is_resource($stderr))fclose($stderr);throw new \RuntimeException('Public image DNS staging is unavailable.'); }
        $process = null; $pipes = [];
        try {
            $process = @proc_open([$executable,'-NoLogo','-NoProfile','-NonInteractive','-WindowStyle','Hidden','-Command',$script], [0=>['pipe','r'],1=>$stdout,2=>$stderr], $pipes, null, null, ['bypass_shell'=>true,'create_new_console'=>false,'suppress_errors'=>true]);
            if (!is_resource($process)) { throw new \RuntimeException('Public image DNS process is unavailable.'); }
            if (fwrite($pipes[0],$host)!==strlen($host)) { throw new \RuntimeException('Public image DNS request failed.'); }
            fclose($pipes[0]); unset($pipes[0]);
            $deadline = microtime(true) + 5;
            do {
                $status = proc_get_status($process);
                if (($stdoutSize=fstat($stdout))===false || ($stderrSize=fstat($stderr))===false || $stdoutSize['size']>8192 || $stderrSize['size']>8192) { throw new \RuntimeException('Public image DNS response exceeded its bound.'); }
                if (!$status['running']) { break; }
                if (microtime(true)>=$deadline) { throw new \RuntimeException('Public image DNS lookup timed out.'); }
                usleep(20000);
            } while (true);
            if ((int)$status['exitcode']!==0) { throw new \RuntimeException('Public image DNS lookup failed.'); }
            rewind($stdout); $answers=json_decode(stream_get_contents($stdout,8193),true);
            if (!is_array($answers) || !$answers || count($answers)>32) { throw new \RuntimeException('Public image DNS returned an invalid response.'); }
            foreach ($answers as $answer) { if (!self::publicIp($answer)) { throw new \RuntimeException('Private or reserved image destinations are not allowed.'); } }
            // Prefer reachable IPv4 while retaining every-record public validation.
            usort($answers,function($a,$b){ return (strpos($a,':')!==false) <=> (strpos($b,':')!==false); });
            $answers = array_values(array_unique($answers));
            if (count(self::$windowsDnsCache)>=64) { self::$windowsDnsCache=[]; }
            self::$windowsDnsCache[$host] = ['until'=>microtime(true)+30,'answers'=>$answers];
            return $answers;
        } finally {
            foreach ($pipes as $pipe) { if(is_resource($pipe))fclose($pipe); }
            if (is_resource($process)) { $status=proc_get_status($process);if($status['running']){proc_terminate($process);}proc_close($process); }
            fclose($stdout); fclose($stderr);
        }
    }

    public function download($url, $remoteId = 0)
    {
        return \app\common\util\VodDeletion::withMediaLock(function () use ($url,$remoteId) { return $this->downloadWithMediaLock($url,$remoteId); });
    }

    private function downloadWithMediaLock($url, $remoteId = 0)
    {
        $target = $this->validateUrl($url);
        $options = $target + ['connect_timeout'=>5,'timeout'=>15,'max_body'=>self::MAX_BODY];
        $response = $this->transport ? call_user_func($this->transport,$url,$options) : $this->request($url,$options);
        if (!is_array($response) || ($response['status'] ?? 0)!==200 || !isset($response['body']) || !is_string($response['body'])) { throw new \RuntimeException('Image request failed. Redirects are not followed.'); }
        $body=$response['body']; if ($body==='' || strlen($body)>self::MAX_BODY) { throw new \RuntimeException('Image is empty or exceeds the eight MiB limit.'); }
        $mime='';foreach (($response['headers'] ?? []) as $name=>$value) { if(strtolower((string)$name)==='content-type')$mime=strtolower(trim(explode(';',(string)$value)[0])); }
        $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'];
        $image=@getimagesizefromstring($body);
        if (!isset($allowed[$mime]) || !$image || ($image['mime'] ?? '')!==$mime || $image[0]<1 || $image[1]<1 || $image[0]>20000 || $image[1]>20000 || $image[0]*$image[1]>100000000) { throw new \RuntimeException('Only valid JPEG, PNG, GIF or WebP images are accepted.'); }
        $hash=hash('sha256',$body); $relative='upload/vod/avdb/'.$hash.'.'.$allowed[$mime];
        $directory=$this->root;
        foreach (['upload','vod','avdb'] as $part) {
            $directory.='/'.$part;
            if (!is_dir($directory) && !@mkdir($directory,0755)) { throw new \RuntimeException('Cannot create image destination.'); }
            $actual=realpath($directory);
            if (is_link($directory) || $actual===false || strpos(str_replace('\\','/',$actual).'/', $this->root.'/')!==0) { throw new \RuntimeException('Image destination must remain inside uploads.'); }
        }
        $path=$this->root.'/'.$relative;
        if (file_exists($path)) { if (is_link($path) || !is_file($path) || !hash_equals($hash,hash_file('sha256',$path))) { throw new \RuntimeException('Existing image destination does not match; it will not be overwritten.'); } return $relative; }
        $temp=$directory.'/avdb-'.bin2hex(random_bytes(16));
        $staged=@fopen($temp,'xb');
        if ($staged===false) { throw new \RuntimeException('Cannot stage downloaded image.'); }
        try {
            if (fwrite($staged,$body)!==strlen($body) || !fflush($staged)) { throw new \RuntimeException('Could not save downloaded image.'); }
            fclose($staged);$staged=null;
            @chmod($temp,0644);
            // Hard-link publish is atomic and refuses an existing basename.
            if (!@link($temp,$path) && (!is_file($path) || is_link($path) || !hash_equals($hash,hash_file('sha256',$path)))) { throw new \RuntimeException('Could not publish downloaded image without overwriting a file.'); }
        } finally { if (is_resource($staged)) { fclose($staged); } if (is_file($temp)) { unlink($temp); } }
        return $relative;
    }

    private function request($url, array $options)
    {
        if (!function_exists('curl_init')) { throw new \RuntimeException('PHP cURL is required for local cover images.'); }
        $handle=curl_init($url);$body='';$headers=[];$overflow=false;
        $ca=ini_get('curl.cainfo');if(is_string($ca)&&$ca!=='')curl_setopt($handle,CURLOPT_CAINFO,$ca);
        $version=curl_version();if(PHP_OS_FAMILY==='Windows'&&($version['version_number']??0)>=0x074700&&preg_match('/^(OpenSSL|LibreSSL|BoringSSL|AWS-LC)\//',$version['ssl_version']??'')){curl_setopt($handle,CURLOPT_SSL_OPTIONS,defined('CURLSSLOPT_NATIVE_CA')?constant('CURLSSLOPT_NATIVE_CA'):16);}
        $address=strpos($options['ip'],':')!==false?'['.$options['ip'].']':$options['ip'];
        if (!filter_var($options['host'],FILTER_VALIDATE_IP) && !curl_setopt($handle,CURLOPT_RESOLVE,[$options['host'].':'.$options['port'].':'.$address])) { curl_close($handle);throw new \RuntimeException('Could not pin the public image destination.'); }
        $configured=curl_setopt_array($handle,[CURLOPT_PROXY=>'',CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_USERAGENT=>'JAVCMS-AVDB-Images/1.0',CURLOPT_HTTPHEADER=>['Accept: image/jpeg,image/png,image/gif,image/webp'],CURLOPT_HEADERFUNCTION=>function($h,$line)use(&$headers){$pair=explode(':',$line,2);if(count($pair)===2)$headers[strtolower(trim($pair[0]))]=trim($pair[1]);return strlen($line);},CURLOPT_WRITEFUNCTION=>function($h,$chunk)use(&$body,&$overflow){if(strlen($body)+strlen($chunk)>self::MAX_BODY){$overflow=true;return 0;}$body.=$chunk;return strlen($chunk);}]);
        if (!$configured) { curl_close($handle);throw new \RuntimeException('Could not configure the image transport securely.'); }
        $ok=curl_exec($handle);$status=(int)curl_getinfo($handle,CURLINFO_HTTP_CODE);$errno=curl_errno($handle);curl_close($handle);
        if($overflow){throw new \RuntimeException('Image exceeds the eight MiB limit.');}if($ok===false){throw new \RuntimeException('Could not fetch image securely (cURL '.$errno.').');}
        return ['status'=>$status,'headers'=>$headers,'body'=>$body];
    }
}
