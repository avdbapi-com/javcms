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
 * Upgrade steps of THIS release, run by the one-click updater after the files were swapped (UPDATER decision U3: "the
 * NEW version's upgrade steps"). The updater starts the framework of the new tree and calls run() in slices of a few
 * seconds. It is the web-step form of tools/upgrade.php: the short, safe parts run here (tables, settings, the indexes
 * of a small catalogue, the filter vocabulary table, caches); the long builds (indexes of a big video table, the search
 * index backfill, counts, sitemap blocks, deep-page maps, the vocabulary build) are left to housekeeping, the
 * "Finish upgrade" button of Speed & protection and php tools/upgrade.php, exactly as tools/upgrade.php does with
 * --no-search-backfill --no-deep-maps --no-ft-vocab.
 *
 * This class is self-contained (it never uses another class of this folder): the engine that calls it is a frozen
 * copy of the previous release living in another namespace.
 *
 * A release adds its own one-off steps in VERSION_STEPS ("<version>" => [method names]): they run when the site comes
 * from a version below that key and goes to that key or above. Every step is idempotent (a second run changes nothing).
 * run() returns ['state' => 'more'|'done'|'error', 'cursor', 'step', 'log' => [lines], 'error', 'db_changed']; a line is
 * ['m' => English, 't' => [locale => text]] (see TEXTS) or, from an older release, a plain English string.
 */
final class UpgradeSteps
{
    /**
     * The oldest installed version these steps upgrade from in one update (the steps are cumulative: every one-off step
     * of VERSION_STEPS between the site's version and this release runs). The release procedure writes it into the
     * signed manifest as upgrade.min_from (release-tools/push_javcms_release.sh reads it from this file); raise it only
     * when a release drops the steps an older version needs.
     */
    const MIN_FROM = '1.2.0';
    /** Steps of every update, in order. */
    const STEPS = array('tables', 'settings', 'indexes', 'ft_vocab', 'version_steps', 'caches');
    /** One-off steps per version (none in 1.2.1). */
    const VERSION_STEPS = array();
    /** Above this many videos, missing list indexes are left to "Finish upgrade" / tools/upgrade.php (minutes-long ALTERs). */
    const INDEX_ROWS_WEB = 200000;
    /**
     * Report lines of the steps in the four admin languages (en-us, vi-vn, zh-cn, zh-tw; {name} = a value of the
     * report). The engine that runs this class is a frozen copy of the PREVIOUS release, whose language files do not
     * know this release's steps, so the texts travel with the steps: run() returns every line as
     * ['m' => English, 't' => [locale => text]] and the updater's pages show the admin's language (the log keeps English).
     */
    const TEXTS = array(
        'step_tables' => array('tables', 'bảng', '数据表', '資料表'),
        'step_settings' => array('settings', 'cài đặt', '设置', '設定'),
        'step_indexes' => array('indexes', 'chỉ mục', '索引', '索引'),
        'step_ft_vocab' => array('filter vocabulary', 'từ vựng bộ lọc', '筛选词汇', '篩選詞彙'),
        'step_version_steps' => array('one-off steps', 'bước riêng của phiên bản', '版本专属步骤', '版本專屬步驟'),
        'step_caches' => array('caches', 'bộ nhớ đệm', '缓存', '快取'),
        'tables_there' => array('scale tables already there', 'các bảng mở rộng đã có sẵn', '扩展数据表已存在', '擴充資料表已存在'),
        'tables_created' => array('created {list}', 'đã tạo {list}', '已创建 {list}', '已建立 {list}'),
        'ft_tables' => array('search index tables ensured', 'các bảng chỉ mục tìm kiếm đã sẵn sàng', '搜索索引表已就绪', '搜尋索引表已就緒'),
        'importer_changes' => array('{n} importer change(s)', '{n} thay đổi cho bộ nhập dữ liệu', '导入器变更 {n} 项', '匯入器變更 {n} 項'),
        'importer_there' => array('importer tables already there', 'các bảng của bộ nhập dữ liệu đã có sẵn', '导入器数据表已存在', '匯入器資料表已存在'),
        'theme_mode' => array('theme switcher display mode: {state}', 'chế độ hiển thị của bộ chuyển giao diện: {state}', '主题切换器显示模式：{state}',
            '佈景主題切換器顯示模式：{state}'),
        'not_in_build' => array('not part of this build', 'không có trong bản này', '本版本不包含此项', '本版本不包含此項'),
        'indexes_ok' => array('all indexes present', 'đã có đủ chỉ mục', '索引齐全', '索引齊全'),
        'indexes_left' => array('missing indexes left to "Finish upgrade" (Speed & protection) or php tools/upgrade.php: {n} videos',
            'các chỉ mục còn thiếu được để lại cho "Hoàn tất nâng cấp" (Tốc độ & bảo vệ) hoặc php tools/upgrade.php: {n} video',
            '缺少的索引留给“完成升级”（速度与防护）或 php tools/upgrade.php：{n} 个视频', '缺少的索引留給「完成升級」（速度與防護）或 php tools/upgrade.php：{n} 部影片'),
        'indexes_state' => array('{state}', '{state}', '{state}', '{state}'),
        'indexes_added' => array('{state}, added {list}', '{state}, đã thêm {list}', '{state}，已添加 {list}', '{state}，已新增 {list}'),
        'vocab_table' => array('vocabulary table {state}; the build is left to housekeeping', 'bảng từ vựng {state}; việc dựng bảng để lại cho tác vụ bảo trì',
            '词汇表 {state}；构建交给后台维护任务', '詞彙表 {state}；建置交給背景維護工作'),
        'version_step' => array('{v} {m}: {log}', '{v} {m}: {log}', '{v} {m}：{log}', '{v} {m}：{log}'),
        'none_for' => array('none for {from} -> {to}', 'không có bước nào cho {from} -> {to}', '{from} -> {to} 没有专属步骤', '{from} -> {to} 沒有專屬步驟'),
        'caches_data' => array('data caches cleared', 'đã xóa bộ nhớ đệm dữ liệu', '已清除数据缓存', '已清除資料快取'),
        'caches_page' => array('page cache: new generation', 'bộ nhớ đệm trang: đã làm mới', '页面缓存：已换新', '頁面快取：已換新'),
        'ad_build' => array('ad build refreshed', 'đã làm mới bản dựng quảng cáo', '广告构建已刷新', '廣告建置已重新整理'),
        'ad_build_error' => array('ad build: {why}', 'bản dựng quảng cáo: {why}', '广告构建：{why}', '廣告建置：{why}'),
    );
    /** Columns of TEXTS. */
    const TEXT_LOCALES = array('en-us', 'vi-vn', 'zh-cn', 'zh-tw');
    /**
     * The words other classes report as a {state} (ThemeSwitcher::upgradeFile, FilterVocab::ensureTable,
     * ScaleSchema::ensureIndexes), in the same four languages; a word that is not listed stays as it is.
     */
    const STATES = array(
        'already' => array('already set', 'đã có sẵn', '已设置', '已設定'),
        'added' => array('added', 'đã thêm', '已添加', '已新增'),
        'missing' => array('settings file missing', 'thiếu tệp cài đặt', '缺少设置文件', '缺少設定檔'),
        'unreadable' => array('settings file unreadable', 'không đọc được tệp cài đặt', '无法读取设置文件', '無法讀取設定檔'),
        'unwritable' => array('settings file not writable', 'không ghi được tệp cài đặt', '无法写入设置文件', '無法寫入設定檔'),
        'exists' => array('already there', 'đã có sẵn', '已存在', '已存在'),
        'created' => array('created', 'đã tạo', '已创建', '已建立'),
        'no_vod_table' => array('no video table', 'không có bảng video', '没有视频数据表', '沒有影片資料表'),
        'skipped' => array('skipped', 'bỏ qua', '已跳过', '已略過'),
        'ok' => array('ok', 'ổn', '正常', '正常'),
        'done' => array('done', 'xong', '完成', '完成'),
        'complete' => array('complete', 'đầy đủ', '完整', '完整'),
        'partial' => array('partly done', 'mới xong một phần', '部分完成', '部分完成'),
        'busy' => array('busy (another step is running)', 'đang bận (bước khác đang chạy)', '忙碌（另一步骤正在运行）', '忙碌（另一步驟正在執行）'),
        'error' => array('error', 'lỗi', '错误', '錯誤'),
        'no_database' => array('database not reachable', 'không kết nối được cơ sở dữ liệu', '无法连接数据库', '無法連線資料庫'),
        // ScaleSchema::ensureIndexes / ensureIndex (the indexes step): never shown as the internal word (ux-4 P3-8)
        'confirmed' => array('all present', 'đã có đủ', '全部就绪', '全部就緒'),
        'incomplete' => array('not complete yet: the rest is left to "Finish upgrade"', 'chưa đủ: phần còn lại để cho "Hoàn tất nâng cấp"',
            '尚未完整：其余部分留给“完成升级”', '尚未完整：其餘部分留給「完成升級」'),
        'present' => array('present', 'đã có', '已存在', '已存在'),
        'no_table' => array('no such table', 'không có bảng này', '没有该数据表', '沒有該資料表'),
        'too_big' => array('table too big for a web step: left to "Finish upgrade"', 'bảng quá lớn cho một bước trên web: để cho "Hoàn tất nâng cấp"',
            '数据表太大，不适合在网页步骤中处理：留给“完成升级”', '資料表太大，不適合在網頁步驟中處理：留給「完成升級」'),
        'no_space' => array('not enough disk space', 'không đủ dung lượng đĩa', '磁盘空间不足', '磁碟空間不足'),
    );

    public static function run($from, $to, array $cursor, $deadline, array $ctx = array())
    {
        $i = (int)($cursor['i'] ?? 0);
        $log = array();
        $dbChanged = false;
        while ($i < count(self::STEPS)) {
            $step = self::STEPS[$i];
            try {
                $r = call_user_func(array(__CLASS__, 'step' . ucfirst(str_replace('_', '', ucwords($step, '_')))), $from, $to, $cursor, $deadline, $ctx);
            } catch (\Throwable $e) {
                return array('state' => 'error', 'cursor' => $cursor, 'step' => $step, 'log' => $log, 'db_changed' => $dbChanged,
                    'error' => $step . ': ' . substr(preg_replace('/\s+/', ' ', $e->getMessage()), 0, 300));
            }
            foreach ((array)($r['log'] ?? array()) as $line) {
                $log[] = is_array($line) ? self::line($step, (string)($line['id'] ?? ''), (array)($line['v'] ?? array())) : $step . ': ' . $line;
            }
            if (!empty($r['db_changed'])) {
                $dbChanged = true;
            }
            if (($r['state'] ?? 'done') === 'error') {
                return array('state' => 'error', 'cursor' => $cursor, 'step' => $step, 'log' => $log, 'db_changed' => $dbChanged,
                    'error' => $step . ': ' . (string)($r['error'] ?? 'failed'));
            }
            if (($r['state'] ?? 'done') === 'more') {
                $cursor = (array)($r['cursor'] ?? $cursor);
                $cursor['i'] = $i;
                return array('state' => 'more', 'cursor' => $cursor, 'step' => $step, 'log' => $log, 'db_changed' => $dbChanged);
            }
            $i++;
            $cursor = array('i' => $i);
            if (microtime(true) >= $deadline && $i < count(self::STEPS)) {
                return array('state' => 'more', 'cursor' => $cursor, 'step' => self::STEPS[$i], 'log' => $log, 'db_changed' => $dbChanged);
            }
        }
        return array('state' => 'done', 'cursor' => array('i' => $i), 'step' => '', 'log' => $log, 'db_changed' => $dbChanged);
    }

    /**
     * One report line of step $step: ['m' => "<step>: <English text>", 't' => [locale => "<step>: <text>"]] from TEXTS
     * entry $id and the values $vars ({name} placeholders). An unknown id gives its id as the text.
     */
    public static function line($step, $id, array $vars = array())
    {
        // a {state} word of another class in the language of each column (STATES); "skipped:<collation>" keeps its detail
        $fill = function ($text, $i) use ($vars) {
            foreach ($vars as $k => $v) {
                $val = is_scalar($v) ? (string)$v : '';
                if ($k === 'state') {
                    $word = strpos($val, ':') !== false ? strstr($val, ':', true) : $val;
                    if (isset(self::STATES[$word])) {
                        $val = (string)(self::STATES[$word][$i] ?? self::STATES[$word][0]) . (strpos($val, ':') !== false ? strstr($val, ':') : '');
                    }
                }
                $text = str_replace('{' . $k . '}', $val, $text);
            }
            return $text;
        };
        $row = self::TEXTS[$id] ?? array($id, $id, $id, $id);
        $label = self::TEXTS['step_' . $step] ?? array($step, $step, $step, $step);
        $out = array('m' => '', 't' => array());
        foreach (self::TEXT_LOCALES as $i => $loc) {
            $text = $fill((string)($label[$i] ?? $label[0]), $i) . ($i >= 2 ? '：' : ': ') . $fill((string)($row[$i] ?? $row[0]), $i);
            if ($i === 0) {
                $out['m'] = $text;
            } else {
                $out['t'][$loc] = $text;
            }
        }
        return $out;
    }

    /** A report line of a step method: the TEXTS id and its values (run() turns it into line()). */
    private static function say($id, array $vars = array())
    {
        return array('id' => $id, 'v' => $vars);
    }

    private static function pdo()
    {
        if (!class_exists('\app\common\util\ScaleSchema') || !method_exists('\app\common\util\ScaleSchema', 'connect')) {
            return array(null, '');
        }
        $pdo = \app\common\util\ScaleSchema::connect(true);
        return array($pdo, \app\common\util\ScaleSchema::configPrefix());
    }

    /** Tables and columns of this release (the same calls as tools/upgrade.php step 1 and the first admin visit). */
    private static function stepTables($from, $to, array $cursor, $deadline, array $ctx)
    {
        list($pdo, $pre) = self::pdo();
        if ($pdo === null) {
            return array('state' => 'error', 'error' => 'the database is not reachable');
        }
        $log = array();
        $changed = false;
        $t = \app\common\util\ScaleSchema::ensureTables($pdo, $pre);
        if (empty($t['ok'])) {
            return array('state' => 'error', 'error' => 'scale tables: ' . (string)($t['error'] ?? 'failed'));
        }
        $log[] = empty($t['created']) ? self::say('tables_there') : self::say('tables_created', array('list' => implode(', ', (array)$t['created'])));
        $changed = $changed || !empty($t['created']);
        if (class_exists('\app\common\util\FtIndex') && method_exists('\app\common\util\FtIndex', 'ensureSchema')) {
            if (method_exists('\app\common\util\FtIndex', 'useConnection')) {
                \app\common\util\FtIndex::useConnection($pdo, $pre, (string)$pdo->query('SELECT DATABASE()')->fetchColumn());
                if (method_exists('\app\common\util\FtIndex', 'useRuntime')) {
                    \app\common\util\FtIndex::useRuntime((string)$ctx['root'] . 'runtime');
                }
            }
            \app\common\util\FtIndex::ensureSchema();
            $log[] = self::say('ft_tables');
        }
        if (class_exists('\app\common\model\AvdbImport')) {
            $l = method_exists('\app\common\model\AvdbImport', 'upgradeSchema') ? (array)\app\common\model\AvdbImport::upgradeSchema() : array();
            if (method_exists('\app\common\model\AvdbImport', 'ensureSchema')) {
                (new \app\common\model\AvdbImport())->ensureSchema();
            }
            $log[] = $l ? self::say('importer_changes', array('n' => count($l))) : self::say('importer_there');
            $changed = $changed || (bool)$l;
        }
        return array('state' => 'done', 'log' => $log, 'db_changed' => $changed);
    }

    /** Settings keys this release adds when missing (a stored value is never changed). */
    private static function stepSettings($from, $to, array $cursor, $deadline, array $ctx)
    {
        $log = array();
        if (class_exists('\app\common\util\ThemeSwitcher') && method_exists('\app\common\util\ThemeSwitcher', 'upgradeFile')) {
            $state = \app\common\util\ThemeSwitcher::upgradeFile(APP_PATH . 'extra/maccms.php');
            $log[] = self::say('theme_mode', array('state' => (string)$state));
        }
        return array('state' => 'done', 'log' => $log);
    }

    /** List / sort / FULLTEXT indexes: added here for a small catalogue; a big one gets them from "Finish upgrade". */
    private static function stepIndexes($from, $to, array $cursor, $deadline, array $ctx)
    {
        $cls = '\app\common\util\ScaleSchema';
        if (!class_exists($cls) || !method_exists($cls, 'ensureIndexes')) {
            return array('state' => 'done', 'log' => array(self::say('not_in_build')));
        }
        list($pdo, $pre) = self::pdo();
        $status = method_exists($cls, 'status') ? (array)$cls::status() : array();
        if (!empty($status['indexes_ok'])) {
            return array('state' => 'done', 'log' => array(self::say('indexes_ok')));
        }
        $rows = 0;
        try {
            $rows = (int)$pdo->query('SELECT COUNT(*) FROM `' . $pre . 'vod`')->fetchColumn();
        } catch (\Throwable $e) {
        }
        if ($rows > self::INDEX_ROWS_WEB) {
            return array('state' => 'done', 'log' => array(self::say('indexes_left', array('n' => number_format($rows)))));
        }
        $ix = $cls::ensureIndexes($pdo, $pre);
        $ixState = (string)($ix['state'] ?? '?');
        $line = !empty($ix['added']) ? self::say('indexes_added', array('state' => $ixState, 'list' => implode(', ', (array)$ix['added'])))
            : self::say('indexes_state', array('state' => $ixState));
        return array('state' => 'done', 'log' => array($line), 'db_changed' => !empty($ix['added']));
    }

    /** The filter vocabulary table (FilterMatch v2); its build is housekeeping's job. */
    private static function stepFtVocab($from, $to, array $cursor, $deadline, array $ctx)
    {
        $file = APP_PATH . 'common/util/FilterVocab.php';
        if (!is_file($file)) {
            return array('state' => 'done', 'log' => array(self::say('not_in_build')));
        }
        require_once $file;
        list($pdo, $pre) = self::pdo();
        $dbCfg = include APP_PATH . 'database.php';
        $fv = \app\common\util\FilterVocab::pdo($pdo, $pre, false, \app\common\util\FilterVocab::dbKey(is_array($dbCfg) ? $dbCfg : array()));
        $table = $fv->ensureTable();
        return array('state' => 'done', 'log' => array(self::say('vocab_table', array('state' => (string)$table))), 'db_changed' => $table === 'created');
    }

    /** One-off steps of the versions between $from and $to. */
    private static function stepVersionSteps($from, $to, array $cursor, $deadline, array $ctx)
    {
        $log = array();
        $changed = false;
        foreach (self::VERSION_STEPS as $ver => $methods) {
            if (self::cmp($from, (string)$ver) >= 0 || self::cmp($to, (string)$ver) < 0) {
                continue;
            }
            foreach ((array)$methods as $m) {
                $r = call_user_func(array(__CLASS__, 'v' . $m), $ctx);
                $log[] = self::say('version_step', array('v' => (string)$ver, 'm' => (string)$m, 'log' => (string)($r['log'] ?? 'done')));
                if (!empty($r['db_changed'])) {
                    $changed = true;
                }
                if (($r['state'] ?? 'done') === 'error') {
                    return array('state' => 'error', 'error' => $ver . ' ' . $m . ': ' . (string)($r['error'] ?? 'failed'), 'log' => $log, 'db_changed' => $changed);
                }
            }
        }
        if (!$log) {
            $log[] = self::say('none_for', array('from' => (string)$from, 'to' => (string)$to));
        }
        return array('state' => 'done', 'log' => $log, 'db_changed' => $changed);
    }

    /** Caches the new code reads (the parts of the admin "Clear cache" that matter after an update). */
    private static function stepCaches($from, $to, array $cursor, $deadline, array $ctx)
    {
        $log = array();
        $prefix = (string)($GLOBALS['config']['app']['cache_flag'] ?? '');
        if ($prefix !== '' && class_exists('\think\Cache')) {
            foreach (array('type_list', 'type_tree', 'group_list', 'data_count', 'data_count_recycle_v1', 'interface_type', 'vod_catalog_revision') as $key) {
                \think\Cache::rm($prefix . '_' . $key);
            }
            \think\Cache::rm('cache_data');
            \think\Cache::rm('ux_overview_stats');
            $log[] = self::say('caches_data');
        }
        if (class_exists('\app\common\model\Type') && method_exists('\app\common\model\Type', 'bumpListRevision')) {
            \app\common\model\Type::bumpListRevision();
        }
        if (class_exists('\app\common\util\EdgeCache') && method_exists('\app\common\util\EdgeCache', 'bumpGeneration')) {
            \app\common\util\EdgeCache::bumpGeneration(true);
            $log[] = self::say('caches_page');
        }
        if (class_exists('\app\common\util\JcxBuild') && method_exists('\app\common\util\JcxBuild', 'ready') && \app\common\util\JcxBuild::ready()) {
            try {
                \app\common\util\JcxBuild::refreshAll(false);
                $log[] = self::say('ad_build');
            } catch (\Throwable $e) {
                $log[] = self::say('ad_build_error', array('why' => substr($e->getMessage(), 0, 120)));
            }
        }
        return array('state' => 'done', 'log' => $log);
    }

    /** SemVer comparison (self-contained copy). */
    private static function cmp($a, $b)
    {
        $pa = self::parts($a);
        $pb = self::parts($b);
        for ($i = 0; $i < 3; $i++) {
            if ($pa[$i] !== $pb[$i]) {
                return $pa[$i] < $pb[$i] ? -1 : 1;
            }
        }
        if ($pa[3] === $pb[3]) {
            return 0;
        }
        if ($pa[3] === '') {
            return 1;
        }
        if ($pb[3] === '') {
            return -1;
        }
        return strcmp($pa[3], $pb[3]) < 0 ? -1 : 1;
    }

    private static function parts($v)
    {
        if (!preg_match('/^(\d+)\.(\d+)\.(\d+)(?:-([0-9A-Za-z.-]+))?$/', (string)$v, $m)) {
            return array(0, 0, 0, 'invalid');
        }
        return array((int)$m[1], (int)$m[2], (int)$m[3], $m[4] ?? '');
    }
}
