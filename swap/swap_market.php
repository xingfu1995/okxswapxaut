<?php
require "../../index.php";

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Lib\Timer;
use Workerman\Worker;
use GatewayWorker\Lib\Gateway;

Worker::$pidFile = __DIR__ . '/aaaruntime/' . basename(__FILE__, '.php') . '.pid';

$worker = new Worker();
$worker->count = 1;
$worker->onWorkerStart = function ($worker) {

    Gateway::$registerAddress = '127.0.0.1:1338';
    if (!class_exists('\Protocols\Ws', false)) {
        class_alias('Workerman\Protocols\Ws', 'Protocols\Ws');
    }
    if (!class_exists('\Protocols\Wss', false)) {
        class_alias('Workerman\Protocols\Ws', 'Protocols\Wss');
    }
    $con = new AsyncTcpConnection('wss://wspri.okx.com:8443/ws/v5/ipublic');

    // 设置以ssl加密方式访问，使之成为wss
    $con->transport = 'ssl';

    $con->onConnect = function ($con) {

        $params = [
            "channel"=>"tickers",
            "instId"=>"XAUT-USDT-SWAP"
        ];

        $request = [
            "op" => "subscribe",
            "args" => [$params],


        ];

        $con->send(json_encode($request));
    };

    $con->onMessage = function ($con, $data) {
//
//
//        file_put_contents('111111.log',$con);
//
//        file_put_contents('111111.log',$data);


        $data = json_decode($data, true);

        if (isset($data['ping'])) {
            $msg = ["pong" => $data['ping']];
            $con->send(json_encode($msg));
        }
        if (isset($data['data'])) {
            $stream = $data['arg'];
            $symbol = explode("-", $stream["instId"])[0]; //币种名称
            // k线原始数据
            $resdata = $data['data'][0];
            // 市场概况




            $XAU_USD_data = Cache::store('redis')->get('swap:XAU_USD_data');

            if (!empty($XAU_USD_data['ID'])) {

                $cache_data = [
                    'id' => $resdata['ts'], //unix时间戳 13位
                    'low' => $XAU_USD_data['Low'], //24小时最低价
                    'high' => $XAU_USD_data['High'], //24小时最高价
                    'open' => $XAU_USD_data['Current'], //开盘价（你原来的写法是错误的）
                    'close' => $XAU_USD_data['Current'],    //最新价格
                    'vol' => $resdata['vol24h'],  //24小时成交额
                    'amount' => $resdata['vol24h'], //24小时成交量
                ];

            } else {

                $cache_data = [
                    'id' => $resdata['ts'], //unix时间戳 13位
                    'low' => $resdata['low24h'], //24小时最低价
                    'high' => $resdata['high24h'], //24小时最高价
                    'open' => $resdata['open24h'], //24小时开盘价
                    'close' => $resdata['last'],    //最新价格
                    'vol' => $resdata['vol24h'],  //24小时成交额
                    'amount' => $resdata['vol24h'], //24小时成交量
                ];

            }


//            $cache_data = [
//                'id' => $resdata['ts'], //unix时间戳 13位
//                'low' => 1, //24小时最低价
//                'high' => 1, //24小时最高价
//                'open' => 1, //开盘价（你原来的写法是错误的）
//                'close' => 1,    //最新价格
//                'vol' => $resdata['vol24h'],  //24小时成交额
//                'amount' => $resdata['vol24h'], //24小时成交量
//            ];


//            // 获取风控任务
//            $risk_key = 'fkJson:' . $symbol . '/USDT';
//            $risk = json_decode(Redis::get($risk_key), true);
//            $minUnit = $risk['minUnit'] ?? 0;
//            $count = $risk['count'] ?? 0;
//            $enabled = $risk['enabled'] ?? 0;
//            if (!blank($risk) && $enabled == 1) {
//                $change = $minUnit * $count;
//                $cache_data['close'] = PriceCalculate($cache_data['close'], '+', $change, 8);
//                $cache_data['open'] = PriceCalculate($cache_data['open'], '+', $change, 8);
//                $cache_data['high'] = PriceCalculate($cache_data['high'], '+', $change, 8);
//                $cache_data['low'] = PriceCalculate($cache_data['low'], '+', $change, 8);
//            }

            if (isset($cache_data['open']) && $cache_data['open'] != 0) {
                // // 获取1dayK线 计算$increase
                // $day_kline = Cache::store('redis')->get('swap:' . $symbol . '_kline_' . '1day');
                // if (blank($day_kline)) {
                //     $increase = PriceCalculate(($cache_data['close'] - $cache_data['open']), '/', $cache_data['open'], 4);
                // } else {
                //     $increase = PriceCalculate(($cache_data['close'] - $day_kline['open']), '/', $day_kline['open'], 4);
                // }

                // 获取24小时前的分钟线  计算$increase
                $kline_book_key = 'swap:' . $symbol . '_kline_book_1min';
                $kline_book = Cache::store('redis')->get($kline_book_key);
                $time = time();
                $priv_id = $time - ($time % 60) - 86400; //获取24小时前的分钟线
                if ($kline_book) {
                    $last_cache_data = collect($kline_book)->firstWhere('id', $priv_id);
                }
                if (!isset($last_cache_data) || blank($last_cache_data)) {
                    $increase = round(($cache_data['close'] - $cache_data['open']) / $cache_data['open'], 4);
                } else {
                    $increase = round(($cache_data['close'] - $last_cache_data['open']) / $last_cache_data['open'], 4);
                }
            } else {
                $increase = 0;
            }
            $cache_data['increase'] = $increase;
            $flag = $increase >= 0 ? '+' : '';
            $cache_data['increaseStr'] = $increase == 0 ? '+0.00%' : $flag . $increase * 100 . '%';

            $key = 'swap:' . $symbol . '_detail';


            $timestamp = time();

            file_put_contents('1m-1111111.log',json_encode($cache_data)."\n".date("Y-m-d H:i:s", $timestamp)."\n\n\n", FILE_APPEND);

            file_put_contents('1m2-1111111.log',$cache_data['close'].'---'."\n".date("Y-m-d H:i:s", $timestamp)."\n\n\n", FILE_APPEND);


            file_put_contents('1m3-1111111.log',json_encode($XAU_USD_data)."\n".date("Y-m-d H:i:s", $timestamp)."\n\n\n", FILE_APPEND);

            file_put_contents('name-1m3-1111111.log',json_encode($key)."\n".date("Y-m-d H:i:s", $timestamp)."\n\n\n", FILE_APPEND);


            if($key == 'swap:XAUT_detail'){

                Cache::store('redis')->put('swap:XAUT_Now_detail', $cache_data);
            }






            Cache::store('redis')->put($key, $cache_data);
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
