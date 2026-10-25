<?php
/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Based on MacCMS 10 (Apache-2.0) by magicblack, changed by JavCMS.
 * JavCMS changes: Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors,
 * JavCMS Community License 1.0. The MacCMS parts stay under the Apache License 2.0.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram: https://t.me/avdb_api
 */
namespace app\admin\controller;
use think\Db;
use app\common\util\Dir;
use app\common\util\Database as dbOper;

class Safety extends Base
{

    var $_files;
    /** Tables the content scan reads (brief F31): videos, articles, comments and the other editable text tables. */
    private $scanTables = ['actor', 'art', 'comment', 'gbook', 'link', 'topic', 'type', 'vod'];
    /** At most this many hits are listed on the page (the count is always the full total). */
    const SCAN_SHOW = 200;

    public function __construct()
    {
        parent::__construct();
    }

    public function index()
    {

    }

    /** Menu label / help with a fallback while the shell's menu strings are not installed yet. */
    private function uxText($key, $fallback = '')
    {
        $text = lang($key);
        if (!is_string($text) || $text === '' || $text === $key) {
            return $fallback === '' ? '' : lang($fallback);
        }
        return $text;
    }

    /**
     * Local file security scan (nothing is sent anywhere; the old version downloaded a MacCMS
     * hash list from a base64-hidden MacCMS update-server URL, which never matched JAVCMS files).
     *  ft=1  server-side files (PHP, .htaccess, .user.ini, ...) inside upload/, template/, static/
     *  ft=2  web-shell patterns in PHP code, plus the template safety scan of every theme.
     *        Themes on the operator allow-list (deployment/reviewed-themes.json, the shipped themes) get the
     *        web-shell patterns on their template files instead of the strict theme-gate rules, whose generic
     *        hits (raw SQL, config reads, helper closures in {php} blocks) are normal theme code (brief F32).
     */
    public function file()
    {
        $param = input();
        if (!empty($param['ck'])) {
            $ft = isset($param['ft']) && is_array($param['ft']) ? $param['ft'] : ['1', '2'];
            @set_time_limit(300);
            mac_echo('<link rel="stylesheet" href="' . MAC_PATH . 'static/css/admin_ux.css?v=' . (int)@filemtime(ROOT_PATH . 'static/css/admin_ux.css') . '"><body class="admin-studio ax-raw-output">');
            $root = rtrim(str_replace('\\', '/', ROOT_PATH), '/') . '/';
            $found = 0;
            $scanned = 0;
            $report = function ($path, $kind, $message) use (&$found) {
                $found++;
                mac_echo(htmlspecialchars($path, ENT_QUOTES, 'UTF-8') . ' &mdash; <span class="ax-' . ($kind === 'warn' ? 'warn' : 'danger') . '-text">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</span>');
            };
            if (in_array('1', $ft, true)) {
                foreach (['upload', 'template', 'static'] as $folder) {
                    foreach ($this->safetyFiles($root . $folder) as $file) {
                        $scanned++;
                        if (\app\admin\util\ThemeGate::isExecutableName($file)) {
                            $report(substr($file, strlen($root)), 'warn', lang('admin/safety/file_msg3'));
                        }
                    }
                }
            }
            if (in_array('2', $ft, true)) {
                $patterns = [
                    '/\beval\s*\(\s*(?:@\s*)?(?:base64_decode|gzinflate|gzuncompress|gzdecode|str_rot13|hex2bin|\$_(?:GET|POST|REQUEST|COOKIE|SERVER))/i',
                    '/\bassert\s*\(\s*(?:@\s*)?\$_(?:GET|POST|REQUEST|COOKIE)/i',
                    '/\b(?:system|exec|shell_exec|passthru|popen|proc_open|pcntl_exec)\s*\(\s*(?:@\s*)?\$_(?:GET|POST|REQUEST|COOKIE)/i',
                    '/\$_(?:GET|POST|REQUEST|COOKIE)\s*\[[^\]]{1,40}\]\s*\(/i',
                    '/\bcreate_function\s*\([^;]{0,200}\$_(?:GET|POST|REQUEST|COOKIE)/i',
                    '/\bbase64_decode\s*\(\s*[\'"]aHR0c/i',
                    '/\bpreg_replace\s*\(\s*[\'"]([^\w\s\\\\])[^\'"]*\1[imsxuADSUXJ]*e[imsxuADSUXJ]*[\'"]/',
                ];
                foreach (['application', 'extend', 'thinkphp', 'vendor', 'addons'] as $folder) {
                    foreach ($this->safetyFiles($root . $folder) as $file) {
                        if (!preg_match('/\.(?:php\d?|phtml|inc)$/i', $file) || filesize($file) > 4194304) {
                            continue;
                        }
                        $scanned++;
                        $code = (string)@file_get_contents($file);
                        foreach ($patterns as $pattern) {
                            if (preg_match($pattern, $code)) {
                                $report(substr($file, strlen($root)), 'danger', lang('admin/safety/file_msg4'));
                                break;
                            }
                        }
                    }
                }
                foreach (glob($root . '*.php') ?: [] as $file) {
                    $scanned++;
                    $code = (string)@file_get_contents($file);
                    foreach ($patterns as $pattern) {
                        if (preg_match($pattern, $code)) {
                            $report(substr(str_replace('\\', '/', $file), strlen($root)), 'danger', lang('admin/safety/file_msg4'));
                            break;
                        }
                    }
                }
                foreach (\app\admin\util\ThemeGate::directories() as $theme) {
                    if (\app\admin\util\ThemeGate::isReviewed($theme)) {
                        // Reviewed (shipped) theme: web-shell patterns on every template text file.
                        foreach ($this->safetyFiles($root . 'template/' . $theme) as $file) {
                            if (!preg_match('/\.(?:html?|tpl|php\d?|phtml|inc)$/i', $file) || filesize($file) > 4194304) {
                                continue;
                            }
                            $code = (string)@file_get_contents($file);
                            foreach ($patterns as $pattern) {
                                if (preg_match($pattern, $code)) {
                                    $report(substr($file, strlen($root)), 'danger', lang('admin/safety/file_msg4'));
                                    break;
                                }
                            }
                        }
                        continue;
                    }
                    $scan = \app\admin\util\ThemeGate::scan($theme);
                    foreach ($scan['findings'] as $finding) {
                        $parts = explode(':', $finding, 2);
                        $report('template/' . $theme . '/' . ($parts[1] ?? ''), 'danger', lang('admin/safety/file_msg4') . ' (' . $parts[0] . ')');
                    }
                }
            }
            mac_echo(lang('admin/safety/file_done', [$scanned, $found]));
            exit;
        }
        $this->assign('title', $this->uxText('admin/ux/menu/security_scan', 'admin/safety/file_inspect'));
        $this->assign('ax_head', [
            'title' => $this->uxText('admin/ux/menu/security_scan', 'admin/safety/file_inspect'),
            'help' => $this->uxText('admin/ux/help/security_scan'),
        ]);
        return $this->fetch('admin@safety/file');
    }

    /** All regular files below a folder (no symlink traversal), as forward-slash paths. */
    private function safetyFiles($dir)
    {
        $files = [];
        if (!is_dir($dir)) {
            return $files;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $files[] = str_replace('\\', '/', $item->getPathname());
            }
        }
        return $files;
    }

    /**
     * Content scan (brief F31). GET lists what would change and never writes:
     *   safety/data?ck=1[&iframes=1]           scan and list (table, column, row id, before / after snippet, total)
     * POST (token required, JSON answers):
     *   make_backup=1[&iframes=1]              back up the tables the scan touches, answer the backup file name
     *   clean=1&confirm=1&expect=N&backup=F    clean exactly the rows a fresh scan finds (N must still match)
     * Old links with ck=1&tbi=... only scan now: nothing is ever cleaned without the confirm step.
     */
    public function data()
    {
        $param = input();
        $iframes = !empty($param['iframes']) && (string)$param['iframes'] === '1';
        if ($this->request->isPost()) {
            $validate = \think\Loader::validate('Token');
            if (!$validate->check($param)) {
                return $this->ajaxErrorWithFreshToken($validate->getError());
            }
            $scan = $this->contentScan($iframes);
            if (!empty($param['make_backup'])) {
                if ($scan['rows'] < 1) {
                    return $this->ajaxErrorWithFreshToken(lang('admin/ux/tools/scan_none'));
                }
                $made = $this->backupTables($scan['tables']);
                if ($made === false) {
                    return $this->ajaxErrorWithFreshToken(lang('admin/database/backup_err'));
                }
                if (is_string($made) && !preg_match('/\.sql(?:\.gz)?$/', $made)) {
                    return $this->ajaxErrorWithFreshToken($made);
                }
                return $this->ajaxSuccessWithFreshToken(lang('admin/ux/tools/scan_backup_made', [$made]), ['backup' => $made, 'rows' => $scan['rows']]);
            }
            if (!empty($param['clean'])) {
                if (empty($param['confirm']) || (string)$param['confirm'] !== '1') {
                    return $this->ajaxErrorWithFreshToken(lang('admin/ux/tools/confirm_needed'));
                }
                $backup = isset($param['backup']) && is_string($param['backup']) ? $param['backup'] : '';
                $folder = $this->backupFolder();
                if (!preg_match('/^\d{8}-\d{6}-\d+\.sql(?:\.gz)?$/', $backup) || !is_file($folder . $backup) || filemtime($folder . $backup) < time() - 3600) {
                    return $this->ajaxErrorWithFreshToken(lang('admin/ux/tools/scan_backup_missing'));
                }
                if ((int)($param['expect'] ?? -1) !== $scan['rows']) {
                    return $this->ajaxErrorWithFreshToken(lang('admin/ux/tools/scan_changed'));
                }
                $changed = 0;
                foreach ($scan['updates'] as $table => $rows) {
                    foreach ($rows as $id => $columns) {
                        Db::name($table)->where($table . '_id', $id)->update($columns);
                        $changed++;
                    }
                }
                return $this->ajaxSuccessWithFreshToken(str_replace('{n}', (string)$changed, lang('admin/ux/tools/sql_rows_changed')), ['rows' => $changed]);
            }
            return $this->ajaxErrorWithFreshToken(lang('param_err'));
        }

        $scanned = !empty($param['ck']);
        $scan = $scanned ? $this->contentScan($iframes) : ['hits' => [], 'rows' => 0, 'tables' => []];
        $this->assign('scanned', $scanned ? 1 : 0);
        $this->assign('iframes', $iframes ? 1 : 0);
        $this->assign('hits', array_slice($scan['hits'], 0, self::SCAN_SHOW));
        $this->assign('hit_total', count($scan['hits']));
        $this->assign('scan_rows', $scan['rows']);
        $this->assign('scan_show', self::SCAN_SHOW);
        $fill = function ($key, $n) { return str_replace('{n}', (string)$n, lang($key)); };
        $this->assign('t_found', $fill('admin/ux/tools/scan_found', $scan['rows']));
        $this->assign('t_first', $fill('admin/ux/tools/scan_first_rows', self::SCAN_SHOW));
        $this->assign('t_confirm', $fill('admin/ux/tools/scan_confirm', $scan['rows']));
        $this->assign('t_clean', $fill('admin/ux/tools/scan_clean', $scan['rows']));
        $this->assign('title', lang('admin/ux/tools/scan_content_title'));
        return $this->fetch('admin@safety/data');
    }

    /** Text with injected code removed: script blocks (own end tag, never greedy), template code, optionally iframes. */
    private function cleanText($text, $iframes)
    {
        $rules = [
            '#<script\b[^>]*>.*?</script\s*>#is',
            '#<script\b[^>]*>#is',
            '#</script\s*>#i',
            '#\{php\}.*?\{/php\}#is',
            '#\{/?php\}#i',
            '#\{:[^}]*\}#s',
        ];
        if ($iframes) {
            $rules[] = '#<iframe\b[^>]*>.*?</iframe\s*>#is';
            $rules[] = '#<iframe\b[^>]*>#is';
            $rules[] = '#</iframe\s*>#i';
        }
        foreach ($rules as $rule) {
            $next = preg_replace($rule, '', $text);
            if (is_string($next)) {
                $text = $next;
            }
        }
        return $text;
    }

    /** Byte offset of the first piece the cleaner would remove (for the snippet). */
    private function firstHit($text, $iframes)
    {
        $pattern = $iframes ? '#<script\b|</script|\{/?php\}|\{:|<iframe\b|</iframe#i' : '#<script\b|</script|\{/?php\}|\{:#i';
        return preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE) ? (int)$m[0][1] : 0;
    }

    private function snippet($text, $offset)
    {
        $start = max(0, $offset - 40);
        $cut = mb_strcut((string)$text, $start, 120, 'UTF-8');
        return ($start > 0 ? '…' : '') . $cut . (strlen((string)$text) > $start + strlen($cut) ? '…' : '');
    }

    /**
     * Read-only scan of the text columns of the scan tables.
     * @return array hits (table, column, id, name, before, after), rows (distinct rows that change),
     *               tables (prefixed names of tables with changes), updates [table => [id => [column => new text]]]
     */
    private function contentScan($iframes)
    {
        $pre = config('database.prefix');
        $schema = Db::query('SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE FROM information_schema.columns WHERE table_schema = ?', [config('database.database')]);
        $columns = [];
        foreach ($schema as $col) {
            $columns[$col['TABLE_NAME']][$col['COLUMN_NAME']] = strtolower($col['DATA_TYPE']);
        }
        $needles = ['<script', '</script', '{php}', '{:'];
        if ($iframes) {
            $needles[] = '<iframe';
        }
        $result = ['hits' => [], 'rows' => 0, 'tables' => [], 'updates' => []];
        foreach ($this->scanTables as $table) {
            $full = $pre . $table;
            $pk = $table . '_id';
            if (empty($columns[$full]) || !isset($columns[$full][$pk])) {
                continue;
            }
            $textCols = [];
            foreach ($columns[$full] as $name => $type) {
                if (in_array($type, ['char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext'], true)) {
                    $textCols[] = $name;
                }
            }
            if (!$textCols) {
                continue;
            }
            $nameCol = isset($columns[$full][$table . '_name']) ? $table . '_name' : '';
            $list = Db::name($table)->field(array_unique(array_merge([$pk], $textCols)))->where(function ($query) use ($textCols, $needles) {
                foreach ($textCols as $col) {
                    foreach ($needles as $needle) {
                        $query->whereOr($col, 'like', '%' . $needle . '%');
                    }
                }
            })->order($pk . ' asc')->select();
            foreach ($list as $row) {
                $id = (int)$row[$pk];
                foreach ($textCols as $col) {
                    $old = (string)$row[$col];
                    if ($old === '') {
                        continue;
                    }
                    $new = $this->cleanText($old, $iframes);
                    if ($new === $old) {
                        continue;
                    }
                    $at = $this->firstHit($old, $iframes);
                    $result['hits'][] = [
                        'table' => $full,
                        'column' => $col,
                        'id' => $id,
                        'name' => $nameCol !== '' ? mb_substr(strip_tags(html_entity_decode((string)$row[$nameCol], ENT_QUOTES, 'UTF-8')), 0, 60) : '',
                        'before' => $this->snippet($old, $at),
                        'after' => $this->snippet($new, $at),
                    ];
                    $result['updates'][$table][$id][$col] = $new;
                }
            }
            if (!empty($result['updates'][$table])) {
                $result['rows'] += count($result['updates'][$table]);
                $result['tables'][] = $full;
            }
        }
        return $result;
    }

    private function backupFolder()
    {
        return rtrim(str_replace('\\', '/', $GLOBALS['config']['db']['backup_path']), '/') . '/';
    }

    /**
     * Back up the given tables with the Database page's backup code (same files, same folder, same lock).
     * @return string|false backup file name (first part), an error text, or false
     */
    private function backupTables(array $tables)
    {
        $config = [
            'path' => $this->backupFolder(),
            'part' => $GLOBALS['config']['db']['part_size'],
            'compress' => $GLOBALS['config']['db']['compress'],
            'level' => $GLOBALS['config']['db']['compress_level'],
        ];
        if (!is_dir($config['path'])) {
            Dir::create($config['path'], 0755, true);
        }
        $lock = $config['path'] . 'backup.lock';
        if (is_file($lock)) {
            return lang('admin/database/lock_check');
        }
        file_put_contents($lock, time());
        // The backup writer appends: never reuse the name of a backup made in the same second.
        for ($try = 0; $try < 3 && glob($config['path'] . date('Ymd-His') . '-*.sql*'); $try++) {
            sleep(1);
        }
        $file = ['name' => date('Ymd-His'), 'part' => 1];
        try {
            $database = new dbOper($file, $config);
            if ($database->create() === false) {
                @unlink($lock);
                return false;
            }
            foreach ($tables as $table) {
                $start = $database->backup($table, 0);
                while (0 !== $start) {
                    if (false === $start) {
                        @unlink($lock);
                        return false;
                    }
                    $start = $database->backup($table, $start[0]);
                }
            }
        } catch (\Throwable $e) {
            @unlink($lock);
            return false;
        }
        @unlink($lock);
        $name = $file['name'] . '-1.sql' . ($config['compress'] ? '.gz' : '');
        return is_file($config['path'] . $name) ? $name : false;
    }
}
