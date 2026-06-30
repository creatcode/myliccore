<?php

namespace safeaccess;

use PhpZip\Exception\ZipException;
use PhpZip\ZipFile;

/**
 * 系统升级服务
 */
class UpgradeService
{
    protected $cloudService;

    public function __construct(CloudService $cloudService)
    {
        $this->cloudService = $cloudService;
    }

    /**
     * 初始化升级服务
     */
    public static function init()
    {
        return new self(CloudService::init());
    }

    /**
     * 检查并执行系统升级
     */
    public static function run()
    {
        $service = self::init();
        $updata = $service->check();

        return $service->upgrade($updata);
    }

    /**
     * 检查系统更新
     */
    public function check()
    {
        $type = defined('THINK_VERSION') ? 1 : 2;
        $response = $this->cloudService->sendRequest(
            '/api/index/site_update',
            [
                'type' => $type,
            ]
        );

        if (!$response || ($response['code'] ?? 0) == 0) {
            exception($response['msg'] ?? '云端服务异常');
        }

        return $response['data'] ?? [];
    }

    /**
     * 系统升级
     */
    public function upgrade(array $updata)
    {
        $version = trim($updata['version'] ?? '');
        $baseDir = RUNTIME_PATH . 'upgrade' . DS;
        $workDir = $baseDir . 'package' . DS;
        $versionDir = $workDir . $version;
        $zipFile = $baseDir . $version . '.zip';
        $backupDir = $baseDir . 'backup' . DS . $version . '_' . date('YmdHis') . DS;
        $fileBackupDir = $backupDir . 'files' . DS;
        $dbBackupFile = $backupDir . 'database.sql';
        $manifest = [
            'created_files' => [],
            'backup_files'  => [],
            'created_dirs'  => [],
        ];
        $hasDbBackup = false;

        try {
            if (empty($updata['upfile']) || $version === '') {
                exception('升级参数异常');
            }

            $this->mkdir($baseDir);
            $this->mkdir($workDir);
            $this->mkdir($backupDir);

            $zipdata = $this->cloudService->sendRequest($updata['upfile'], [], 'get');
            if ($zipdata === '' || $zipdata === false) {
                exception('下载升级包失败');
            }
            if (file_put_contents($zipFile, $zipdata) === false) {
                exception('保存升级包失败');
            }

            $zip = new ZipFile();
            try {
                $zip->openFile($zipFile)->extractTo($workDir);
            } catch (ZipException $e) {
                exception('解压升级包失败');
            } finally {
                $zip->close();
            }

            $versionDir = $this->getPackageDir($workDir, $version);
            $sqlFiles = glob($versionDir . DS . '*.sql') ?: [];

            // 先备份文件和数据库，后续任意步骤失败都可以回滚。
            $this->backupFiles($versionDir, $fileBackupDir, $manifest);
            $this->backupFile(APP_PATH . 'extra/cloud.php', $fileBackupDir, $manifest);
            $this->writeManifest($backupDir, $manifest);

            if (!empty($sqlFiles)) {
                $this->dumpDatabase($dbBackupFile);
                $hasDbBackup = true;
            }

            $this->copyUpgradeFiles($versionDir, ROOT_PATH, $manifest);
            $this->writeManifest($backupDir, $manifest);

            foreach ($sqlFiles as $sqlFile) {
                $this->importsql($sqlFile);
            }

            $this->updateVersion($version);
        } catch (\Throwable $th) {
            $this->rollback($fileBackupDir, $manifest, $hasDbBackup ? $dbBackupFile : null);
            exception('升级失败，已尝试回滚：' . $th->getMessage());
        } finally {
            is_file($zipFile) && @unlink($zipFile);
            is_dir($workDir) && @rmdirs($workDir);
        }

        try {
            $this->cloudService->sendRequest('/api/index/site_change', ['version' => $version]);
        } catch (\Throwable $th) {
            \think\Log::record('通知云端升级版本失败：' . $th->getMessage(), 'error');
        }

        return true;
    }

    /**
     * 获取升级包目录
     */
    protected function getPackageDir($workDir, $version)
    {
        $versionDir = $workDir . $version;
        if (is_dir($versionDir)) {
            return $versionDir;
        }

        $dirs = glob($workDir . '*', GLOB_ONLYDIR) ?: [];
        if (count($dirs) === 1) {
            return $dirs[0];
        }

        exception('升级包目录异常');
    }

    /**
     * 备份升级会覆盖的文件
     */
    protected function backupFiles($sourceDir, $backupDir, array &$manifest)
    {
        if (!is_dir($sourceDir)) {
            exception('升级包目录不存在');
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relative = $this->normalizeRelativePath($iterator->getSubPathName());
            $target = ROOT_PATH . str_replace('/', DS, $relative);

            if ($item->isDir()) {
                if (!is_dir($target)) {
                    $manifest['created_dirs'][] = $relative;
                }
                continue;
            }

            $this->backupTargetFile($target, $relative, $backupDir, $manifest);
        }
    }

    /**
     * 备份单个文件
     */
    protected function backupFile($file, $backupDir, array &$manifest)
    {
        $relative = $this->relativeRootPath($file);
        $this->backupTargetFile($file, $relative, $backupDir, $manifest);
    }

    /**
     * 备份目标文件或记录新增文件
     */
    protected function backupTargetFile($target, $relative, $backupDir, array &$manifest)
    {
        if (is_file($target)) {
            $backupFile = $backupDir . str_replace('/', DS, $relative);
            $this->mkdir(dirname($backupFile));
            if (!copy($target, $backupFile)) {
                exception('备份文件失败：' . $relative);
            }
            $manifest['backup_files'][$relative] = $relative;
        } else {
            $manifest['created_files'][] = $relative;
        }
    }

    /**
     * 复制升级文件
     */
    protected function copyUpgradeFiles($sourceDir, $destDir, array &$manifest)
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relative = $this->normalizeRelativePath($iterator->getSubPathName());
            $target = $destDir . str_replace('/', DS, $relative);

            if ($item->isDir()) {
                if (!is_dir($target)) {
                    $this->mkdir($target);
                    $manifest['created_dirs'][] = $relative;
                }
                continue;
            }

            $this->mkdir(dirname($target));
            if (!copy($item->getPathname(), $target)) {
                exception('复制升级文件失败：' . $relative);
            }
        }
    }

    /**
     * 更新本地版本号
     */
    protected function updateVersion($version)
    {
        $cloudFile = APP_PATH . 'extra/cloud.php';
        $cloudConfig = is_file($cloudFile) ? include $cloudFile : [];
        $cloudConfig = is_array($cloudConfig) ? $cloudConfig : [];
        $cloudConfig = array_merge($cloudConfig, ['version' => $version]);

        if (file_put_contents($cloudFile, '<?php' . "\n\nreturn " . var_export($cloudConfig, true) . ";\n") === false) {
            exception('更新本地版本号失败');
        }
    }

    /**
     * 回滚文件和数据库
     */
    protected function rollback($fileBackupDir, array $manifest, $dbBackupFile = null)
    {
        if ($dbBackupFile && is_file($dbBackupFile)) {
            try {
                $this->restoreDatabase($dbBackupFile);
            } catch (\Throwable $th) {
                \think\Log::record('数据库回滚失败：' . $th->getMessage(), 'error');
            }
        }

        foreach (array_unique($manifest['created_files']) as $relative) {
            $target = ROOT_PATH . str_replace('/', DS, $relative);
            is_file($target) && @unlink($target);
        }

        foreach ($manifest['backup_files'] as $relative) {
            $backupFile = $fileBackupDir . str_replace('/', DS, $relative);
            $target = ROOT_PATH . str_replace('/', DS, $relative);
            if (is_file($backupFile)) {
                $this->mkdir(dirname($target));
                @copy($backupFile, $target);
            }
        }

        $dirs = array_reverse(array_unique($manifest['created_dirs']));
        foreach ($dirs as $relative) {
            $target = ROOT_PATH . str_replace('/', DS, $relative);
            if (is_dir($target)) {
                @rmdir($target);
            }
        }
    }

    /**
     * 导出数据库备份
     */
    protected function dumpDatabase($backupFile)
    {
        $this->mkdir(dirname($backupFile));
        $pdo = \think\Db::getPdo();
        $tables = $pdo->query('SHOW FULL TABLES')->fetchAll(\PDO::FETCH_NUM);
        $handle = fopen($backupFile, 'wb');
        if (!$handle) {
            exception('创建数据库备份文件失败');
        }

        fwrite($handle, "-- 系统升级数据库备份\n");
        fwrite($handle, "-- 备份时间：" . date('Y-m-d H:i:s') . "\n\n");
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        foreach ($tables as $item) {
            $table = $item[0] ?? '';
            $type = strtoupper($item[1] ?? 'BASE TABLE');
            if ($table === '') {
                continue;
            }

            $tableName = $this->quoteIdentifier($table);
            $create = $pdo->query(($type === 'VIEW' ? 'SHOW CREATE VIEW ' : 'SHOW CREATE TABLE ') . $tableName)->fetch(\PDO::FETCH_ASSOC);
            $createSql = array_values($create)[1] ?? '';
            if ($createSql === '') {
                continue;
            }

            fwrite($handle, ($type === 'VIEW' ? 'DROP VIEW IF EXISTS ' : 'DROP TABLE IF EXISTS ') . $tableName . ";\n");
            fwrite($handle, str_replace(["\r", "\n"], ' ', $createSql) . ";\n");

            if ($type === 'VIEW') {
                fwrite($handle, "\n");
                continue;
            }

            $stmt = $pdo->query('SELECT * FROM ' . $tableName);
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $columns = array_map([$this, 'quoteIdentifier'], array_keys($row));
                $values = array_map([$this, 'quoteValue'], array_values($row));
                fwrite($handle, 'INSERT INTO ' . $tableName . ' (' . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ");\n");
            }

            fwrite($handle, "\n");
        }

        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);
    }

    /**
     * 恢复数据库备份
     */
    protected function restoreDatabase($backupFile)
    {
        $pdo = \think\Db::getPdo();
        $items = $pdo->query('SHOW FULL TABLES')->fetchAll(\PDO::FETCH_NUM);
        $views = [];
        $tables = [];

        foreach ($items as $item) {
            $name = $item[0] ?? '';
            $type = strtoupper($item[1] ?? 'BASE TABLE');
            if ($name === '') {
                continue;
            }
            if ($type === 'VIEW') {
                $views[] = $name;
            } else {
                $tables[] = $name;
            }
        }

        // 先清空当前库，避免升级失败后残留新增表。
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach ($views as $view) {
            $pdo->exec('DROP VIEW IF EXISTS ' . $this->quoteIdentifier($view));
        }
        foreach ($tables as $table) {
            $pdo->exec('DROP TABLE IF EXISTS ' . $this->quoteIdentifier($table));
        }

        $lines = file($backupFile, FILE_IGNORE_NEW_LINES);

        foreach ($lines as $line) {
            $sql = trim($line);
            if ($sql === '' || substr($sql, 0, 2) === '--') {
                continue;
            }
            $pdo->exec($sql);
        }
    }

    /**
     * 导入SQL文件
     */
    protected function importsql($sqlpath)
    {
        if (!is_file($sqlpath)) {
            return true;
        }

        $lines = file($sqlpath);
        $templine = '';

        foreach ($lines as $line) {
            if (substr($line, 0, 2) == '--' || trim($line) == '' || substr($line, 0, 2) == '/*') {
                continue;
            }

            $templine .= $line;
            if (substr(trim($line), -1) == ';') {
                $templine = str_ireplace('__PREFIX__', config('database.prefix'), $templine);
                $templine = str_ireplace('INSERT INTO ', 'INSERT IGNORE INTO ', $templine);
                \think\Db::getPdo()->exec($templine);
                $templine = '';
            }
        }

        return true;
    }

    /**
     * 写入备份清单
     */
    protected function writeManifest($backupDir, array $manifest)
    {
        file_put_contents($backupDir . 'manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * 创建目录
     */
    protected function mkdir($dir)
    {
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            exception('创建目录失败：' . $dir);
        }
    }

    /**
     * 规范升级包内相对路径，防止越权写入
     */
    protected function normalizeRelativePath($path)
    {
        $path = str_replace('\\', '/', trim($path, '/'));
        $parts = explode('/', $path);
        if ($path === '' || in_array('..', $parts, true)) {
            exception('升级包路径异常：' . $path);
        }

        return $path;
    }

    /**
     * 获取项目根目录相对路径
     */
    protected function relativeRootPath($path)
    {
        $root = str_replace('\\', '/', rtrim(ROOT_PATH, DS)) . '/';
        $path = str_replace('\\', '/', $path);
        if (strpos($path, $root) !== 0) {
            exception('备份文件路径异常：' . $path);
        }

        return $this->normalizeRelativePath(substr($path, strlen($root)));
    }

    /**
     * 包裹表名或字段名
     */
    protected function quoteIdentifier($name)
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /**
     * 转换SQL值
     */
    protected function quoteValue($value)
    {
        if ($value === null) {
            return 'NULL';
        }

        return "'" . strtr($value, [
            "\\"   => "\\\\",
            "'"    => "\\'",
            "\0"   => "\\0",
            "\n"   => "\\n",
            "\r"   => "\\r",
            "\x1a" => "\\Z",
        ]) . "'";
    }
}
