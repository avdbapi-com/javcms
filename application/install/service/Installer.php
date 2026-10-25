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
namespace app\install\service;

class Installer
{
    public static function databaseConfig(array $input)
    {
        foreach (['hostname', 'database', 'username', 'password', 'hostport', 'prefix'] as $key) {
            if (!isset($input[$key]) || !is_string($input[$key]) || strpos($input[$key], "\0") !== false) {
                throw new \InvalidArgumentException('Invalid database field');
            }
        }
        if ($input['hostname'] === '' || $input['username'] === '' || !preg_match('/^[a-zA-Z0-9_]{1,64}$/D', $input['database']) || !preg_match('/^[a-z][a-z0-9_]{0,19}_$/D', $input['prefix']) || !ctype_digit($input['hostport']) || (int)$input['hostport'] < 1 || (int)$input['hostport'] > 65535) {
            throw new \InvalidArgumentException('Invalid database settings');
        }
        return [
            'type' => 'mysql', 'hostname' => $input['hostname'], 'database' => $input['database'],
            'username' => $input['username'], 'password' => $input['password'], 'hostport' => $input['hostport'],
            'dsn' => '', 'params' => [], 'charset' => 'utf8mb4', 'prefix' => $input['prefix'],
            'debug' => false, 'deploy' => 0, 'rw_separate' => false, 'master_num' => 1,
            'slave_no' => '', 'fields_strict' => false, 'resultset_type' => 'array',
            'auto_timestamp' => false, 'datetime_format' => 'Y-m-d H:i:s', 'sql_explain' => false,
            'builder' => '', 'query' => '\think\db\Query',
        ];
    }

    public static function assertEmptyPrefix(array $tables, $prefix)
    {
        foreach ($tables as $row) {
            foreach ($row as $name) {
                if (strncmp($name, $prefix, strlen($prefix)) === 0) {
                    throw new \RuntimeException('The table prefix is already in use');
                }
            }
        }
    }

    public static function validateAdministrator($name, $password, $confirm)
    {
        if (!is_string($name) || !preg_match('/^[a-zA-Z0-9_\-]{3,30}$/D', $name) || !is_string($password) || strlen($password) < 12 || strlen($password) > 72 || strpos($password, "\0") !== false || !is_string($confirm) || !hash_equals($password, $confirm)) {
            throw new \InvalidArgumentException('Choose an account name and matching password of 12 to 72 bytes');
        }
    }

    public static function schemaPrefix($sql, $prefix)
    {
        if (!is_string($sql) || trim($sql) === '' || !preg_match('/^[a-z][a-z0-9_]{0,19}_$/D', $prefix)) {
            throw new \InvalidArgumentException('Invalid installation schema or prefix');
        }
        return preg_replace('/`(?:javcms_|mac_)([a-z0-9_]+)`/i', '`' . $prefix . '$1`', $sql);
    }

    public static function writeConfig($path, array $values)
    {
        $directory = realpath(dirname($path));
        if ($directory === false || !is_writable($directory)) {
            throw new \RuntimeException('Configuration directory must be writable');
        }
        $temp = tempnam($directory, '.install-');
        if ($temp === false) { throw new \RuntimeException('Cannot create configuration file'); }
        try {
            if (realpath(dirname($temp)) !== $directory) {
                throw new \RuntimeException('Configuration temporary file must remain in its directory');
            }
            $contents = "<?php\nreturn " . var_export($values, true) . ";\n";
            if (file_put_contents($temp, $contents, LOCK_EX) !== strlen($contents) || !rename($temp, $path)) {
                throw new \RuntimeException('Cannot save configuration file');
            }
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($path, true);
            }
        } finally {
            if (is_file($temp)) { unlink($temp); }
        }
    }

    /**
     * Site name and public address chosen in the installer.
     * @return array [site_name (HTML-escaped like admin saves), site_url (host[:port]), canonical_scheme (https|auto)]
     */
    public static function siteIdentity($name, $url, $fallbackHost = '', $fallbackSsl = false)
    {
        $host = '';
        $scheme = $fallbackSsl ? 'https' : 'http';
        $url = is_string($url) ? trim($url) : '';
        if ($url !== '') {
            if (strlen($url) > 255 || preg_match('/[\x00-\x20\x7f"\'<>\\\\]/', $url)) {
                throw new \InvalidArgumentException('Invalid site URL');
            }
            if (!preg_match('~^[a-z][a-z0-9+.-]*://~i', $url)) {
                $url = $scheme . '://' . $url;
            }
            $parts = parse_url($url);
            if (!is_array($parts) || !isset($parts['host']) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
                || isset($parts['user']) || isset($parts['pass'])) {
                throw new \InvalidArgumentException('Invalid site URL');
            }
            $scheme = strtolower($parts['scheme']);
            // Convert an IDN host to punycode before lowercasing (strtolower is byte-based and would
            // corrupt UTF-8); punycode is ASCII, so the lowercase is then safe.
            $host = strtolower(self::asciiHost($parts['host']));
            if (isset($parts['port'])) {
                $host .= ':' . (int)$parts['port'];
            }
        } elseif (is_string($fallbackHost)) {
            $host = strtolower(self::asciiHost(trim($fallbackHost)));
        }
        if ($host === '' || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*|\[[0-9a-f:.]+\])(?::[0-9]{1,5})?$/D', $host)) {
            throw new \InvalidArgumentException('Invalid site URL');
        }
        $name = is_string($name) ? trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($name, ENT_QUOTES, 'UTF-8')))) : '';
        if ($name === '' || !preg_match('//u', $name)) {
            $name = preg_replace('/:\d+$/', '', $host);
        }
        if (function_exists('mb_substr')) {
            $name = mb_substr($name, 0, 100, 'UTF-8');
        }
        return [htmlspecialchars($name, ENT_QUOTES, 'UTF-8'), $host, $scheme === 'https' ? 'https' : 'auto'];
    }

    /**
     * Convert an internationalised host to its ASCII (punycode) form when the intl extension is
     * available; a host that is already ASCII, or cannot be converted, is returned unchanged
     * (the caller's ASCII host check then rejects an untranslatable one with a clear message).
     */
    private static function asciiHost($host)
    {
        if ($host === '' || !preg_match('/[^\x00-\x7f]/', $host) || !function_exists('idn_to_ascii')) {
            return $host;
        }
        $variant = defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : (defined('INTL_IDNA_VARIANT_2003') ? INTL_IDNA_VARIANT_2003 : 0);
        $ascii = @idn_to_ascii($host, 0, $variant);
        return (is_string($ascii) && $ascii !== '') ? $ascii : $host;
    }

    /** Create a missing writable folder; true when it exists and is writable afterwards. */
    public static function ensureWritableDir($path)
    {
        if (!is_dir($path)) {
            @mkdir($path, 0755, true);
        }
        return is_dir($path) && is_writable($path);
    }

    /** A config file is writable when it exists and is writable, or does not exist yet in a writable folder. */
    public static function fileWritable($path)
    {
        return is_file($path) ? is_writable($path) : (is_dir(dirname($path)) && is_writable(dirname($path)));
    }

    /** Catalog answers of the installer question "Which catalogs will you import?" (XVID-SCALE-BRIEF 3.8 step 5, D14). */
    const CATALOGS = ['avdb', 'xvid', 'both', 'later'];
    /** Slug of the provider parent category for the xvidapi genres (brief 2.6: the slug never changes, D20). */
    const PARENT_SLUG = 'western';
    /** D20 default display name of the provider parent. */
    const PARENT_DEFAULT = 'International';

    /**
     * Normalise the catalog answer and the parent display name.
     * @return array [catalog ('avdb'|'xvid'|'both'|'later'), parent display name (plain text, 1-60 characters)]
     */
    public static function catalogChoice($catalog, $parentName)
    {
        $catalog = is_string($catalog) ? strtolower(trim($catalog)) : '';
        if (!in_array($catalog, self::CATALOGS, true)) {
            $catalog = 'later';
        }
        $name = is_string($parentName) ? trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode($parentName, ENT_QUOTES, 'UTF-8')))) : '';
        if ($name === '' || !preg_match('//u', $name) || preg_match('/[\x00-\x1f\x7f<>"]/', $name)) {
            $name = self::PARENT_DEFAULT;
        }
        if (function_exists('mb_substr')) {
            $name = mb_substr($name, 0, 60, 'UTF-8');
        }
        // The parent name is only asked when xvidapi is part of the answer; otherwise the D20 default is kept.
        if (!in_array($catalog, ['xvid', 'both'], true)) {
            $name = self::PARENT_DEFAULT;
        }
        return [$catalog, $name];
    }

    /**
     * Which seeded video categories are enabled for a catalog answer.
     * JAV = every seeded top-level video category except the provider parent; parent = the "western" slug row.
     * "later" keeps the 1.1.0 seed (JAV on, parent off): the first xvidapi import turns the parent on (HO-E-8).
     * @return array ['jav' => 0|1, 'parent' => 0|1]
     */
    public static function categoryPlan($catalog)
    {
        $plans = [
            'avdb'  => ['jav' => 1, 'parent' => 0],
            'xvid'  => ['jav' => 0, 'parent' => 1],
            'both'  => ['jav' => 1, 'parent' => 1],
            'later' => ['jav' => 1, 'parent' => 0],
        ];
        return isset($plans[$catalog]) ? $plans[$catalog] : $plans['later'];
    }

    /**
     * SQL updates for the seeded categories (prefix already validated by databaseConfig()).
     * Seeded SEO fields stay empty: the category title then reads "<category name in the visitor's language> - <site>"
     * and the description is built from the listing (no fixed JAV text, no frozen site name).
     * @return array list of [sql, bind]
     */
    public static function categoryStatements($prefix, $catalog, $parentName)
    {
        if (!is_string($prefix) || !preg_match('/^[a-z][a-z0-9_]{0,19}_$/D', $prefix)) {
            throw new \InvalidArgumentException('Invalid table prefix');
        }
        $plan = self::categoryPlan($catalog);
        $table = '`' . $prefix . 'type`';
        return [
            ["UPDATE $table SET type_status = ? WHERE type_mid = 1 AND type_pid = 0 AND type_en <> ?", [$plan['jav'], self::PARENT_SLUG]],
            ["UPDATE $table SET type_status = ?, type_name = ? WHERE type_mid = 1 AND type_en = ?",
                [$plan['parent'], htmlspecialchars($parentName, ENT_QUOTES, 'UTF-8'), self::PARENT_SLUG]],
            ["UPDATE $table SET type_title = '', type_des = '', type_key = '' WHERE type_title <> '' OR type_des <> '' OR type_key <> ''", []],
        ];
    }

    /**
     * Neutral site texts for the catalog answer. AVDB-only sites keep the shipped JAV texts; every other answer gets
     * texts that do not promise one kind of video. Plain text; stored HTML-escaped like the admin saves them.
     * @return array ['site_keywords' => string, 'site_description' => string] (empty array = keep the shipped values)
     */
    public static function siteTexts($catalog)
    {
        if ($catalog === 'avdb') {
            return [];
        }
        return [
            'site_keywords' => 'videos, HD videos, free videos, watch videos online',
            'site_description' => 'Watch the newest videos online in HD. Updated daily.',
        ];
    }

    /**
     * Create the tables of the 1.2.0 subsystems on the fresh database (brief 3.8 step 5; 2.12 step 2a/2b on an empty
     * table is instant). Each owner's method is called only when it exists, so a tree without one of them still installs;
     * the admin's first visit and tools/upgrade.php run the same idempotent steps again.
     * @param \PDO   $pdo    connection to the new database (exceptions on)
     * @param string $prefix validated table prefix
     * @param string $dbName database name
     * @return array step => 'ok' | 'skipped' | 'error: <class of the exception>'
     */
    public static function schemaSteps(\PDO $pdo, $prefix, $dbName)
    {
        $out = [];
        $run = function ($name, callable $fn) use (&$out) {
            try {
                $r = $fn();
                if ($r === null) {
                    $out[$name] = 'skipped';
                } elseif (is_array($r) && array_key_exists('ok', $r) && !$r['ok']) {
                    $out[$name] = 'error: ' . (isset($r['state']) && $r['state'] !== '' ? $r['state'] : 'failed');
                } else {
                    $out[$name] = 'ok';
                }
            } catch (\Throwable $e) {
                $out[$name] = 'error: ' . get_class($e);
            }
        };
        $scale = '\\app\\common\\util\\ScaleSchema';
        $run('scale_tables', function () use ($scale, $pdo, $prefix) {
            return class_exists($scale) && method_exists($scale, 'ensureTables') ? $scale::ensureTables($pdo, $prefix) : null;
        });
        $run('scale_indexes', function () use ($scale, $pdo, $prefix) {
            return class_exists($scale) && method_exists($scale, 'ensureIndexes') ? $scale::ensureIndexes($pdo, $prefix) : null;
        });
        $ft = '\\app\\common\\util\\FtIndex';
        $run('search_index', function () use ($ft, $pdo, $prefix, $dbName) {
            if (!class_exists($ft) || !method_exists($ft, 'ensureSchema')) {
                return null;
            }
            if (method_exists($ft, 'useConnection')) {
                $ft::useConnection($pdo, $prefix, $dbName);
            }
            $ft::ensureSchema();
            return true;
        });
        $avdb = '\\app\\common\\model\\AvdbImport';
        $run('importer', function () use ($avdb) {
            if (!class_exists($avdb) || !method_exists($avdb, 'ensureSchema')) {
                return null;
            }
            $method = new \ReflectionMethod($avdb, 'ensureSchema');
            if ($method->isStatic()) {
                $avdb::ensureSchema();
            } else {
                (new $avdb())->ensureSchema();
            }
            return true;
        });
        // fp-port: the filter vocabulary of FilterMatch v2 (table from install.sql) is built on the empty catalogue at once,
        // so it is READY from the start and the importers' catch-ups keep it current as videos arrive (index-fast filters
        // from the first import; tools/upgrade.php and housekeeping repeat this idempotent step)
        $fv = '\\app\\common\\util\\FilterVocab';
        $run('ft_vocab', function () use ($fv, $pdo, $prefix) {
            if (!class_exists($fv) || !method_exists($fv, 'ensureTable')) {
                return null;
            }
            $key = function_exists('config') ? $fv::dbKey(['hostname' => config('database.hostname'), 'hostport' => config('database.hostport'),
                'database' => config('database.database')]) : null;
            $v = $fv::pdo($pdo, $prefix, false, $key);
            $t = $v->ensureTable();
            if ($t !== 'exists' && $t !== 'created') {
                return null;
            }
            $r = $v->buildStep(30, 0, 2, false);
            return ['ok' => in_array($r['state'], ['done', 'idle'], true), 'state' => (string)$r['state']];
        });
        return $out;
    }

    public static function neutralSettings(array $settings)
    {
        $clear = function ($values) use (&$clear) {
            foreach ($values as $key => $value) {
                if (is_array($value)) { $values[$key] = $clear($value); }
                elseif (is_string($value) && preg_match('~https?://~i', $value)) { $values[$key] = ''; }
                elseif (preg_match('/(?:pwd|password|secret|token|key|appid|openid|mchid|cookie|auth|account|^pass$|^user$|^username$|^host$|^server$|^name$|^url$|^imgurl$)/i', $key)) { $values[$key] = ''; }
            }
            return $values;
        };
        foreach (['db', 'pay', 'connect', 'weixin', 'email', 'sms', 'urlsend', 'upload', 'interface', 'api'] as $section) {
            if (isset($settings[$section]) && is_array($settings[$section])) { $settings[$section] = $clear($settings[$section]); }
        }
        foreach (['pay', 'connect', 'weixin', 'email', 'sms', 'urlsend', 'api', 'interface'] as $section) {
            if (!isset($settings[$section])) { continue; }
            $disable = function ($values) use (&$disable) {
                foreach ($values as $key => $value) {
                    if (is_array($value)) { $values[$key] = $disable($value); }
                    elseif ($key === 'status') { $values[$key] = '0'; }
                }
                return $values;
            };
            $settings[$section] = $disable($settings[$section]);
        }
        $settings['db']['server'] = '127.0.0.1';
        $settings['db']['path'] = '';
        $settings['db']['backup_path'] = './application/data/backup/';
        $settings['site']['site_url'] = '';
        $settings['site']['site_email'] = '';
        $settings['site']['site_tj'] = '';
        $settings['site']['install_dir'] = '/';
        $settings['app']['cache_type'] = 'file';
        $settings['app']['cache_host'] = '127.0.0.1';
        $settings['app']['cache_username'] = '';
        $settings['app']['cache_password'] = '';
        $settings['app']['extra_var'] = '';
        $settings['app']['cache_flag'] = '';
        $settings['rewrite']['encode_key'] = '';
        $settings['play']['parse'] = '';
        $settings['play']['prestrain'] = '';
        $settings['play']['buffer'] = '';
        $settings['upload']['watermark'] = '0';
        $settings['upload']['watermark_content'] = '';
        $settings['upload']['mode'] = 'local';
        $settings['upload']['remoteurl'] = '';
        $settings['upload']['img_api'] = '';
        $settings['interface']['status'] = '0';
        return $settings;
    }
}
