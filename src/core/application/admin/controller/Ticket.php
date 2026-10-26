<?php

namespace app\admin\controller;

use app\common\service\TicketService;
use app\common\service\TicketRatingService;
use think\Db;

class Ticket extends OpsBase
{
    protected $title = '工单';

    public function index()
    {
        $q = Db::name('ticket');
        $status = trim((string)$this->get('status', ''));
        $department = trim((string)$this->get('department', ''));
        $priority = trim((string)$this->get('priority', ''));
        $assignee = (int)$this->get('assignee', 0);
        $keyword = trim((string)$this->get('kw', ''));
        $overdue = (int)$this->get('overdue', 0);
        $unread = (int)$this->get('unread', 0);
        $rating = trim((string)$this->get('rating', ''));
        if ($status && isset(TicketService::statuses()[$status])) {
            $q->where('status', $status);
        }
        if ($department) {
            $q->where('department', $department);
        }
        if ($priority && isset(TicketService::priorities()[$priority])) {
            $q->where('priority', $priority);
        }
        if ($assignee >= 0 && $this->get('assignee', '') !== '') {
            $q->where('last_admin_id', $assignee);
        }
        if ($keyword) {
            $q->where('subject|related', 'like', '%' . $keyword . '%');
        }
        if ($overdue) {
            $q->where('waiting_since', '>', 0)->where('waiting_since', '<', time() - (int)setting('ticket_overdue_hours', '6') * 3600)->where('status', 'in', array('open', 'processing'));
        }
        if ($rating === 'bad') {
            $ids = Db::name('ticket_rating')->where('score', '<=', 2)->column('ticket_id');
            $q->where('id', 'in', $ids ? $ids : array(0));
        }
        list($list, $total, $page, $size) = $this->paginate($q->order('update_time desc'), 20);
        $readMap = array();
        foreach (Db::name('ticket_read')->where('party', 'admin')->where('user_id', $this->admin['id'])->select() as $read) {
            $readMap[(int)$read['ticket_id']] = (int)$read['last_reply_id'];
        }
        foreach ($list as &$ticket) {
            $ticket['overdue'] = $ticket['waiting_since'] && in_array($ticket['status'], array('open', 'processing'), true) && $ticket['waiting_since'] < time() - (int)setting('ticket_overdue_hours', '6') * 3600;
            $ticket['unread'] = (int)Db::name('ticket_reply')->where('ticket_id', $ticket['id'])->where('id', '>', isset($readMap[(int)$ticket['id']]) ? $readMap[(int)$ticket['id']] : 0)->where('party', 'user')->count() > 0;
        }
        unset($ticket);
        $uids = array();
        foreach ($list as $ticket) {
            $uids[] = (int)$ticket['user_id'];
            $uids[] = (int)$ticket['seller_user_id'];
        }
        $uids = array_values(array_unique(array_filter($uids)));
        $users = $uids ? Db::name('user')->where('id', 'in', $uids)->column('username', 'id') : array();
        $admins = Db::name('admin')->where('status', 1)->column('username', 'id');

        return $this->fetch('ticket/index', array('pageTitle' => '工单', 'users' => $users, 'admins' => $admins, 'list' => $list, 'total' => $total, 'page' => $page, 'size' => $size, 'statuses' => TicketService::statuses(), 'priorities' => TicketService::priorities(), 'departments' => TicketService::departments(), 'status' => $status, 'department' => $department, 'priority' => $priority, 'assignee' => $assignee, 'kw' => $keyword, 'overdue' => $overdue, 'unread' => $unread, 'rating' => $rating));
    }

    public function detail()
    {
        $id = (int)$this->get('id');
        $ticket = TicketService::ticket($id, 'admin', 0);
        if (!$ticket) {
            $this->abort('工单不存在', 404);
        }
        if ($this->request->isPost()) {
            try {
                $action = $this->post('action');
                if ($action === 'reply' || $action === 'note') {
                    $files = $this->request->file('attachments');
                    TicketService::reply($ticket, $this->admin['id'], 'admin', $this->post('content', ''), $files, $action === 'note', $this->post('status', ''));
                } elseif ($action === 'status') {
                    TicketService::setStatus($ticket, $this->post('status'), $this->admin['id']);
                } elseif ($action === 'assign') {
                    Db::name('ticket')->where('id', $id)->update(array('last_admin_id' => (int)$this->post('admin_id')));
                    audit('ticket.assign', '工单#' . $id, array('admin_id' => (int)$this->post('admin_id')));
                } elseif ($action === 'priority') {
                    if (!isset(TicketService::priorities()[$this->post('priority')])) {
                        throw new \Exception('优先级无效');
                    }
                    Db::name('ticket')->where('id', $id)->update(array('priority' => $this->post('priority')));
                    audit('ticket.priority', '工单#' . $id, $this->post('priority'));
                } elseif ($action === 'department') {
                    $names = array();
                    foreach (TicketService::departments() as $d) {
                        $names[] = (string)$d['name'];
                    }
                    if (!in_array(trim($this->post('department')), $names, true)) {
                        throw new \Exception('部门无效');
                    }
                    Db::name('ticket')->where('id', $id)->update(array('department' => trim($this->post('department'))));
                    audit('ticket.department', '工单#' . $id, $this->post('department'));
                }
                $this->flash('操作成功', 'success');
            } catch (\Exception $e) {
                $this->flash($e->getMessage(), 'danger');
            }

            return $this->redirectTo('admin/ticket/detail', array('id' => $id));
        }
        $rows = Db::name('ticket_reply')->where('ticket_id', $id)->order('id asc')->select();
        foreach ($rows as &$row) {
            $row['html'] = TicketService::renderMarkdown($row['content']);
        }
        $admins = Db::name('admin')->where('status', 1)->select();
        $owner = Db::name('user')->field('id,username,email,status,create_time')->find((int)$ticket['user_id']);
        $seller = (int)$ticket['seller_user_id'] ? Db::name('user')->field('id,username,email')->find((int)$ticket['seller_user_id']) : null;
        $canned = Db::name('ticket_canned')->where('status', 1)->order('id desc')->select();
        Db::name('ticket_read')->insert(array('ticket_id' => $id, 'party' => 'admin', 'user_id' => $this->admin['id'], 'last_reply_id' => (int)Db::name('ticket_reply')->where('ticket_id', $id)->max('id'), 'update_time' => time()), true);

        return $this->fetch('ticket/detail', array('pageTitle' => '工单详情', 'owner' => $owner, 'seller' => $seller, 'canned' => $canned, 'ticket' => $ticket, 'replies' => $rows, 'attachments' => Db::name('ticket_attachment')->where('ticket_id', $id)->select(), 'admins' => $admins, 'statuses' => TicketService::statuses(), 'priorities' => TicketService::priorities(), 'departments' => TicketService::departments(), 'rating' => TicketRatingService::get($id)));
    }

    public function download()
    {
        $attachment = Db::name('ticket_attachment')->find((int)$this->get('id'));
        if (!$attachment || !TicketService::ticket($attachment['ticket_id'], 'admin', 0)) {
            $this->abort('附件不存在', 404);
        }
        $file = DATA_PATH . 'tickets/' . $attachment['path'] . '/' . $attachment['stored_name'];
        if (!is_file($file)) {
            $this->abort('附件不存在', 404);
        }
        $name = str_replace(array('"', "\r", "\n"), '', basename($attachment['original_name']));
        header('Content-Type: ' . ($attachment['mime'] ?: 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        exit;
    }

    public function canned()
    {
        if ($this->request->isPost()) {
            $id = (int)$this->post('id');
            $action = (string)$this->post('action', 'save');
            if ($action === 'delete' && $id) {
                Db::name('ticket_canned')->where('id', $id)->delete();
                audit('ticket.canned.delete', '快捷回复#' . $id);
                $this->flash('已删除', 'success');
            } elseif (trim($this->post('title')) === '' || trim($this->post('content')) === '') {
                $this->flash('标题和内容不能为空', 'danger');
            } else {
                $data = array('title' => mb_substr(trim($this->post('title')), 0, 100), 'content' => (string)$this->post('content'), 'update_time' => time());
                if ($id) {
                    Db::name('ticket_canned')->where('id', $id)->update($data);
                } else {
                    $data['create_time'] = time();
                    Db::name('ticket_canned')->insert($data);
                }
                audit('ticket.canned.save', '快捷回复', $this->post('title'));
                $this->flash('已保存', 'success');
            }

            return $this->redirectTo('admin/ticket/canned');
        }
        $edit = (int)$this->get('id') ? Db::name('ticket_canned')->find((int)$this->get('id')) : null;

        return $this->fetch('ticket/canned', array('pageTitle' => '快捷回复', 'edit' => $edit, 'items' => Db::name('ticket_canned')->where('status', 1)->order('id desc')->select()));
    }

    public function departments()
    {
        if ($this->request->isPost()) {
            $id = (int)$this->post('id');
            $action = (string)$this->post('action', 'create');
            if ($action === 'toggle' && $id) {
                $row = Db::name('ticket_department')->find($id);
                if ($row) {
                    $enabled = Db::name('ticket_department')->where('status', 1)->count();
                    if ((int)$row['status'] === 1 && $enabled <= 1) {
                        $this->flash('至少保留一个启用的部门', 'danger');
                    } else {
                        Db::name('ticket_department')->where('id', $id)->update(array('status' => (int)$row['status'] ? 0 : 1));
                        audit('ticket.department.toggle', '工单部门', $row['name']);
                        $this->flash('已更新', 'success');
                    }
                }
            } elseif ($action === 'sort' && $id) {
                Db::name('ticket_department')->where('id', $id)->update(array('sort' => (int)$this->post('sort')));
                $this->flash('已更新排序', 'success');
            } else {
                $name = mb_substr(trim($this->post('name')), 0, 32);
                if ($name === '') {
                    $this->flash('部门名称不能为空', 'danger');
                } elseif (Db::name('ticket_department')->where('name', $name)->count()) {
                    $this->flash('部门已存在', 'danger');
                } else {
                    Db::name('ticket_department')->insert(array('name' => $name, 'sort' => (int)$this->post('sort'), 'status' => 1));
                    audit('ticket.department.create', '工单部门', $name);
                    $this->flash('已保存', 'success');
                }
            }

            return $this->redirectTo('admin/ticket/departments');
        }
        $counts = Db::name('ticket')->group('department')->column('count(*)', 'department');

        return $this->fetch('ticket/departments', array('pageTitle' => '工单部门', 'counts' => $counts, 'items' => Db::name('ticket_department')->order('sort asc,id asc')->select()));
    }
}
