<?php

namespace app\common\service;

use app\common\lib\Notify;
use think\Db;

class TicketService
{
    public static function statuses()
    {
        return array('open' => '待处理', 'processing' => '处理中', 'waiting_user' => '等待用户', 'resolved' => '已解决', 'closed' => '已关闭');
    }

    public static function priorities()
    {
        return array('低' => '低', '中' => '中', '高' => '高', '紧急' => '紧急');
    }

    public static function departments()
    {
        try {
            $rows = Db::name('ticket_department')->where('status', 1)->order('sort asc,id asc')->select();
            if ($rows) {
                return $rows;
            }
        } catch (\Exception $e) {
        }

        return array(array('name' => '技术支持'), array('name' => '财务'), array('name' => '售前咨询'), array('name' => '域名市场'), array('name' => '投诉举报'));
    }

    public static function renderMarkdown($text)
    {
        $s = h((string)$text);
        $s = str_replace(array("\r\n", "\r"), "\n", $s);
        $blocks = array();
        $s = preg_replace_callback('/```([a-zA-Z0-9_-]*)\n([\s\S]*?)```/', function ($m) use (&$blocks) {
            $key = '@@CODE' . count($blocks) . '@@';
            $blocks[$key] = '<pre><code>' . trim($m[2]) . '</code></pre>';

            return "\n" . $key . "\n";
        }, $s);
        $s = preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^\s\)]+|mailto:[^\s\)]+)\)/', function ($m) {
            return '<a href="' . h($m[2]) . '" rel="nofollow noopener noreferrer" target="_blank">' . $m[1] . '</a>';
        }, $s);
        $s = preg_replace('/`([^`\n]+)`/', '<code>$1</code>', $s);
        $s = preg_replace('/\*\*([^*\n]+)\*\*/', '<strong>$1</strong>', $s);
        $s = preg_replace('/\*([^*\n]+)\*/', '<em>$1</em>', $s);
        $out = '';
        $list = false;
        foreach (explode("\n", $s) as $line) {
            $trim = trim($line);
            if (isset($blocks[$trim])) {
                if ($list) {
                    $out .= '</ul>';
                    $list = false;
                }
                $out .= $blocks[$trim];
            } elseif (preg_match('/^\s*[-*]\s+(.+)$/', $line, $m)) {
                if (!$list) {
                    $out .= '<ul>';
                    $list = true;
                }
                $out .= '<li>' . $m[1] . '</li>';
            } elseif ($list) {
                $out .= '</ul>';
                $list = false;
                if ($trim !== '') {
                    $out .= '<p>' . $line . '</p>';
                }
            } elseif (preg_match('/^\s*&gt;\s?(.*)$/', $line, $m)) {
                $out .= '<blockquote>' . $m[1] . '</blockquote>';
            } elseif ($trim !== '') {
                $out .= '<p>' . $line . '</p>';
            }
        }
        if ($list) {
            $out .= '</ul>';
        }

        return $out;
    }

    public static function ticket($id, $party, $uid)
    {
        $ticket = Db::name('ticket')->find((int)$id);
        if (!$ticket) {
            return null;
        }
        if ($party === 'admin') {
            return $ticket;
        }
        if ((int)$ticket['user_id'] === (int)$uid) {
            return $ticket;
        }
        if ($party === 'seller' && (int)$ticket['seller_user_id'] === (int)$uid) {
            return $ticket;
        }

        return null;
    }

    public static function validateRelated($uid, $raw)
    {
        $raw = trim((string)$raw);
        if ($raw === '') {
            return array('', 0);
        }
        if (!preg_match('/^(order|service|subdomain|host|nat|listing):(\d+)$/', $raw, $m)) {
            throw new \Exception('关联对象格式无效');
        }
        $id = (int)$m[2];
        if ($m[1] === 'order') {
            $ok = Db::name('order')->where('id', $id)->where('user_id', $uid)->count();
        } elseif (in_array($m[1], array('service', 'host', 'nat'), true)) {
            $ok = Db::name('service')->where('id', $id)->where('user_id', $uid)->count();
        } elseif ($m[1] === 'subdomain') {
            $ok = Db::name('dns_subdomain')->where('id', $id)->where('user_id', $uid)->count();
        } else {
            $ok = Db::name('dns_domain')->where('id', $id)->where('user_id', $uid)->count();
        }
        if (!$ok) {
            throw new \Exception('只能关联您自己的对象');
        }

        return array($raw, 0);
    }

    public static function sellerSubdomain($uid, $subId)
    {
        $row = Db::name('dns_subdomain')->alias('s')->join('dns_domain d', 'd.id=s.domain_id')->where('s.id', (int)$subId)->where('s.user_id', (int)$uid)->field('s.*,d.user_id seller_user_id')->find();
        if (!$row || (int)$row['seller_user_id'] <= 0 || (int)$row['seller_user_id'] === (int)$uid) {
            throw new \Exception('该子域名不属于其他市场卖家');
        }

        return $row;
    }

    public static function rateLimit($uid, $reply = false)
    {
        $now = time();
        if ($reply) {
            $last = Db::name('ticket_reply')->where('user_id', $uid)->where('party', 'user')->order('id desc')->value('create_time');
            if ($last && $now - (int)$last < (int)setting('ticket_reply_min_seconds', '10')) {
                throw new \Exception('回复过于频繁，请稍后再试');
            }

            return;
        }
        if (Db::name('ticket')->where('user_id', $uid)->where('create_time', '>', $now - 3600)->count() >= (int)setting('ticket_max_new_hour', '5')) {
            throw new \Exception('新建工单频率已达上限');
        }
        if (Db::name('ticket')->where('user_id', $uid)->where('status', 'in', array('open', 'processing', 'waiting_user', 'resolved'))->count() >= (int)setting('ticket_max_open', '10')) {
            throw new \Exception('未关闭工单数量已达上限');
        }
    }

    public static function add($uid, $data, $files = array())
    {
        self::rateLimit($uid, false);
        list($related, $seller) = self::validateRelated($uid, isset($data['related']) ? $data['related'] : '');
        if (!empty($data['seller_subdomain_id'])) {
            $sub = self::sellerSubdomain($uid, (int)$data['seller_subdomain_id']);
            $seller = (int)$sub['seller_user_id'];
            $related = 'subdomain:' . (int)$data['seller_subdomain_id'];
        }
        $department = trim((string)(isset($data['department']) ? $data['department'] : ''));
        $valid = false;
        foreach (self::departments() as $d) {
            if ($department === (string)$d['name']) {
                $valid = true;
                break;
            }
        }
        if (!$valid) {
            throw new \Exception('部门无效');
        }
        $priority = isset($data['priority']) && isset(self::priorities()[$data['priority']]) ? $data['priority'] : '中';
        if (trim((string)(isset($data['subject']) ? $data['subject'] : '')) === '' || trim((string)(isset($data['content']) ? $data['content'] : '')) === '') {
            throw new \Exception('请填写主题和问题描述');
        }
        $checked = self::checkAttachments($files);
        $now = time();
        Db::startTrans();
        try {
            $id = Db::name('ticket')->insertGetId(array('user_id' => (int)$uid, 'subject' => mb_substr(trim((string)$data['subject']), 0, 191), 'department' => $department, 'priority' => $priority, 'seller_user_id' => $seller, 'waiting_since' => $now, 'status' => 'open', 'related' => $related, 'create_time' => $now, 'update_time' => $now));
            $replyId = self::entry($id, $uid, 0, 'user', isset($data['content']) ? $data['content'] : '', false, false);
            self::storeAttachments($id, $replyId, $checked);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            throw $e;
        }
        Notify::admin('ticket_new', array('_key' => 'new:' . $id, 'ticket_id' => $id, 'subject' => $data['subject'], 'content' => $data['content'], 'username' => ''));
        if ($seller) {
            $user = Db::name('user')->find($seller);
            if ($user) {
                Notify::seller('ticket_seller_new', $user, array('_key' => 'sellernew:' . $id, 'ticket_id' => $id, 'subject' => $data['subject']));
            }
        }

        return $id;
    }

    public static function entry($tid, $uid, $aid, $party, $content, $internal = false, $system = false)
    {
        return Db::name('ticket_reply')->insertGetId(array('ticket_id' => (int)$tid, 'user_id' => (int)$uid, 'admin_id' => (int)$aid, 'party' => $party, 'content' => mb_substr((string)$content, 0, 20000), 'is_internal' => $internal ? 1 : 0, 'is_system' => $system ? 1 : 0, 'create_time' => time()));
    }

    public static function reply($ticket, $uid, $party, $content, $files = array(), $internal = false, $status = '')
    {
        if (!$internal) {
            self::rateLimit($uid, $party !== 'admin' && $party !== 'seller');
        }
        if (trim((string)$content) === '') {
            throw new \Exception('回复内容不能为空');
        }
        $checked = self::checkAttachments($files);
        $aid = $party === 'admin' ? (int)$uid : 0;
        Db::startTrans();
        try {
            $rid = self::entry($ticket['id'], $party === 'admin' ? 0 : $uid, $aid, $party, $content, $internal, false);
            self::storeAttachments($ticket['id'], $rid, $checked);
            Db::commit();
        } catch (\Exception $e) {
            Db::rollback();
            throw $e;
        }
        if ($internal) {
            audit('ticket.note', '工单#' . $ticket['id'], array('reply_id' => $rid));

            return $rid;
        }
        $newStatus = $status !== '' && isset(self::statuses()[$status]) ? $status : (($party === 'admin' || $party === 'seller') ? 'waiting_user' : ((int)$ticket['last_admin_id'] ? 'processing' : 'open'));
        $update = array('status' => $newStatus, 'update_time' => time(), 'waiting_since' => ($party === 'user' ? time() : 0), 'reminded_at' => 0);
        if ($party === 'admin') {
            $update['last_admin_id'] = (int)$uid;
        }
        Db::name('ticket')->where('id', $ticket['id'])->update($update);
        if ($newStatus !== $ticket['status']) {
            self::entry($ticket['id'], 0, $party === 'admin' ? $uid : 0, $party, '状态变更为：' . self::statuses()[$newStatus], false, true);
        }
        $vars = array('_key' => 'reply:' . $rid, 'ticket_id' => $ticket['id'], 'subject' => $ticket['subject'], 'content' => $content, 'status' => $newStatus);
        if ($party === 'admin' || $party === 'seller') {
            $user = Db::name('user')->find($ticket['user_id']);
            if ($user) {
                Notify::user($party === 'admin' ? 'ticket_admin_reply' : 'ticket_seller_reply', $user, $vars);
            }
        } else {
            Notify::admin('ticket_user_reply', $vars);
            if (!empty($ticket['seller_user_id'])) {
                $seller = Db::name('user')->find($ticket['seller_user_id']);
                if ($seller) {
                    Notify::seller('ticket_seller_reply', $seller, $vars);
                }
            }
        }

        return $rid;
    }

    protected static function detect($path, $ext)
    {
        $ext = strtolower($ext);
        $head = @file_get_contents($path, false, null, 0, 32);
        if (substr($head, 0, 3) === "\xFF\xD8\xFF") {
            $type = 'jpg';
        } elseif (substr($head, 0, 8) === "\x89PNG\r\n\x1a\n") {
            $type = 'png';
        } elseif (substr($head, 0, 6) === 'GIF87a' || substr($head, 0, 6) === 'GIF89a') {
            $type = 'gif';
        } elseif (substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') {
            $type = 'webp';
        } elseif (substr($head, 0, 4) === '%PDF') {
            $type = 'pdf';
        } elseif (strlen($head) >= 12 && substr($head, 4, 4) === 'ftyp' && in_array(substr($head, 8, 4), array('avif', 'avis'), true)) {
            $type = 'avif';
        } elseif (substr($head, 0, 4) === "PK\x03\x04") {
            $all = @file_get_contents($path);
            if (class_exists('ZipArchive')) {
                $zip = new \ZipArchive();
                $type = ($zip->open($path) === true && $zip->locateName('[Content_Types].xml') !== false) ? 'ooxml' : 'zip';
                $zip->close();
            } else {
                $type = strpos($all, '[Content_Types].xml') !== false ? 'ooxml' : 'zip';
            }
        } else {
            $all = @file_get_contents($path);
            $type = strpos($all, "\0") === false ? 'text' : '';
        }
        if ($type === 'text' && in_array($ext, array('txt', 'log'), true)) {
            return $type;
        }
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }

        return ($type === $ext || ($type === 'ooxml' && in_array($ext, array('docx', 'xlsx', 'pptx'), true))) ? $type : '';
    }

    /**
     * 校验上传的附件（数量、白名单扩展名、大小、文件头魔数），全部通过才返回；
     * 必须在写入任何工单数据之前调用，避免失败时留下半条工单。
     */
    public static function checkAttachments($files)
    {
        $max = max(1, (int)setting('ticket_attachment_max_files', '5'));
        $size = max(1024, (int)setting('ticket_attachment_max_size', '5242880'));
        $allow = array_filter(array_map('trim', explode(',', strtolower(setting('ticket_attachment_whitelist', 'jpg,jpeg,png,gif,webp,avif,pdf,txt,log,zip,docx,xlsx,pptx')))));
        $out = array();
        if (is_object($files)) {
            $files = array($files);
        }
        foreach ((array)$files as $file) {
            if (!$file || !method_exists($file, 'getPathname')) {
                continue;
            }
            if (count($out) >= $max) {
                throw new \Exception('附件数量超过限制（最多 ' . $max . ' 个）');
            }
            $info = $file->getInfo();
            if (isset($info['error']) && (int)$info['error'] !== 0) {
                throw new \Exception('附件上传失败（错误码 ' . (int)$info['error'] . '），请检查文件大小');
            }
            $original = isset($info['name']) ? (string)$info['name'] : 'attachment';
            $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
            $bytes = (int)@filesize($file->getPathname());
            if (!in_array($ext, $allow, true) || $bytes > $size || $bytes <= 0) {
                throw new \Exception('附件类型或大小不允许：' . $original);
            }
            if (self::detect($file->getPathname(), $ext) === '') {
                throw new \Exception('附件内容与扩展名不匹配：' . $original);
            }
            $out[] = array('file' => $file, 'name' => $original, 'ext' => $ext, 'size' => $bytes);
        }

        return $out;
    }

    /** 保存已通过校验的附件到 data 目录（随机文件名，网站目录外） */
    public static function storeAttachments($tid, $rid, array $checked)
    {
        if (!$checked) {
            return;
        }
        $dir = DATA_PATH . 'tickets/' . date('Ym') . '/';
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            throw new \Exception('附件目录不可写');
        }
        foreach ($checked as $item) {
            $stored = bin2hex(random_bytes(16)) . '.' . $item['ext'];
            if (!$item['file']->move($dir, $stored)) {
                throw new \Exception('附件保存失败：' . $item['file']->getError());
            }
            $ext = $item['ext'];
            $mime = in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'), true) ? 'image/' . ($ext === 'jpg' || $ext === 'jpeg' ? 'jpeg' : $ext) : ($ext === 'pdf' ? 'application/pdf' : 'application/octet-stream');
            Db::name('ticket_attachment')->insert(array('ticket_id' => (int)$tid, 'reply_id' => (int)$rid, 'original_name' => mb_substr($item['name'], 0, 191), 'stored_name' => $stored, 'path' => date('Ym'), 'mime' => $mime, 'size' => (int)$item['size'], 'create_time' => time()));
        }
    }

    /** 兼容旧调用：校验并保存 */
    public static function attachments($tid, $rid, $files)
    {
        self::storeAttachments($tid, $rid, self::checkAttachments($files));
    }

    public static function setStatus($ticket, $status, $by, $party = 'admin')
    {
        if (!isset(self::statuses()[$status])) {
            throw new \Exception('状态无效');
        }
        $old = $ticket['status'];
        Db::name('ticket')->where('id', $ticket['id'])->update(array('status' => $status, 'update_time' => time(), 'waiting_since' => $status === 'waiting_user' ? time() : 0, 'reminded_at' => 0));
        self::entry($ticket['id'], $party === 'admin' ? 0 : $by, $party === 'admin' ? $by : 0, $party, '状态变更：' . self::statuses()[$old] . ' → ' . self::statuses()[$status], false, true);
        if ($party === 'admin' && $old !== $status) {
            $user = Db::name('user')->find($ticket['user_id']);
            if ($user) {
                Notify::user('ticket_status', $user, array('_key' => 'status:' . $ticket['id'] . ':' . $status . ':' . time(), 'ticket_id' => $ticket['id'], 'subject' => $ticket['subject'], 'status' => self::statuses()[$status]));
            }
        }
        audit('ticket.status', '工单#' . $ticket['id'], array('from' => $old, 'status' => $status));
    }
}
