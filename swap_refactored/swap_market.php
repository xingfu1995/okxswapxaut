<?php
/**
 * OKX Market 数据处理服务（重构版）
 *
 * 功能：
 * 1. 订阅 OKX tickers 频道获取市场行情数据
 * 2. 存储 OKX 原始数据（未调整）
 * 3. 从 Redis 读取 FOREX 价格
 * 4. 计算价格差值（FOREX - OKX）
 * 5. 应用差值到市场数据并存储
 * 6. 计算24小时涨跌幅
 * 7. 推送数据给客户端（通过 GatewayWorker）
 *
 * Redis Keys:
 * - swap:OKX_XAUT_market_original (Hash) - OKX 原始市场数据
 * - swap:OKX_XAUT_last_price (String) - OKX 最新价格
 * - swap:XAUT_price_difference (String) - 价格差值
 * - swap:XAUT_difference_info (Hash) - 差值详细信息
 * - swap:XAUT_detail (Hash) - 调整后的市场数据
 * - swap:XAUT_Now_detail (Hash) - 当前实时行情
 */

require "../../index.php";

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Worker;
use GatewayWorker\Lib\Gateway;

Worker::$pidFile = __DIR__ . '/runtime/' . basename(__FILE__, '.php') . '.pid';

$worker = new Worker();
$worker->count = 1;

$worker->onWorkerStart = function ($worker) {

    Gateway::$registerAddress = '127.0.0.1:1338';

    // WebSocket 协议别名
    if (!class_exists('\Protocols\Ws', false)) {
        class_alias('Workerman\Protocols\Ws', 'Protocols\Ws');
    }
    if (!class_exists('\Protocols\Wss', false)) {
        class_alias('Workerman\Protocols\Ws', 'Protocols\Wss');
    }

    // 连接 OKX WebSocket
    $con = new AsyncTcpConnection('wss://wspri.okx.com:8443/ws/v5/ipublic');
    $con->transport = 'ssl';

    // 连接成功后订阅 tickers 频道
    $con->onConnect = function ($con) {
        $params = [
            "channel" => "tickers",
            "instId" => "XAUT-USDT-SWAP"
        ];

        $request = [
            "op" => "subscribe",
            "args" => [$params],
        ];

        $con->send(json_encode($request));
        echo "[订阅] 已订阅 OKX tickers 频道: XAUT-USDT-SWAP\n";
    };

    // 接收消息
    $con->onMessage = function ($con, $data) {
        try {
            $data = json_decode($data, true);

            // 处理 ping/pong 心跳
            if (isset($data['ping'])) {
                $msg = ["pong" => $data['ping']];
                $con->send(json_encode($msg));
                return;
            }

            // 处理市场数据
            if (!isset($data['data'])) {
                return;
            }

            $stream = $data['arg'];
            $symbol = explode("-", $stream["instId"])[0]; // XAUT
            $resdata = $data['data'][0];

            // ========== 第1步：存储 OKX 原始数据 ==========
            $okx_original = [
                'id' => $resdata['ts'],
                'close' => floatval($resdata['last']),
                'open' => floatval($resdata['open24h']),
                'high' => floatval($resdata['high24h']),
                'low' => floatval($resdata['low24h']),
                'vol' => floatval($resdata['vol24h']),
                'amount' => floatval($resdata['vol24h']),
                'timestamp' => intval($resdata['ts'] / 1000),
            ];

            // 存储原始数据
            Cache::store('redis')->put('swap:OKX_XAUT_market_original', $okx_original, 3600);
            Cache::store('redis')->put('swap:OKX_XAUT_last_price', $okx_original['close'], 3600);

            echo sprintf(
                "[OKX原始] close: %.2f, open: %.2f, high: %.2f, low: %.2f\n",
                $okx_original['close'],
                $okx_original['open'],
                $okx_original['high'],
                $okx_original['low']
            );

            // ========== 第2步：读取 FOREX 价格 ==========
            $forex_data = Cache::store('redis')->get('swap:FOREX_XAU_USD_price');
            $forex_status = Cache::store('redis')->get('swap:FOREX_status') ?: 'offline';

            $forex_price = 0;
            if (!empty($forex_data) && !empty($forex_data['current'])) {
                $forex_price = floatval($forex_data['current']);
            }

            echo sprintf(
                "[FOREX] price: %.2f, status: %s\n",
                $forex_price,
                $forex_status
            );

            // ========== 第3步：计算差值 ==========
            $difference = 0;

            if ($forex_price > 0 && $okx_original['close'] > 0) {
                // 计算新差值
                $difference = $forex_price - $okx_original['close'];

                // 存储差值
                Cache::store('redis')->put('swap:XAUT_price_difference', $difference);

                // 存储差值详细信息
                $difference_info = [
                    'difference' => $difference,
                    'forex_price' => $forex_price,
                    'okx_price' => $okx_original['close'],
                    'forex_status' => $forex_status,
                    'calculate_time' => time(),
                    'calculate_time_str' => date('Y-m-d H:i:s'),
                ];
                Cache::store('redis')->put('swap:XAUT_difference_info', $difference_info);

                echo sprintf(
                    "[差值] %.2f = FOREX(%.2f) - OKX(%.2f)\n",
                    $difference,
                    $forex_price,
                    $okx_original['close']
                );
            } else {
                // 无法计算差值，使用上次的差值（如果存在）
                $old_difference = Cache::store('redis')->get('swap:XAUT_price_difference');
                if ($old_difference !== null) {
                    $difference = floatval($old_difference);
                    echo sprintf(
                        "[差值] 使用上次差值: %.2f (FOREX 或 OKX 数据不可用)\n",
                        $difference
                    );
                } else {
                    echo "[警告] 无法计算差值，且无历史差值，使用差值 0\n";
                }
            }

            // ========== 第4步：应用差值到市场数据 ==========
            $adjusted_data = [
                'id' => $okx_original['id'],
                'close' => round($okx_original['close'] + $difference, 2),
                'open' => round($okx_original['open'] + $difference, 2),
                'high' => round($okx_original['high'] + $difference, 2),
                'low' => round($okx_original['low'] + $difference, 2),
                'vol' => $okx_original['vol'],
                'amount' => $okx_original['amount'],
            ];

            echo sprintf(
                "[调整后] close: %.2f, open: %.2f, high: %.2f, low: %.2f\n",
                $adjusted_data['close'],
                $adjusted_data['open'],
                $adjusted_data['high'],
                $adjusted_data['low']
            );

            // ========== 第5步：计算24小时涨跌幅 ==========
            $increase = 0;

            if (isset($adjusted_data['open']) && $adjusted_data['open'] != 0) {
                // 获取24小时前的分钟K线
                $kline_book_key = 'swap:' . $symbol . '_kline_book_1min';
                $kline_book = Cache::store('redis')->get($kline_book_key);
                $time = time();
                $priv_id = $time - ($time % 60) - 86400; // 24小时前的分钟K线ID

                if ($kline_book) {
                    $last_cache_data = collect($kline_book)->firstWhere('id', $priv_id);
                }

                if (!isset($last_cache_data) || blank($last_cache_data)) {
                    // 使用当前的 open 和 close 计算
                    $increase = round(($adjusted_data['close'] - $adjusted_data['open']) / $adjusted_data['open'], 4);
                } else {
                    // 使用24小时前的 open 价格计算
                    $increase = round(($adjusted_data['close'] - $last_cache_data['open']) / $last_cache_data['open'], 4);
                }
            }

            $adjusted_data['increase'] = $increase;
            $flag = $increase >= 0 ? '+' : '';
            $adjusted_data['increaseStr'] = $increase == 0 ? '+0.00%' : $flag . ($increase * 100) . '%';

            echo sprintf(
                "[涨跌幅] %s (%.4f)\n",
                $adjusted_data['increaseStr'],
                $increase
            );

            // ========== 第6步：存储调整后的数据 ==========
            $key = 'swap:' . $symbol . '_detail';

            Cache::store('redis')->put($key, $adjusted_data, 3600);

            // 如果是 XAUT，额外存储一份
            if ($key == 'swap:XAUT_detail') {
                Cache::store('redis')->put('swap:XAUT_Now_detail', $adjusted_data, 3600);
            }

            echo "[存储] 数据已存储到 Redis: $key\n";
            echo str_repeat("=", 80) . "\n";

        } catch (\Exception $e) {
            echo "[异常] 处理市场数据时发生错误：" . $e->getMessage() . "\n";
            echo $e->getTraceAsString() . "\n";
        }
    };

    // 连接关闭时自动重连
    $con->onClose = function ($con) {
        echo "[断线] WebSocket 连接关闭，1秒后重连...\n";
        $con->reConnect(1);
    };

    // 错误处理
    $con->onError = function ($con, $code, $msg) {
        echo "[错误] WebSocket 错误: code=$code, msg=$msg\n";
    };

    // 开始连接
    $con->connect();
    echo "[启动] OKX Market 数据处理服务已启动\n";
};

Worker::runAll();
