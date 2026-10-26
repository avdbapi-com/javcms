<?php
/*
 * This file is part of JavCMS - https://github.com/avdbapi-com/javcms
 * Based on MacCMS 10 (Apache-2.0) by magicblack, changed by JavCMS.
 * JavCMS changes: Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors,
 * JavCMS Community License 1.0. The MacCMS parts stay under the Apache License 2.0.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://github.com/avdbapi-com/javcms https://avdbapi.com https://xvidapi.com https://upload18.org
 * Live demo: https://javcms.net (adult content, 18 and over only)
 * Telegram group: https://t.me/+jj6McO-iYfA0MTM1  Support: https://t.me/avdbapi_support
 */
namespace app\admin\controller;
use think\Db;
use app\common\util\Dir;
use app\common\util\Database as dbOper;

class Database extends Base
{
    var $_db_config;
    public function __construct()
    {
        parent::__construct();
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

    /** The backup folder as configured (relative to the site root), with one trailing slash. */
    private function backupFolder()
    {
        return rtrim(str_replace('\\', '/', $GLOBALS['config']['db']['backup_path']), '/') . '/';
    }

    /** Backup folder shown to the admin ("application/data/backup/"). */
    private function backupFolderLabel()
    {
        return preg_replace('#^\./#', '', $this->backupFolder());
    }

    /**
     * Stream one backup file (N11). Only a plain file name from the backup folder is accepted.
     * @return false when the name or file is not acceptable
     */
    private function sendBackup($name)
    {
        if (!is_string($name) || !preg_match('/^[\w.-]+\.(sql|gz|zip)$/', $name) || strpos($name, '..') !== false) {
            return false;
        }
        $folder = realpath($this->backupFolder());
        $file = $folder === false ? false : realpath($folder . DIRECTORY_SEPARATOR . $name);
        if ($file === false || !is_file($file) || dirname($file) !== $folder) {
            return false;
        }
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($file));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($file);
        exit;
    }

    /**
     * Hardening (security r3 P2-3): backup, restore, delete, optimize and repair change data or files, so they
     * need a POST with the session admin token (__token__ field or X-CSRF-Token header), the same rule as axCsrfOk.
     */
    private function writeOk()
    {
        if (!$this->request->isPost()) {
            return false;
        }
        $sent = $this->postText('__token__');
        if (!is_string($sent) || $sent === '') {
            $sent = (string)$this->request->header('X-CSRF-Token');
        }
        $expected = function_exists('mac_admin_csrf_token') ? (string)mac_admin_csrf_token() : '';
        return $sent !== '' && $expected !== '' && hash_equals($expected, $sent);
    }

    /** One POST field as a string ('' when missing or not a string, e.g. an array). */
    private function postText($name)
    {
        $post = $this->request->post();
        return isset($post[$name]) && is_string($post[$name]) ? trim($post[$name]) : '';
    }

    /** A backup id is the Unix time in its file names (date('Ymd-His')); anything else gives 0. */
    private function backupId($id)
    {
        if (!is_scalar($id) || !preg_match('/^[1-9][0-9]{8,10}$/', (string)$id)) {
            return 0;
        }
        return (int)$id;
    }

    /** Part files of one backup, sorted, from the backup folder only. */
    private function backupFiles($id)
    {
        $files = glob($this->backupFolder() . date('Ymd-His', $id) . '-*.sql*') ?: [];
        $files = array_values(array_filter($files, function ($f) {
            return is_file($f) && preg_match('/^\d{8}-\d{6}-\d+\.sql(?:\.gz)?$/', basename($f));
        }));
        sort($files);
        return $files;
    }

    /** "553 B", "12.4 KB", "11.0 MB": the size a beginner can read. */
    private function sizeLabel($bytes)
    {
        $bytes = (float)$bytes;
        if ($bytes < 1024) {
            return (int)$bytes . ' B';
        }
        foreach (['KB', 'MB', 'GB'] as $i => $unit) {
            $value = $bytes / pow(1024, $i + 1);
            if ($value < 1024 || $unit === 'GB') {
                return number_format($value, 1) . ' ' . $unit;
            }
        }
        return (int)$bytes . ' B';
    }

    /** Table names sent by the list, kept only when they are real tables of this database. */
    private function realTables($ids)
    {
        $ids = is_array($ids) ? $ids : [$ids];
        $known = [];
        foreach (Db::query("SHOW TABLE STATUS") as $row) {
            $known[$row['Name']] = true;
        }
        $tables = [];
        foreach ($ids as $name) {
            if (is_string($name) && isset($known[$name])) {
                $tables[] = $name;
            }
        }
        return array_values(array_unique($tables));
    }

    public function index()
    {
        $download = input('get.download', '', 'trim');
        if ($download !== '') {
            if ($this->sendBackup($download) === false) {
                return $this->error(lang('admin/ux/tools/backup_not_found'));
            }
        }
        $group = input('group');
        if($group=='import'){
            // List backup files
            $path = trim( $GLOBALS['config']['db']['backup_path'], '/').DS;
            if (!is_dir($path)) {
                Dir::create($path);
            }
            $flag = \FilesystemIterator::KEY_AS_FILENAME;
            $glob = new \FilesystemIterator($path,  $flag);

            $list = [];
            foreach ($glob as $name => $file) {
                if(preg_match('/^\d{8,8}-\d{6,6}-\d+\.sql(?:\.gz)?$/', $name)){
                    $name = sscanf($name, '%4s%2s%2s-%2s%2s%2s-%d');
                    $date = "{$name[0]}-{$name[1]}-{$name[2]}";
                    $time = "{$name[3]}:{$name[4]}:{$name[5]}";
                    $part = $name[6];

                    $fileName = $file->getFilename();
                    if(isset($list["{$date} {$time}"])){
                        $info = $list["{$date} {$time}"];
                        $info['part'] = max($info['part'], $part);
                        $info['size'] = $info['size'] + $file->getSize();
                        $info['files'][] = $fileName;
                        sort($info['files']);
                    } else {
                        $info['part'] = $part;
                        $info['size'] = $file->getSize();
                        $info['files'] = [$fileName];
                    }

                    $extension        = strtoupper($file->getExtension());
                    $info['compress'] = ($extension === 'SQL') ? 'None' : $extension;
                    $info['time']     = strtotime("{$date} {$time}");
                    $info['size_label'] = $this->sizeLabel($info['size']);

                    $list["{$date} {$time}"] = $info;
                }
            }
        }
        else{
            $group='export';
            $list = Db::query("SHOW TABLE STATUS");
        }

        if ($group == 'import') {
            krsort($list);
        }
        $this->assign('list',$list);
        $this->assign('backup_folder', $this->backupFolderLabel());
        $this->assign('db_js', json_encode([
            'running' => lang('admin/ux/tools/backup_running'),
            'failed' => lang('admin/ux/kit/request_failed'),
            'saved_in' => lang('admin/ux/tools/backup_saved_in'),
            'download' => lang('admin/ux/tools/backup_download'),
            'download_url' => url('database/index'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
        $this->assign('title', $this->uxText('admin/ux/menu/backup', 'admin/database/title'));
        $this->assign('ax_head', [
            'title' => $this->uxText('admin/ux/menu/backup', 'admin/database/title'),
            'help' => $this->uxText('admin/ux/help/backup'),
        ]);
        return $this->fetch('admin@database/'.$group);
    }

    public function export($ids = '', $start = 0)
    {
        if ($this->request->isPost()) {
            if (!$this->writeOk()) {
                return $this->error(lang('admin/ux/system/token_expired'));
            }
            $tables = $this->realTables(input('post.ids/a', []));
            if (empty($tables)) {
                return $this->error(lang('admin/database/select_export_table'));
            }
            $start = 0;
            $have_admin = false;
            $admin_table='';
            foreach($tables as $k=>$v){
                if(strpos($v,'_admin')!==false){
                    $have_admin=true;
                    $admin_table = $v;
                    unset($tables[$k]);
                }
            }
            if($have_admin){
                $tables[] = $admin_table;
            }

            // Read backup configuration
            $config = array(
                'path'     => $GLOBALS['config']['db']['backup_path'] .DS,
                'part'     => $GLOBALS['config']['db']['part_size'] ,
                'compress' => $GLOBALS['config']['db']['compress'] ,
                'level'    => $GLOBALS['config']['db']['compress_level'] ,
            );

            // Check for an existing backup lock
            $lock = "{$config['path']}backup.lock";
            if(is_file($lock)){
                return $this->error(lang('admin/database/lock_check'));
            } else {
                if (!is_dir($config['path'])) {
                    Dir::create($config['path'], 0755, true);
                }
                // Create a lock file
                file_put_contents($lock, $this->request->time());
            }

            // Prepare the backup filename and initial part number
            $file = [
                'name' => date('Ymd-His', $this->request->time()),
                'part' => 1,
            ];

            // Create backup files
            $database = new dbOper($file, $config);
            if($database->create() !== false) {
                // Back up the specified table
                foreach ($tables as $table) {
                    $start = $database->backup($table, $start);
                    while (0 !== $start) {
                        if (false === $start) {
                            @unlink($lock);
                            return $this->error(lang('admin/database/backup_err'));
                        }
                        $start = $database->backup($table, $start[0]);
                    }
                }
                // Delete the lock file after the backup completes
                unlink($lock);
            }
            // Close the last part now, so the sizes below are the real file sizes (a .gz is written when it is closed).
            unset($database);
            clearstatcache();
            $files = [];
            foreach (glob($config['path'] . $file['name'] . '-*.sql*') ?: [] as $made) {
                $files[] = ['name' => basename($made), 'size' => filesize($made)];
            }
            return $this->success(lang('admin/database/backup_ok'), null, ['files' => $files, 'path' => $this->backupFolderLabel(), 'tables' => count($tables)]);
        }
        return $this->error(lang('admin/database/backup_err'));
    }

    /* *
 * 恢复数据库 [参考原作者 麦当苗儿 <zuojiazi@vip.qq.com>]
 * @param string|array $ids table name
 * @param integer $start number of starting lines
 * @author 橘子俊 <364666827@qq.com>
 * @return mixed */
    public function import($id = '')
    {
        // Hardening (security r3 P2-3): a restore overwrites tables, so it needs POST + the admin token
        // and the word RESTORE typed in the confirm on the page. A plain GET link does nothing.
        if (!$this->writeOk()) {
            return $this->error(lang('admin/ux/system/token_expired'));
        }
        if ($this->postText('confirm') !== 'RESTORE') {
            return $this->error(lang('admin/ux/tools/confirm_needed'));
        }
        $id = $this->backupId($this->postText('id'));
        if (!$id) {
            return $this->error(lang('admin/database/select_file'));
        }

        $list  = array();
        foreach ($this->backupFiles($id) as $name) {
            $basename = basename($name);
            $match    = sscanf($basename, '%4s%2s%2s-%2s%2s%2s-%d');
            $gz       = preg_match('/^\d{8,8}-\d{6,6}-\d+\.sql.gz$/', $basename);
            $list[$match[6]] = array($match[6], $name, $gz);
        }
        if (empty($list)) {
            return $this->error(lang('admin/ux/tools/backup_not_found'));
        }
        ksort($list);

        // Check that the backup part count matches the last part number
        $last = end($list);
        if(count($list) === $last[0]){
            // fp-port-c (FINISH-PORT-REVIEW-b P2-1): marked before the tables change too, so a restore cut in the middle (timeout,
            // closed browser) leaves the filters on the plain LIKE until the next full vocabulary build (the mark below follows)
            \app\common\util\FilterVocab::stale();
            foreach ($list as $item) {
                $config = [
                    'path'     => trim($GLOBALS['config']['db']['backup_path'], '/').DS,
                    'compress' => $item[2]
                ];
                $database = new dbOper($item, $config);
                $start = $database->import(0);
                // Continue importing until this backup part is complete
                while (0 !== $start) {
                    if (false === $start) {
                        return $this->error(lang('admin/database/import_err'));
                    }
                    $start = $database->import($start[0]);
                }
            }
            \app\common\util\FilterVocab::stale(); // ft-vocab: tables were replaced; filters use the plain LIKE until the next vocabulary build
            return $this->success(lang('admin/database/import_ok'));
        }
        return $this->error(lang('admin/database/file_damage'));
    }

    public function optimize($ids = '')
    {
        if (!$this->writeOk()) {
            return $this->error(lang('admin/ux/system/token_expired'));
        }
        $table = $this->realTables(input('post.ids/a', []));
        if (empty($table)) {
            return $this->error(lang('admin/database/select_optimize_table'));
        }

        $tables = implode('`,`', $table);
        $res = Db::query("OPTIMIZE TABLE `{$tables}`");
        if ($res) {
            return $this->success(lang('admin/database/optimize_ok'));
        }
        return $this->error(lang('admin/database/optimize_err'));
    }

    public function repair($ids = '')
    {
        if (!$this->writeOk()) {
            return $this->error(lang('admin/ux/system/token_expired'));
        }
        $table = $this->realTables(input('post.ids/a', []));
        if (empty($table)) {
            return $this->error(lang('admin/database/select_repair_table'));
        }

        $tables = implode('`,`', $table);
        $res = Db::query("REPAIR TABLE `{$tables}`");
        if ($res) {
            return $this->success(lang('admin/database/repair_ok'));
        }
        return $this->error(lang('admin/database/repair_err'));
    }

    public function del($id = '')
    {
        // Hardening (security r3 P2-3): POST + admin token; the page confirm names the backup (date, files, size).
        if (!$this->writeOk()) {
            return $this->error(lang('admin/ux/system/token_expired'));
        }
        $id = $this->backupId($this->postText('id'));
        if (!$id) {
            return $this->error(lang('admin/database/select_del_file'));
        }
        $files = $this->backupFiles($id);
        if (empty($files)) {
            return $this->error(lang('admin/ux/tools/backup_not_found'));
        }
        foreach ($files as $file) {
            @unlink($file);
        }
        if ($this->backupFiles($id)) {
            return $this->error(lang('del_err'));
        }
        return $this->success(lang('del_ok'));
    }

    /** Kind of a statement: read (SELECT / SHOW / DESCRIBE / DESC / EXPLAIN, one statement) or write. */
    private function sqlKind($sql)
    {
        $body = rtrim(trim($sql), "; \t\r\n");
        $first = strtolower((string)preg_replace('/^(?:\s|\/\*.*?\*\/|--[^\n]*\n|#[^\n]*\n)*([a-zA-Z]+).*$/s', '$1', $body));
        if (in_array($first, ['select', 'show', 'describe', 'desc', 'explain'], true) && strpos($body, ';') === false) {
            return 'read';
        }
        return 'write';
    }

    /** Database error text without file paths (F27). */
    private function sqlError(\Throwable $e)
    {
        $msg = (string)$e->getMessage();
        $msg = preg_replace('#[A-Za-z]:[\\\\/][^\s\'"]+|(?<![\w.])/(?:[\w.-]+/)+[\w.-]+#', '[path]', $msg);
        $msg = trim(preg_replace('/\s+/', ' ', $msg));
        return lang('admin/ux/tools/sql_error', [mb_substr($msg, 0, 400)]);
    }

    public function sql()
    {
        if($this->request->isPost()){
            $param=input('post.', [], null);
            $validate = \think\Loader::validate('Token');
            if(!$validate->check($param)){
                return $this->ajaxErrorWithFreshToken($validate->getError());
            }

            if (!isset($param['sql']) || !is_string($param['sql'])) {
                return $this->ajaxErrorWithFreshToken(lang('format_err'));
            }

            $sql = trim($param['sql']);
            if ($sql === '') {
                return $this->ajaxErrorWithFreshToken(lang('admin/ux/tools/sql_empty'));
            }
            // Block the file functions and CHAR calls (REVIEW-admin-security-r10, R10-1). The words are looked for
            // anywhere in the raw text, comments included: MySQL / MariaDB run the text inside /*! ... */ and end
            // # and -- comments at a new line, but a keyword cannot be split by a comment, so a plain word search is
            // complete. "infile" also covers LOAD DATA [LOCAL] INFILE and LOAD XML INFILE. The look-behind keeps
            // ordinary VARCHAR(255) definitions valid while catching /*!50000char*/(. The second CHAR test reads a
            // copy with the comments taken out (the text of /*! */ and /*M! */ kept, as the database runs it), so
            // char/*x*/(, char#x<new line>( and char-- x<new line>( are refused too.
            // Statements built at run time never show the blocked words in the posted text (REVIEW-final-polish
            // P2-1), so dynamic SQL is refused as well: EXECUTE IMMEDIATE and PREPARE (this also covers
            // sys.execute_prepared_stmt), and the sys schema, whose routines build and run their own statements
            // (sys.ps_trace_thread writes INTO OUTFILE a path it is given). A database user with SUPER can write
            // files outside secure_file_priv through server settings and plugins, so SET GLOBAL / @@global (for
            // example general_log_file, slow_query_log_file, server_audit_file_path) and INSTALL ... SONAME are
            // refused too. The page's own syntax check prepares the text in PHP, not from the posted words.
            $flat = (string)preg_replace(['/\/\*M?!\d*/i', '/\/\*.*?\*\//s', '/\*\//', '/#[^\r\n]*/', '/--(?:[^\S\r\n][^\r\n]*)?(?=[\r\n]|$)/'], ' ', $sql);
            if (preg_match('/outfile|dumpfile|load_file|infile|immediate|prepare|general_log|slow_query_log/i', $sql)
                || preg_match('/(?<![a-z0-9_$])(?:sys|global|soname)(?![a-z0-9_$])/i', $sql)
                || preg_match('/(?<![a-z_])char[\s\/*!#-]*\(/i', $sql)
                || preg_match('/(?<![a-z_])char\s*\(/i', $flat)) {
                return $this->ajaxErrorWithFreshToken(lang('format_err'));
            }
            $sql = str_replace('{pre}',config('database.prefix'),$sql);
            $kind = $this->sqlKind($sql);
            if ($kind === 'read') {
                // F26: read statements show their rows (first 100).
                $sql = rtrim($sql, "; \t\r\n");
                $isSelect = (bool)preg_match('/^\s*select\b/i', $sql);
                if ($isSelect && !preg_match('/\blimit\s+\d+(?:\s*,\s*\d+)?(?:\s+offset\s+\d+)?\s*(?:for\s+update|lock\s+in\s+share\s+mode)?\s*$/i', $sql)) {
                    $sql .= ' LIMIT 100';
                }
                try {
                    $rows = Db::query($sql);
                } catch (\Throwable $e) {
                    return $this->ajaxErrorWithFreshToken($this->sqlError($e));
                }
                $rows = is_array($rows) ? $rows : [];
                $columns = $rows ? array_keys((array)reset($rows)) : [];
                $out = [];
                foreach (array_slice($rows, 0, 100) as $row) {
                    $line = [];
                    foreach ($columns as $col) {
                        $value = isset($row[$col]) ? $row[$col] : null;
                        $line[] = $value === null ? null : mb_substr((string)$value, 0, 500);
                    }
                    $out[] = $line;
                }
                return $this->ajaxSuccessWithFreshToken(lang('run_ok'), [
                    'kind' => 'read',
                    'columns' => $columns,
                    'rows' => $out,
                    'count' => count($out),
                    'more' => count($rows) > 100 || (count($out) === 100 && $isSelect),
                ]);
            }
            // F27: a typo answers with the database error at once (PREPARE checks the syntax and runs nothing).
            try {
                Db::execute('PREPARE javcms_sql_check FROM ?', [$sql]);
                Db::execute('DEALLOCATE PREPARE javcms_sql_check');
            } catch (\Throwable $e) {
                // 1295 = statement type not supported by PREPARE: it is checked when it runs.
                if (strpos((string)$e->getMessage(), '1295') === false) {
                    return $this->ajaxErrorWithFreshToken($this->sqlError($e));
                }
            }
            // F28: a write runs only after the typed confirm on the page.
            if (empty($param['confirm']) || (string)$param['confirm'] !== '1') {
                return $this->ajaxErrorWithFreshToken(lang('admin/ux/tools/sql_write_confirm'), ['need_confirm' => 1]);
            }
            // fp-port-c (P2-1): marked before the write too (it may show hidden videos or change filter columns), so a request cut
            // right after the statement still leaves the filters on the plain LIKE until the next full build
            \app\common\util\FilterVocab::stale();
            try {
                $changed = Db::execute($sql);
            } catch (\Throwable $e) {
                return $this->ajaxErrorWithFreshToken($this->sqlError($e));
            }
            \app\common\util\FilterVocab::stale(); // ft-vocab: any write may change the filter columns
            return $this->ajaxSuccessWithFreshToken(str_replace('{n}', (string)(int)$changed, lang('admin/ux/tools/sql_rows_changed')), ['kind' => 'write', 'changed' => (int)$changed]);
        }
        $this->assign('sql_js', json_encode([
            'error_title' => lang('admin/ux/tools/sql_error_title'),
            'failed' => lang('admin/ux/kit/request_failed'),
            'rows_title' => lang('admin/ux/tools/sql_rows'),
            'first_rows' => lang('admin/ux/tools/sql_first_rows'),
            'no_rows' => lang('admin/ux/tools/sql_no_rows'),
            'empty' => lang('admin/ux/tools/sql_empty'),
            'write_title' => lang('admin/ux/tools/sql_write_title'),
            'write_confirm' => lang('admin/ux/tools/sql_write_confirm'),
            'run' => lang('admin/ux/tools/sql_run'),
            'backup_now' => lang('admin/ux/tools/sql_backup_first'),
            'backup_url' => url('database/index'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
        $this->assign('title', $this->uxText('admin/ux/menu/run_sql', 'admin/database/sql'));
        $this->assign('ax_head', [
            'title' => $this->uxText('admin/ux/menu/run_sql', 'admin/database/sql'),
            'help' => $this->uxText('admin/ux/help/run_sql'),
        ]);
        return $this->fetch('admin@database/sql');
    }

    public function columns()
    {
        $param = input();
        $table = $param['table'];
        if (!empty($table) && !$this->isValidTable($table)) {
            return $this->error('Table is invalid.');
        }
        if (!empty($table)) {
            $list = Db::query('SHOW COLUMNS FROM ' . $table);
            $this->success(lang('obtain_ok'),null, $list);
        }
        $this->error(lang('param_err'));
    }

    public function rep()
    {
        if($this->request->isPost()){
            $param = input('post.', [], null);
            $table = isset($param['table']) ? $param['table'] : '';
            $field = isset($param['field']) ? $param['field'] : '';
            $findstr = isset($param['findstr']) ? $param['findstr'] : '';
            $tostr = isset($param['tostr']) ? $param['tostr'] : '';
            $where = isset($param['where']) ? $param['where'] : '';

            $validate = \think\Loader::validate('Token');
            if(!$validate->check($param)){
                return $this->ajaxErrorWithFreshToken($validate->getError());
            }
            if (!is_string($table) || $table === '' || !$this->isValidTable($table)) {
                return $this->ajaxErrorWithFreshToken(lang('admin/database/select_table'));
            }
            // F29: "Replace with" may be empty (removes the text); "Find text" may not.
            if (!is_string($field) || $field === '' || !is_string($findstr) || $findstr === '' || !is_string($tostr)) {
                return $this->ajaxErrorWithFreshToken(lang('param_err'));
            }
            if (!$this->isValidField($table, $field)) {
                return $this->ajaxErrorWithFreshToken(lang('admin/database/select_col'));
            }
            $whereSql = $this->sanitizeRepWhereClause($where);
            if ($whereSql === false) {
                return $this->ajaxErrorWithFreshToken(lang('admin/ux/tools/rep_where_bad'));
            }
            $quote = chr(96);
            $quotedTable = $quote . str_replace($quote, $quote . $quote, $table) . $quote;
            $quotedField = $quote . str_replace($quote, $quote . $quote, $field) . $quote;
            // Exactly the rows whose text changes (binary compare: REPLACE() is case-sensitive).
            $changeSql = ' WHERE 1=1' . $whereSql . ' AND ' . $quotedField . ' IS NOT NULL AND BINARY REPLACE(' . $quotedField . ', ?, ?) <> BINARY ' . $quotedField;
            // Response helpers throw HttpResponseException: keep them outside the try blocks.
            $error = '';
            $count = 0;
            $changed = 0;
            try {
                $count = Db::query('SELECT COUNT(*) AS n FROM ' . $quotedTable . $changeSql, [$findstr, $tostr]);
                $count = (int)($count[0]['n'] ?? 0);
            } catch (\Throwable $e) {
                $error = $this->sqlError($e);
            }
            if ($error !== '') {
                return $this->ajaxErrorWithFreshToken($error);
            }
            if (!empty($param['preview'])) {
                return $this->ajaxSuccessWithFreshToken(str_replace('{n}', (string)$count, lang('admin/ux/tools/rep_count')), ['count' => $count]);
            }
            if (empty($param['confirm']) || (string)$param['confirm'] !== '1') {
                return $this->ajaxErrorWithFreshToken(lang('admin/ux/tools/confirm_needed'), ['need_confirm' => 1, 'count' => $count]);
            }
            // fp-port-c (FINISH-PORT-REVIEW-b P2-1): a filter column, or the status / recycle columns (videos shown or hidden), of
            // the video table: marked before and after the write, so a cut request still leaves the plain LIKE until a full build
            $ftCol = $table === config('database.prefix') . 'vod' && in_array($field, ['vod_tag', 'vod_actor', 'vod_class', 'vod_director', 'vod_status', 'vod_recycle_time'], true);
            if ($ftCol && $count > 0) {
                \app\common\util\FilterVocab::stale();
            }
            try {
                $changed = Db::execute('UPDATE ' . $quotedTable . ' SET ' . $quotedField . '=REPLACE(' . $quotedField . ', ?, ?)' . $changeSql, [$findstr, $tostr, $findstr, $tostr]);
            } catch (\Throwable $e) {
                $error = $this->sqlError($e);
            }
            if ($error !== '') {
                return $this->ajaxErrorWithFreshToken($error);
            }
            if ((int)$changed > 0 && $ftCol) {
                \app\common\util\FilterVocab::stale(); // ft-vocab: replaced text in a filter column (fp-port-c: or the status columns)
            }
            return $this->ajaxSuccessWithFreshToken(str_replace('{n}', (string)(int)$changed, lang('admin/ux/tools/rep_done')), ['changed' => (int)$changed]);
        }
        $list = Db::query("SHOW TABLE STATUS");
        $this->assign('list',$list);
        $this->assign('rep_js', json_encode([
            'columns_url' => url('database/columns'),
            'need_table' => lang('admin/database/select_table'),
            'need_field' => lang('admin/database/select_col'),
            'need_find' => lang('admin/database/findstr'),
            'failed' => lang('admin/ux/kit/request_failed'),
            'confirm_title' => lang('admin/ux/tools/rep_confirm_title'),
            'count' => lang('admin/ux/tools/rep_count'),
            'cannot_undo' => lang('admin/ux/kit/cannot_undo'),
            'replace' => lang('admin/ux/tools/rep_replace'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT));
        $this->assign('title', $this->uxText('admin/ux/menu/db_replace', 'admin/database/batch_replace'));
        $this->assign('ax_head', [
            'title' => $this->uxText('admin/ux/menu/db_replace', 'admin/database/batch_replace'),
            'help' => $this->uxText('admin/ux/help/db_replace'),
        ]);
        return $this->fetch('admin@database/rep');
    }

    private function isValidTable($table) {
        if (!is_string($table) || $table === '') {
            return false;
        }
        $list = Db::query("SHOW TABLE STATUS");
        foreach ($list as $table_raw) {
            if ($table_raw['Name'] == $table) {
                return true;
            }
        }
        return false;
    }

    /**
     * Confirm the replacement field is an actual identifier in the selected table.
     * Values remain bound parameters; only metadata-derived identifiers are quoted.
     */
    private function isValidField($table, $field)
    {
        if (!is_string($field) || !preg_match('/^[a-zA-Z0-9_]+$/', $field)) {
            return false;
        }
        $quote = chr(96);
        $quotedTable = $quote . str_replace($quote, $quote . $quote, $table) . $quote;
        $list = Db::query('SHOW COLUMNS FROM ' . $quotedTable);
        if (!is_array($list)) {
            return false;
        }
        foreach ($list as $row) {
            if (!empty($row['Field']) && $row['Field'] === $field) {
                return true;
            }
        }
        return false;
    }

    /**
     * Keep the legacy free-form WHERE field useful for simple AND clauses while
     * refusing statement breaks, comments, subqueries, and common SQLi tokens.
     */
    private function sanitizeRepWhereClause($where)
    {
        $where = trim((string)$where);
        if ($where === '') {
            return '';
        }
        if (strlen($where) > 500) {
            return false;
        }
        $norm = preg_replace('/\s+/', ' ', ' ' . strtolower($where));
        $norm = is_string($norm) ? ltrim($norm) : $norm;
        if (!is_string($norm)) {
            return false;
        }
        $blocked = [
            ';', '--', '/*', '*/', ' union ', ' select ', ' insert ', ' update ', ' delete ',
            ' drop ', ' create ', ' alter ', ' grant ', ' revoke ', ' exec ', ' execute ',
            'sleep(', 'benchmark(', 'load_file', 'outfile', 'dumpfile', ' information_schema',
            ' xor ', ' or 1', ' or true',
        ];
        foreach ($blocked as $token) {
            if (strpos(' ' . $norm, $token) !== false) {
                return false;
            }
        }
        if (strncmp($norm, 'and ', 4) !== 0) {
            $where = 'and ' . $where;
        }
        return ' ' . $where;
    }
}
