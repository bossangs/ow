<?php

namespace app\admin\controller;

use app\common\controller\AdminBase;
use app\common\service\CouponService;
use think\Db;

class Coupon extends AdminBase
{
    protected $title = '优惠码';

    protected function opts($table, $field, $label)
    {
        $out = [];
        foreach (Db::name($table)->order('id desc')->select() as $r) {
            $out[$r['id']] = $label === 'domain' ? $r['domain'] : $r['name'];
        }

        return $out;
    }

    protected function dataFields()
    {
        return [
            'code' => ['代码', 'text', 'required' => true], 'name' => ['名称', 'text'],
            'type' => ['类型', 'select', ['percent' => '百分比减免', 'fixed' => '固定金额']], 'value' => ['优惠值', 'number', 'required' => true],
            'scenes' => ['场景', 'multiselect', CouponService::scenes()], 'item_types' => ['商品类型', 'multiselect', CouponService::itemTypes()],
            'product_ids' => ['指定产品', 'multiselect', $this->opts('product', 'name', 'name')], 'group_ids' => ['指定分组', 'multiselect', $this->opts('product_group', 'name', 'name')],
            'package_ids' => ['指定 DNS 套餐', 'multiselect', $this->opts('dns_package', 'name', 'name')], 'domain_ids' => ['指定域名', 'multiselect', $this->opts('dns_domain', 'domain', 'domain')],
            'tier_ids' => ['指定会员等级', 'multiselect', $this->opts('user_tier', 'name', 'name')],
            'min_amount' => ['最低金额', 'number'], 'max_discount' => ['优惠封顶', 'number'], 'total_limit' => ['总次数上限', 'number'], 'per_user_limit' => ['每人次数上限', 'number'],
            'new_user_only' => ['仅新用户', 'switch'], 'new_user_days' => ['新用户天数', 'number'], 'first_order_only' => ['仅首单', 'switch'],
            'start_time' => ['开始时间（留空=立即）', 'datetime'], 'end_time' => ['结束时间（留空=不限）', 'datetime'], 'status' => ['状态', 'switch'], 'note' => ['备注', 'text']
        ];
    }
    protected function normalize($k, $f)
    {
        $t = isset($f[1]) ? $f[1] : 'text';
        if ($t === 'multiselect') {
            return implode(',', array_map('intval', (array)$this->request->post($k . '/a', [])));
        }
        if ($t === 'switch') {
            return $this->request->post($k) ? 1 : 0;
        }
        if ($t === 'datetime') {
            $v = trim((string)$this->request->post($k, ''));
            $ts = $v === '' ? 0 : strtotime(str_replace('T', ' ', $v));

            return $ts ? (int)$ts : 0;
        }

        return $t === 'number' ? (float)$this->request->post($k, 0) : trim((string)$this->request->post($k, ''));
    }
    public function index()
    {
        $q = Db::name('coupon');
        $kw = trim((string)$this->request->get('kw', ''));
        if ($kw !== '') {
            $q->where('code|name', 'like', '%' . $kw . '%');
        }
        $status = $this->request->get('status', '');
        if ($status !== '') {
            $q->where('status', (int)$status);
        }
        list($list, $total, $page, $size) = $this->paginate($q->order('id desc'), 20);

        return $this->fetch('coupon/index', compact('list', 'total', 'page', 'size', 'kw', 'status'));
    }
    public function edit()
    {
        $id = (int)$this->request->param('id', 0);
        $row = $id ? Db::name('coupon')->find($id) : [];
        if ($id && !$row) {
            $this->abort('记录不存在', 404);
        }
        $defs = $this->dataFields();
        if ($this->request->isPost()) {
            try {
                $d = [];
                foreach ($defs as $k => $f) {
                    $d[$k] = $this->normalize($k, $f);
                } $d['code'] = strtoupper($d['code']);
                if (!preg_match('/^[A-Z0-9_-]{3,40}$/', $d['code'])) {
                    throw new \Exception('代码须为 3-40 位大写字母、数字、下划线或短横线');
                } if (Db::name('coupon')->where('code', $d['code'])->where('id', '<>', (int)$id)->count()) {
                    throw new \Exception('代码已存在');
                } $d['update_time'] = time();
                if ($id) {
                    Db::name('coupon')->where('id', $id)->update($d);
                } else {
                    $d['create_time'] = time();
                    $id = Db::name('coupon')->insertGetId($d);
                } audit('admin.coupon.save', '优惠码#' . $id, $d);
                $this->flash('保存成功');

                return $this->redirectTo('admin/coupon/index');
            } catch (\Exception $e) {
                $this->flash($e->getMessage(), 'danger');
            }
        }

        return $this->fetch('coupon/form', ['defs' => $defs, 'row' => $row, 'id' => $id]);
    }
    public function batch()
    {
        if (!$this->request->isPost()) {
            return $this->fetch('coupon/batch');
        }
        try {
            $prefix = strtoupper(trim($this->request->post('prefix', '')));
            $n = (int)$this->request->post('count', 0);
            if ($n < 1 || $n > 500) {
                throw new \Exception('数量须为 1-500');
            }if ($prefix !== '' && !preg_match('/^[A-Z0-9_-]{1,20}$/', $prefix)) {
                throw new \Exception('前缀格式不正确');
            }$rows = [];
            $seen = [];
            for ($i = 0;$i < $n;$i++) {
                do {
                    $code = CouponService::randomCode($prefix, 8);
                } while (isset($seen[$code]) || Db::name('coupon')->where('code', $code)->count());
                $seen[$code] = 1;
                $rows[] = ['code' => $code, 'name' => $this->request->post('name', '批量生成'), 'type' => 'percent', 'value' => 0, 'scenes' => 'new', 'item_types' => '', 'status' => 1, 'per_user_limit' => 1, 'create_time' => time(), 'update_time' => time()];
            }Db::name('coupon')->insertAll($rows);
            audit('admin.coupon.batch', '批量生成优惠码', ['count' => $n, 'prefix' => $prefix]);
            $this->flash('已生成 ' . $n . ' 个优惠码');

            return $this->redirectTo('admin/coupon/index');
        } catch (\Exception $e) {
            $this->flash($e->getMessage(), 'danger');

            return $this->back();
        }
    }
    public function detail()
    {
        $id = (int)$this->request->param('id');
        $coupon = Db::name('coupon')->find($id);
        if (!$coupon) {
            $this->abort('记录不存在', 404);
        }$stats = CouponService::stats($id);
        $uses = Db::name('coupon_use')->where('coupon_id', $id)->order('id desc')->select();

        return $this->fetch('coupon/detail', compact('coupon', 'stats', 'uses'));
    }
    public function toggle()
    {
        if (!$this->request->isPost()) {
            $this->abort('请求方式错误', 405);
        }$id = (int)$this->request->post('id');
        $status = (int)$this->request->post('status');
        Db::name('coupon')->where('id', $id)->update(['status' => $status, 'update_time' => time()]);
        audit('admin.coupon.toggle', '优惠码#' . $id, ['status' => $status]);
        $this->flash($status ? '已启用' : '已停用');

        return $this->back();
    }
    public function delete()
    {
        if (!$this->request->isPost()) {
            $this->abort('请求方式错误', 405);
        }$id = (int)$this->request->post('id');
        if (Db::name('coupon_use')->where('coupon_id', $id)->where('status', 'used')->count()) {
            $this->flash('已有已使用记录，不能删除，请停用优惠码', 'warning');

            return $this->back();
        }Db::name('coupon')->where('id', $id)->delete();
        audit('admin.coupon.delete', '优惠码#' . $id, '', 'warn');
        $this->flash('已删除');

        return $this->back();
    }
}
