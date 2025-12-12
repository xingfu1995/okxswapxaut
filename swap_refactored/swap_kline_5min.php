<?php
/**
 * OKX 5分钟K线数据采集服务（重构版）
 */

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

Worker::$pidFile = __DIR__ . '/runtime/' . basename(__FILE__, '.php') . '.pid';

$worker = new Worker();
$worker->count = 1;

$worker->onWorkerStart = function ($worker) {

    Gateway::$registerAddress = '127.0.0.1:1338';

    // 初始化 5分钟周期
    $period = '5m';
    $symbol = "XAUT";
    $ok = new OK($period, $symbol, true, false); // 不拉取历史数据，避免重复
    $onConnect = $ok->onConnectParams($period);  // 只订阅当前周期

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

    $con->onConnect = function ($con) use ($onConnect) {
        $con->send($onConnect);
        echo "[订阅] 已订阅 5分钟K线数据\n";
    };

    $con->onMessage = function ($con, $data) use ($ok) {
        try {
            $data = json_decode($data, true);

            // 处理心跳
            if (isset($data['ping'])) {
                $msg = ["pong" => $data['ping']];
                $con->send(json_encode($msg));
                return;
            }

            // 处理K线数据
            $cache_data = $ok->data($data);

            if ($cache_data) {
                $group_id2 = 'swapKline_' . $ok->symbol . '_' . $cache_data["period"];

                // 缓存最新K线数据
                Cache::store('redis')->put('swap_kline_now_' . $cache_data["period"], $cache_data["data"], 3600);

                // 推送给客户端
                if (Gateway::getClientIdCountByGroup($group_id2) > 0) {
                    Gateway::sendToGroup($group_id2, json_encode([
                        'code' => 0,
                        'msg' => 'success',
                        'data' => $cache_data["data"],
                        'sub' => $group_id2,
                        'type' => 'dynamic'
                    ]));
                }
            }

        } catch (\Exception $e) {
            echo "[异常] 处理5分钟K线数据时发生错误：" . $e->getMessage() . "\n";
        }
    };

    $con->onClose = function ($con) {
        echo "[断线] 5分钟K线 WebSocket 连接关闭，1秒后重连...\n";
        $con->reConnect(1);
    };

    $con->onError = function ($con, $code, $msg) {
        echo "[错误] 5分钟K线 WebSocket 错误: code=$code, msg=$msg\n";
    };

    $con->connect();
    echo "[启动] 5分钟K线数据采集服务已启动\n";

    // ========== 添加定时器：每秒更新当前K线价格 ==========
    $history_fetched = false; // 标记是否已拉取历史数据
    $debug_counter = 0; // 调试计数器，只打印部分日志
    Timer::add(1, function() use ($ok, &$history_fetched, &$debug_counter) {
        try {
            $debug_counter++;
            $should_debug = ($debug_counter % 10 == 0); // 每10秒打印一次调试信息

            // 从 Redis 获取最新市场价格
            $market_data = Cache::store('redis')->get('swap:XAUT_detail');
            if (!$market_data) {
                if ($should_debug) echo "[定时器] 5分钟K线: 市场价格不存在\n";
                return;
            }

            $period = $ok->periods[$ok->period]['period'];
            $kline_book_key = 'swap:' . $ok->symbol . '_kline_book_' . $period;
            $kline_book = Cache::store('redis')->get($kline_book_key);

            // 如果缓存为空且还没拉取过历史数据，尝试拉取
            if ((!$kline_book || empty($kline_book)) && !$history_fetched) {
                echo "[自动拉取] 5分钟K线缓存为空，自动拉取历史数据...\n";
                $ok->getHistory($ok->period);
                $history_fetched = true;
                return;
            }

            if (!$kline_book || empty($kline_book)) {
                if ($should_debug) echo "[定时器] 5分钟K线: K线缓存为空\n";
                return;
            }

            // 获取当前这根K线
            $current_kline = end($kline_book);
            $period_seconds = 300; // 5分钟 = 300秒
            $current_timestamp = floor(time() / $period_seconds) * $period_seconds;

            if ($should_debug) {
                echo "[定时器调试] 5分钟K线:\n";
                echo "  - 当前时间: " . date('H:i:s', time()) . " (" . time() . ")\n";
                echo "  - 计算的时间戳: " . date('H:i:s', $current_timestamp) . " (" . $current_timestamp . ")\n";
                echo "  - 缓存K线ID: " . date('H:i:s', $current_kline['id']) . " (" . $current_kline['id'] . ")\n";
                echo "  - 时间戳匹配: " . ($current_kline['id'] == $current_timestamp ? '是' : '否') . "\n";
                echo "  - 市场价: " . $market_data['close'] . "\n";
                echo "  - K线close: " . $current_kline['close'] . "\n";
            }

            // 只更新当前这根K线
            if ($current_kline['id'] == $current_timestamp) {
                // 更新收盘价为最新市场价
                $current_kline['close'] = floatval($market_data['close']);

                // 更新最高价和最低价
                if ($current_kline['close'] > $current_kline['high']) {
                    $current_kline['high'] = $current_kline['close'];
                }
                if ($current_kline['close'] < $current_kline['low']) {
                    $current_kline['low'] = $current_kline['close'];
                }

                // 更新缓存
                $kline_book[count($kline_book) - 1] = $current_kline;
                Cache::store('redis')->put($kline_book_key, $kline_book, 86400 * 7);

                // 更新当前K线缓存
                Cache::store('redis')->put('swap_kline_now_' . $period, $current_kline, 3600);

                // 推送给客户端
                $group_id = 'swapKline_' . $ok->symbol . '_' . $period;
                if (Gateway::getClientIdCountByGroup($group_id) > 0) {
                    Gateway::sendToGroup($group_id, json_encode([
                        'code' => 0,
                        'msg' => 'success',
                        'data' => $current_kline,
                        'sub' => $group_id,
                        'type' => 'dynamic'
                    ]));

                    if ($should_debug) echo "[定时器] 5分钟K线: 推送成功\n";
                }
            } else {
                if ($should_debug) {
                    echo "[定时器警告] 5分钟K线: 时间戳不匹配，跳过更新\n";
                    echo "  差值: " . ($current_timestamp - $current_kline['id']) . " 秒\n";
                }
            }
        } catch (\Exception $e) {
            echo "[定时器异常] " . $e->getMessage() . "\n";
        }
    });
};

Worker::runAll();
