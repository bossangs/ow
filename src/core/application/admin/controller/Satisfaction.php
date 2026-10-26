<?php

namespace app\admin\controller;

use app\common\controller\AdminBase;
use app\common\service\TicketRatingService;

class Satisfaction extends AdminBase
{
    protected $title = '满意度报表';

    public function index()
    {
        $from = trim((string)$this->request->get('from', ''));
        $to = trim((string)$this->request->get('to', ''));
        $department = trim((string)$this->request->get('department', ''));
        $fromTs = $from !== '' ? strtotime($from . ' 00:00:00') : 0;
        $toTs = $to !== '' ? strtotime($to . ' 23:59:59') : 0;
        $report = TicketRatingService::report($fromTs, $toTs, $department);
        $bad = array();
        foreach ($report['rows'] as $row) {
            if ((int)$row['score'] <= 2) {
                $bad[] = $row;
            }
        }

        return $this->fetch('satisfaction/index', array('report' => $report, 'bad' => $bad, 'from' => $from, 'to' => $to, 'department' => $department));
    }
}
