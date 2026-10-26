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
namespace app\common\util\updater;

/**
 * The updater's own zip reader (UPDATER-SCOUT-HOST rule 1).
 *
 * PharData::extractTo() silently corrupts the stored entries of the real 1.2.0 archive on PHP 7.4.30 / 8.0.22 / 8.1.9
 * (local and central extra fields differ), so it is never used. This reader parses the central directory itself (zip64
 * included), inflates with inflate_init(ZLIB_ENCODING_RAW) / inflate_add() in 64 KiB pieces, checks CRC32 and size of
 * every entry, never writes more bytes than the central directory announces (zip bombs stop there), and lets a caller
 * extract entry by entry across several requests. Without zlib it falls back to ZipArchive's entry streams. phar:// is
 * not used either: it needs zlib as well, and PharData unserializes archive metadata.
 *
 * Only entries that a verified manifest lists are ever extracted; symlinks, encrypted entries and unknown compression
 * methods are refused.
 */
final class Zip
{
    /** Bytes of compressed input per inflate_add() call. */
    const INFLATE_PIECE = 8192;

    /** @var resource */
    private $fh;
    /** @var string */
    private $path;
    /** @var array name => entry */
    private $entries = array();
    /** @var string last error */
    public $error = '';

    private function __construct()
    {
    }

    /** Opens an archive and reads its central directory; null with $error set when it is not a usable zip. */
    public static function open($path, &$error = '')
    {
        $z = new self();
        $z->path = (string)$path;
        $z->fh = @fopen($z->path, 'rb');
        if (!$z->fh) {
            $error = 'cannot open the archive';
            return null;
        }
        try {
            $z->readDirectory();
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            @fclose($z->fh);
            return null;
        }
        return $z;
    }

    public function __destruct()
    {
        if (is_resource($this->fh)) {
            @fclose($this->fh);
        }
    }

    /** name => ['method', 'csize', 'size', 'crc', 'offset', 'flags', 'dir', 'link'] */
    public function entries()
    {
        return $this->entries;
    }

    public function has($name)
    {
        return isset($this->entries[$name]);
    }

    private function readDirectory()
    {
        $size = filesize($this->path);
        if ($size === false || $size < 22) {
            throw new \RuntimeException('archive too small');
        }
        $scan = min($size, 65557);
        fseek($this->fh, $size - $scan);
        $tail = fread($this->fh, $scan);
        $pos = strrpos($tail, "PK\x05\x06");
        if ($pos === false) {
            throw new \RuntimeException('end of central directory not found');
        }
        $eocd = unpack('vdisk/vcddisk/vcount_disk/vcount/Vcdsize/Vcdoffset/vcomment', substr($tail, $pos + 4, 18));
        $count = $eocd['count'];
        $cdSize = $eocd['cdsize'];
        $cdOffset = $eocd['cdoffset'];
        // zip64 end of central directory locator just before the classic record
        if (($count === 0xFFFF || $cdOffset === 0xFFFFFFFF || $cdSize === 0xFFFFFFFF) && $pos >= 20) {
            $loc = substr($tail, $pos - 20, 20);
            if (substr($loc, 0, 4) === "PK\x06\x07") {
                $l = unpack('Vdisk/Plo/Vdisks', substr($loc, 4, 16));
                fseek($this->fh, $l['lo']);
                $rec = fread($this->fh, 56);
                if (substr($rec, 0, 4) !== "PK\x06\x06") {
                    throw new \RuntimeException('zip64 record not found');
                }
                $z = unpack('Psize/vmade/vneed/Vdisk/Vcddisk/Pcount_disk/Pcount/Pcdsize/Pcdoffset', substr($rec, 4, 52));
                $count = $z['count'];
                $cdSize = $z['cdsize'];
                $cdOffset = $z['cdoffset'];
            }
        }
        if ($cdOffset + $cdSize > $size || $count > 200000) {
            throw new \RuntimeException('central directory out of range');
        }
        fseek($this->fh, $cdOffset);
        $cd = $cdSize > 0 ? fread($this->fh, $cdSize) : '';
        if (strlen($cd) !== $cdSize) {
            throw new \RuntimeException('central directory truncated');
        }
        $p = 0;
        for ($i = 0; $i < $count; $i++) {
            if (substr($cd, $p, 4) !== "PK\x01\x02") {
                throw new \RuntimeException('bad central directory entry');
            }
            $h = unpack('vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnlen/velen/vclen/vdisk/vint/Vext/Voffset', substr($cd, $p + 4, 42));
            $name = substr($cd, $p + 46, $h['nlen']);
            $extra = substr($cd, $p + 46 + $h['nlen'], $h['elen']);
            $p += 46 + $h['nlen'] + $h['elen'] + $h['clen'];
            $csize = $h['csize'];
            $usize = $h['size'];
            $offset = $h['offset'];
            if ($usize === 0xFFFFFFFF || $csize === 0xFFFFFFFF || $offset === 0xFFFFFFFF) {
                // zip64 extended information (tag 0x0001): the fields that are 0xFFFFFFFF, in this order
                $e = 0;
                while ($e + 4 <= strlen($extra)) {
                    $t = unpack('vtag/vlen', substr($extra, $e, 4));
                    if ($t['tag'] === 1) {
                        $q = $e + 4;
                        if ($usize === 0xFFFFFFFF) {
                            $usize = unpack('P', substr($extra, $q, 8))[1];
                            $q += 8;
                        }
                        if ($csize === 0xFFFFFFFF) {
                            $csize = unpack('P', substr($extra, $q, 8))[1];
                            $q += 8;
                        }
                        if ($offset === 0xFFFFFFFF) {
                            $offset = unpack('P', substr($extra, $q, 8))[1];
                        }
                        break;
                    }
                    $e += 4 + $t['len'];
                }
            }
            $unix = ($h['made'] >> 8) === 3;
            $mode = ($h['ext'] >> 16) & 0xFFFF;
            $this->entries[$name] = array(
                'method' => $h['method'], 'csize' => $csize, 'size' => $usize, 'crc' => $h['crc'], 'offset' => $offset,
                'flags' => $h['flags'], 'dir' => substr($name, -1) === '/',
                'link' => $unix && (($mode & 0xF000) === 0xA000),
            );
        }
    }

    /**
     * Extracts entry $name to $dest (a temp file next to it, then rename). Returns ['ok', 'sha256', 'size', 'error'].
     * The content must match $wantSha / $wantSize when they are given (the manifest's values).
     */
    public function extract($name, $dest, $wantSha = null, $wantSize = null)
    {
        if (!isset($this->entries[$name])) {
            return array('ok' => false, 'sha256' => '', 'size' => 0, 'error' => 'not in the archive: ' . $name);
        }
        $e = $this->entries[$name];
        if ($e['dir'] || $e['link']) {
            return array('ok' => false, 'sha256' => '', 'size' => 0, 'error' => 'refused (directory or symlink entry): ' . $name);
        }
        if ($e['flags'] & 1) {
            return array('ok' => false, 'sha256' => '', 'size' => 0, 'error' => 'refused (encrypted entry): ' . $name);
        }
        if ($wantSize !== null && $e['size'] !== (int)$wantSize) {
            return array('ok' => false, 'sha256' => '', 'size' => 0, 'error' => 'size differs from the manifest: ' . $name);
        }
        $dest = str_replace('\\', '/', (string)$dest);
        $dir = dirname($dest);
        if (!is_dir($dir) && !Util::mkdirs($dir)) {
            return array('ok' => false, 'sha256' => '', 'size' => 0, 'error' => 'cannot create folder for ' . $name);
        }
        $tmp = $dir . '/.' . substr(basename($dest), 0, 40) . '.' . Util::randomId(5) . '.up-tmp';
        $out = @fopen($tmp, 'xb');
        if (!$out) {
            return array('ok' => false, 'sha256' => '', 'size' => 0, 'error' => 'cannot write ' . $name);
        }
        $sink = function ($data) use ($out) {
            return @fwrite($out, $data) === strlen($data);
        };
        $r = function_exists('inflate_init') ? $this->streamOwn($e, $sink) : $this->streamZipArchive($name, $e, $sink);
        @fflush($out);
        Util::fsync($out);
        $closed = @fclose($out);
        if (!$r['ok'] || !$closed) {
            @unlink($tmp);
            return array('ok' => false, 'sha256' => '', 'size' => 0, 'error' => ($r['error'] !== '' ? $r['error'] : 'write failed') . ': ' . $name);
        }
        if ($wantSha !== null && !hash_equals((string)$wantSha, $r['sha256'])) {
            @unlink($tmp);
            return array('ok' => false, 'sha256' => $r['sha256'], 'size' => $r['size'], 'error' => 'content differs from the manifest: ' . $name);
        }
        $mv = Util::renameRetry($tmp, $dest, 2.0);
        if (!$mv['ok']) {
            @unlink($tmp);
            return array('ok' => false, 'sha256' => '', 'size' => 0, 'error' => 'cannot place ' . $name);
        }
        return array('ok' => true, 'sha256' => $r['sha256'], 'size' => $r['size'], 'error' => '');
    }

    /**
     * sha256 and size of entry $name without writing it (release tools). ['ok', 'sha256', 'size', 'error'].
     */
    public function digest($name)
    {
        if (!isset($this->entries[$name])) {
            return array('ok' => false, 'sha256' => '', 'size' => 0, 'error' => 'not in the archive: ' . $name);
        }
        $e = $this->entries[$name];
        if ($e['dir'] || $e['link'] || ($e['flags'] & 1)) {
            return array('ok' => false, 'sha256' => '', 'size' => 0, 'error' => 'refused entry: ' . $name);
        }
        $sink = function ($data) {
            return true;
        };
        $r = function_exists('inflate_init') ? $this->streamOwn($e, $sink) : $this->streamZipArchive($name, $e, $sink);
        return array('ok' => $r['ok'], 'sha256' => $r['sha256'], 'size' => $r['size'], 'error' => $r['error']);
    }

    /** Own reader: local header, then stored copy or raw inflate, CRC32 + sha256 on the fly; $sink gets the bytes. */
    private function streamOwn(array $e, $sink)
    {
        fseek($this->fh, $e['offset']);
        $lh = fread($this->fh, 30);
        if (strlen($lh) !== 30 || substr($lh, 0, 4) !== "PK\x03\x04") {
            return array('ok' => false, 'error' => 'bad local header', 'sha256' => '', 'size' => 0);
        }
        $l = unpack('vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vsize/vnlen/velen', substr($lh, 4, 26));
        fseek($this->fh, $e['offset'] + 30 + $l['nlen'] + $l['elen']);
        $left = $e['csize'];
        $crc = hash_init('crc32b');
        $sha = hash_init('sha256');
        $written = 0;
        $limit = $e['size'];
        $inf = null;
        if ($e['method'] === 8) {
            $inf = @inflate_init(ZLIB_ENCODING_RAW);
            if (!$inf) {
                return array('ok' => false, 'error' => 'inflate not available', 'sha256' => '', 'size' => 0);
            }
        } elseif ($e['method'] !== 0) {
            return array('ok' => false, 'error' => 'unsupported compression method ' . $e['method'], 'sha256' => '', 'size' => 0);
        }
        while ($left > 0) {
            $chunk = fread($this->fh, (int)min(65536, $left));
            if ($chunk === false || $chunk === '') {
                return array('ok' => false, 'error' => 'archive ended early', 'sha256' => '', 'size' => 0);
            }
            $left -= strlen($chunk);
            // compressed input goes to inflate in small pieces: deflate expands at most about 1:1032, so one piece never
            // produces more than about 8 MB before the size check below stops an entry larger than the directory says
            $pieces = $inf !== null ? str_split($chunk, self::INFLATE_PIECE) : array($chunk);
            $last = count($pieces) - 1;
            foreach ($pieces as $pi => $piece) {
                if ($inf !== null) {
                    $data = @inflate_add($inf, $piece, ($left > 0 || $pi < $last) ? ZLIB_SYNC_FLUSH : ZLIB_FINISH);
                    if ($data === false) {
                        return array('ok' => false, 'error' => 'damaged compressed data', 'sha256' => '', 'size' => 0);
                    }
                } else {
                    $data = $piece;
                }
                if ($data === '') {
                    continue;
                }
                $written += strlen($data);
                if ($written > $limit) {
                    return array('ok' => false, 'error' => 'entry larger than announced', 'sha256' => '', 'size' => 0);
                }
                hash_update($crc, $data);
                hash_update($sha, $data);
                if (!$sink($data)) {
                    return array('ok' => false, 'error' => 'write failed (disk full?)', 'sha256' => '', 'size' => 0);
                }
            }
        }
        if ($written !== $limit) {
            return array('ok' => false, 'error' => 'entry size mismatch', 'sha256' => '', 'size' => 0);
        }
        $got = hexdec(hash_final($crc));
        if ((int)$got !== (int)$e['crc']) {
            return array('ok' => false, 'error' => 'CRC32 mismatch', 'sha256' => '', 'size' => 0);
        }
        return array('ok' => true, 'error' => '', 'sha256' => hash_final($sha), 'size' => $written);
    }

    /** Fallback without zlib: ZipArchive's stream of one entry (ext-zip carries its own inflater). */
    private function streamZipArchive($name, array $e, $sink)
    {
        if (!class_exists('ZipArchive')) {
            return array('ok' => false, 'error' => 'neither zlib nor zip is available', 'sha256' => '', 'size' => 0);
        }
        $za = new \ZipArchive();
        if ($za->open($this->path) !== true) {
            return array('ok' => false, 'error' => 'ZipArchive cannot open the archive', 'sha256' => '', 'size' => 0);
        }
        $in = $za->getStream($name);
        if (!$in) {
            $za->close();
            return array('ok' => false, 'error' => 'ZipArchive cannot read the entry', 'sha256' => '', 'size' => 0);
        }
        $sha = hash_init('sha256');
        $crc = hash_init('crc32b');
        $written = 0;
        while (!feof($in)) {
            $data = fread($in, 65536);
            if ($data === false || $data === '') {
                break;
            }
            $written += strlen($data);
            if ($written > $e['size']) {
                fclose($in);
                $za->close();
                return array('ok' => false, 'error' => 'entry larger than announced', 'sha256' => '', 'size' => 0);
            }
            hash_update($sha, $data);
            hash_update($crc, $data);
            if (!$sink($data)) {
                fclose($in);
                $za->close();
                return array('ok' => false, 'error' => 'write failed (disk full?)', 'sha256' => '', 'size' => 0);
            }
        }
        fclose($in);
        $za->close();
        if ($written !== $e['size'] || (int)hexdec(hash_final($crc)) !== (int)$e['crc']) {
            return array('ok' => false, 'error' => 'entry check failed', 'sha256' => '', 'size' => 0);
        }
        return array('ok' => true, 'error' => '', 'sha256' => hash_final($sha), 'size' => $written);
    }
}
