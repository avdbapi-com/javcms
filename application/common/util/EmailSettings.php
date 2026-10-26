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

/** Validates email settings without network access or configuration writes. */
class EmailSettings
{
    private static function invalid()
    {
        throw new \InvalidArgumentException('admin/system/configemail/invalid_settings');
    }

    private static function scalar($value)
    {
        if (!is_scalar($value)) { self::invalid(); }
        return (string) $value;
    }

    private static function headerValue($value)
    {
        $value = self::scalar($value);
        if (preg_match('/[\x00-\x1f\x7f]/', $value)) { self::invalid(); }
        return $value;
    }

    private static function integer($value, $maximum)
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^[0-9]+$/D', (string) $value)
            || strlen((string) $value) > 10 || (float) $value < 1 || (float) $value > $maximum) { self::invalid(); }
        return (int) $value;
    }

    public static function recipient($value)
    {
        $value = trim(self::headerValue($value));
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) { self::invalid(); }
        return $value;
    }

    private static function host($value)
    {
        $value = trim(self::headerValue($value));
        if ($value === '' || preg_match('/[\s\/@?#;\\\\]/', $value)) { self::invalid(); }
        $ip = $value;
        if ($value[0] === '[' && substr($value, -1) === ']') { $ip = substr($value, 1, -1); }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) { return '[' . $ip . ']'; }
        if (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) { return $value; }
        if (strlen($value) > 253 || !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/iD', $value)) { self::invalid(); }
        // Invalid dotted IP literals must not fall back to the DNS-name grammar.
        if (preg_match('/^[0-9.]+$/D', $value)) { self::invalid(); }
        return $value;
    }

    private static function secure($value)
    {
        $value = strtolower(trim(self::headerValue($value)));
        if ($value === 'tsl') { $value = 'tls'; }
        if (!in_array($value, ['', 'tls', 'ssl'], true)) { self::invalid(); }
        return $value;
    }

    public static function normalizeSmtp(array $config)
    {
        $settings = $config;
        $settings['host'] = self::host($config['host'] ?? '');
        $settings['port'] = self::integer($config['port'] ?? 25, 65535);
        $settings['secure'] = self::secure($config['secure'] ?? '');
        $settings['username'] = self::headerValue($config['username'] ?? '');
        $settings['password'] = self::scalar($config['password'] ?? '');
        $settings['nick'] = self::headerValue($config['nick'] ?? '');
        $from = self::headerValue($config['from'] ?? '');
        $settings['from'] = self::recipient(trim($from) === '' ? $settings['username'] : $from);
        $settings['auth'] = $settings['username'] !== '';
        if (!$settings['auth'] && $settings['password'] !== '') { self::invalid(); }
        return $settings;
    }

    /** Preserve omitted custom providers, templates and provider-specific settings. */
    public static function merge(array $old, array $posted)
    {
        foreach (['type', 'nick', 'test', 'time'] as $field) {
            if (array_key_exists($field, $posted)) { self::scalar($posted[$field]); }
        }
        if (array_key_exists('type', $posted)) {
            $posted['type'] = trim(self::headerValue($posted['type']));
            if ($posted['type'] !== '' && !preg_match('/^[a-z][a-z0-9_]{0,63}$/iD', $posted['type'])) { self::invalid(); }
        }
        if (array_key_exists('nick', $posted)) { $posted['nick'] = self::headerValue($posted['nick']); }
        if (array_key_exists('test', $posted)) {
            $posted['test'] = trim(self::headerValue($posted['test']));
            if ($posted['test'] !== '') { $posted['test'] = self::recipient($posted['test']); }
        }
        if (array_key_exists('time', $posted)) { $posted['time'] = self::integer($posted['time'], 2147483647); }
        if (array_key_exists('tpl', $posted)) {
            if (!is_array($posted['tpl'])) { self::invalid(); }
            foreach ($posted['tpl'] as $value) { self::scalar($value); }
        }
        if (array_key_exists('phpmailer', $posted)) {
            if (!is_array($posted['phpmailer'])) { self::invalid(); }
            foreach (['host', 'port', 'username', 'password', 'secure', 'from', 'nick'] as $field) {
                if (array_key_exists($field, $posted['phpmailer'])) { self::scalar($posted['phpmailer'][$field]); }
            }
            foreach (['host', 'username', 'from', 'nick'] as $field) {
                if (array_key_exists($field, $posted['phpmailer'])) { self::headerValue($posted['phpmailer'][$field]); }
            }
            if (isset($posted['phpmailer']['host']) && $posted['phpmailer']['host'] !== '') { $posted['phpmailer']['host'] = self::host($posted['phpmailer']['host']); }
            if (isset($posted['phpmailer']['port']) && $posted['phpmailer']['port'] !== '') { $posted['phpmailer']['port'] = self::integer($posted['phpmailer']['port'], 65535); }
            if (isset($posted['phpmailer']['from']) && trim((string) $posted['phpmailer']['from']) !== '') { $posted['phpmailer']['from'] = self::recipient($posted['phpmailer']['from']); }
            if (array_key_exists('secure', $posted['phpmailer'])) { $posted['phpmailer']['secure'] = self::secure($posted['phpmailer']['secure']); }
        }
        $merged = array_replace_recursive($old, $posted);
        if (strtolower((string) ($merged['type'] ?? '')) === 'phpmailer') {
            if (!isset($merged['phpmailer']) || !is_array($merged['phpmailer'])) { self::invalid(); }
            // Sender nickname belongs to the email section in existing forms.
            $normalized = self::normalizeSmtp(array_replace($merged['phpmailer'], ['nick' => $merged['nick'] ?? '']));
            foreach (['host', 'port', 'secure', 'username', 'password', 'from'] as $field) { $merged['phpmailer'][$field] = $normalized[$field]; }
        }
        return $merged;
    }
}
