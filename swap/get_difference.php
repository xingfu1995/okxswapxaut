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


    //所有交易对
    $period = '1D';
    $symbol = "XAUT";
    $ok = new OKX($period, $symbol, true);
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


//
//        print_r($data);
//        print_r("\n\n");

        if (substr(round(microtime(true), 1), -1) % 2 == 0) { //当千分秒为为偶数 则处理数据
            $data = json_decode($data, true);

            $v = $data['data'][0];


            $XAU_USD_data = Cache::store('redis')->get('swap:XAU_USD_data');


//            file_put_contents('sussron-111---22222.log',date('Y-m-d H:i:s',time())."\n".json_encode($XAU_USD_data)."\n\n", FILE_APPEND);



            if (!empty($XAU_USD_data['ID'])) {

                $cache_data = [
                    'id' => intval($v['0'] / 1000),  // 时间戳

                    'open' => round((floatval($XAU_USD_data['Current']) - floatval($v[1])), 4), //开盘价（你原来的写法是错误的）
                    'high' => round((floatval($XAU_USD_data['Current']) - floatval($v[2])), 4),    //最高价（使用真实高价）
                    'low' => round((floatval($XAU_USD_data['Current']) - floatval($v[3])), 4),      //最低价（使用真实低价）
                    'close' => round((floatval($XAU_USD_data['Current']) - floatval($v[4])), 4), //收盘价（同上）

                    'amount' => floatval($v[5]), //成交量
                    'vol' => round(floatval($v[7]), 4), //成交额
                    'time' => time(),
                ];


//
//                $cache_data = [
//                    'id' => intval($v['0'] / 1000),  // 时间戳
//                    'open' => round((floatval($XAU_USD_data['Current']) - floatval($v[1])) , 3), //开盘价（你原来的写法是错误的）
//                    'high' => round((floatval($XAU_USD_data['Current']) - floatval($v[2])), 3),    //最高价（使用真实高价）
//                    'low' => round((floatval($XAU_USD_data['Current']) - floatval($v[3])), 3),      //最低价（使用真实低价）
//                    'close' => round((floatval($XAU_USD_data['Current']) - floatval($v[4])), 3), //收盘价（同上）
//                    'amount' => floatval($v[5]), //成交量
//                    'vol' => round(floatval($v[7]), 4), //成交额
//                    'time' => time(),
//                ];

                print_r($cache_data);
                print_r("\n\n");

//                file_put_contents('get_difference.log',json_encode($cache_data));


                Cache::store('redis')->put('swap:XAU_USD_data2', $cache_data);

            } else {

                $cache_data = [
                    'id' => 0,  // 时间戳
                    'open' => 0, //开盘价（你原来的写法是错误的）
                    'high' => 0,    //最高价（使用真实高价）
                    'low' => 0,      //最低价（使用真实低价）
                    'close' => 0, //收盘价（同上）
                    'amount' => 0, //成交量
                    'vol' => 0, //成交额
                    'time' => 0,
                ];

                file_put_contents('null-111---22222.log',date('Y-m-d H:i:s',time())."\n".json_encode($cache_data)."\n\n", FILE_APPEND);

                Cache::store('redis')->put('swap:XAU_USD_data2', $cache_data);

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
