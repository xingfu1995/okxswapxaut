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






Gateway::$registerAddress = '127.0.0.1:1338';


$group_id2 = 'Kline_xautusdt_1min';

$group_idkey = 'Kline_xautusdt_';

$swap_group_idkey = 'swapKline_XAUT_';



$periods = [
    '1min' => 60,
    '5min' => 300,
    '15min' => 900,
    '30min' => 1800,
    '60min' => 3600,
    '1day' => 86400,
    '1week' => 604800,
    '1mon' => 2592000,
];


Worker::$pidFile = __DIR__ . '/aaaruntime/' . basename(__FILE__, '.php') . '.pid';
$worker = new Worker();

$worker->onWorkerStart = function() use ($periods, $group_idkey,$swap_group_idkey) {

    Timer::add(1, function() use ($periods, $group_idkey,$swap_group_idkey){


        foreach ($periods as $k=>$v){

            $group_idkey3 = $swap_group_idkey.$k;

            if ( Gateway::getClientIdCountByGroup($group_idkey3) > 0  ) {



                $aaaa = Cache::store('redis')->get('swap_kline_now_'. $k);

                $detail = Cache::store('redis')->get('swap:XAUT_Now_detail');


                $aaaa['close'] = floatval($detail['close']);

                if( floatval($aaaa['close']) > floatval($aaaa['high']) ){
                    $aaaa['high'] = floatval($aaaa['close']);
                }
                if( floatval($aaaa['low'])  > floatval($aaaa['close']) ){
                    $aaaa['low'] = floatval($aaaa['close']);
                }

                $aaaa['open'] = floatval($aaaa['close']);


//                print_r($detail);
//                print_r("\n");
//                print_r(floatval($detail['close']));
//                print_r("\n");




                $timestamp = time();
                file_put_contents($k.'1m-111111112.log',json_encode($aaaa)."\n".date("Y-m-d H:i:s", $timestamp)."\n\n\n", FILE_APPEND);
                file_put_contents($k.'1m-2211111112.log',json_encode($detail)."\n".date("Y-m-d H:i:s", $timestamp)."\n\n\n", FILE_APPEND);



                Gateway::sendToGroup($group_idkey3, json_encode(['code' => 0, 'msg' => 'success', 'data' => $aaaa, 'sub' => $group_idkey3, 'type' => 'dynamic','mian'=>2222]));


            }


        }


    });
};

Worker::runAll();

