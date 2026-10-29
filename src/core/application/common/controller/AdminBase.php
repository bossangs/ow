<?php

namespace app\common\controller;

use app\common\service\TwoFactorService;
use think\Db;

abstract class AdminBase extends Base
{
    protected $admin = null;
    protected $needLogin = true;
    /**
     * 需要“二次验证”（重新输入谷歌验证码）的动作：'edit' 表示任何请求，'edit:post' 只在提交时。
     * 只对已开启两步验证的管理员生效；验证通过后若干分钟内（默认 5）不再重复询问。
     */
    protected $sudoActions = [];
    /** 只有超级管理员能进入的控制器 */
    protected $superOnly = false;
    protected $table = '';
    protected $title = '';
    protected $order = 'id desc';
    protected $searchFields = [];

    protected function initialize()
    {
        $aid = (int) session('admin_id');
        if ($aid) {
            $a = Db::name('admin')->find($aid);
            if ($a && (int) $a['status'] === 1 && session('admin_pwd_sig') === substr(sha1($a['password']), 0, 16)) {
                $this->admin = $a;
            } else {
                session('admin_id', null);
            }
        }
        // 后台访问时自动补齐所有未完成的数据库升级（包括登录页与新增版本迁移）。
        require_once CORE_PATH . 'install/upgrade.php';
        if ((int) setting('schema_version', '1') < IDC_SCHEMA_VERSION) {
            idc_schema_upgrade();
        }
        if ($this->needLogin && !$this->admin) {
            if ($this->request->isAjax()) {
                $this->abort('请先登录后台', 401);
            }
            throw new \think\exception\HttpResponseException($this->redirectTo('admin/login/index'));
        }
        if ($this->admin) {
            $this->enforceTwofaBinding();
            if ($this->superOnly && !$this->isSuper()) {
                $this->abort('只有超级管理员可以进行此操作', 403);
            }
            $act = strtolower($this->request->action());
            foreach ($this->sudoActions as $rule) {
                $parts = explode(':', strtolower($rule));
                if ($parts[0] === $act && (!isset($parts[1]) || ($parts[1] === 'post' && $this->request->isPost()))) {
                    $this->requireSudo();
                }
            }
        }
        $this->assign([
            'admin' => $this->admin,
            'siteName' => setting('site_name', 'IDC 云'),
            'navActive' => strtolower($this->request->controller()),
            'pageTitle' => $this->title,
            'pendingCounts' => $this->admin ? $this->pendingCounts() : [],
        ]);
    }

    protected function isSuper()
    {
        return $this->admin && !empty($this->admin['is_super']);
    }

    /** 后台开启“所有管理员必须两步验证”时，没绑定的管理员只能访问绑定页 */
    protected function enforceTwofaBinding()
    {
        if (setting('admin_2fa_required', '0') !== '1' || TwoFactorService::enabled($this->admin)) {
            return;
        }
        $ctrl = strtolower($this->request->controller());
        $act = strtolower($this->request->action());
        if ($ctrl === 'profile' || ($ctrl === 'login' && in_array($act, ['logout', 'captcha'], true)) || $ctrl === 'logout') {
            return;
        }
        if ($this->request->isAjax()) {
            $this->abort('系统要求所有管理员开启两步验证，请先在「个人资料 → 两步验证」完成绑定', 403);
        }
        $this->flash('系统要求所有管理员开启两步验证（谷歌验证器），请先完成绑定后再使用后台', 'warning');
        throw new \think\exception\HttpResponseException($this->redirectTo('admin/profile/twofa'));
    }

    /** 二次验证是否仍在有效期内（未开启两步验证的管理员视为通过） */
    protected function sudoValid()
    {
        return !TwoFactorService::enabled($this->admin) || (int) session('admin_sudo_until') >= time();
    }

    /** 要求在几分钟内输入过谷歌验证码，否则跳转到验证页，验证后回到原页面 */
    protected function requireSudo()
    {
        if ($this->sudoValid()) {
            return;
        }
        $back = $this->request->isPost() ? $this->refererPath() : $this->request->url();
        session('admin_sudo_back', $back);
        if ($this->request->isAjax()) {
            $this->abort('该操作需要先输入谷歌验证码确认身份，请刷新页面', 403);
        }
        if ($this->request->isPost()) {
            $this->flash('为了安全，请先输入谷歌验证码确认身份，然后重新提交刚才的操作', 'warning');
        }
        throw new \think\exception\HttpResponseException($this->redirectTo('admin/login/sudo'));
    }

    /** 同站来源页的路径部分（/index.php?s=...），用于验证后返回 */
    protected function refererPath()
    {
        $ref = isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : '';
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
        if ($ref === '' || parse_url($ref, PHP_URL_HOST) !== parse_url('http://' . $host, PHP_URL_HOST)) {
            return '';
        }
        $path = (string) parse_url($ref, PHP_URL_PATH);
        $q = (string) parse_url($ref, PHP_URL_QUERY);

        return ($path === '' ? '/' : $path) . ($q !== '' ? '?' . $q : '');
    }

    protected function pendingCounts()
    {
        try {
            return [
                'sub_review' => Db::name('dns_subdomain')->where('status', 'review')->count(),
                'rec_review' => Db::name('dns_record')->where('status', 'review')->count(),
                'ticket' => Db::name('ticket')->where('status', 'in', ['open', 'replied'])->count(),
                'failed' => Db::name('service')->where('status', 'failed')->count(),
                'manual_pay' => Db::name('payment')->where('gateway', 'manual')->where('status', 0)->where('remark', '<>', '')->count(),
                'cancelrequest' => Db::name('cancel_request')->where('status', 'pending')->count(),
            ];
        } catch (\Exception $e) {
            return [];
        }
    }

    /** 是否允许新增 / 编辑 / 删除（只读列表如销售记录可关闭） */
    protected $canAdd = true;
    protected $canEdit = true;
    protected $canDelete = true;

    protected function fields()
    {
        return [];
    }

    protected function beforeSave(array $data, $id)
    {
        return $data;
    }

    protected function afterSave($id, array $data)
    {
    }

    protected function beforeDelete($id)
    {
    }

    protected function rowActions()
    {
        return [];
    }

    protected function topActions()
    {
        return [];
    }

    protected function ctrlName()
    {
        return \think\Loader::parseName($this->request->controller());
    }

    protected function listQuery()
    {
        $q = Db::name($this->table);
        $kw = trim((string) $this->request->get('kw', ''));
        if ($kw !== '' && $this->searchFields) {
            $q->where(implode('|', $this->searchFields), 'like', '%' . $kw . '%');
        }

        return $q->order($this->order);
    }

    /** 列表行加工（子类可补充关联名称等） */
    protected function decorateList(array $list)
    {
        return $list;
    }

    public function index()
    {
        list($list, $total, $page, $size) = $this->paginate($this->listQuery(), 20);

        return $this->fetch('common/crud_list', [
            'fields' => $this->fields(),
            'list' => $this->decorateList($list),
            'total' => $total,
            'page' => $page,
            'size' => $size,
            'ctrl' => $this->ctrlName(),
            'search' => !empty($this->searchFields),
            'rowActions' => $this->rowActions(),
            'topActions' => $this->topActions(),
            'canAdd' => $this->canAdd,
            'canEdit' => $this->canEdit,
            'canDelete' => $this->canDelete,
        ]);
    }

    /** 读取旧的加密配置（用于敏感字段留空不修改） */
    protected function oldConfig($key, $id)
    {
        if (!$id) {
            return [];
        }

        return decrypt_json((string) Db::name($this->table)->where('id', $id)->value($key));
    }

    /** driver-config 字段：根据当前驱动取字段定义 */
    protected function driverOf(array $f, $id)
    {
        if (!empty($f['driver'])) {
            return $f['driver'];
        }
        $field = isset($f['driver_field']) ? $f['driver_field'] : 'driver';
        $v = (string) $this->request->post($field, '');
        if ($v === '' && $id) {
            $v = (string) Db::name($this->table)->where('id', $id)->value($field);
        }

        return $v;
    }

    protected function normalizeValue($key, $f, $id)
    {
        $type = isset($f['type']) ? $f['type'] : 'text';
        if ($type === 'html' || !empty($f['readonly'])) {
            return null;
        }
        $v = $this->request->post($key, isset($f['default']) ? $f['default'] : '');
        if ($type === 'switch' || $type === 'checkbox') {
            return $this->request->post($key) ? 1 : 0;
        }
        if ($type === 'multiselect') {
            return implode(',', array_map('strval', (array) $this->request->post($key . '/a', [])));
        }
        if ($type === 'select' && isset($f['options']) && $v !== '' && !array_key_exists((string) $v, (array) $f['options'])) {
            throw new \Exception($f[0] . '的取值无效');
        }
        if ($type === 'json-kv') {
            if (is_array($v)) {
                return json_encode($v, JSON_UNESCAPED_UNICODE);
            }
            if (trim((string) $v) === '') {
                return '{}';
            }
            $decoded = json_decode((string) $v, true);
            if (!is_array($decoded)) {
                throw new \Exception($f[0] . '必须是有效 JSON');
            }

            return json_encode($decoded, JSON_UNESCAPED_UNICODE);
        }
        if ($type === 'pricing') {
            // 每个付款周期一个输入框；留空 = 不提供该周期
            $out = [];
            foreach (billing_cycles() as $c => $info) {
                $x = trim((string) $this->request->post($key . '_' . $c, ''));
                if ($x === '') {
                    continue;
                }
                if (!is_numeric($x) || (float) $x < 0) {
                    throw new \Exception($info['name'] . '价格必须是不小于 0 的数字');
                }
                $out[$c] = round((float) $x, 2);
            }

            return json_encode($out, JSON_UNESCAPED_UNICODE);
        }
        if ($type === 'lines') {
            // 每行一项，保存为 JSON 数组
            $items = [];
            foreach (preg_split('/\r\n|\r|\n/', (string) $v) as $line) {
                $line = trim($line);
                if ($line !== '' && !in_array($line, $items, true)) {
                    $items[] = $line;
                }
            }

            return json_encode($items, JSON_UNESCAPED_UNICODE);
        }
        if ($type === 'driver-config') {
            $driver = $this->driverOf($f, $id);
            $drivers = (array) $f['drivers'];
            if (!isset($drivers[$driver])) {
                throw new \Exception('请选择驱动');
            }
            $old = $this->oldConfig($key, $id);
            // 切换驱动时旧密钥不沿用
            $oldDriver = $id && empty($f['driver'])
                ? (string) Db::name($this->table)->where('id', $id)->value(isset($f['driver_field']) ? $f['driver_field'] : 'driver')
                : $driver;
            $sameDriver = $oldDriver === $driver;
            // 驱动声明了 manual_flag（如 NAT 节点由系统自动填写）：未勾选「手动填写」时保留原配置，不校验必填
            if (!empty($drivers[$driver]['manual_flag']) && !$this->request->post($drivers[$driver]['manual_flag'])) {
                return encrypt_json($sameDriver ? $old : []);
            }
            $out = [];
            foreach ((array) $drivers[$driver]['fields'] as $df) {
                $fk = $df['key'];
                $x = $this->request->post('cfg_' . $driver . '_' . $fk, '');
                $x = is_array($x) ? implode(',', $x) : trim((string) $x);
                if (!empty($df['secret']) && $x === '' && isset($old[$fk]) && $sameDriver) {
                    $x = $old[$fk]; // 敏感字段留空 = 不修改
                }
                if (!empty($df['required']) && $x === '') {
                    throw new \Exception($df['label'] . '不能为空');
                }
                if (isset($df['type']) && $df['type'] === 'number' && $x !== '' && !is_numeric($x)) {
                    throw new \Exception($df['label'] . '必须是数字');
                }
                $out[$fk] = $x;
            }

            return encrypt_json($out);
        }
        if ($type === 'lifecycle') {
            $out = [];
            foreach (\app\common\service\LifecycleService::overrideFields() as $lk => $lf) {
                $x = $this->request->post($key . '_' . $lk, '');
                $out[$lk] = $x === '' ? '' : (string) $x;
            }

            return json_encode($out, JSON_UNESCAPED_UNICODE);
        }
        if ($type === 'number') {
            return is_numeric($v) ? $v + 0 : 0;
        }
        if ($type === 'datetime') {
            $v = trim((string) $v);

            return $v === '' ? 0 : (int) strtotime($v);
        }
        if ($type === 'password') {
            if ((string) $v === '') {
                return null;
            }

            return encrypt_str((string) $v);
        }

        return trim((string) $v);
    }

    public function edit()
    {
        if (!$this->canEdit && !$this->canAdd) {
            $this->abort('此记录为只读', 403);
        }
        $id = (int) $this->request->param('id', 0);
        if (($id && !$this->canEdit) || (!$id && !$this->canAdd)) {
            $this->abort('此记录不允许' . ($id ? '编辑' : '新增'), 403);
        }
        $row = $id ? Db::name($this->table)->find($id) : null;
        if ($id && !$row) {
            $this->abort('记录不存在', 404);
        }
        $fields = $this->fields();
        if ($this->request->isPost()) {
            $data = [];
            try {
                foreach ($fields as $k => $f) {
                    $v = $this->normalizeValue($k, $f, $id);
                    if ($v === null) {
                        continue;
                    }
                    if (!empty($f['required']) && $v === '') {
                        throw new \Exception($f[0] . '不能为空');
                    }
                    $data[$k] = $v;
                }
                $data = $this->beforeSave($data, $id);
                if ($id) {
                    Db::name($this->table)->where('id', $id)->update($data);
                } else {
                    $id = Db::name($this->table)->insertGetId($data);
                }
                $this->afterSave($id, $data);
                $logData = array_diff_key($data, array_flip(['password', 'config']));
                audit('admin.' . $this->table . '.save', $this->title . '#' . $id, $logData);
                $this->flash('保存成功');

                return $this->redirectTo('admin/' . $this->ctrlName() . '/index');
            } catch (\Exception $e) {
                $this->flash($e->getMessage(), 'danger');

                return $this->back();
            }
        }

        return $this->fetch('common/crud_form', [
            'fields' => $fields,
            'row' => $row,
            'id' => $id,
            'ctrl' => $this->ctrlName(),
        ]);
    }

    public function delete()
    {
        if (!$this->request->isPost()) {
            $this->abort('请求方式错误', 405);
        }
        if (!$this->canDelete) {
            $this->abort('此记录不允许删除', 403);
        }
        $id = (int) $this->request->post('id');
        try {
            $this->beforeDelete($id);
            Db::name($this->table)->where('id', $id)->delete();
        } catch (\Exception $e) {
            $this->flash($e->getMessage(), 'danger');

            return $this->back();
        }
        audit('admin.' . $this->table . '.delete', $this->title . '#' . $id, '', 'warn');
        $this->flash('已删除');

        return $this->back();
    }
}
