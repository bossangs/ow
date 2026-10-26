<?php

namespace app\index\controller;

use app\common\controller\FrontBase;
use app\common\service\DnsService;
use app\common\service\OrderAdminService;
use app\common\service\OrderService;
use app\common\service\InventoryService;
use think\Db;

class Dns extends FrontBase
{
    protected $needLogin = true;
    public function index()
    {
        $subs = Db::name('dns_subdomain')->where('user_id', $this->user['id'])->order('id desc')->select();
        foreach ($subs as &$s) {
            $s['domain'] = Db::name('dns_domain')->find($s['domain_id']);
            $s['record_count'] = Db::name('dns_record')->where('sub_id', $s['id'])->count();
        }

        return $this->fetch('dns/index', ['title' => '我的子域名', 'subs' => $subs, 'quota' => DnsService::quota($this->user), 'domains' => DnsService::availableDomains($this->user), 'cancelRequests' => Db::name('cancel_request')->where('user_id', $this->user['id'])->where('type', 'sub')->where('status', 'pending')->select()]);
    }
    public function register()
    {
        $domain = Db::name('dns_domain')->where('id', (int)$this->get('domain_id'))->where('status', 1)->where('listing_status', 'active')->find();
        if (!$domain) {
            return $this->abort('域名不存在或暂不可用', 404);
        } $available = null;
        if ($this->request->isPost()) {
            try {
                $available = DnsService::checkAvailable($domain, $this->post('prefix'), false);
                if ((float)$domain['price'] > 0) {
                    $o = OrderService::create($this->user, 'dns_sub_new', '注册 ' . $available['fqdn'], ['domain_id' => $domain['id'], 'prefix' => $available['prefix']], $domain['price'], true, trim($this->post('coupon_code')));

                    return $this->redirectTo('index/pay/index', ['id' => $o['id']]);
                } InventoryService::check('domain', $domain['id'], $this->user['id']);
                DnsService::registerSub($this->user, $domain['id'], $available['prefix'], false);
                $this->flash('子域名注册成功');

                return $this->redirectTo('index/dns/index');
            } catch (\Exception $e) {
                $this->flash($e->getMessage(), 'danger');
            }
        }

        return $this->fetch('dns/register', ['title' => '注册子域名', 'domain' => $domain, 'available' => $available, 'availableCount' => InventoryService::available('domain', $domain['id'])]);
    }
    public function check()
    {
        $d = Db::name('dns_domain')->where('id', (int)$this->get('domain_id'))->where('status', 1)->where('listing_status', 'active')->find();
        if (!$d) {
            return $this->fail('域名不存在');
        }try {
            return $this->ok('前缀可用', DnsService::checkAvailable($d, $this->get('prefix'), false));
        } catch (\Exception $e) {
            return $this->fail($e->getMessage());
        }
    }
    protected function ownedSub($id)
    {
        $s = Db::name('dns_subdomain')->where('id', (int)$id)->where('user_id', $this->user['id'])->find();
        if (!$s) {
            $this->abort('子域名不存在', 404);
        }

        return $s;
    }
    public function records()
    {
        $sub = $this->ownedSub($this->get('id'));
        $domain = Db::name('dns_domain')->find($sub['domain_id']);
        $records = Db::name('dns_record')->where('sub_id', $sub['id'])->order('id desc')->select();
        $protected = Db::name('dns_protected')->where('domain_id', $domain['id'])->order('id asc')->select();
        if ($this->request->isPost()) {
            try {
                $d = $this->post();
                $args = [trim($d['host']), strtoupper(trim($d['type'])), trim($d['value']), trim($d['line'] ?: 'default'), (int)$d['ttl'], (int)$d['mx'], false, !empty($d['proxied'])];
                if (!empty($d['record_id'])) {
                    DnsService::updateRecord($this->user, (int)$d['record_id'], $args[0], $args[1], $args[2], $args[3], $args[4], $args[5], false, $args[7]);
                } else {
                    DnsService::addRecord($this->user, $sub['id'], $args[0], $args[1], $args[2], $args[3], $args[4], $args[5], false, $args[7]);
                }$this->flash('解析记录已保存');

                return $this->redirectTo('index/dns/records', ['id' => $sub['id']]);
            } catch (\Exception $e) {
                $this->flash($e->getMessage(), 'danger');
            }
        }

        return $this->fetch('dns/records', ['title' => '解析记录', 'sub' => $sub, 'domain' => $domain, 'records' => $records, 'protected' => $protected, 'types' => DnsService::allowedTypes($this->user, $domain), 'lineEnabled' => DnsService::lineAllowed($this->user, $domain), 'proxyEnabled' => DnsService::proxyAllowed($this->user, $domain), 'minTtl' => (int)setting('dns_min_ttl', '600')]);
    }
    public function deleteRecord()
    {
        if ($this->request->isPost()) {
            try {
                DnsService::deleteRecord($this->user, (int)$this->post('id'), false);
                $this->flash('记录已删除');
            } catch (\Exception $e) {
                $this->flash($e->getMessage(), 'danger');
            }
        }

        return $this->back();
    }
    public function renew()
    {
        $sub = $this->ownedSub($this->post('id', $this->get('id')));
        $domain = Db::name('dns_domain')->find($sub['domain_id']);
        $price = (float)$domain['price'];
        if ($this->request->isPost()) {
            try {
                $o = OrderService::create($this->user, 'dns_sub_renew', '续期 ' . $sub['fqdn'], ['sub_id' => $sub['id']], $price, true, trim($this->post('coupon_code')));

                return $this->redirectTo('index/pay/index', ['id' => $o['id']]);
            } catch (\Exception $e) {
                $this->flash($e->getMessage(), 'danger');
            }
        }

        return $this->fetch('dns/renew', ['title' => '续期子域名', 'sub' => $sub, 'domain' => $domain]);
    }
    public function release()
    {
        if ($this->request->isPost()) {
            try {
                $s = $this->ownedSub($this->post('id'));
                DnsService::releaseSub($s['id']);
                $this->flash('子域名已释放');
            } catch (\Exception $e) {
                $this->flash($e->getMessage(), 'danger');
            }
        }

        return $this->back('index/dns/index');
    }
    public function cancel()
    {
        if ($this->request->isPost()) {
            try {
                $this->ownedSub($this->post('target_id'));
                OrderAdminService::requestCancel($this->user, 'sub', (int)$this->post('target_id'), $this->post('mode', 'end'), $this->post('reason'));
                $this->flash('取消申请已提交');
            } catch (\Exception $e) {
                $this->flash($e->getMessage(), 'danger');
            }
        }

        return $this->back();
    }
    public function withdrawCancel()
    {
        if ($this->request->isPost()) {
            try {
                OrderAdminService::withdrawRequest($this->user, (int)$this->post('id'));
                $this->flash('取消申请已撤回');
            } catch (\Exception $e) {
                $this->flash($e->getMessage(), 'danger');
            }
        }

        return $this->back();
    }
}
