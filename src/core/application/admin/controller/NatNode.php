<?php
namespace app\admin\controller;

use app\common\controller\AdminBase;
use app\common\nat\NatScript;
use app\common\service\NatNodeService;
use think\Db;

/**
 * NAT 节点：添加节点 → 生成一键安装命令 → 节点注册后自动接入上游。
 * 令牌、过期、状态、IP、心跳、上游关联都由系统维护，后台只读显示。
 */
class NatNode extends AdminBase
{
    /** 新建/修改节点、重新生成令牌、删除需要二次验证（已开启两步验证的管理员） */
    protected $sudoActions = ['edit:post', 'quick', 'regen', 'delete'];
    protected $table = 'nat_node';
    protected $title = 'NAT 节点';

    private function node($id)
    {
        $n = $id ? Db::name('nat_node')->find((int) $id) : null;
        if (!$n) {
            $this->abort('节点不存在', 404);
        }
        return $n;
    }

    private function decorate(array $n)
    {
        $n['state'] = NatNodeService::state($n);
        list($used, $max, $range) = NatNodeService::capacity($n);
        $n['cap_used'] = $used;
        $n['cap_max'] = $max;
        $n['port_range'] = $range;
        $o = NatNodeService::options($n);
        $n['agent_url'] = isset($o['agent_url']) ? $o['agent_url'] : '';
        $n['opts'] = $o;
        $n['upstream_name'] = $n['upstream_id'] ? (string) Db::name('upstream')->where('id', $n['upstream_id'])->value('name') : '';
        return $n;
    }

    public function index()
    {
        $kw = trim((string) $this->request->get('kw', ''));
        $q = Db::name('nat_node')->order('id desc');
        if ($kw !== '') {
            $q->where('name|public_ip|public_ipv6', 'like', '%' . $kw . '%');
        }
        $list = [];
        foreach ($q->limit(200)->select() as $n) {
            $list[] = $this->decorate($n);
        }
        return $this->fetch('nat_node/index', [
            'list' => $list, 'kw' => $kw, 'show' => (int) $this->request->get('show', 0),
        ]);
    }

    public function edit()
    {
        $id = (int) $this->request->param('id', 0);
        $row = $id ? $this->node($id) : null;
        $registered = $row && (int) $row['registered_time'] > 0;
        $opts = $row ? array_merge(NatNodeService::formDefaults(), array_intersect_key(NatNodeService::options($row), array_flip(NatScript::OPTION_KEYS))) : NatNodeService::formDefaults();
        $upstreamId = $row ? (int) $row['upstream_id'] : (int) $this->request->param('upstream_id', 0);
        if ($this->request->isPost()) {
            try {
                $p = $this->request->post();
                $name = trim((string) $this->request->post('name', ''));
                if ($name === '' || mb_strlen($name) > 100) {
                    throw new \Exception('请填写节点名称（100 字以内）');
                }
                $in = [];
                foreach (NatScript::OPTION_KEYS as $k) {
                    $in[$k] = isset($p[$k]) ? $p[$k] : '';
                }
                $in['images'] = (array) $this->request->post('images/a', []);
                $in['enable_ipv6'] = (string) $this->request->post('enable_ipv6', 'auto');
                $in['tls'] = $this->request->post('tls') ? 1 : 0;
                $clean = NatScript::normalize($in);
                if ($row) {
                    $data = ['name' => $name, 'options' => json_encode(array_merge(NatNodeService::options($row), $clean), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
                    if (!$registered) {
                        $up = (int) $this->request->post('upstream_id', 0);
                        if ($up && !array_key_exists($up, NatNodeService::freeUpstreams($id))) {
                            throw new \Exception('关联的上游无效或已被其他节点占用');
                        }
                        $data['upstream_id'] = $up;
                    }
                    Db::name('nat_node')->where('id', $id)->update($data);
                    audit('admin.nat_node.save', 'NAT节点#' . $id, $clean);
                    $this->flash($registered ? '已保存。节点已安装，新参数要在节点上重新运行安装命令后才会生效' : '已保存，安装命令已按新参数更新');
                    return $registered ? $this->redirectTo('admin/nat_node/index') : $this->redirectTo('admin/nat_node/command', ['id' => $id]);
                }
                $up = (int) $this->request->post('upstream_id', 0);
                if ($up && !array_key_exists($up, NatNodeService::freeUpstreams())) {
                    throw new \Exception('关联的上游无效或已被其他节点占用');
                }
                $newId = NatNodeService::create($name, $clean, $up, $this->request->post('token_hours', ''));
                audit('admin.nat_node.create', 'NAT节点#' . $newId, $clean + ['upstream_id' => $up]);
                $this->flash('节点已添加，复制下面的命令到节点服务器上运行即可');
                return $this->redirectTo('admin/nat_node/command', ['id' => $newId]);
            } catch (\Exception $e) {
                $this->flash($e->getMessage(), 'danger');
                return $this->back();
            }
        }
        return $this->fetch('nat_node/form', [
            'row' => $row ? $this->decorate($row) : null, 'id' => $id, 'opts' => $opts, 'registered' => $registered,
            'upstreamId' => $upstreamId, 'upstreams' => $registered ? [] : NatNodeService::freeUpstreams($id),
            'tokenHours' => NatNodeService::tokenHours(), 'defaultName' => NatNodeService::defaultName(),
        ]);
    }

    /** 安装命令（partial=1 时只输出弹窗内容） */
    public function command()
    {
        $n = $this->decorate($this->node((int) $this->request->get('id', 0)));
        $vars = ['n' => $n, 'info' => NatNodeService::commandInfo($n), 'partial' => (bool) $this->request->get('partial', 0)];
        return $vars['partial'] ? $this->fetch('nat_node/command', $vars, false) : $this->fetch('nat_node/command', $vars);
    }

    /** 快速添加：全部默认值，直接显示安装命令 */
    public function quick()
    {
        if (!$this->request->isPost()) {
            $this->abort('请求方式错误', 405);
        }
        try {
            $up = (int) $this->request->post('upstream_id', 0);
            if ($up && !array_key_exists($up, NatNodeService::freeUpstreams())) {
                throw new \Exception('关联的上游无效或已被其他节点占用');
            }
            $id = NatNodeService::quickCreate($up);
        } catch (\Exception $e) {
            $this->flash($e->getMessage(), 'danger');
            return $this->back();
        }
        audit('admin.nat_node.create', 'NAT节点#' . $id, ['quick' => 1, 'upstream_id' => $up]);
        $this->flash('已用默认参数添加节点，复制下面的命令到节点服务器上运行即可');
        return $this->redirectTo('admin/nat_node/command', ['id' => $id]);
    }

    /** 重新生成令牌：旧令牌和旧安装命令立即失效 */
    public function regen()
    {
        if (!$this->request->isPost()) {
            $this->abort('请求方式错误', 405);
        }
        $n = $this->node((int) $this->request->post('id', 0));
        list(, $exp) = NatNodeService::regenerate($n['id']);
        audit('admin.nat_node.regen', 'NAT节点#' . $n['id'], ['expire' => date('Y-m-d H:i', $exp)], 'warn');
        $this->flash('已重新生成令牌，旧的安装命令已失效');
        return $this->redirectTo('admin/nat_node/command', ['id' => $n['id']]);
    }

    public function delete()
    {
        if (!$this->request->isPost()) {
            $this->abort('请求方式错误', 405);
        }
        $n = $this->node((int) $this->request->post('id', 0));
        Db::name('nat_node')->where('id', $n['id'])->delete();
        audit('admin.nat_node.delete', 'NAT节点#' . $n['id'], ['name' => $n['name'], 'upstream_id' => $n['upstream_id']], 'warn');
        $this->flash($n['upstream_id'] ? '节点已删除。关联的上游 #' . $n['upstream_id'] . ' 仍保留，不再使用可到「上游接口」删除' : '节点已删除');
        return $this->redirectTo('admin/nat_node/index');
    }
}
