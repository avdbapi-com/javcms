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
 * Ed25519 signatures of release manifests (UPDATER decision U2).
 *
 * A manifest javcms-<version>.json is signed byte for byte; its signature file javcms-<version>.json.sig is JSON:
 *   {"format":"javcms-sig-1","alg":"ed25519","key":"<key id>","sig":"<base64, 64 bytes>","endorse":[...]}
 * "endorse" (optional) carries key rotations: {"key":"<new id>","pk":"<base64>","from":"YYYY-MM-DD","by":"<old id>",
 * "sig":"<base64>"}, where sig signs the text "javcms-key-endorse-1\n<new id>\n<pk>\n<from>" with the old key.
 *
 * Verification uses ext-sodium (0.1 ms) and, when a host lacks it (PHP on Windows does not load it by default), the
 * trimmed sodium_compat copy in sodium/ (about 60 ms; UPDATER-SCOUT-HOST rule 20).
 *
 * Endorsements do not expire, and their "from" date is informational: a trusted key can endorse new keys for as long as
 * it is trusted. A rotation is therefore finished only when a later release lists the old key in Keys::REVOKED (then
 * neither that key nor anything it endorsed is accepted again, unless the new key is itself listed in Keys::TRUSTED);
 * release-tools/README.md gives the procedure.
 */
final class Signature
{
    const FORMAT = 'javcms-sig-1';
    const ENDORSE_PREFIX = "javcms-key-endorse-1\n";

    /** Detached Ed25519 check; false for any malformed input. */
    public static function verifyDetached($sig, $msg, $pk)
    {
        if (!is_string($sig) || !is_string($msg) || !is_string($pk) || strlen($sig) !== 64 || strlen($pk) !== 32) {
            return false;
        }
        if (function_exists('sodium_crypto_sign_verify_detached') && !Config::fault('no_sodium')) {
            try {
                return sodium_crypto_sign_verify_detached($sig, $msg, $pk);
            } catch (\Throwable $e) {
                return false;
            }
        }
        if (!class_exists('JavcmsUpdSodium_Core_Ed25519', false)) {
            require_once __DIR__ . '/sodium/load.php';
        }
        try {
            return (bool)\JavcmsUpdSodium_Core_Ed25519::verify_detached($sig, $msg, $pk);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Which engine verifies here: 'ext-sodium' or 'sodium_compat'. */
    public static function engine()
    {
        return function_exists('sodium_crypto_sign_verify_detached') && !Config::fault('no_sodium') ? 'ext-sodium' : 'sodium_compat';
    }

    /** Strict base64 → bytes of an exact length, or null. */
    public static function b64($s, $len)
    {
        if (!is_string($s) || $s === '' || strlen($s) > 200) {
            return null;
        }
        $raw = base64_decode($s, true);
        return (is_string($raw) && strlen($raw) === $len) ? $raw : null;
    }

    /**
     * Keys in force: id => raw public key. Production: Keys::TRUSTED; test mode: the TEST keys of the test file. Plus
     * keys endorsed earlier (runtime/update/keys.php), each re-checked against the keys above on every load.
     */
    public static function keyring()
    {
        $ring = array();
        $test = Config::test();
        $tool = Config::toolKeys();
        $base = $test !== null ? (array)($test['keys'] ?? array()) : ($tool !== null ? $tool['trusted'] : Keys::TRUSTED);
        foreach ($base as $id => $b64) {
            $raw = self::b64($b64, 32);
            if ($raw !== null && Keys::id($raw) === $id && !self::revoked($id)) {
                $ring[$id] = $raw;
            }
        }
        $stored = $tool !== null ? null : Util::loadData(Util::root() . 'runtime/update/keys.php');
        if (is_array($stored)) {
            foreach ((array)($stored['endorsed'] ?? array()) as $e) {
                if (is_array($e) && self::checkEndorsement($e, $ring) && !isset($ring[$e['key']])) {
                    $ring[$e['key']] = self::b64($e['pk'], 32);
                }
            }
        }
        return $ring;
    }

    public static function revoked($id)
    {
        $test = Config::test();
        $tool = Config::toolKeys();
        $list = $test !== null ? (array)($test['revoked'] ?? array()) : ($tool !== null ? $tool['revoked'] : Keys::REVOKED);
        return in_array($id, $list, true);
    }

    /** True when endorsement $e is signed by a key of $ring and names a well-formed, unrevoked key. */
    public static function checkEndorsement(array $e, array $ring)
    {
        foreach (array('key', 'pk', 'from', 'by', 'sig') as $k) {
            if (!isset($e[$k]) || !is_string($e[$k])) {
                return false;
            }
        }
        $pk = self::b64($e['pk'], 32);
        $sig = self::b64($e['sig'], 64);
        if ($pk === null || $sig === null || Keys::id($pk) !== $e['key'] || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $e['from'])) {
            return false;
        }
        if (self::revoked($e['key']) || self::revoked($e['by']) || !isset($ring[$e['by']])) {
            return false;
        }
        $text = self::ENDORSE_PREFIX . $e['key'] . "\n" . $e['pk'] . "\n" . $e['from'];
        return self::verifyDetached($sig, $text, $ring[$e['by']]);
    }

    /**
     * Verifies $bytes (the manifest file) against $sigJson (the .sig file). Returns ['ok' => bool, 'key' => id,
     * 'error' => text, 'endorsed' => id of a key accepted through an endorsement or ''].
     */
    public static function verify($bytes, $sigJson)
    {
        $s = json_decode((string)$sigJson, true);
        if (!is_array($s) || ($s['format'] ?? '') !== self::FORMAT || ($s['alg'] ?? '') !== 'ed25519') {
            return array('ok' => false, 'key' => '', 'error' => 'signature file is not a JavCMS signature', 'endorsed' => '');
        }
        $id = (string)($s['key'] ?? '');
        $sig = self::b64($s['sig'] ?? '', 64);
        if ($sig === null || !preg_match('/^k[0-9a-f]{12}$/', $id)) {
            return array('ok' => false, 'key' => $id, 'error' => 'signature is malformed', 'endorsed' => '');
        }
        if (self::revoked($id)) {
            return array('ok' => false, 'key' => $id, 'error' => 'the release key ' . $id . ' was revoked', 'endorsed' => '');
        }
        $ring = self::keyring();
        if (!$ring) {
            return array('ok' => false, 'key' => $id, 'error' => 'this build has no release key', 'endorsed' => '');
        }
        $endorsed = '';
        if (!isset($ring[$id])) {
            foreach ((array)($s['endorse'] ?? array()) as $e) {
                if (is_array($e) && ($e['key'] ?? '') === $id && self::checkEndorsement($e, $ring)) {
                    $ring[$id] = self::b64($e['pk'], 32);
                    $endorsed = $id;
                    $endorsement = $e;
                    break;
                }
            }
        }
        if (!isset($ring[$id])) {
            return array('ok' => false, 'key' => $id, 'error' => 'signed by an unknown key ' . $id, 'endorsed' => '');
        }
        if (!self::verifyDetached($sig, (string)$bytes, $ring[$id])) {
            return array('ok' => false, 'key' => $id, 'error' => 'signature does not match the file', 'endorsed' => '');
        }
        if ($endorsed !== '' && isset($endorsement)) {
            self::remember($endorsement);
        }
        return array('ok' => true, 'key' => $id, 'error' => '', 'endorsed' => $endorsed);
    }

    /** Stores an accepted endorsement (runtime/update/keys.php). */
    private static function remember(array $e)
    {
        if (Config::toolKeys() !== null) {
            return; // the release tools check, they never store anything
        }
        $file = Util::dir() . 'keys.php';
        if ($file === 'keys.php') {
            return;
        }
        $data = Util::loadData($file);
        $data = is_array($data) ? $data : array('endorsed' => array());
        foreach ((array)$data['endorsed'] as $old) {
            if (is_array($old) && ($old['key'] ?? '') === $e['key']) {
                return;
            }
        }
        $data['endorsed'][] = array('key' => $e['key'], 'pk' => $e['pk'], 'from' => $e['from'], 'by' => $e['by'], 'sig' => $e['sig'], 'at' => time());
        Util::saveData($file, $data);
    }
}
