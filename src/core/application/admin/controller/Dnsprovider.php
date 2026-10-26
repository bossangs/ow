<?php

namespace app\admin\controller;

use app\common\dns\DnsFactory;
use think\Db;

/**
 * DNS 服务商账号（14 家）
 * 平台账号 user_id = 0；用户在域名市场发布自己的域名时会生成 user_id > 0 的记录
 */
class Dnsprovider extends Crud
{
    /** 修改密钥/接口配置、审批等敏感操作需要二次验证（已开启两步验证的管理员） */
    protected $sudoActions = ['edit:post', 'delete'];
    protected $table = 'dns_provider';
    protected $title = 'DNS 服务商';
    protected $searchFields = ['name', 'driver', 'remark'];

    protected function fields()
    {
        $drivers = DnsFactory::infoAll();
        $opts = [];
        foreach ($drivers as $code => $info) {
            $opts[$code] = $info['name'] . (!empty($info['proxy']) ? '（支持 CDN 代理）' : '');
        }

        return [
            'name' => ['名称', 'type' => 'text', 'required' => true, 'help' => '仅后台显示，如「DNSPod 主账号」'],
            'driver' => ['服务商', 'type' => 'select', 'required' => true, 'options' => $opts],
            'config' => ['API 凭据', 'type' => 'driver-config', 'list' => false, 'drivers' => $drivers],
            'user_id' => ['所属', 'type' => 'number', 'readonly' => true],
            'status' => ['启用', 'type' => 'switch', 'default' => 1],
            'last_test_msg' => ['最近测试结果', 'type' => 'text', 'readonly' => true],
            'remark' => ['备注', 'type' => 'text'],
        ];
    }

    protected function beforeSave(array $d, $id)
    {
        if (!$id) {
            $d['create_time'] = time();
            $d['user_id'] = 0;
        }

        return $d;
    }

    protected function decorateList(array $list)
    {
        return $this->userLabels($list);
    }

    protected function beforeDelete($id)
    {
        $n = Db::name('dns_domain')->where('provider_id', $id)->count();
        if ($n) {
            throw new \Exception('还有 ' . $n . ' 个根域名使用该服务商，请先删除或迁移这些域名');
        }
    }

    protected function loadRow()
    {
        $r = Db::name($this->table)->find((int) $this->request->post('id'));
        if (!$r) {
            throw new \Exception('服务商不存在');
        }

        return $r;
    }

    public function test()
    {
        if (!$this->request->isPost()) {
            $this->abort('请求方式错误', 405);
        }
        try {
            $r = $this->loadRow();
            try {
                $x = DnsFactory::make($r['driver'], decrypt_json($r['config']))->check();
                $msg = is_array($x) && isset($x['message']) ? $x['message'] : '连接成功';
                $ok = true;
            } catch (\Exception $e) {
                $msg = '失败：' . $e->getMessage();
                $ok = false;
            }
            Db::name($this->table)->where('id', $r['id'])->update(['last_test_time' => time(), 'last_test_msg' => mb_substr($msg, 0, 250)]);
            $this->flash(($ok ? '测试连接成功：' : '测试') . $msg, $ok ? 'success' : 'danger');
        } catch (\Exception $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        return $this->back();
    }

    /** 拉取账号下的域名，作为「未上架」根域名加入，管理员设置价格规则后上架 */
    public function syncDomains()
    {
        if (!$this->request->isPost()) {
            $this->abort('请求方式错误', 405);
        }
        try {
            $r = $this->loadRow();
            $items = DnsFactory::make($r['driver'], decrypt_json($r['config']))->getDomains();
            $added = 0;
            $updated = 0;
            foreach ((array) $items as $x) {
                $name = isset($x['domain']) ? strtolower(trim($x['domain'], '. ')) : '';
                if ($name === '') {
                    continue;
                }
                $remoteId = (string) (isset($x['id']) ? $x['id'] : '');
                $exists = Db::name('dns_domain')->where('domain', $name)->find();
                if ($exists) {
                    if ((int) $exists['provider_id'] === (int) $r['id'] && $exists['remote_id'] !== $remoteId) {
                        Db::name('dns_domain')->where('id', $exists['id'])->update(['remote_id' => $remoteId]);
                        $updated++;
                    }
                    continue;
                }
                Db::name('dns_domain')->insert([
                    'provider_id' => $r['id'],
                    'user_id' => 0,
                    'domain' => $name,
                    'remote_id' => $remoteId,
                    'listing_status' => 'active',
                    'verified' => 1,
                    'status' => 0,
                    'types' => 'A,AAAA,CNAME,TXT',
                    'period_days' => 365,
                    'price' => 0,
                    'reserved' => "www\nmail\nadmin\nns1\nns2",
                    'create_time' => time(),
                ]);
                $added++;
                // 把根域名下已有的解析记录登记为受保护，用户不能注册同名前缀或改动它们
                try {
                    $row = Db::name('dns_domain')->where('domain', $name)->find();
                    \app\common\service\MarketService::snapshot($row);
                } catch (\Exception $e) {
                    \think\facade\Log::record('同步后记录保护快照失败：' . $name . ' ' . $e->getMessage(), 'error');
                }
            }
            audit('admin.dns_provider.sync', '服务商#' . $r['id'], ['added' => $added, 'updated' => $updated]);
            $this->flash('同步完成：新增 ' . $added . ' 个根域名（默认未启用，请在「根域名」设置价格后启用），更新 ' . $updated . ' 个');
        } catch (\Exception $e) {
            $this->flash('同步失败：' . $e->getMessage(), 'danger');
        }

        return $this->back();
    }

    protected function rowActions()
    {
        return [
            ['label' => '测试连接', 'post' => true, 'url' => function ($r) {
                return url_to('admin/dnsprovider/test');
            }],
            ['label' => '同步根域名', 'post' => true, 'url' => function ($r) {
                return url_to('admin/dnsprovider/syncDomains');
            }],
        ];
    }
}
