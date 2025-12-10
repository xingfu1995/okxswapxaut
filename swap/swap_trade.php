<?php
require "../../index.php";

use Carbon\Carbon;
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

    $period = 'trades';
    $symbol = "XAUT";
    $ok = new OK($period, $symbol,false);
    if (!class_exists('\Protocols\Ws', false)) {
        class_alias('Workerman\Protocols\Ws', 'Protocols\Ws');
    }
    if (!class_exists('\Protocols\Wss', false)) {
        class_alias('Workerman\Protocols\Ws', 'Protocols\Wss');
    }
    $con = new AsyncTcpConnection('wss://wspri.okx.com:8443/ws/v5/ipublic');
    $onConnect = $ok->onConnectParams();
    // 设置以ssl加密方式访问，使之成为wss
    $con->transport = 'ssl';

    $con->onConnect = function ($con) use ($onConnect) {
        $con->send($onConnect);
    };

    $con->onMessage = function ($con, $data) {
        if (substr(round(microtime(true), 1), -1) % 2 == 0) { //当千分秒为为偶数 则处理数据
            $data =  json_decode($data, true);
            if (isset($data['ping'])) {
                $msg = ["pong" => $data['ping']];
                $con->send(json_encode($msg));
            } else {
                if (isset($data['data'])) {
                    $stream = $data['arg'];
                    $symbol = explode("-",$stream["instId"])[0]; //币种名称
                    // k线原始数据
                    $resdata = $data['data'][0];
                    $cache_data = [
                        'ts' => $resdata['ts'], //成交时间
                        'tradeId' => $resdata['tradeId'], //唯一成交ID
                        'amount' => $resdata['sz'], // 成交量(买或卖一方)
                        'price' => floatval($resdata['px']), //成交价
                        'direction'  => $resdata['side'], //buy/sell 买卖方向
                    ];

                    // 获取风控任务
                    $risk_key = 'fkJson:' . $symbol . '/USDT';
                    $risk = json_decode(Redis::get($risk_key), true);
                    $minUnit = $risk['minUnit'] ?? 0;
                    $count = $risk['count'] ?? 0;
                    $enabled = $risk['enabled'] ?? 0;
                    if (!blank($risk) && $enabled == 1) {
                        $change = $minUnit * $count;
                        // ============================================================ 2021-11-03 取消
                        //$cache_data['price'] = PriceCalculate($cache_data['price'], '+', $change, 8);
                    }

                    // TODO 获取Kline数据 计算涨幅
                    // $kline_key = 'swap:' . $symbol . '_kline_1day';
                    // $last_cache_data = Cache::store('redis')->get($kline_key);

                    // 计算24小时涨幅
                    $kline_book_key = 'swap:' . $symbol . '_kline_book_1min';
                    $kline_book = Cache::store('redis')->get($kline_book_key);
                    $time = time();
                    $priv_id = $time - ($time % 60) - 86400; //获取24小时前的分钟线
                    if ($kline_book) {
                        $last_cache_data = collect($kline_book)->firstWhere('id', $priv_id);
                    }
                    if (isset($last_cache_data) && !blank($last_cache_data) && $last_cache_data['open']) {
                        $increase = PriceCalculate(custom_number_format($cache_data['price'] - $last_cache_data['open'], 8), '/', custom_number_format($last_cache_data['open'], 8), 4);
                        $cache_data['increase'] = $increase;
                        $flag = $increase >= 0 ? '+' : '';
                        $cache_data['increaseStr'] = $increase == 0 ? '+0.00%' : $flag . $increase * 100 . '%';
                    } else {
                        $cache_data['increase'] = 0;
                        $cache_data['increaseStr'] = '+0.00%';
                    }
                    // if ($symbol == 'BTC') dump($cache_data['increaseStr']);

                    $group_id2 = 'swapTradeList_' . $symbol; //最近成交明细
                    if (Gateway::getClientIdCountByGroup($group_id2) > 0) {
                        Gateway::sendToGroup($group_id2, json_encode(['code' => 0, 'msg' => 'success', 'data' => $cache_data, 'sub' => $group_id2, 'type' => 'dynamic']));
                    }

                    $trade_detail_key = 'swap:trade_detail_' . $symbol;
                    Cache::store('redis')->put($trade_detail_key, $cache_data);

                    // 合约止盈止损
                    \App\Jobs\TriggerStrategy::dispatch(['symbol' => $symbol, 'realtime_price' => $cache_data['price']])->onQueue('triggerStrategy');

                    //缓存历史数据book
                    $trade_list_key = 'swap:tradeList_' . $symbol;
                    $trade_list = Cache::store('redis')->get($trade_list_key);
                    if (blank($trade_list)) {
                        Cache::store('redis')->put($trade_list_key, [$cache_data]);
                    } else {
                        array_push($trade_list, $cache_data);
                        if (count($trade_list) > 30) {
                            array_shift($trade_list);
                        }
                        Cache::store('redis')->put($trade_list_key, $trade_list);
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
