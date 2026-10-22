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

/** Local calendar windows and serialized task-settings writes. No task dispatch here. */
class ScheduledTasks
{
    public static function selected($value, $maximum, $padded = false)
    {
        if (!is_string($value)) { return []; }
        $items = [];
        foreach (explode(',', $value) as $item) {
            $item = trim($item);
            if ($item === '' || !preg_match('/^[0-9]{1,2}$/D', $item) || (int) $item > $maximum) { continue; }
            $items[] = $padded ? sprintf('%02d', (int) $item) : (string) (int) $item;
        }
        return array_values(array_unique($items));
    }

    public static function due(array $task, $now, $timezone, $enforce = false)
    {
        if (!isset($task['status']) || (string) $task['status'] !== '1') { return false; }
        if ($enforce) { return true; }
        $zone = new \DateTimeZone($timezone);
        $current = (new \DateTimeImmutable('@' . (int) $now))->setTimezone($zone);
        if (!in_array($current->format('w'), self::selected($task['weeks'] ?? '', 6), true)
            || !in_array($current->format('H'), self::selected($task['hours'] ?? '', 23, true), true)) { return false; }
        $runtime = $task['runtime'] ?? 0;
        if ((is_int($runtime) || is_string($runtime)) && preg_match('/^[0-9]+$/D', (string) $runtime) && (int) $runtime > 0) {
            $last = (new \DateTimeImmutable('@' . (int) $runtime))->setTimezone($zone);
            if ($last->format('Y-m-d H') === $current->format('Y-m-d H')) { return false; }
        }
        return true;
    }

    private static function text($value)
    {
        if (!is_string($value) && !is_int($value)) { throw new \InvalidArgumentException(); }
        return (string) $value;
    }

    public static function name($value)
    {
        $name = trim(self::text($value));
        if ($name === '' || strlen($name) > 128 || preg_match('/[\x00-\x1f\x7f,]/', $name)) { throw new \InvalidArgumentException(); }
        return $name;
    }

    private static function calendar($items, $maximum, $padded)
    {
        if (!is_array($items) || count($items) > $maximum + 1) { throw new \InvalidArgumentException(); }
        $result = [];
        foreach ($items as $item) {
            if ((!is_string($item) && !is_int($item)) || !preg_match('/^[0-9]{1,2}$/D', (string) $item) || (int) $item > $maximum) { throw new \InvalidArgumentException(); }
            $result[] = $padded ? sprintf('%02d', (int) $item) : (string) (int) $item;
        }
        return implode(',', array_values(array_unique($result)));
    }

    public static function edit(array $old, array $posted)
    {
        $next = $old;
        $next['name'] = self::name($posted['name'] ?? '');
        $next['des'] = self::text($posted['des'] ?? '');
        $next['param'] = self::text($posted['param'] ?? '');
        $next['file'] = self::text($posted['file'] ?? '');
        $next['status'] = self::text($posted['status'] ?? '0');
        if (!in_array($next['file'], ['collect', 'make', 'cj', 'cache', 'urlsend'], true)
            || !in_array($next['status'], ['0', '1'], true) || strlen($next['des']) > 4096 || strlen($next['param']) > 65535) { throw new \InvalidArgumentException(); }
        $next['weeks'] = self::calendar($posted['weeks'] ?? [], 6, false);
        $next['hours'] = self::calendar($posted['hours'] ?? [], 23, true);
        if (!isset($next['runtime'])) { $next['runtime'] = 0; }
        return $next;
    }

    /** Backup intentional admin mutations once; automatic runtime claims use exact in-memory rollback. */
    public static function update($path, $runtime, callable $mutator, $backup = true)
    {
        $lock = @fopen(rtrim($runtime, '/\\') . '/scheduled-task-settings.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) {
            if ($lock) { fclose($lock); }
            throw new \RuntimeException('Scheduled task settings unavailable');
        }
        try {
            $original = @file_get_contents($path);
            if ($original === false) { throw new \RuntimeException('Scheduled task settings unavailable'); }
            if (function_exists('opcache_invalidate')) { @opcache_invalidate($path, true); }
            $old = include $path;
            if (!is_array($old)) { throw new \RuntimeException('Scheduled task settings invalid'); }
            $next = $mutator($old);
            if (!is_array($next)) { throw new \RuntimeException('Scheduled task settings invalid'); }
            if ($next === $old) { return $next; }
            if ($backup) {
                if (!class_exists(__NAMESPACE__ . '\\UrlSettings')) { require_once __DIR__ . '/UrlSettings.php'; }
                if (UrlSettings::backupFiles([$path], rtrim($runtime, '/\\') . '/scheduled-task-backups/') === false) {
                    throw new \RuntimeException('Scheduled task backup failed');
                }
            }
            $expected = "<?php\nreturn " . var_export($next, true) . ';';
            try { $written = mac_arr2file($path, $next); }
            catch (\Throwable $error) { $written = false; }
            if ($written === false || @file_get_contents($path) !== $expected) {
                $restored = @file_put_contents($path, $original, LOCK_EX);
                if (function_exists('opcache_invalidate')) { @opcache_invalidate($path, true); }
                if ($restored !== strlen($original) || @file_get_contents($path) !== $original) {
                    throw new \RuntimeException('Scheduled task settings rollback failed');
                }
                throw new \RuntimeException('Scheduled task settings write failed');
            }
            if (function_exists('opcache_invalidate')) { @opcache_invalidate($path, true); }
            return $next;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
