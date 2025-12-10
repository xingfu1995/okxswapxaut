<?php
require "../../index.php";

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Lib\Timer;
use Workerman\Worker;
use GatewayWorker\Lib\Gateway;
Worker::$pidFile = __DIR__ . '/aaaruntime/' . basename(__FILE__, '.php') . '.pid';
$worker = new Worker();
$worker->count = 1;
$worker->onWorkerStart = function ($worker) {

    Gateway::$registerAddress = '127.0.0.1:1338';

    //所有交易对
    $period = '1M';
    $symbol = "XAUT";
    $ok = new OK($period, $symbol);
    $ok->getHistory();
    $onConnect = $ok->onConnectParams();

    if (!class_exists('\Protocols\Ws', false)) {
        class_alias('Workerman\Protocols\Ws', 'Protocols\Ws');
    }
    if (!class_exists('\Protocols\Wss', false)) {
        class_alias('Workerman\Protocols\Ws', 'Protocols\Wss');
    }
    $con = new AsyncTcpConnection('wss://wspri.okx.com:8443/ws/v5/ipublic');

    // 设置以ssl加密方式访问，使之成为wss
    $con->transport = 'ssl';

    $con->onConnect = function ($con) use ($onConnect) {
        $con->send($onConnect);
    };

    $con->onMessage = function ($con, $data) use ($ok) {
        if (substr(round(microtime(true), 1), -1) % 2 == 0) { //当千分秒为为偶数 则处理数据
            $data =  json_decode($data, true);

            if (isset($data['ping'])) {
                $msg = ["pong" => $data['ping']];
                $con->send(json_encode($msg));
            } else {
                $cache_data = $ok->data($data);
                if($cache_data){
                    $group_id2 = 'swapKline_' . $ok->symbol . '_' . $ok->period;
                    if (Gateway::getClientIdCountByGroup($group_id2) > 0) {
                        Gateway::sendToGroup($group_id2, json_encode(['code' => 0, 'msg' => 'success', 'data' => $cache_data, 'sub' => $group_id2, 'type' => 'dynamic']));
                    }
                }

            }
        }
    };

    $con->onClose = function ($con) {
        //这个是延迟断线重连，当服务端那边出现不确定因素，比如宕机，那么相对应的socket客户端这边也链接不上，那么可以吧1改成适当值，则会在多少秒内重新，我也是1，也就是断线1秒重新链接
        $con->reConnect(1);
    };

    $con->onError = function ($con, $code, $msg) {
        echo "error $code $msg\n";
    };

    $con->connect();
};

Worker::runAll();
