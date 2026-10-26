<?php

namespace app\index\controller;

use app\common\controller\FrontBase;
use app\common\service\DnsService;
use app\common\service\ProvisionService;
use think\Db;

class Index extends FrontBase
{
    protected $needLogin = false;

    public function index()
    {
        $groups = Db::name('product_group')->where('status', 1)->order('sort asc,id asc')->select();
        $products = Db::name('product')->where('status', 1)->order('sort asc,id asc')->select();
        foreach ($products as &$product) {
            $prices = ProvisionService::pricing($product);
            $product['_prices'] = $prices;
            $product['_min'] = $prices ? min($prices) : 0;
        }
        unset($product);
        $domains = $this->user ? DnsService::availableDomains($this->user) : Db::name('dns_domain')
            ->where('status', 1)->where('listing_status', 'active')->order('sort asc,id asc')->select();
        $packages = Db::name('dns_package')->where('status', 1)->order('sort asc,id asc')->select();
        $news = Db::name('announcement')->where('status', 1)->order('sort asc,id desc')->limit(6)->select();

        return $this->fetch('index/index', compact('groups', 'products', 'domains', 'packages', 'news'));
    }

    public function announce()
    {
        $news = Db::name('announcement')->where('status', 1)->order('sort asc,id desc')->select();

        return $this->fetch('index/announce', ['title' => '公告', 'news' => $news]);
    }

    public function article()
    {
        $item = Db::name('announcement')->where('id', (int)$this->get('id'))
            ->where('status', 1)->find();
        if (!$item) {
            return $this->abort('公告不存在', 404);
        }

        return $this->fetch('index/text', ['title' => $item['title'], 'text' => $item['content']]);
    }

    public function terms()
    {
        return $this->fetch('index/text', [
            'title' => '服务条款',
            'text' => setting('terms_content', '请遵守平台服务条款。'),
        ]);
    }

    public function help()
    {
        return $this->fetch('index/text', [
            'title' => '帮助中心',
            'text' => setting('help_content', '如需帮助，请提交工单联系我们。'),
        ]);
    }

    public function abuse()
    {
        if ($this->request->isPost()) {
            try {
                Db::name('abuse_report')->insert([
                    'target' => mb_substr(trim($this->post('target')), 0, 190),
                    'reason' => mb_substr(trim($this->post('reason')), 0, 1000),
                    'contact' => mb_substr(trim($this->post('contact')), 0, 120),
                    'ip' => client_ip(),
                    'create_time' => time(),
                ]);
                $this->flash('举报已提交，我们会尽快处理。');
            } catch (\Exception $e) {
                $this->flash($e->getMessage(), 'danger');
            }

            return $this->back();
        }

        return $this->fetch('index/abuse', ['title' => '滥用举报']);
    }
}
