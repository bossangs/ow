<?php

namespace app\index\controller;

use app\common\controller\FrontBase;
use app\common\service\TicketService;
use app\common\service\TicketRatingService;
use think\Db;

class Ticket extends FrontBase
{
    protected $needLogin = true;

    protected function own($id, $party = 'user')
    {
        $ticket = TicketService::ticket((int)$id, $party, $this->user['id']);
        if (!$ticket) {
            $this->abort('工单不存在', 404);
        }

        return $ticket;
    }

    protected function files()
    {
        $files = $this->request->file('attachments');
        if (!$files) {
            return array();
        }
        if (!is_array($files)) {
            return array($files);
        }
        $out = array();
        foreach ($files as $file) {
            if (is_object($file)) {
                $out[] = $file;
            }
        }

        return $out;
    }

    public function index()
    {
        $q = Db::name('ticket')->where(function ($query) {
            $query->where('user_id', $this->user['id'])->whereOr('seller_user_id', $this->user['id']);
        });
        $status = trim((string)$this->get('status', ''));
        $keyword = trim((string)$this->get('kw', ''));
        if ($status && isset(TicketService::statuses()[$status])) {
            $q->where('status', $status);
        }
        if ($keyword !== '') {
            $q->where('subject', 'like', '%' . $keyword . '%');
        }
        $tickets = $q->order('update_time desc')->select();
        $last = array();
        foreach (Db::name('ticket_read')->where('user_id', $this->user['id'])->select() as $read) {
            $last[$read['ticket_id'] . ':' . $read['party']] = (int)$read['last_reply_id'];
        }
        foreach ($tickets as &$ticket) {
            $party = (int)$ticket['seller_user_id'] === (int)$this->user['id'] && (int)$ticket['user_id'] !== (int)$this->user['id'] ? 'seller' : 'user';
            $maxReply = (int)Db::name('ticket_reply')->where('ticket_id', $ticket['id'])->max('id');
            $ticket['unread'] = $maxReply > (isset($last[$ticket['id'] . ':' . $party]) ? $last[$ticket['id'] . ':' . $party] : 0);
        }
        unset($ticket);

        return $this->fetch('ticket/index', array('title' => '工单支持', 'sidebar' => true, 'tickets' => $tickets, 'statuses' => TicketService::statuses(), 'status' => $status, 'kw' => $keyword));
    }

    public function create()
    {
        if ($this->request->isPost()) {
            try {
                $id = TicketService::add($this->user['id'], $this->post(), $this->files());
                $this->flash('工单已提交', 'success');

                return $this->redirectTo('index/ticket/detail', array('id' => $id));
            } catch (\Exception $e) {
                $this->flash($e->getMessage(), 'danger');
            }
        }

        $uid = (int)$this->user['id'];
        $subs = Db::name('dns_subdomain')->alias('s')->join('dns_domain d', 'd.id=s.domain_id')
            ->where('s.user_id', $uid)->field('s.id,s.fqdn,d.user_id seller_user_id')->order('s.id desc')->limit(100)->select();
        $sellerSubs = array();
        foreach ($subs as $sub) {
            if ((int)$sub['seller_user_id'] > 0 && (int)$sub['seller_user_id'] !== $uid) {
                $sellerSubs[] = $sub;
            }
        }

        return $this->fetch('ticket/create', array(
            'title' => '提交工单',
            'sidebar' => true,
            'departments' => TicketService::departments(),
            'priorities' => TicketService::priorities(),
            'orders' => Db::name('order')->where('user_id', $uid)->field('id,order_no,title')->order('id desc')->limit(50)->select(),
            'services' => Db::name('service')->where('user_id', $uid)->field('id,hostname')->order('id desc')->limit(50)->select(),
            'subs' => $subs,
            'sellerSubs' => $sellerSubs,
            'preRelated' => (string)$this->get('related', ''),
            'preSeller' => (int)$this->get('seller_subdomain_id', 0),
        ));
    }

    public function detail()
    {
        // 买家或该工单的市场卖家都可查看与回复（卖家不能关闭/评价/重开）
        $ticket = $this->own((int)$this->get('id'), 'seller');
        $party = ((int)$ticket['seller_user_id'] === (int)$this->user['id'] && (int)$ticket['user_id'] !== (int)$this->user['id']) ? 'seller' : 'user';
        if ($this->request->isPost()) {
            try {
                TicketService::reply($ticket, $this->user['id'], $party, $this->post('content', ''), $this->files());
                $this->flash('回复已提交', 'success');
            } catch (\Exception $e) {
                $this->flash($e->getMessage(), 'danger');
            }

            return $this->redirectTo('index/ticket/detail', array('id' => $ticket['id']));
        }
        $replyId = (int)Db::name('ticket_reply')->where('ticket_id', $ticket['id'])->max('id');
        Db::name('ticket_read')->insert(array('ticket_id' => $ticket['id'], 'party' => $party, 'user_id' => $this->user['id'], 'last_reply_id' => $replyId, 'update_time' => time()), true);
        $replies = Db::name('ticket_reply')->where('ticket_id', $ticket['id'])->where('is_internal', 0)->order('id asc')->select();
        foreach ($replies as &$reply) {
            $reply['html'] = TicketService::renderMarkdown($reply['content']);
        }
        $attachments = Db::name('ticket_attachment')->where('ticket_id', $ticket['id'])->select();

        return $this->fetch('ticket/detail', array('title' => '工单详情', 'sidebar' => true, 'ticket' => $ticket, 'replies' => $replies, 'attachments' => $attachments, 'rating' => TicketRatingService::get($ticket['id']), 'ratingLabels' => TicketRatingService::labels(), 'party' => $party, 'statuses' => TicketService::statuses()));
    }

    public function rate()
    {
        if (!$this->request->isPost()) {
            $this->abort('请求方式错误', 405);
        }
        try {
            TicketRatingService::rate((int)$this->post('id'), $this->user['id'], $this->post('score'), $this->post('comment', ''));
            $this->flash('感谢您的评价', 'success');
        } catch (\Exception $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        return $this->redirectTo('index/ticket/detail', array('id' => (int)$this->post('id')));
    }

    public function close()
    {
        if (!$this->request->isPost()) {
            $this->abort('请求方式错误', 405);
        }
        $ticket = $this->own((int)$this->post('id'));
        try {
            TicketService::setStatus($ticket, 'closed', $this->user['id'], 'user');
            $this->flash('工单已关闭', 'success');
        } catch (\Exception $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        return $this->redirectTo('index/ticket/index');
    }

    public function reopen()
    {
        if (!$this->request->isPost()) {
            $this->abort('请求方式错误', 405);
        }
        $ticket = $this->own((int)$this->post('id'));
        if ($ticket['status'] !== 'closed' || time() - (int)$ticket['update_time'] > (int)setting('ticket_reopen_days', '7') * 86400) {
            $this->abort('已超过可重开期限', 400);
        }
        TicketService::setStatus($ticket, 'open', $this->user['id'], 'user');
        $this->flash('工单已重开', 'success');

        return $this->redirectTo('index/ticket/detail', array('id' => $ticket['id']));
    }

    public function download()
    {
        $attachment = Db::name('ticket_attachment')->find((int)$this->get('id'));
        if (!$attachment) {
            $this->abort('附件不存在', 404);
        }
        $ticket = $this->own((int)$attachment['ticket_id'], 'seller');
        $file = DATA_PATH . 'tickets/' . $attachment['path'] . '/' . $attachment['stored_name'];
        if (!is_file($file)) {
            $this->abort('附件不存在', 404);
        }
        $name = str_replace(array('"', "\r", "\n"), '', basename($attachment['original_name']));
        header('Content-Type: ' . ($attachment['mime'] ? $attachment['mime'] : 'application/octet-stream'));
        header('Content-Disposition: ' . (strpos($attachment['mime'], 'image/') === 0 ? 'inline' : 'attachment') . '; filename="' . $name . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        exit;
    }
}
