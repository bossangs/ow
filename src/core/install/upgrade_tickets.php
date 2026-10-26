<?php

function upgrade_ticket_rating_tables()
{
    $p = (string)config('database.prefix');
    $q = function ($sql) {
        \think\Db::execute($sql);
    };
    $q("CREATE TABLE IF NOT EXISTS `{$p}ticket_rating` (`id` int unsigned NOT NULL AUTO_INCREMENT,`ticket_id` int NOT NULL,`user_id` int NOT NULL DEFAULT 0,`score` tinyint NOT NULL DEFAULT 0,`comment` varchar(1000) NOT NULL DEFAULT '',`party` varchar(16) NOT NULL DEFAULT 'user',`create_time` int NOT NULL DEFAULT 0,`update_time` int NOT NULL DEFAULT 0,PRIMARY KEY(`id`),UNIQUE KEY ticket_id(ticket_id),KEY score(score),KEY create_time(create_time)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS `{$p}ticket_attachment` (`id` int unsigned NOT NULL AUTO_INCREMENT,`ticket_id` int NOT NULL,`reply_id` int NOT NULL DEFAULT 0,`original_name` varchar(191) NOT NULL DEFAULT '',`stored_name` varchar(80) NOT NULL DEFAULT '',`path` varchar(20) NOT NULL DEFAULT '',`mime` varchar(100) NOT NULL DEFAULT 'application/octet-stream',`size` int NOT NULL DEFAULT 0,`create_time` int NOT NULL DEFAULT 0,PRIMARY KEY(id),KEY ticket_reply(ticket_id,reply_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS `{$p}ticket_department` (`id` int unsigned NOT NULL AUTO_INCREMENT,`name` varchar(64) NOT NULL,`sort` int NOT NULL DEFAULT 0,`status` tinyint NOT NULL DEFAULT 1,PRIMARY KEY(id),UNIQUE KEY name(name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS `{$p}ticket_canned` (`id` int unsigned NOT NULL AUTO_INCREMENT,`title` varchar(191) NOT NULL DEFAULT '',`content` text,`status` tinyint NOT NULL DEFAULT 1,`create_time` int NOT NULL DEFAULT 0,`update_time` int NOT NULL DEFAULT 0,PRIMARY KEY(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS `{$p}ticket_read` (`ticket_id` int NOT NULL,`party` varchar(16) NOT NULL,`user_id` int NOT NULL DEFAULT 0,`last_reply_id` int NOT NULL DEFAULT 0,`update_time` int NOT NULL DEFAULT 0,PRIMARY KEY(ticket_id,party,user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $t = $p . 'ticket';
    $r = $p . 'ticket_reply';
    $fields = array('priority' => "varchar(16) NOT NULL DEFAULT '中'", 'seller_user_id' => 'int NOT NULL DEFAULT 0', 'waiting_since' => 'int NOT NULL DEFAULT 0', 'reminded_at' => 'int NOT NULL DEFAULT 0', 'remind_count' => 'int NOT NULL DEFAULT 0', 'last_admin_id' => 'int NOT NULL DEFAULT 0');
    foreach ($fields as $f => $d) {
        $x = \think\Db::query('SELECT COUNT(*) n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', array($t, $f));
        if (empty($x[0]['n'])) {
            $q("ALTER TABLE `" . str_replace('`', '', $t) . "` ADD COLUMN `{$f}` {$d}");
        }
    }
    foreach (array('party' => "varchar(16) NOT NULL DEFAULT 'user'", 'is_internal' => 'tinyint NOT NULL DEFAULT 0', 'is_system' => 'tinyint NOT NULL DEFAULT 0') as $f => $d) {
        $x = \think\Db::query('SELECT COUNT(*) n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?', array($r, $f));
        if (empty($x[0]['n'])) {
            $q("ALTER TABLE `" . str_replace('`', '', $r) . "` ADD COLUMN `{$f}` {$d}");
        }
    }
    foreach (array('技术支持', '财务', '售前咨询', '域名市场', '投诉举报') as $name) {
        $q("INSERT IGNORE INTO `{$p}ticket_department` (`name`,`sort`,`status`) VALUES ('" . addslashes($name) . "',0,1)");
    }

    return true;
}
