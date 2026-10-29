<?php

/**
 * 数据库结构升级器（幂等）。
 *
 * - 全新安装：schema.sql.php 已包含全部结构，这里只会把 schema_version 记为最新。
 * - 覆盖升级：上传新版文件后，管理员首次进入后台或计划任务运行时自动补齐新表/新字段。
 * 每一步都可以重复执行，不会破坏已有数据。
 */

use think\Db;

if (!defined('IDC_SCHEMA_VERSION')) {
    define('IDC_SCHEMA_VERSION', 4);
}

function idc_upgrade_has_column($table, $column)
{
    $x = Db::query('SELECT COUNT(*) n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', array($table, $column));

    return !empty($x[0]['n']);
}

function idc_upgrade_add_columns($table, array $columns)
{
    $p = (string)config('database.prefix');
    $full = $p . $table;
    foreach ($columns as $name => $definition) {
        if (!idc_upgrade_has_column($full, $name)) {
            Db::execute('ALTER TABLE `' . str_replace('`', '', $full) . '` ADD COLUMN `' . str_replace('`', '', $name) . '` ' . $definition);
        }
    }
}

/** 第 3 版：云主机产品的配置规格字段（前台商品页展示） */
function idc_upgrade_v3_product_specs()
{
    idc_upgrade_add_columns('product', array(
        'spec_cpu' => "varchar(64) NOT NULL DEFAULT ''",
        'spec_memory' => "varchar(64) NOT NULL DEFAULT ''",
        'spec_disk' => "varchar(64) NOT NULL DEFAULT ''",
        'spec_bandwidth' => "varchar(64) NOT NULL DEFAULT ''",
        'spec_traffic' => "varchar(64) NOT NULL DEFAULT ''",
        'spec_ip' => "varchar(64) NOT NULL DEFAULT ''",
        'spec_region' => "varchar(64) NOT NULL DEFAULT ''",
        'spec_line' => "varchar(64) NOT NULL DEFAULT ''",
        'spec_extra' => 'text',
    ));
}

/** 从 schema.sql.php 中取出某张表的建表语句（替换前缀与字符集） */
function idc_upgrade_create_table($table)
{
    $p = (string)config('database.prefix');
    $exists = Db::query('SELECT COUNT(*) n FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', array($p . $table));
    if (!empty($exists[0]['n'])) {
        return;
    }
    $sql = (string)file_get_contents(__DIR__ . '/schema.sql.php');
    if (!preg_match('/CREATE TABLE `\{p\}' . preg_quote($table, '/') . '` \(.*?\) ENGINE=InnoDB DEFAULT CHARSET=\{charset\};/s', $sql, $m)) {
        throw new \RuntimeException('schema 中缺少表 ' . $table);
    }
    $charset = (string)config('database.charset') ?: 'utf8mb4';
    Db::execute(str_replace(array('{p}', '{charset}'), array($p, $charset), rtrim($m[0], ';')));
}
/** 第 4 版：谷歌验证器两步验证、恢复码、失败锁定、找回密码链接、超级管理员标记 */
function idc_upgrade_v4_twofa()
{
    $totp = array(
        'totp_secret' => "varchar(255) NOT NULL DEFAULT ''",
        'totp_enabled' => 'tinyint NOT NULL DEFAULT 0',
        'totp_last_step' => 'bigint NOT NULL DEFAULT 0',
        'totp_bind_time' => 'int NOT NULL DEFAULT 0',
    );
    idc_upgrade_add_columns('admin', array_merge(array('is_super' => 'tinyint NOT NULL DEFAULT 0'), $totp));
    idc_upgrade_add_columns('user', $totp);
    foreach (array('twofa_recovery', 'auth_guard', 'password_reset') as $t) {
        idc_upgrade_create_table($t);
    }
    // 老版本没有角色：把最早创建的管理员（安装时创建的那个）设为超级管理员
    if (!Db::name('admin')->where('is_super', 1)->count()) {
        $first = Db::name('admin')->order('id asc')->find();
        if ($first) {
            Db::name('admin')->where('id', $first['id'])->update(array('is_super' => 1));
        }
    }
}
/**
 * 执行所有未完成的升级步骤。返回执行的步骤数；出错时记录日志并返回 -1（不影响页面）。
 */
function idc_schema_upgrade($force = false)
{
    $current = (int)setting('schema_version', '1');
    if (!$force && $current >= IDC_SCHEMA_VERSION) {
        return 0;
    }
    $steps = 0;
    try {
        if ($force || $current < 2) {
            require_once __DIR__ . '/upgrade_tickets.php';
            upgrade_ticket_rating_tables();
            $steps++;
        }
        if ($force || $current < 3) {
            idc_upgrade_v3_product_specs();
            $steps++;
        }
        if ($force || $current < 4) {
            idc_upgrade_v4_twofa();
            $steps++;
        }
        setting_save(array('schema_version' => (string)IDC_SCHEMA_VERSION));
    } catch (\Exception $e) {
        try {
            \think\facade\Log::record('schema upgrade failed: ' . $e->getMessage(), 'error');
        } catch (\Exception $ignore) {
        }

        return -1;
    }

    return $steps;
}
