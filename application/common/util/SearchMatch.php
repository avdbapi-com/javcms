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
use think\Db;

/**
 * Exact, cheaper SQL for the public video search (search-speed, 2026-10-14).
 *
 * The search page matches `col LIKE '%word%'` on six columns under their general_ci collation. That case- and
 * accent-insensitive LIKE is the costly part of a search (about 0.9 s per pass over 524,000 videos; a binary
 * comparison of the same rows costs about 0.35 s). condition() returns SQL that finds exactly the same rows:
 *  - every character of the word has no other general_ci equivalent (CJK, kana, Hangul, digits, most punctuation):
 *    a binary LIKE is the same test;
 *  - the word holds a run of two or more such characters (codes such as ACZD-094): a binary LIKE on that run, then
 *    the original case-insensitive LIKE only on the rows that pass;
 *  - any other word on MariaDB: REGEXP on the binary collation where every character is replaced by the exact set
 *    of characters general_ci treats as equal to it (classes generated from the server's own weight table).
 * It is used only when the server's general_ci weight table for the whole BMP has the fingerprint the classes were
 * generated from, all searched columns share one general_ci collation, and the word holds no LIKE wildcard (% _ \),
 * no control character and no character above U+FFFF (general_ci treats all of those as one). In every other case
 * it returns null and the caller keeps its original LIKE, so other engines and collations behave as before.
 */
final class SearchMatch
{
    /** count:SUM(CRC32):BIT_XOR(CRC32) of "cp:HEX(WEIGHT_STRING(char))" over U+0000-U+FFFF without surrogates. */
    const FINGERPRINT = '63488:136536177501178:4105774790';
    /** general_ci classes with more than one member (hex code points), MariaDB 11.4 utf8mb4_general_ci. */
    const CLASSES = '41,61,c0,c1,c2,c3,c4,c5,e0,e1,e2,e3,e4,e5,100,101,102,103,104,105,1cd,1ce,1de,1df,1e0,1e1,1fa,1fb,200,201,202,203,226,227,1e00,1e01,1ea0,1ea1,1ea2,1ea3,1ea4,1ea5,1ea6,1ea7,1ea8,1ea9,1eaa,1eab,1eac,1ead,1eae,1eaf,1eb0,1eb1,1eb2,1eb3,1eb4,1eb5,1eb6,1eb7;42,62,1e02,1e03,1e04,1e05,1e06,1e07;43,63,c7,e7,106,107,108,109,10a,10b,10c,10d,1e08,1e09;44,64,10e,10f,1e0a,1e0b,1e0c,1e0d,1e0e,1e0f,1e10,1e11,1e12,1e13;45,65,c8,c9,ca,cb,e8,e9,ea,eb,112,113,114,115,116,117,118,119,11a,11b,204,205,206,207,228,229,1e14,1e15,1e16,1e17,1e18,1e19,1e1a,1e1b,1e1c,1e1d,1eb8,1eb9,1eba,1ebb,1ebc,1ebd,1ebe,1ebf,1ec0,1ec1,1ec2,1ec3,1ec4,1ec5,1ec6,1ec7;46,66,1e1e,1e1f;47,67,11c,11d,11e,11f,120,121,122,123,1e6,1e7,1f4,1f5,1e20,1e21;48,68,124,125,21e,21f,1e22,1e23,1e24,1e25,1e26,1e27,1e28,1e29,1e2a,1e2b,1e96;49,69,cc,cd,ce,cf,ec,ed,ee,ef,128,129,12a,12b,12c,12d,12e,12f,130,131,1cf,1d0,208,209,20a,20b,1e2c,1e2d,1e2e,1e2f,1ec8,1ec9,1eca,1ecb;4a,6a,134,135,1f0;4b,6b,136,137,1e8,1e9,1e30,1e31,1e32,1e33,1e34,1e35;4c,6c,139,13a,13b,13c,13d,13e,1e36,1e37,1e38,1e39,1e3a,1e3b,1e3c,1e3d;4d,6d,1e3e,1e3f,1e40,1e41,1e42,1e43;4e,6e,d1,f1,143,144,145,146,147,148,1f8,1f9,1e44,1e45,1e46,1e47,1e48,1e49,1e4a,1e4b;4f,6f,d2,d3,d4,d5,d6,f2,f3,f4,f5,f6,14c,14d,14e,14f,150,151,1a0,1a1,1d1,1d2,1ea,1eb,1ec,1ed,20c,20d,20e,20f,22a,22b,22c,22d,22e,22f,230,231,1e4c,1e4d,1e4e,1e4f,1e50,1e51,1e52,1e53,1ecc,1ecd,1ece,1ecf,1ed0,1ed1,1ed2,1ed3,1ed4,1ed5,1ed6,1ed7,1ed8,1ed9,1eda,1edb,1edc,1edd,1ede,1edf,1ee0,1ee1,1ee2,1ee3;50,70,1e54,1e55,1e56,1e57;51,71;52,72,154,155,156,157,158,159,210,211,212,213,1e58,1e59,1e5a,1e5b,1e5c,1e5d,1e5e,1e5f;53,73,df,15a,15b,15c,15d,15e,15f,160,161,17f,218,219,1e60,1e61,1e62,1e63,1e64,1e65,1e66,1e67,1e68,1e69,1e9b;54,74,162,163,164,165,21a,21b,1e6a,1e6b,1e6c,1e6d,1e6e,1e6f,1e70,1e71,1e97;55,75,d9,da,db,dc,f9,fa,fb,fc,168,169,16a,16b,16c,16d,16e,16f,170,171,172,173,1af,1b0,1d3,1d4,1d5,1d6,1d7,1d8,1d9,1da,1db,1dc,214,215,216,217,1e72,1e73,1e74,1e75,1e76,1e77,1e78,1e79,1e7a,1e7b,1ee4,1ee5,1ee6,1ee7,1ee8,1ee9,1eea,1eeb,1eec,1eed,1eee,1eef,1ef0,1ef1;56,76,1e7c,1e7d,1e7e,1e7f;57,77,174,175,1e80,1e81,1e82,1e83,1e84,1e85,1e86,1e87,1e88,1e89,1e98;58,78,1e8a,1e8b,1e8c,1e8d;59,79,dd,fd,ff,176,177,178,232,233,1e8e,1e8f,1e99,1ef2,1ef3,1ef4,1ef5,1ef6,1ef7,1ef8,1ef9;5a,7a,179,17a,17b,17c,17d,17e,1e90,1e91,1e92,1e93,1e94,1e95;c6,e6,1e2,1e3,1fc,1fd;d0,f0;d8,f8,1fe,1ff;de,fe;110,111;126,127;132,133;13f,140;141,142;14a,14b;152,153;166,167;181,253;182,183;184,185;186,254;187,188;189,256;18a,257;18b,18c;18e,1dd;18f,259;190,25b;191,192;193,260;194,263;196,269;197,268;198,199;19c,26f;19d,272;19f,275;1a2,1a3;1a4,1a5;1a6,280;1a7,1a8;1a9,283;1ac,1ad;1ae,288;1b1,28a;1b2,28b;1b3,1b4;1b5,1b6;1b7,1ee,1ef,292;1b8,1b9;1bc,1bd;1c4,1c5,1c6;1c7,1c8,1c9;1ca,1cb,1cc;1e4,1e5;1f1,1f2,1f3;195,1f6;1bf,1f7;21c,21d;222,223;224,225;386,391,3ac,3b1,1f00,1f01,1f02,1f03,1f04,1f05,1f06,1f07,1f08,1f09,1f0a,1f0b,1f0c,1f0d,1f0e,1f0f,1f70,1f80,1f81,1f82,1f83,1f84,1f85,1f86,1f87,1f88,1f89,1f8a,1f8b,1f8c,1f8d,1f8e,1f8f,1fb0,1fb1,1fb2,1fb3,1fb4,1fb6,1fb7,1fb8,1fb9,1fba,1fbc;392,3b2,3d0;393,3b3;394,3b4;388,395,3ad,3b5,1f10,1f11,1f12,1f13,1f14,1f15,1f18,1f19,1f1a,1f1b,1f1c,1f1d,1f72,1fc8;396,3b6;389,397,3ae,3b7,1f20,1f21,1f22,1f23,1f24,1f25,1f26,1f27,1f28,1f29,1f2a,1f2b,1f2c,1f2d,1f2e,1f2f,1f74,1f90,1f91,1f92,1f93,1f94,1f95,1f96,1f97,1f98,1f99,1f9a,1f9b,1f9c,1f9d,1f9e,1f9f,1fc2,1fc3,1fc4,1fc6,1fc7,1fca,1fcc;398,3b8,3d1;345,38a,390,399,3aa,3af,3b9,3ca,1f30,1f31,1f32,1f33,1f34,1f35,1f36,1f37,1f38,1f39,1f3a,1f3b,1f3c,1f3d,1f3e,1f3f,1f76,1fbe,1fd0,1fd1,1fd2,1fd6,1fd7,1fd8,1fd9,1fda;39a,3ba,3f0;39b,3bb;b5,39c,3bc;39d,3bd;39e,3be;38c,39f,3bf,3cc,1f40,1f41,1f42,1f43,1f44,1f45,1f48,1f49,1f4a,1f4b,1f4c,1f4d,1f78,1ff8;3a0,3c0,3d6;3a1,3c1,3f1,1fe4,1fe5,1fec;3a3,3c2,3c3,3f2;3a4,3c4;38e,3a5,3ab,3b0,3c5,3cb,3cd,1f50,1f51,1f52,1f53,1f54,1f55,1f56,1f57,1f59,1f5b,1f5d,1f5f,1f7a,1fe0,1fe1,1fe2,1fe6,1fe7,1fe8,1fe9,1fea;3a6,3c6,3d5;3a7,3c7;3a8,3c8;38f,3a9,3c9,3ce,1f60,1f61,1f62,1f63,1f64,1f65,1f66,1f67,1f68,1f69,1f6a,1f6b,1f6c,1f6d,1f6e,1f6f,1f7c,1fa0,1fa1,1fa2,1fa3,1fa4,1fa5,1fa6,1fa7,1fa8,1fa9,1faa,1fab,1fac,1fad,1fae,1faf,1ff2,1ff3,1ff4,1ff6,1ff7,1ffa,1ffc;3d2,3d3,3d4;3da,3db;3dc,3dd;3de,3df;3e0,3e1;3e2,3e3;3e4,3e5;3e6,3e7;3e8,3e9;3ea,3eb;3ec,3ed;3ee,3ef;402,452;404,454;405,455;406,407,456,457;408,458;409,459;40a,45a;40b,45b;40f,45f;410,430,4d0,4d1,4d2,4d3;411,431;412,432;403,413,433,453;414,434;400,401,415,435,450,451,4d6,4d7;416,436,4c1,4c2,4dc,4dd;417,437,4de,4df;40d,418,438,45d,4e2,4e3,4e4,4e5;419,439;40c,41a,43a,45c;41b,43b;41c,43c;41d,43d;41e,43e,4e6,4e7;41f,43f;420,440;421,441;422,442;40e,423,443,45e,4ee,4ef,4f0,4f1,4f2,4f3;424,444;425,445;426,446;427,447,4f4,4f5;428,448;429,449;42a,44a;42b,44b,4f8,4f9;42c,44c;42d,44d,4ec,4ed;42e,44e;42f,44f;460,461;462,463;464,465;466,467;468,469;46a,46b;46c,46d;46e,46f;470,471;472,473;474,475,476,477;478,479;47a,47b;47c,47d;47e,47f;480,481;48c,48d;48e,48f;490,491;492,493;494,495;496,497;498,499;49a,49b;49c,49d;49e,49f;4a0,4a1;4a2,4a3;4a4,4a5;4a6,4a7;4a8,4a9;4aa,4ab;4ac,4ad;4ae,4af;4b0,4b1;4b2,4b3;4b4,4b5;4b6,4b7;4b8,4b9;4ba,4bb;4bc,4bd;4be,4bf;4c3,4c4;4c7,4c8;4cb,4cc;4d4,4d5;4d8,4d9,4da,4db;4e0,4e1;4e8,4e9,4ea,4eb;531,561;532,562;533,563;534,564;535,565;536,566;537,567;538,568;539,569;53a,56a;53b,56b;53c,56c;53d,56d;53e,56e;53f,56f;540,570;541,571;542,572;543,573;544,574;545,575;546,576;547,577;548,578;549,579;54a,57a;54b,57b;54c,57c;54d,57d;54e,57e;54f,57f;550,580;551,581;552,582;553,583;554,584;555,585;556,586;1f71,1fbb;1f73,1fc9;1f75,1fcb;1f77,1fdb;1f7b,1feb;1f79,1ff9;1f7d,1ffb;2160,2170;2161,2171;2162,2172;2163,2173;2164,2174;2165,2175;2166,2176;2167,2177;2168,2178;2169,2179;216a,217a;216b,217b;216c,217c;216d,217d;216e,217e;216f,217f;24b6,24d0;24b7,24d1;24b8,24d2;24b9,24d3;24ba,24d4;24bb,24d5;24bc,24d6;24bd,24d7;24be,24d8;24bf,24d9;24c0,24da;24c1,24db;24c2,24dc;24c3,24dd;24c4,24de;24c5,24df;24c6,24e0;24c7,24e1;24c8,24e2;24c9,24e3;24ca,24e4;24cb,24e5;24cc,24e6;24cd,24e7;24ce,24e8;24cf,24e9;ff21,ff41;ff22,ff42;ff23,ff43;ff24,ff44;ff25,ff45;ff26,ff46;ff27,ff47;ff28,ff48;ff29,ff49;ff2a,ff4a;ff2b,ff4b;ff2c,ff4c;ff2d,ff4d;ff2e,ff4e;ff2f,ff4f;ff30,ff50;ff31,ff51;ff32,ff52;ff33,ff53;ff34,ff54;ff35,ff55;ff36,ff56;ff37,ff57;ff38,ff58;ff39,ff59;ff3a,ff5a';
    const CACHE_KEY = 'search_match_v3_';
    /** Longest word (characters) the REGEXP path takes; longer words keep the original LIKE (SS-V2: the pattern grows
     * about 6 KB per accentable letter and MariaDB refuses it above about 300 letters). */
    const REGEX_MAX_CHARS = 64;
    /** Seconds between re-reads of the searched columns' collations while the capability is cached (SS-V3). */
    const COLLATION_RECHECK = 600;

    private static $classOf;
    private static $memo = [];
    private static $server;

    /**
     * SQL condition equivalent to (`c1` LIKE '%word%' OR `c2` LIKE '%word%' ...) on $table, or null.
     * @param string $table   full table name (prefix included)
     * @param array  $columns column names
     * @param mixed  $word    the search word exactly as the original LIKE used it
     */
    public static function condition($table, array $columns, $word)
    {
        try {
            if (!is_string($word) || $word === '' || strlen($word) > 512 || strpbrk($word, "%_\\") !== false || !preg_match('//u', $word)) {
                return null;
            }
            if (!$columns || !preg_match('/^[A-Za-z0-9_]+$/D', (string)$table)) { return null; }
            foreach ($columns as $column) {
                if (!is_string($column) || !preg_match('/^[A-Za-z0-9_]+$/D', $column)) { return null; }
            }
            $cap = self::capability($table, $columns);
            return empty($cap['ok']) ? null : self::build($cap, $columns, $word);
        } catch (\Throwable $error) {
            return null;
        }
    }

    /**
     * The condition for a server capability $cap (['ok', 'intro', 'bin', 'regex']); null when the word needs the
     * original LIKE. Pure (no database access), so tests can call it.
     */
    public static function build(array $cap, array $columns, $word)
    {
        try {
            if (empty($cap['ok']) || !is_string($word) || $word === '' || strlen($word) > 512 || strpbrk($word, "%_\\") !== false || !preg_match('//u', $word)) {
                return null;
            }
            foreach ($columns as $column) {
                if (!is_string($column) || !preg_match('/^[A-Za-z0-9_]+$/D', $column)) { return null; }
            }
            $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY);
            $classOf = self::classes();
            $cps = [];
            foreach ($chars as $char) {
                $cp = self::ord($char);
                if ($cp < 0x20 || $cp === 0x7F || $cp > 0xFFFF || $cp === 0xFFFD || ($cp >= 0xD800 && $cp <= 0xDFFF)) { return null; }
                $cps[] = $cp;
            }
            // Longest run of characters general_ci equates with nothing else (spaces alone do not narrow anything).
            $bestStart = 0; $bestLen = 0; $runStart = 0; $runLen = 0;
            foreach ($cps as $i => $cp) {
                if (isset($classOf[$cp])) { $runLen = 0; continue; }
                if ($runLen === 0) { $runStart = $i; }
                $runLen++;
                if ($runLen > $bestLen) { $bestLen = $runLen; $bestStart = $runStart; }
            }
            $lit = function ($text) use ($cap) { return $cap['intro'] . " X'" . bin2hex($text) . "'"; };
            // Byte comparison: same answer as a character comparison on valid UTF-8, and twice as fast as COLLATE x_bin.
            $binLike = function ($column, $text) use ($cap) {
                $hex = "X'" . bin2hex($text) . "'";
                return empty($cap['maria']) ? "CAST(`$column` AS BINARY) LIKE $hex" : "`$column` LIKE BINARY $hex";
            };
            $bin = $cap['bin'];
            $parts = [];
            if ($bestLen === count($cps)) {
                foreach ($columns as $column) { $parts[] = $binLike($column, '%' . $word . '%'); }
            } elseif ($bestLen >= 2 && trim(implode('', array_slice($chars, $bestStart, $bestLen))) !== '') {
                $run = implode('', array_slice($chars, $bestStart, $bestLen));
                foreach ($columns as $column) {
                    $parts[] = '(' . $binLike($column, '%' . $run . '%') . " AND `$column` LIKE " . $lit('%' . $word . '%') . ')';
                }
            } elseif (!empty($cap['regex'])) {
                if (count($cps) > self::REGEX_MAX_CHARS) { return null; }
                $pattern = '';
                foreach ($cps as $cp) {
                    if (!isset($classOf[$cp])) { $pattern .= sprintf('\\x{%x}', $cp); continue; }
                    $pattern .= '[';
                    foreach ($classOf[$cp] as $member) { $pattern .= sprintf('\\x{%x}', $member); }
                    $pattern .= ']';
                }
                foreach ($columns as $column) { $parts[] = "`$column` COLLATE $bin REGEXP " . $lit($pattern); }
            } else {
                return null;
            }
            return '(' . implode(' OR ', $parts) . ')';
        } catch (\Throwable $error) {
            return null;
        }
    }

    /** ['maria' => bool, 'version' => 'x.y.z'] of the connected server; cached for the request. */
    public static function server()
    {
        if (self::$server === null) {
            self::$server = ['maria' => false, 'version' => '0'];
            try {
                $row = Db::query('SELECT VERSION() AS v');
                $text = isset($row[0]['v']) ? (string)$row[0]['v'] : '';
                if (preg_match('/^(\d+\.\d+\.\d+)/', $text, $m)) {
                    self::$server = ['maria' => stripos($text, 'mariadb') !== false, 'version' => $m[1]];
                }
            } catch (\Throwable $error) {
                // keep the conservative default: no MariaDB-only SQL
            }
        }
        return self::$server;
    }

    /** True on MariaDB >= $version. */
    public static function mariadbAtLeast($version)
    {
        $server = self::server();
        return $server['maria'] && version_compare($server['version'], $version, '>=');
    }

    private static function capability($table, array $columns)
    {
        $memoKey = $table . '|' . implode(',', $columns);
        if (isset(self::$memo[$memoKey])) { return self::$memo[$memoKey]; }
        $cacheKey = self::CACHE_KEY . md5($memoKey . '|' . (string)config('database.hostname') . '|' . (string)config('database.hostport') . '|' . (string)config('database.database'));
        $cap = null;
        try { $cap = Cache::get($cacheKey); } catch (\Throwable $error) { $cap = null; }
        if (is_array($cap) && !empty($cap['ok']) && time() - (int)($cap['checked'] ?? 0) > self::COLLATION_RECHECK) {
            // The columns may have been converted to another collation of the same charset since the probe (for example
            // utf8mb4_unicode_ci): the classes would no longer be exact. Metadata only, about 2 ms.
            $now = self::collation($table, $columns);
            if ($now === ($cap['collation'] ?? null)) {
                $cap['checked'] = time();
                try { Cache::set($cacheKey, $cap, 86400); } catch (\Throwable $error) { /* re-check next request */ }
            } else {
                $cap = null;
            }
        }
        if (!is_array($cap) || !isset($cap['ok'])) {
            $cap = self::probe($table, $columns);
            try { Cache::set($cacheKey, $cap, empty($cap["ok"]) ? 3600 : 86400); } catch (\Throwable $error) { /* probe again next request */ }
        }
        return self::$memo[$memoKey] = $cap;
    }

    /** The one collation all $columns share (lower case), '' when they differ, null when a column is missing or on error. */
    private static function collation($table, array $columns)
    {
        try {
            $collation = null;
            $found = [];
            foreach (Db::query('SHOW FULL COLUMNS FROM `' . $table . '`') as $row) {
                $row = array_change_key_case($row, CASE_LOWER);
                if (in_array($row['field'], $columns, true)) {
                    $found[$row['field']] = true;
                    $c = strtolower((string)$row['collation']);
                    if ($collation !== null && $collation !== $c) { return ''; }
                    $collation = $c;
                }
            }
            return count($found) === count(array_unique($columns)) ? $collation : null;
        } catch (\Throwable $error) {
            return null;
        }
    }

    private static function probe($table, array $columns)
    {
        $off = ['ok' => false];
        try {
            $collation = self::collation($table, $columns);
            if (!is_string($collation) || $collation === '') { return $off; }
            $charsets = ['utf8mb4_general_ci' => 'utf8mb4', 'utf8_general_ci' => 'utf8', 'utf8mb3_general_ci' => 'utf8mb3'];
            if (!isset($charsets[$collation])) { return $off; }
            $cs = $charsets[$collation];
            $digits = '(SELECT 0 v UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7'
                . ' UNION ALL SELECT 8 UNION ALL SELECT 9 UNION ALL SELECT 10 UNION ALL SELECT 11 UNION ALL SELECT 12 UNION ALL SELECT 13 UNION ALL SELECT 14 UNION ALL SELECT 15)';
            $item = "CRC32(CONCAT(c.cp, ':', HEX(WEIGHT_STRING(CONVERT(CHAR(c.cp USING utf32) USING $cs) COLLATE $collation))))";
            $rows = Db::query("SELECT COUNT(*) AS n, SUM($item) AS s, BIT_XOR($item) AS x FROM (SELECT a.v + 16 * b.v + 256 * d.v + 4096 * e.v AS cp"
                . " FROM $digits a, $digits b, $digits d, $digits e) c WHERE c.cp NOT BETWEEN 55296 AND 57343");
            $print = isset($rows[0]) ? $rows[0]['n'] . ':' . $rows[0]['s'] . ':' . $rows[0]['x'] : '';
            if ($print !== self::FINGERPRINT) { return $off; }
            return ['ok' => true, 'intro' => '_' . $cs, 'bin' => $cs . '_bin', 'maria' => self::mariadbAtLeast('5.5.0'), 'regex' => self::mariadbAtLeast('10.0.5'),
                'collation' => $collation, 'checked' => time()];
        } catch (\Throwable $error) {
            return $off;
        }
    }

    /** code point => list of every code point in its general_ci class (only classes with more than one member). */
    private static function classes()
    {
        if (self::$classOf === null) {
            self::$classOf = [];
            foreach (explode(';', self::CLASSES) as $group) {
                $members = array_map('hexdec', explode(',', $group));
                foreach ($members as $cp) { self::$classOf[$cp] = $members; }
            }
        }
        return self::$classOf;
    }

    private static function ord($char)
    {
        if (function_exists('mb_ord')) { return mb_ord($char, 'UTF-8'); }
        $u = unpack('N', mb_convert_encoding($char, 'UCS-4BE', 'UTF-8'));
        return (int)$u[1];
    }
}
