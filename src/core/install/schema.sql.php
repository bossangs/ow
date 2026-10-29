<?php exit; ?>
-- IDC 云主机销售 + DNS 域名分发系统 数据库结构
-- {p} 将被替换为表前缀；索引字段长度控制在 191 以内兼容 MySQL 5.5/5.6 utf8mb4

CREATE TABLE `{p}admin` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `nickname` varchar(50) NOT NULL DEFAULT '',
  `email` varchar(120) NOT NULL DEFAULT '',
  `status` tinyint NOT NULL DEFAULT 1,
  `login_fail` int NOT NULL DEFAULT 0,
  `lock_until` int NOT NULL DEFAULT 0,
  `last_login_time` int NOT NULL DEFAULT 0,
  `last_login_ip` varchar(64) NOT NULL DEFAULT '',
  `is_super` tinyint NOT NULL DEFAULT 0,
  `totp_secret` varchar(255) NOT NULL DEFAULT '',
  `totp_enabled` tinyint NOT NULL DEFAULT 0,
  `totp_last_step` bigint NOT NULL DEFAULT 0,
  `totp_bind_time` int NOT NULL DEFAULT 0,
  `create_time` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};

CREATE TABLE `{p}setting` (
  `name` varchar(64) NOT NULL,
  `value` mediumtext,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};

CREATE TABLE `{p}user` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(120) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `tier_id` int NOT NULL DEFAULT 0,
  `tier_expire` int NOT NULL DEFAULT 0,
  `balance` decimal(12,2) NOT NULL DEFAULT '0.00',
  `status` tinyint NOT NULL DEFAULT 1,
  `email_verified` tinyint NOT NULL DEFAULT 0,
  `telegram_chat_id` varchar(64) NOT NULL DEFAULT '',
  `login_fail` int NOT NULL DEFAULT 0,
  `lock_until` int NOT NULL DEFAULT 0,
  `reg_ip` varchar(64) NOT NULL DEFAULT '',
  `last_login_ip` varchar(64) NOT NULL DEFAULT '',
  `last_login_time` int NOT NULL DEFAULT 0,
  `remark` varchar(255) NOT NULL DEFAULT '',
  `create_time` int NOT NULL DEFAULT 0,
  `update_time` int NOT NULL DEFAULT 0,
  `telegram_verified` tinyint NOT NULL DEFAULT 0,
  `totp_secret` varchar(255) NOT NULL DEFAULT '',
  `totp_enabled` tinyint NOT NULL DEFAULT 0,
  `totp_last_step` bigint NOT NULL DEFAULT 0,
  `totp_bind_time` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `username` (`username`),
  KEY `reg_ip` (`reg_ip`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};

CREATE TABLE `{p}twofa_recovery` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `owner_type` varchar(10) NOT NULL,
  `owner_id` int unsigned NOT NULL,
  `code_hash` char(64) NOT NULL,
  `used_time` int NOT NULL DEFAULT 0,
  `create_time` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `owner` (`owner_type`,`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};

CREATE TABLE `{p}auth_guard` (
  `k` varchar(150) NOT NULL,
  `fails` int NOT NULL DEFAULT 0,
  `lock_until` int NOT NULL DEFAULT 0,
  `update_time` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};

CREATE TABLE `{p}password_reset` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `owner_type` varchar(10) NOT NULL,
  `owner_id` int unsigned NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expire_time` int NOT NULL DEFAULT 0,
  `used_time` int NOT NULL DEFAULT 0,
  `ip` varchar(64) NOT NULL DEFAULT '',
  `create_time` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `owner` (`owner_type`,`owner_id`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};

CREATE TABLE `{p}verify_code` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `target` varchar(120) NOT NULL,
  `scene` varchar(20) NOT NULL,
  `code` varchar(64) NOT NULL,
  `expire_time` int NOT NULL DEFAULT 0,
  `used` tinyint NOT NULL DEFAULT 0,
  `ip` varchar(64) NOT NULL DEFAULT '',
  `create_time` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `target` (`target`,`scene`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};

CREATE TABLE `{p}user_tier` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `level` int NOT NULL DEFAULT 0,
  `price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `period_days` int NOT NULL DEFAULT 30,
  `discount` int NOT NULL DEFAULT 100,
  `dns_max_subdomains` int NOT NULL DEFAULT 1,
  `dns_max_records` int NOT NULL DEFAULT 5,
  `dns_types` varchar(255) NOT NULL DEFAULT 'A,AAAA,CNAME,TXT',
  `dns_smart_line` tinyint NOT NULL DEFAULT 0,
  `dns_proxy` tinyint NOT NULL DEFAULT 0 COMMENT '可开启 Cloudflare 代理(橙云)',
  `commission_rate` decimal(5,2) NOT NULL DEFAULT -1 COMMENT '发布域名销售的平台佣金% -1=全局',
  `dns_review_exempt` tinyint NOT NULL DEFAULT 0,
  `is_default` tinyint NOT NULL DEFAULT 0,
  `purchasable` tinyint NOT NULL DEFAULT 1,
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `description` text,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};

CREATE TABLE `{p}balance_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `balance` decimal(12,2) NOT NULL,
  `type` varchar(20) NOT NULL,
  `remark` varchar(255) NOT NULL DEFAULT '',
  `balance_before` decimal(12,2) NOT NULL DEFAULT 0,
  `operator` varchar(64) NOT NULL DEFAULT '' COMMENT 'system/user/admin:用户名',
  `ip` varchar(64) NOT NULL DEFAULT '',
  `order_id` int NOT NULL DEFAULT 0,
  `create_time` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};

CREATE TABLE `{p}gateway` (
  `code` varchar(32) NOT NULL,
  `name` varchar(50) NOT NULL,
  `enabled` tinyint NOT NULL DEFAULT 0,
  `config` text,
  `sort` int NOT NULL DEFAULT 0,
  `fee_rate` decimal(6,2) NOT NULL DEFAULT '0.00',
  `min_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `max_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`code`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};

CREATE TABLE `{p}payment` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `trade_no` varchar(40) NOT NULL,
  `user_id` int NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `fee` decimal(12,2) NOT NULL DEFAULT '0.00',
  `gateway` varchar(32) NOT NULL,
  `channel` varchar(32) NOT NULL DEFAULT '',
  `purpose` varchar(20) NOT NULL,
  `order_id` int NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 0,
  `out_trade_no` varchar(100) NOT NULL DEFAULT '',
  `paid_time` int NOT NULL DEFAULT 0,
  `notify_raw` text,
  `remark` varchar(255) NOT NULL DEFAULT '',
  `create_time` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `trade_no` (`trade_no`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};

CREATE TABLE `{p}upstream` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `driver` varchar(32) NOT NULL,
  `config` text,
  `status` tinyint NOT NULL DEFAULT 1,
  `last_test_time` int NOT NULL DEFAULT 0,
  `last_test_msg` varchar(255) NOT NULL DEFAULT '',
  `remark` varchar(255) NOT NULL DEFAULT '',
  `create_time` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};

CREATE TABLE `{p}product_group` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text,
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};

CREATE TABLE `{p}product` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `group_id` int NOT NULL DEFAULT 0,
  `name` varchar(100) NOT NULL,
  `description` text,
  `upstream_id` int NOT NULL DEFAULT 0,
  `upstream_pid` varchar(64) NOT NULL DEFAULT '',
  `upstream_options` text,
  `pricing` text,
  `price_mode` varchar(10) NOT NULL DEFAULT 'fixed',
  `markup` decimal(8,2) NOT NULL DEFAULT '0.00',
  `cost` text,
  `stock` int NOT NULL DEFAULT -1,
  `sold` int NOT NULL DEFAULT 0,
  `os_list` text,
  `allow_hostname` tinyint NOT NULL DEFAULT 1,
  `auto_setup` tinyint NOT NULL DEFAULT 1,
  `tier_ids` varchar(255) NOT NULL DEFAULT '',
  `status` tinyint NOT NULL DEFAULT 1,
  `sort` int NOT NULL DEFAULT 0,
  `create_time` int NOT NULL DEFAULT 0,
  `update_time` int NOT NULL DEFAULT 0,
  `lifecycle` text COMMENT '到期策略覆盖 JSON，空=继承全局',
  `reserved` int NOT NULL DEFAULT 0 COMMENT '已预留(待支付)',
  `per_user_limit` int NOT NULL DEFAULT 0 COMMENT '每用户限购 0=不限',
  `stock_alert` int NOT NULL DEFAULT -1 COMMENT '低库存阈值 -1=用全局',
  `stock_state` varchar(10) NOT NULL DEFAULT '' COMMENT '提醒状态 ok/low/out',
  `nat_stock_cap` int NOT NULL DEFAULT -1 COMMENT 'NAT 手动上限 -1=按节点容量',
  `spec_cpu` varchar(64) NOT NULL DEFAULT '',
  `spec_memory` varchar(64) NOT NULL DEFAULT '',
  `spec_disk` varchar(64) NOT NULL DEFAULT '',
  `spec_bandwidth` varchar(64) NOT NULL DEFAULT '',
  `spec_traffic` varchar(64) NOT NULL DEFAULT '',
  `spec_ip` varchar(64) NOT NULL DEFAULT '',
  `spec_region` varchar(64) NOT NULL DEFAULT '',
  `spec_line` varchar(64) NOT NULL DEFAULT '',
  `spec_extra` text,
  PRIMARY KEY (`id`),
  KEY `group_id` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};

CREATE TABLE `{p}order` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_no` varchar(32) NOT NULL,
  `user_id` int NOT NULL,
  `type` varchar(20) NOT NULL,
  `title` varchar(255) NOT NULL DEFAULT '',
  `data` text,
  `amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `discount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `pay_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `status` varchar(16) NOT NULL DEFAULT 'unpaid',
  `gateway` varchar(32) NOT NULL DEFAULT '',
  `paid_time` int NOT NULL DEFAULT 0,
  `deliver_status` varchar(16) NOT NULL DEFAULT '',
  `deliver_msg` varchar(500) NOT NULL DEFAULT '',
  `admin_remark` varchar(500) NOT NULL DEFAULT '',
  `coupon_id` int NOT NULL DEFAULT 0,
  `coupon_code` varchar(40) NOT NULL DEFAULT '',
  `coupon_discount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `update_time` int NOT NULL DEFAULT 0,
  `create_time` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_no` (`order_no`),
  KEY `user_status` (`user_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};

CREATE TABLE `{p}service` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `product_id` int NOT NULL,
  `order_id` int NOT NULL DEFAULT 0,
  `upstream_id` int NOT NULL DEFAULT 0,
  `remote_id` varchar(64) NOT NULL DEFAULT '',
  `remote_order_id` varchar(64) NOT NULL DEFAULT '',
  `hostname` varchar(100) NOT NULL DEFAULT '',
  `billing_cycle` varchar(20) NOT NULL DEFAULT 'monthly',
  `price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `status` varchar(16) NOT NULL DEFAULT 'pending',
  `ip` varchar(255) NOT NULL DEFAULT '',
  `username` varchar(64) NOT NULL DEFAULT '',
  `password` text,
  `os` varchar(100) NOT NULL DEFAULT '',
  `options` text,
  `info` text,
  `reg_time` int NOT NULL DEFAULT 0,
  `due_time` int NOT NULL DEFAULT 0,
  `remind_time` int NOT NULL DEFAULT 0,
  `suspend_reason` varchar(255) NOT NULL DEFAULT '',
  `last_error` varchar(500) NOT NULL DEFAULT '',
  `last_sync_time` int NOT NULL DEFAULT 0,
  `create_time` int NOT NULL DEFAULT 0,
  `update_time` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `status_due` (`status`,`due_time`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};

CREATE TABLE `{p}dns_provider` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `user_id` int NOT NULL DEFAULT 0 COMMENT '0=平台 >0=用户自有凭据(仅用于其域名)',
  `driver` varchar(32) NOT NULL,
  `config` text,
  `status` tinyint NOT NULL DEFAULT 1,
  `remark` varchar(255) NOT NULL DEFAULT '',
  `last_test_time` int NOT NULL DEFAULT 0,
  `last_test_msg` varchar(255) NOT NULL DEFAULT '',
  `create_time` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};

CREATE TABLE `{p}dns_domain` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `provider_id` int NOT NULL,
  `user_id` int NOT NULL DEFAULT 0 COMMENT '0=平台域名 >0=用户发布的域名',
  `listing_status` varchar(16) NOT NULL DEFAULT 'active' COMMENT 'pending待审 active上架 rejected驳回 offline下架 paused凭据失效暂停',
  `verify_token` varchar(64) NOT NULL DEFAULT '',
  `verified` tinyint NOT NULL DEFAULT 0,
  `stock` int NOT NULL DEFAULT 0 COMMENT '最多可售子域名数 0=不限',
  `sold` int NOT NULL DEFAULT 0,
  `commission_rate` decimal(5,2) NOT NULL DEFAULT -1 COMMENT '-1=按全局/等级',
  `income` decimal(12,2) NOT NULL DEFAULT 0.00,
  `pause_reason` varchar(255) NOT NULL DEFAULT '',
  `last_check_time` int NOT NULL DEFAULT 0,
  `domain` varchar(191) NOT NULL,
  `remote_id` varchar(100) NOT NULL DEFAULT '',
  `status` tinyint NOT NULL DEFAULT 1,
  `price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `period_days` int NOT NULL DEFAULT 0,
  `renew_before_days` int NOT NULL DEFAULT 7,
  `types` varchar(255) NOT NULL DEFAULT '',
  `min_len` int NOT NULL DEFAULT 3,
  `max_len` int NOT NULL DEFAULT 32,
  `reserved` text,
  `tier_ids` varchar(255) NOT NULL DEFAULT '',
  `max_per_user` int NOT NULL DEFAULT 0,
  `require_review` tinyint NOT NULL DEFAULT 0,
  `default_ttl` int NOT NULL DEFAULT 600,
  `allow_line` tinyint NOT NULL DEFAULT 1,
  `allow_proxy` tinyint NOT NULL DEFAULT 0 COMMENT '允许用户开启 CDN 代理(仅 Cloudflare)',
  `description` varchar(255) NOT NULL DEFAULT '',
  `sort` int NOT NULL DEFAULT 0,
  `create_time` int NOT NULL DEFAULT 0,
  `lifecycle` text COMMENT '到期策略覆盖 JSON，空=继承全局',
  `stock_alert` int NOT NULL DEFAULT -1,
  `stock_state` varchar(10) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};

CREATE TABLE `{p}dns_package` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `days` int NOT NULL DEFAULT 30,
  `sub_quota` int NOT NULL DEFAULT 1,
  `record_quota` int NOT NULL DEFAULT 10,
  `types` varchar(255) NOT NULL DEFAULT '',
  `smart_line` tinyint NOT NULL DEFAULT 0,
  `proxy` tinyint NOT NULL DEFAULT 0 COMMENT '可开启 Cloudflare 代理(橙云)',
  `status` tinyint NOT NULL DEFAULT 1,
  `sort` int NOT NULL DEFAULT 0,
  `description` text,
  `lifecycle` text COMMENT '到期策略覆盖 JSON，空=继承全局',
  `stock` int NOT NULL DEFAULT -1 COMMENT '库存 -1=不限',
  `sold` int NOT NULL DEFAULT 0,
  `reserved` int NOT NULL DEFAULT 0,
  `per_user_limit` int NOT NULL DEFAULT 0,
  `stock_alert` int NOT NULL DEFAULT -1,
  `stock_state` varchar(10) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};

CREATE TABLE `{p}user_package` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `package_id` int NOT NULL,
  `order_id` int NOT NULL DEFAULT 0,
  `name` varchar(100) NOT NULL DEFAULT '',
  `sub_quota` int NOT NULL DEFAULT 0,
  `record_quota` int NOT NULL DEFAULT 0,
  `types` varchar(255) NOT NULL DEFAULT '',
  `smart_line` tinyint NOT NULL DEFAULT 0,
  `proxy` tinyint NOT NULL DEFAULT 0,
  `expire_time` int NOT NULL DEFAULT 0,
  `create_time` int NOT NULL DEFAULT 0,
  `remind_time` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};

CREATE TABLE `{p}dns_subdomain` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `domain_id` int NOT NULL,
  `order_id` int NOT NULL DEFAULT 0,
  `prefix` varchar(64) NOT NULL,
  `fqdn` varchar(191) NOT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'active',
  `expire_time` int NOT NULL DEFAULT 0,
  `remind_time` int NOT NULL DEFAULT 0,
  `reject_reason` varchar(255) NOT NULL DEFAULT '',
  `remark` varchar(255) NOT NULL DEFAULT '',
  `create_time` int NOT NULL DEFAULT 0,
  `update_time` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `fqdn` (`fqdn`),
  KEY `user_id` (`user_id`),
  KEY `domain_id` (`domain_id`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};

CREATE TABLE `{p}dns_record` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `sub_id` int NOT NULL,
  `user_id` int NOT NULL,
  `domain_id` int NOT NULL,
  `host` varchar(128) NOT NULL DEFAULT '@',
  `name` varchar(191) NOT NULL,
  `type` varchar(10) NOT NULL,
  `value` varchar(500) NOT NULL,
  `line` varchar(20) NOT NULL DEFAULT 'default',
  `ttl` int NOT NULL DEFAULT 600,
  `mx` int NOT NULL DEFAULT 10,
  `proxied` tinyint NOT NULL DEFAULT 0,
  `remote_id` varchar(255) NOT NULL DEFAULT '',
  `status` varchar(16) NOT NULL DEFAULT 'active',
  `last_error` varchar(255) NOT NULL DEFAULT '',
  `create_time` int NOT NULL DEFAULT 0,
  `update_time` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `sub_id` (`sub_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};

CREATE TABLE `{p}risk_rule` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `type` varchar(20) NOT NULL,
  `match_type` varchar(10) NOT NULL DEFAULT 'contains',
  `pattern` varchar(255) NOT NULL,
  `action` varchar(10) NOT NULL DEFAULT 'deny',
  `remark` varchar(255) NOT NULL DEFAULT '',
  `status` tinyint NOT NULL DEFAULT 1,
  `hits` int NOT NULL DEFAULT 0,
  `create_time` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `type` (`type`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};

CREATE TABLE `{p}audit_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `actor_type` varchar(10) NOT NULL,
  `actor_id` int NOT NULL DEFAULT 0,
  `actor_name` varchar(64) NOT NULL DEFAULT '',
  `action` varchar(64) NOT NULL,
  `target` varchar(191) NOT NULL DEFAULT '',
  `detail` text,
  `ip` varchar(64) NOT NULL DEFAULT '',
  `ua` varchar(255) NOT NULL DEFAULT '',
  `level` varchar(10) NOT NULL DEFAULT 'info',
  `create_time` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `actor` (`actor_type`,`actor_id`,`create_time`),
  KEY `action` (`action`),
  KEY `ip_time` (`ip`,`create_time`),
  KEY `create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};

CREATE TABLE `{p}notify_log` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `channel` varchar(20) NOT NULL,
  `receiver` varchar(191) NOT NULL DEFAULT '',
  `title` varchar(255) NOT NULL DEFAULT '',
  `status` tinyint NOT NULL DEFAULT 0,
  `error` varchar(500) NOT NULL DEFAULT '',
  `create_time` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};

CREATE TABLE `{p}announcement` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(191) NOT NULL,
  `content` text,
  `status` tinyint NOT NULL DEFAULT 1,
  `sort` int NOT NULL DEFAULT 0,
  `create_time` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};

CREATE TABLE `{p}ticket` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `subject` varchar(191) NOT NULL,
  `department` varchar(32) NOT NULL DEFAULT '',
  `priority` varchar(16) NOT NULL DEFAULT '中',
  `seller_user_id` int NOT NULL DEFAULT 0,
  `waiting_since` int NOT NULL DEFAULT 0,
  `reminded_at` int NOT NULL DEFAULT 0,
  `remind_count` int NOT NULL DEFAULT 0,
  `last_admin_id` int NOT NULL DEFAULT 0,
  `status` varchar(16) NOT NULL DEFAULT 'open',
  `related` varchar(64) NOT NULL DEFAULT '',
  `create_time` int NOT NULL DEFAULT 0,
  `update_time` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};

CREATE TABLE `{p}ticket_reply` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ticket_id` int NOT NULL,
  `user_id` int NOT NULL DEFAULT 0,
  `admin_id` int NOT NULL DEFAULT 0,
  `party` varchar(16) NOT NULL DEFAULT 'user',
  `is_internal` tinyint NOT NULL DEFAULT 0,
  `is_system` tinyint NOT NULL DEFAULT 0,
  `content` text,
  `create_time` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `ticket_id` (`ticket_id`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};

CREATE TABLE `{p}ticket_attachment` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ticket_id` int NOT NULL,
  `reply_id` int NOT NULL DEFAULT 0,
  `original_name` varchar(191) NOT NULL DEFAULT '',
  `stored_name` varchar(80) NOT NULL DEFAULT '',
  `path` varchar(20) NOT NULL DEFAULT '',
  `mime` varchar(100) NOT NULL DEFAULT 'application/octet-stream',
  `size` int NOT NULL DEFAULT 0,
  `create_time` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`), KEY `ticket_reply` (`ticket_id`,`reply_id`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};

CREATE TABLE `{p}ticket_department` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(64) NOT NULL,
  `sort` int NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`), UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};

CREATE TABLE `{p}ticket_canned` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(191) NOT NULL DEFAULT '',
  `content` text,
  `status` tinyint NOT NULL DEFAULT 1,
  `create_time` int NOT NULL DEFAULT 0,
  `update_time` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};

CREATE TABLE `{p}ticket_read` (
  `ticket_id` int NOT NULL,
  `party` varchar(16) NOT NULL,
  `user_id` int NOT NULL DEFAULT 0,
  `last_reply_id` int NOT NULL DEFAULT 0,
  `update_time` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`ticket_id`,`party`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};

CREATE TABLE `{p}ticket_rating` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ticket_id` int NOT NULL,
  `user_id` int NOT NULL DEFAULT 0,
  `score` tinyint NOT NULL DEFAULT 0,
  `comment` varchar(1000) NOT NULL DEFAULT '',
  `party` varchar(16) NOT NULL DEFAULT 'user',
  `create_time` int NOT NULL DEFAULT 0,
  `update_time` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ticket_id` (`ticket_id`),
  KEY `score` (`score`),
  KEY `create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};

CREATE TABLE `{p}abuse_report` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `target` varchar(191) NOT NULL,
  `reason` text,
  `contact` varchar(120) NOT NULL DEFAULT '',
  `ip` varchar(64) NOT NULL DEFAULT '',
  `status` tinyint NOT NULL DEFAULT 0,
  `handle_note` varchar(255) NOT NULL DEFAULT '',
  `create_time` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};
CREATE TABLE `{p}nat_node` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `upstream_id` int NOT NULL DEFAULT 0,
  `name` varchar(100) NOT NULL,
  `reg_token` varchar(64) NOT NULL DEFAULT '',
  `reg_expire` int NOT NULL DEFAULT 0,
  `options` text,
  `status` tinyint NOT NULL DEFAULT 0 COMMENT '0待安装 1在线 2离线',
  `public_ip` varchar(64) NOT NULL DEFAULT '',
  `public_ipv6` varchar(64) NOT NULL DEFAULT '',
  `stats` text,
  `last_heartbeat` int NOT NULL DEFAULT 0,
  `registered_time` int NOT NULL DEFAULT 0,
  `create_time` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `upstream_id` (`upstream_id`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};
CREATE TABLE `{p}dns_protected` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `domain_id` int NOT NULL,
  `remote_id` varchar(255) NOT NULL DEFAULT '',
  `name` varchar(191) NOT NULL DEFAULT '',
  `type` varchar(10) NOT NULL DEFAULT '',
  `value` varchar(500) NOT NULL DEFAULT '',
  `create_time` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `domain_id` (`domain_id`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};
CREATE TABLE `{p}withdraw` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `fee` decimal(12,2) NOT NULL DEFAULT 0.00,
  `method` varchar(32) NOT NULL DEFAULT '',
  `account` varchar(255) NOT NULL DEFAULT '',
  `status` varchar(16) NOT NULL DEFAULT 'pending' COMMENT 'pending approved paid rejected',
  `admin_note` varchar(255) NOT NULL DEFAULT '',
  `handle_time` int NOT NULL DEFAULT 0,
  `create_time` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};
CREATE TABLE `{p}market_sale` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `domain_id` int NOT NULL,
  `seller_id` int NOT NULL,
  `buyer_id` int NOT NULL,
  `sub_id` int NOT NULL DEFAULT 0,
  `order_id` int NOT NULL DEFAULT 0,
  `amount` decimal(12,2) NOT NULL,
  `commission` decimal(12,2) NOT NULL,
  `income` decimal(12,2) NOT NULL,
  `gross_amount` decimal(12,2) NOT NULL DEFAULT 0 COMMENT '折前价',
  `paid_amount` decimal(12,2) NOT NULL DEFAULT 0 COMMENT '买家实付',
  `base_amount` decimal(12,2) NOT NULL DEFAULT 0 COMMENT '计佣基数',
  `bearer` varchar(10) NOT NULL DEFAULT 'platform',
  `create_time` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `seller_id` (`seller_id`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};
CREATE TABLE `{p}cancel_request` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `type` varchar(10) NOT NULL COMMENT 'service|sub',
  `target_id` int NOT NULL,
  `mode` varchar(10) NOT NULL DEFAULT 'end' COMMENT 'immediate|end',
  `reason` varchar(500) NOT NULL DEFAULT '',
  `status` varchar(16) NOT NULL DEFAULT 'pending' COMMENT 'pending approved rejected done withdrawn',
  `admin_note` varchar(255) NOT NULL DEFAULT '',
  `create_time` int NOT NULL DEFAULT 0,
  `handle_time` int NOT NULL DEFAULT 0,
  `done_time` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `target` (`type`,`target_id`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};
CREATE TABLE `{p}user_notice` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `title` varchar(191) NOT NULL DEFAULT '',
  `content` text,
  `is_read` tinyint NOT NULL DEFAULT 0,
  `create_time` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `user_read` (`user_id`,`is_read`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};

CREATE TABLE `{p}notify_queue` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `event` varchar(40) NOT NULL DEFAULT '',
  `channel` varchar(20) NOT NULL DEFAULT 'email',
  `receiver` varchar(191) NOT NULL DEFAULT '',
  `title` varchar(255) NOT NULL DEFAULT '',
  `body` text,
  `dedupe_key` char(40) NOT NULL,
  `status` tinyint NOT NULL DEFAULT 0 COMMENT '0待发 1成功 2失败 3发送中',
  `attempts` int NOT NULL DEFAULT 0,
  `next_try` int NOT NULL DEFAULT 0,
  `last_error` varchar(500) NOT NULL DEFAULT '',
  `create_time` int NOT NULL DEFAULT 0,
  `sent_time` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `dedupe_key` (`dedupe_key`),
  KEY `status_next` (`status`,`next_try`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};

CREATE TABLE `{p}inventory_hold` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int NOT NULL DEFAULT 0,
  `user_id` int NOT NULL DEFAULT 0,
  `item_type` varchar(16) NOT NULL COMMENT 'product/package/domain',
  `item_id` int NOT NULL DEFAULT 0,
  `qty` int NOT NULL DEFAULT 1,
  `status` varchar(12) NOT NULL DEFAULT 'reserved' COMMENT 'reserved/committed/released',
  `create_time` int NOT NULL DEFAULT 0,
  `update_time` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_item` (`order_id`,`item_type`,`item_id`),
  KEY `item` (`item_type`,`item_id`,`status`),
  KEY `user_item` (`user_id`,`item_type`,`item_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};


CREATE TABLE `{p}card_batch` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL DEFAULT '',
  `note` varchar(255) NOT NULL DEFAULT '',
  `face_value` decimal(12,2) NOT NULL DEFAULT 0,
  `qty` int NOT NULL DEFAULT 0,
  `prefix` varchar(16) NOT NULL DEFAULT '',
  `expire_time` int NOT NULL DEFAULT 0 COMMENT '0=永久',
  `per_user_limit` int NOT NULL DEFAULT 0 COMMENT '每用户最多兑换本批次张数 0=不限',
  `export_blob` mediumtext COMMENT '加密的明文卡密，首次下载或 24 小时后删除',
  `export_expire` int NOT NULL DEFAULT 0,
  `exported` tinyint NOT NULL DEFAULT 0,
  `admin` varchar(64) NOT NULL DEFAULT '',
  `create_time` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};

CREATE TABLE `{p}recharge_card` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `batch_id` int NOT NULL DEFAULT 0,
  `code_hash` char(64) NOT NULL,
  `code_tail` varchar(8) NOT NULL DEFAULT '' COMMENT '末 4 位，便于核对',
  `face_value` decimal(12,2) NOT NULL DEFAULT 0,
  `status` varchar(10) NOT NULL DEFAULT 'unused' COMMENT 'unused/used/disabled',
  `expire_time` int NOT NULL DEFAULT 0,
  `used_user_id` int NOT NULL DEFAULT 0,
  `used_time` int NOT NULL DEFAULT 0,
  `used_ip` varchar(64) NOT NULL DEFAULT '',
  `create_time` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code_hash` (`code_hash`),
  KEY `batch_status` (`batch_id`,`status`),
  KEY `used_user` (`used_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};

CREATE TABLE `{p}redeem_fail` (
  `fkey` varchar(100) NOT NULL COMMENT 'u:用户ID 或 ip:地址',
  `fails` int NOT NULL DEFAULT 0,
  `lock_until` int NOT NULL DEFAULT 0,
  `update_time` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`fkey`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};

CREATE TABLE `{p}coupon` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(40) NOT NULL,
  `name` varchar(100) NOT NULL DEFAULT '',
  `type` varchar(10) NOT NULL DEFAULT 'percent' COMMENT 'percent 折扣% / fixed 立减',
  `value` decimal(12,2) NOT NULL DEFAULT 0 COMMENT 'percent: 优惠百分比(20=减20%)；fixed: 金额',
  `scenes` varchar(60) NOT NULL DEFAULT 'new' COMMENT 'new,renew,upgrade',
  `item_types` varchar(100) NOT NULL DEFAULT '' COMMENT 'host,nat,dns_sub,dns_package,market 空=全部',
  `product_ids` varchar(500) NOT NULL DEFAULT '',
  `group_ids` varchar(500) NOT NULL DEFAULT '',
  `package_ids` varchar(500) NOT NULL DEFAULT '',
  `domain_ids` varchar(500) NOT NULL DEFAULT '',
  `min_amount` decimal(12,2) NOT NULL DEFAULT 0,
  `max_discount` decimal(12,2) NOT NULL DEFAULT 0 COMMENT '0=不封顶',
  `total_limit` int NOT NULL DEFAULT 0 COMMENT '0=不限',
  `per_user_limit` int NOT NULL DEFAULT 1 COMMENT '0=不限',
  `used_count` int NOT NULL DEFAULT 0,
  `reserved_count` int NOT NULL DEFAULT 0,
  `new_user_only` tinyint NOT NULL DEFAULT 0 COMMENT '注册 N 天内',
  `new_user_days` int NOT NULL DEFAULT 7,
  `first_order_only` tinyint NOT NULL DEFAULT 0,
  `tier_ids` varchar(255) NOT NULL DEFAULT '',
  `start_time` int NOT NULL DEFAULT 0,
  `end_time` int NOT NULL DEFAULT 0,
  `status` tinyint NOT NULL DEFAULT 1,
  `note` varchar(255) NOT NULL DEFAULT '',
  `create_time` int NOT NULL DEFAULT 0,
  `update_time` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};

CREATE TABLE `{p}coupon_use` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `coupon_id` int NOT NULL,
  `order_id` int NOT NULL,
  `user_id` int NOT NULL,
  `discount` decimal(12,2) NOT NULL DEFAULT 0,
  `status` varchar(10) NOT NULL DEFAULT 'reserved' COMMENT 'reserved/used/released',
  `create_time` int NOT NULL DEFAULT 0,
  `update_time` int NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `order_id` (`order_id`),
  KEY `coupon_user` (`coupon_id`,`user_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET={charset};
