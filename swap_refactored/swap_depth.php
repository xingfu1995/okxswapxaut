<?php
/**
 * OKX 深度数据处理服务（重构版）
 *
 * 功能：
 * 1. 订阅 OKX books5 频道获取5档深度数据
 * 2. 从 Redis 读取价格差值
 * 3. 应用差值到买卖盘价格
 * 4. 推送给客户端
 */

require "../../index.php";

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

    if (!class_exists('\Protocols\Ws', false)) {
        class_alias('Workerman\Protocols\Ws', 'Protocols\Ws');
    }
    if (!class_exists('\Protocols\Wss', false)) {
        class_alias('Workerman\Protocols\Ws', 'Protocols\Wss');
    }

    $con = new AsyncTcpConnection('wss://wsus.okx.com:8443/ws/v5/public');
    $con->transport = 'ssl';

    $con->onConnect = function ($con) {
        $params = [
            "channel" => "books5",
            "instId" => "XAUT-USDT-SWAP"
        ];

        $request = [
            "op" => "subscribe",
            "args" => [$params],
        ];

        $con->send(json_encode($request));
        echo "[订阅] 已订阅 OKX 深度数据\n";
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

            // 获取价格差值
            $difference = Cache::store('redis')->get('swap:XAUT_price_difference');
            if ($difference === null) {
                $difference = 0;
            } else {
                $difference = floatval($difference);
            }

            // 处理买盘数据
            $cacheBuyList = collect($data['data'][0]['bids'] ?? [])->map(function ($item) use ($difference) {
                return [
                    'id' => (string)Str::uuid(),
                    'amount' => $item[1],
                    'price' => round(floatval($item[0]) + $difference, 4)
                ];
            })->toArray();

            // 处理卖盘数据
            $cacheSellList = collect($data['data'][0]['asks'] ?? [])->map(function ($item) use ($difference) {
                return [
                    'id' => (string)Str::uuid(),
                    'amount' => $item[1],
                    'price' => round(floatval($item[0]) + $difference, 4)
                ];
            })->toArray();

            // 存储深度数据
            Cache::store('redis')->put('swap:' . $symbol . '_depth_buy', $cacheBuyList, 60);
            Cache::store('redis')->put('swap:' . $symbol . '_depth_sell', $cacheSellList, 60);

            // 推送给客户端
            $group_id1 = 'swapBuyList_' . $symbol;
            $group_id2 = 'swapSellList_' . $symbol;

            if (Gateway::getClientIdCountByGroup($group_id1) > 0) {
                Gateway::sendToGroup($group_id1, json_encode([
                    'code' => 0,
                    'msg' => 'success',
                    'data' => $cacheBuyList,
                    'sub' => $group_id1
                ]));
                Gateway::sendToGroup($group_id2, json_encode([
                    'code' => 0,
                    'msg' => 'success',
                    'data' => $cacheSellList,
                    'sub' => $group_id2
                ]));
            }

        } catch (\Exception $e) {
            echo "[异常] 处理深度数据时发生错误：" . $e->getMessage() . "\n";
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
    echo "[启动] OKX 深度数据处理服务已启动\n";
};

Worker::runAll();
