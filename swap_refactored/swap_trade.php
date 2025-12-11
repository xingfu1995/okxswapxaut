<?php
/**
 * OKX 成交数据处理服务（重构版）
 *
 * 功能：
 * 1. 订阅 OKX trades 频道获取成交明细
 * 2. 从 Redis 读取价格差值
 * 3. 应用差值到成交价格
 * 4. 计算涨跌幅
 * 5. 推送给客户端
 * 6. 触发止盈止损策略（如果有）
 */

require "../../index.php";

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Lib\Timer;
use Workerman\Worker;
use GatewayWorker\Lib\Gateway;

Worker::$pidFile = __DIR__ . '/runtime/' . basename(__FILE__, '.php') . '.pid';

$worker = new Worker();
$worker->count = 1;

$worker->onWorkerStart = function ($worker) {

    Gateway::$registerAddress = '127.0.0.1:1338';

    $period = 'trades';
    $symbol = "XAUT";
    $ok = new OK($period, $symbol, false);

    if (!class_exists('\Protocols\Ws', false)) {
        class_alias('Workerman\Protocols\Ws', 'Protocols\Ws');
    }
    if (!class_exists('\Protocols\Wss', false)) {
        class_alias('Workerman\Protocols\Ws', 'Protocols\Wss');
    }

    $con = new AsyncTcpConnection('wss://wspri.okx.com:8443/ws/v5/ipublic');
    $onConnect = $ok->onConnectParams();
    $con->transport = 'ssl';

    $con->onConnect = function ($con) use ($onConnect) {
        $con->send($onConnect);
        echo "[订阅] 已订阅 OKX 成交数据\n";
    };

    $con->onMessage = function ($con, $data) {
        try {
            $data = json_decode($data, true);

            if (isset($data['ping'])) {
                $msg = ["pong" => $data['ping']];
                $con->send(json_encode($msg));
                return;
            }

            if (!isset($data['data'])) {
                return;
            }

            $stream = $data['arg'];
            $symbol = explode("-", $stream["instId"])[0];
            $resdata = $data['data'][0];

            // 获取价格差值
            $difference = Cache::store('redis')->get('swap:XAUT_price_difference');
            if ($difference === null) {
                $difference = 0;
            } else {
                $difference = floatval($difference);
            }

            // 构建成交数据
            $cache_data = [
                'ts' => $resdata['ts'],
                'tradeId' => $resdata['tradeId'],
                'amount' => $resdata['sz'],
                'price' => floatval($resdata['px']) + $difference,  // 应用差值
                'direction' => $resdata['side'],
            ];

            // 计算24小时涨跌幅
            $kline_book_key = 'swap:' . $symbol . '_kline_book_1min';
            $kline_book = Cache::store('redis')->get($kline_book_key);
            $time = time();
            $priv_id = $time - ($time % 60) - 86400;

            if ($kline_book) {
                $last_cache_data = collect($kline_book)->firstWhere('id', $priv_id);
            }

            if (isset($last_cache_data) && !blank($last_cache_data) && $last_cache_data['open']) {
                $increase = round(($cache_data['price'] - $last_cache_data['open']) / $last_cache_data['open'], 4);
                $cache_data['increase'] = $increase;
                $flag = $increase >= 0 ? '+' : '';
                $cache_data['increaseStr'] = $increase == 0 ? '+0.00%' : $flag . ($increase * 100) . '%';
            } else {
                $cache_data['increase'] = 0;
                $cache_data['increaseStr'] = '+0.00%';
            }

            // 推送给客户端
            $group_id2 = 'swapTradeList_' . $symbol;
            if (Gateway::getClientIdCountByGroup($group_id2) > 0) {
                Gateway::sendToGroup($group_id2, json_encode([
                    'code' => 0,
                    'msg' => 'success',
                    'data' => $cache_data,
                    'sub' => $group_id2,
                    'type' => 'dynamic'
                ]));
            }

            // 存储最新成交数据
            $trade_detail_key = 'swap:trade_detail_' . $symbol;
            Cache::store('redis')->put($trade_detail_key, $cache_data, 3600);

            // 触发止盈止损策略（如果有）
            if (class_exists('\App\Jobs\TriggerStrategy')) {
                \App\Jobs\TriggerStrategy::dispatch([
                    'symbol' => $symbol,
                    'realtime_price' => $cache_data['price']
                ])->onQueue('triggerStrategy');
            }

            // 缓存历史成交数据
            $trade_list_key = 'swap:tradeList_' . $symbol;
            $trade_list = Cache::store('redis')->get($trade_list_key);

            if (blank($trade_list)) {
                Cache::store('redis')->put($trade_list_key, [$cache_data], 3600);
            } else {
                array_push($trade_list, $cache_data);
                if (count($trade_list) > 30) {
                    array_shift($trade_list);
                }
                Cache::store('redis')->put($trade_list_key, $trade_list, 3600);
            }

        } catch (\Exception $e) {
            echo "[异常] 处理成交数据时发生错误：" . $e->getMessage() . "\n";
        }
    };

    $con->onClose = function ($con) {
        echo "[断线] WebSocket 连接关闭，1秒后重连...\n";
        $con->reConnect(1);
    };

    $con->onError = function ($con, $code, $msg) {
        echo "[错误] WebSocket 错误: code=$code, msg=$msg\n";
    };

    $con->connect();
    echo "[启动] OKX 成交数据处理服务已启动\n";
};

Worker::runAll();
