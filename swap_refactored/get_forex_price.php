<?php
/**
 * FOREX XAU/USD 价格获取服务（重构版）
 *
 * 功能：
 * 1. 通过 SignalR SSE 连接获取 FOREX XAU/USD 实时价格
 * 2. 处理连接失败、超时等异常情况
 * 3. 存储价格数据到 Redis
 * 4. 维护 FOREX 数据状态（online/offline）
 *
 * Redis Keys:
 * - swap:FOREX_XAU_USD_price (Hash) - FOREX 价格数据
 * - swap:FOREX_last_update_time (String) - 最后更新时间
 * - swap:FOREX_status (String) - 数据状态：online/offline
 */

require "../../index.php";

use Workerman\Worker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

// PID 文件
Worker::$pidFile = __DIR__ . '/runtime/' . basename(__FILE__, '.php') . '.pid';

/**
 * negotiate 获取 Token
 */
function getToken()
{
    $ts = (int)(microtime(true) * 1000);

    $url = "https://rates-live.efxnow.com/signalr/negotiate?" . http_build_query([
            "clientProtocol" => "2.1",
            "connectionData" => '[{"name":"ratesstreamer"}]',
            "_" => $ts,
        ]);

    $res = @file_get_contents($url);
    if (!$res) {
        echo "[错误] negotiate 获取失败\n";
        return false;
    }

    echo "[成功] negotiate 返回：$res\n";
    $json = json_decode($res, true);
    return $json["ConnectionToken"] ?? false;
}

/**
 * SignalR start
 */
function sendStart($token)
{
    $url = "https://rates-live.efxnow.com/signalr/start?" . http_build_query([
            "transport" => "serverSentEvents",
            "clientProtocol" => "2.1",
            "connectionToken" => $token,
            "connectionData" => '[{"name":"ratesstreamer"}]',
        ]);

    echo "[请求] 发送 start：$url\n";

    $res = @file_get_contents($url);
    echo "[响应] start 返回：$res\n";

    return $res && strpos($res, "started") !== false;
}

/**
 * 订阅价格
 */
function sendSubscribe($token)
{
    $url = "https://rates-live.efxnow.com/signalr/send?" . http_build_query([
            "transport" => "serverSentEvents",
            "clientProtocol" => "2.1",
            "connectionToken" => $token,
            "connectionData" => '[{"name":"ratesstreamer"}]',
            "tid" => 6,
        ]);

    $data = [
        "H" => "ratesstreamer",
        "M" => "SubscribeToPriceUpdates",
        "A" => ["401527511"],  // XAU/USD
        "I" => 0
    ];

    $post = http_build_query([
        "data" => json_encode($data, JSON_UNESCAPED_SLASHES)
    ]);

    $opts = [
        "http" => [
            "method" => "POST",
            "header" => "Content-Type: application/x-www-form-urlencoded\r\n",
            "content" => $post,
            "timeout" => 10
        ]
    ];

    echo "[请求] 发送订阅 POST\n";

    $context = stream_context_create($opts);
    $res = @file_get_contents($url, false, $context);

    echo "[响应] 发送订阅结果：$res\n";
    return $res;
}

/**
 * 更新 FOREX 状态到 Redis
 */
function updateForexStatus($status)
{
    Cache::store('redis')->put('swap:FOREX_status', $status, 300); // 5分钟过期
    echo "[状态] FOREX 状态更新为：$status\n";
}

/**
 * 处理 SSE 数据事件
 */
function processSSE($json)
{
    $res = json_decode($json, true);
    if (empty($res["M"])) {
        // 心跳或控制消息
        return;
    }

    foreach ($res["M"] as $msg) {
        if (($msg["H"] ?? "") !== "ratesStreamer") {
            continue;
        }
        if (($msg["M"] ?? "") !== "updateMarketPrice") {
            continue;
        }
        if (empty($msg["A"][0])) {
            continue;
        }

        $str = $msg["A"][0];
        $parts = explode("|", $str);

        if (count($parts) < 8) {
            return;
        }

        list($id, $symbol, $time, $current, $ask, $bid, $high, $low) = $parts;

        if ($symbol !== "XAU/USD") {
            return;
        }

        // 准备价格数据
        $priceData = [
            'current' => $bid,        // 使用买入价作为当前价
            'bid' => $current,
            'ask' => $ask,
            'high' => $high,
            'low' => $low,
            'time' => time(),
            'symbol' => $symbol,
            'raw_time' => $time,
            'raw_id' => $id,
        ];

        // 存储到 Redis（Hash 结构）
        Cache::store('redis')->put('swap:FOREX_XAU_USD_price', $priceData, 300); // 5分钟过期

        // 更新最后更新时间
        Cache::store('redis')->put('swap:FOREX_last_update_time', time(), 300);

        // 更新状态为在线
        updateForexStatus('online');

        echo sprintf(
            "[数据] XAU/USD - Current: %s, Bid: %s, Ask: %s, High: %s, Low: %s, Time: %s\n",
            $bid, $current, $ask, $high, $low, date('Y-m-d H:i:s')
        );
    }
}

/**
 * Worker 进程
 */
$worker = new Worker();
$worker->count = 1;

$worker->onWorkerStart = function () {

    // 初始状态设置为离线
    updateForexStatus('offline');

    // 连接重试计数
    $retryCount = 0;
    $maxRetries = 3;

    // 开始连接循环
    while (true) {
        try {
            echo "\n========== 开始连接 FOREX 数据源 ==========\n";

            // 1. 获取 Token
            $token = getToken();
            if (!$token) {
                echo "[错误] 获取 token 失败，10 秒后重试...\n";
                updateForexStatus('offline');
                sleep(10);
                continue;
            }

            echo "[成功] Token: $token\n";

            // 2. 构建 SSE URL
            $sseUrl = "https://rates-live.efxnow.com/signalr/connect?" . http_build_query([
                    "transport" => "serverSentEvents",
                    "clientProtocol" => "2.1",
                    "connectionToken" => $token,
                    "connectionData" => '[{"name":"ratesstreamer"}]',
                ]);

            echo "[请求] SSE URL: $sseUrl\n";

            // 3. 打开 SSE 流
            $stream = @fopen($sseUrl, "r");
            if (!$stream) {
                echo "[错误] 无法连接 SSE，10 秒后重试\n";
                updateForexStatus('offline');
                sleep(10);
                continue;
            }

            echo "[成功] SSE 已连接\n";

            // 4. 发送 start 命令
            if (!sendStart($token)) {
                echo "[错误] start 失败，关闭连接，10 秒后重试\n";
                fclose($stream);
                updateForexStatus('offline');
                sleep(10);
                continue;
            }

            // 5. 发送订阅命令
            sendSubscribe($token);

            echo "[开始] 读取 SSE 数据流...\n";

            // 重置重试计数
            $retryCount = 0;

            // 空数据计数（用于检测连接是否正常）
            $emptyCount = 0;
            $maxEmptyCount = 60; // 60秒无数据则认为连接异常

            // 6. 读取 SSE 数据流
            while (!feof($stream)) {
                $line = fgets($stream);

                if ($line === false) {
                    usleep(500000); // 0.5秒
                    $emptyCount++;

                    // 如果长时间无数据，标记为离线
                    if ($emptyCount > $maxEmptyCount) {
                        echo "[警告] 长时间无数据，标记为离线\n";
                        updateForexStatus('offline');
                        $emptyCount = $maxEmptyCount; // 防止溢出
                    }
                    continue;
                }

                $line = trim($line);

                if ($line === '' || $line === 'initialized') {
                    continue;
                }

                if (strpos($line, 'data:') !== 0) {
                    continue;
                }

                $json = trim(substr($line, 5));
                if ($json === '' || $json === '{}' || $json === 'initialized') {
                    continue;
                }

                // 收到有效数据，重置空数据计数
                $emptyCount = 0;

                // 处理数据
                processSSE($json);
            }

            // 流结束
            echo "[提示] SSE 流结束，关闭连接\n";
            @fclose($stream);

        } catch (\Exception $e) {
            echo "[异常] 发生错误：" . $e->getMessage() . "\n";
            echo $e->getTraceAsString() . "\n";

            if (isset($stream) && is_resource($stream)) {
                @fclose($stream);
            }
        }

        // 标记为离线
        updateForexStatus('offline');

        // 重连延迟（带指数退避）
        $retryCount++;
        if ($retryCount > $maxRetries) {
            $retryCount = $maxRetries;
        }

        $delay = min(10 * $retryCount, 60); // 最多60秒
        echo "[重连] {$delay} 秒后重新连接...\n";
        sleep($delay);
    }
};

Worker::runAll();
