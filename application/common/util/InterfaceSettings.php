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

/** External submission settings; no HTTP requests or database writes. */
class InterfaceSettings
{
    const MODULES = ['vodtype'=>1, 'arttype'=>2, 'actortype'=>8, 'websitetype'=>11];

    private static function fail($reason, $field = '', $line = 0)
    {
        throw new \InvalidArgumentException($reason . '|' . $field . '|' . $line);
    }

    public static function errorMessage(\InvalidArgumentException $error)
    {
        $parts = explode('|', $error->getMessage());
        $reason = in_array($parts[0], ['invalid','separator','destination','ambiguous','duplicate'], true) ? $parts[0] : 'invalid';
        $message = lang('admin/interface/error_' . $reason);
        if (!empty($parts[1]) && (isset(self::MODULES[$parts[1]]) || in_array($parts[1], ['status', 'pass'], true))) { $message .= ' [' . $parts[1] . ':' . (int)($parts[2] ?? 0) . ']'; }
        return $message;
    }

    /** A physical newline marks modern line format; hashes then remain literal. */
    public static function lines($text, $legacy = true)
    {
        if (!is_string($text) || strlen($text) > 1048576 || strpos($text, "\0") !== false) { self::fail('invalid'); }
        if (strpbrk($text, "\r\n") !== false) { return preg_split('/\r\n|\r|\n/', $text); }
        if (strpos($text, '#') !== false) {
            $pieces = explode('#', $text);
            $complete = count($pieces) > 1;
            foreach ($pieces as $piece) {
                if (trim($piece) === '') { continue; }
                $pair = explode('=', $piece, 2);
                if (count($pair) !== 2 || trim($pair[0]) === '' || trim($pair[1]) === '') { $complete = false; }
            }
            if ($complete) {
                if (!$legacy) { self::fail('separator'); }
                return $pieces;
            }
        }
        return [$text];
    }

    public static function display($text)
    {
        try { return implode("\n", self::lines($text)); }
        catch (\InvalidArgumentException $error) { return is_string($text) ? $text : ''; }
    }

    private static function destination($name, $mid, array $types, $field, $line)
    {
        $matches = [];
        foreach ($types as $row) {
            if ((int)($row['type_mid'] ?? 0) === $mid && (string)($row['type_name'] ?? '') === $name) {
                $id = (int)($row['type_id'] ?? 0);
                if ($id > 0) { $matches[$id] = true; }
            }
        }
        if (count($matches) > 1) { self::fail('ambiguous', $field, $line); }
        if (count($matches) === 1) { return (int)array_keys($matches)[0]; }
        if (ctype_digit($name)) {
            foreach ($types as $row) {
                if ((int)($row['type_id'] ?? 0) === (int)$name && (int)($row['type_mid'] ?? 0) === $mid && (int)$name > 0) { return (int)$name; }
            }
        }
        self::fail('destination', $field, $line);
    }

    public static function parse($text, $field, array $types, $legacy = true, $strict = true)
    {
        if (!isset(self::MODULES[$field])) { self::fail('invalid'); }
        $map = []; $normalized = []; $issues = []; $blocked = [];
        $lines = self::lines($text, $legacy);
        if (count($lines) > 10000) { self::fail('invalid', $field); }
        foreach ($lines as $index => $line) {
            if (trim($line) === '') { continue; }
            $parts = explode('=', $line, 2);
            $remote = count($parts) === 2 ? trim($parts[1]) : '';
            try {
                if (count($parts) !== 2 || trim($parts[0]) === '' || $remote === '') { self::fail('invalid', $field, $index + 1); }
                $local = trim($parts[0]);
                $id = self::destination($local, self::MODULES[$field], $types, $field, $index + 1);
                if (isset($blocked[$remote])) { self::fail('duplicate', $field, $index + 1); }
                if (array_key_exists($remote, $map) && $map[$remote] !== $id) { self::fail('duplicate', $field, $index + 1); }
                $map[$remote] = $id;
                $normalized[] = $local . '=' . $remote;
            } catch (\InvalidArgumentException $error) {
                if ($strict) { throw $error; }
                $issues[] = $error->getMessage();
                if ($remote !== '') { $map[$remote] = 0; $blocked[$remote] = true; }
            }
        }
        return ['map'=>$map, 'text'=>$normalized ? implode("\n", $normalized) . "\n" : '', 'issues'=>$issues];
    }

    public static function maps(array $config, array $types)
    {
        $out = [];
        foreach (self::MODULES as $field => $mid) {
            try { $out[$field] = self::parse($config[$field] ?? '', $field, $types, true, false)['map']; }
            catch (\InvalidArgumentException $error) { $out[$field] = []; }
        }
        return $out;
    }

    public static function merge(array $current, array $posted, array $types)
    {
        $next = $current;
        foreach ($posted as $field => $value) {
            if (!is_string($value)) { self::fail('invalid', $field); }
            if (isset(self::MODULES[$field])) {
                // Preserve untouched legacy aliases, including visibly warned invalid rows.
                // Disabling access must remain possible without rewriting old mappings.
                $old = isset($current[$field]) && is_string($current[$field]) ? $current[$field] : '';
                $submitted = str_replace(["\r\n", "\r"], "\n", $value);
                if ($submitted === self::display($old)) { continue; }
                $next[$field] = self::parse($value, $field, $types, false)['text'];
            }
            elseif ($field === 'status') {
                if (!in_array($value, ['0','1'], true)) { self::fail('invalid', $field); }
                $next[$field] = $value;
            } elseif ($field === 'pass') {
                if (strlen($value) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $value)) { self::fail('invalid', $field); }
                $next[$field] = $value;
            } else { self::fail('invalid', $field); }
        }
        if ((string)($next['status'] ?? '0') === '1' && strlen((string)($next['pass'] ?? '')) < 16) { self::fail('invalid', 'pass'); }
        return $next;
    }
}
