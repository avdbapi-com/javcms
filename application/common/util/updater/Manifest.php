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
namespace app\common\util\updater;

/**
 * The signed release manifest (javcms-<version>.json), parsed and checked field by field.
 *
 *   format    "javcms-release-1"           product   "javcms"            channel   "stable"
 *   version   SemVer ("1.2.1")             released  "YYYY-MM-DD"        tag       "v1.2.1"
 *   requires  {php_min, php_tested, mysql_min, mariadb_min, extensions[]}
 *   zip       {name, size, sha256}         the release archive (paths inside it = the "files" keys)
 *   files     {"<path>": ["<sha256>", <size>], ...}   every file of the archive
 *   removed   {"<path>": ["<sha256>", "<version>"]}  files earlier releases shipped and this one does not (optional)
 *   upgrade   {min_from, compat: "additive"|"breaking", alters[], creates[]}
 *   notes     release notes (Markdown, shown escaped)
 *
 * Nothing in a manifest can widen what an update may touch: Paths decides site-owned files, seeds and merge-required
 * files on its own; a manifest that lists a path Paths refuses as invalid is rejected as a whole.
 */
final class Manifest
{
    const FORMAT = 'javcms-release-1';
    const MAX_BYTES = 16777216;
    const MAX_FILES = 60000;

    /** @var array parsed fields */
    public $data = array();
    /** @var string sha256 of the manifest bytes */
    public $sha = '';

    /**
     * Parses and checks a manifest. Throws \InvalidArgumentException with a short reason.
     */
    public static function parse($bytes)
    {
        if (!is_string($bytes) || $bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('manifest is empty or too large');
        }
        $d = json_decode($bytes, true);
        if (!is_array($d)) {
            throw new \InvalidArgumentException('manifest is not JSON');
        }
        if (($d['format'] ?? '') !== self::FORMAT) {
            throw new \InvalidArgumentException('unknown manifest format');
        }
        if (($d['product'] ?? '') !== 'javcms') {
            throw new \InvalidArgumentException('manifest is for another product');
        }
        if (!Util::semverValid((string)($d['version'] ?? ''))) {
            throw new \InvalidArgumentException('manifest version is not valid');
        }
        if (!is_string($d['channel'] ?? null) || !preg_match('/^[a-z]{1,20}$/', $d['channel'])) {
            throw new \InvalidArgumentException('manifest channel is not valid');
        }
        if (!is_string($d['released'] ?? null) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['released'])) {
            throw new \InvalidArgumentException('manifest release date is not valid');
        }
        $zip = $d['zip'] ?? null;
        if (!is_array($zip) || !is_string($zip['name'] ?? null) || !preg_match('/^[A-Za-z0-9._-]{1,100}\.zip$/', $zip['name'])
            || !is_int($zip['size'] ?? null) || $zip['size'] < 1 || !self::isSha($zip['sha256'] ?? null)) {
            throw new \InvalidArgumentException('manifest zip entry is not valid');
        }
        $files = $d['files'] ?? null;
        if (!is_array($files) || !$files || count($files) > self::MAX_FILES) {
            throw new \InvalidArgumentException('manifest file list is not valid');
        }
        $keys = array();
        foreach ($files as $path => $row) {
            $path = (string)$path;
            $why = Paths::invalid($path);
            if ($why !== '') {
                throw new \InvalidArgumentException('manifest path refused (' . $why . '): ' . Util::cut($path, 80));
            }
            if (!is_array($row) || count($row) < 2 || !self::isSha($row[0]) || !is_int($row[1]) || $row[1] < 0) {
                throw new \InvalidArgumentException('manifest entry is not valid: ' . Util::cut($path, 80));
            }
            $k = Paths::key($path);
            if (isset($keys[$k])) {
                throw new \InvalidArgumentException('manifest lists two paths that differ only in case: ' . Util::cut($path, 80));
            }
            $keys[$k] = true;
        }
        foreach ((array)($d['removed'] ?? array()) as $path => $row) {
            if (Paths::invalid((string)$path) !== '' || !is_array($row) || !self::isSha($row[0] ?? null)) {
                throw new \InvalidArgumentException('manifest removed-list entry is not valid');
            }
        }
        $req = is_array($d['requires'] ?? null) ? $d['requires'] : array();
        foreach (array('php_min', 'php_tested', 'mysql_min', 'mariadb_min') as $k) {
            if (isset($req[$k]) && (!is_string($req[$k]) || !preg_match('/^\d+(\.\d+){0,2}$/', $req[$k]))) {
                throw new \InvalidArgumentException('manifest requirement ' . $k . ' is not valid');
            }
        }
        foreach ((array)($req['extensions'] ?? array()) as $ext) {
            if (!is_string($ext) || !preg_match('/^[a-z0-9_]{1,30}$/', $ext)) {
                throw new \InvalidArgumentException('manifest extension list is not valid');
            }
        }
        $up = is_array($d['upgrade'] ?? null) ? $d['upgrade'] : array();
        if (isset($up['min_from']) && !Util::semverValid((string)$up['min_from'])) {
            throw new \InvalidArgumentException('manifest upgrade.min_from is not valid');
        }
        if (isset($up['compat']) && !in_array($up['compat'], array('additive', 'breaking'), true)) {
            throw new \InvalidArgumentException('manifest upgrade.compat is not valid');
        }
        foreach (array('alters', 'creates') as $k) {
            foreach ((array)($up[$k] ?? array()) as $t) {
                if (!is_string($t) || !preg_match('/^[a-z0-9_]{1,60}$/', $t)) {
                    throw new \InvalidArgumentException('manifest upgrade.' . $k . ' is not valid');
                }
            }
        }
        if (isset($d['notes']) && (!is_string($d['notes']) || strlen($d['notes']) > 200000)) {
            throw new \InvalidArgumentException('manifest notes are not valid');
        }
        $m = new self();
        $m->data = $d;
        $m->sha = hash('sha256', $bytes);
        return $m;
    }

    private static function isSha($v)
    {
        return is_string($v) && preg_match('/^[0-9a-f]{64}$/', $v) === 1;
    }

    public function version()
    {
        return (string)$this->data['version'];
    }

    public function files()
    {
        return $this->data['files'];
    }

    public function zip()
    {
        return $this->data['zip'];
    }

    public function requires()
    {
        $r = is_array($this->data['requires'] ?? null) ? $this->data['requires'] : array();
        return $r + array('php_min' => '7.4.0', 'php_tested' => '', 'mysql_min' => '5.6', 'mariadb_min' => '10.1', 'extensions' => array());
    }

    public function upgrade()
    {
        $u = is_array($this->data['upgrade'] ?? null) ? $this->data['upgrade'] : array();
        return $u + array('min_from' => '', 'compat' => 'additive', 'alters' => array(), 'creates' => array());
    }

    public function removed()
    {
        return is_array($this->data['removed'] ?? null) ? $this->data['removed'] : array();
    }

    public function notes()
    {
        return (string)($this->data['notes'] ?? '');
    }

    /** Short facts for the admin page and the CLI. */
    public function summary()
    {
        $z = $this->zip();
        return array(
            'version' => $this->version(), 'released' => (string)$this->data['released'], 'channel' => (string)$this->data['channel'],
            'tag' => (string)($this->data['tag'] ?? ('v' . $this->version())), 'zip' => $z['name'], 'size' => $z['size'],
            'files' => count($this->files()), 'requires' => $this->requires(), 'upgrade' => $this->upgrade(),
        );
    }
}
