<?php
require "../../index.php";

use Workerman\Worker;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Lib\Timer;

use GatewayWorker\Lib\Gateway;
// =========================
// PID 文件
// =========================
Worker::$pidFile = __DIR__ . '/aaaruntime/' . basename(__FILE__, '.php') . '.pid';


// negotiate 获取 Token
function getToken()
{
    $ts = (int)(microtime(true) * 1000);

    $url = "https://rates-live.efxnow.com/signalr/negotiate?" . http_build_query([
            "clientProtocol" => "2.1",
            "connectionData" => '[{"name":"ratesstreamer"}]',
            "_"              => $ts,
        ]);

    $res = @file_get_contents($url);
    if (!$res) return false;

    $json = json_decode($res, true);
    echo "negotiate 返回：$res\n";

    return $json["ConnectionToken"] ?? false;
}

/**
 * start：SignalR 启动握手（可选但建议加上）
 */
function sendStart($token)
{
    $url = "https://rates-live.efxnow.com/signalr/start?" . http_build_query([
            "transport"       => "serverSentEvents",
            "clientProtocol"  => "2.1",
            "connectionToken" => $token,
            "connectionData"  => '[{"name":"ratesstreamer"}]',
        ]);

    echo "发送 start：$url\n";

    $res = @file_get_contents($url);
    echo "start 返回：$res\n";

    return $res && strpos($res, "started") !== false;
}

/**
 * 通过 /signalr/send 发送订阅指令
 */
function sendSubscribe($token)
{
    // 按 SignalR 规范，把 transport / connectionToken / connectionData 放到 URL 上
    $url = "https://rates-live.efxnow.com/signalr/send?" . http_build_query([
            "transport"       => "serverSentEvents",
            "clientProtocol"  => "2.1",
            "connectionToken" => $token,
            "connectionData"  => '[{"name":"ratesstreamer"}]',
            "tid"             => 6,   // 随便 0-9 的整数，照你之前的示例写 6
        ]);

    // 只订阅你说的这个 ID：401527511
    $data = [
        "H" => "ratesstreamer",
        "M" => "SubscribeToPriceUpdates",
        "A" => ["401527511"],
        "I" => 0,
    ];

    // POST 体里只需要 data=...
    $post = http_build_query([
        "data" => json_encode($data, JSON_UNESCAPED_SLASHES),
    ]);

    $opts = [
        "http" => [
            "method"        => "POST",
            "header"        => "Content-Type: application/x-www-form-urlencoded\r\n",
            "content"       => $post,
            "timeout"       => 10,
            "ignore_errors" => true, // 即使 4xx 也拿到返回体
        ],
    ];

    echo "发送订阅 POST URL：$url\n";
    echo "POST 数据：$post\n";

    $context = stream_context_create($opts);
    $res     = @file_get_contents($url, false, $context);

    echo "发送订阅结果：$res\n";

    // 打印 HTTP 状态，方便排错
    global $http_response_header;
    if (!empty($http_response_header)) {
        echo "HTTP 响应头：\n";
        print_r($http_response_header);
    }

    return $res;
}

// =========================
// Worker
// =========================
$worker = new Worker();
$worker->count = 1;

$worker->onWorkerStart = function () {

    retry:

    $token = getToken();
    if (!$token) {
        echo "无法获取 token，3 秒后重试\n";
        sleep(3);
        goto retry;
    }

    echo "Token: $token\n";

    // 拼 SSE URL
    $sseUrl = "https://rates-live.efxnow.com/signalr/connect?" . http_build_query([
            "transport"       => "serverSentEvents",
            "clientProtocol"  => "2.1",
            "connectionToken" => $token,
            "connectionData"  => '[{"name":"ratesstreamer"}]',
        ]);

    echo "SSE URL: $sseUrl\n\n";

    // 打开 SSE 流
    $stream = @fopen($sseUrl, "r");

    if (!$stream) {
        echo "无法连接 SSE，1 秒后重试\n";
        sleep(1);
        goto retry;
    }

    echo "SSE 已连接，发送 start + 订阅...\n";

    // 先 start，再订阅
    $started = sendStart($token);
    if (!$started) {
        echo "start 失败，3 秒后重试\n";
        fclose($stream);
        sleep(3);
        goto retry;
    }

    sendSubscribe($token);

    echo "开始读取 SSE 数据...\n\n";

    while (!feof($stream)) {

        $line = fgets($stream);
        if ($line === false) {
            break;
        }

        $line = trim($line);

//        // 调试输出原始行
//        print_r($line . "\n");

        if (strpos($line, "data:") !== 0) {
            continue;
        }

        $json = substr($line, 5);
        $json = trim($json);
        if ($json === "" || $json === "initialized") {
            continue;
        }

        $res = json_decode($json, true);
        if (!is_array($res)) {
            continue;
        }

        if (!isset($res["M"][0]["A"][0])) {
            continue;
        }

        $str   = $res["M"][0]["A"][0];
        $parts = explode("|", $str);

        if (count($parts) < 8) {
            continue;
        }

        list($id, $symbol, $time, $current, $ask, $bid, $high, $low) = $parts;

        // 按你要求校验币种
        if ($symbol !== 'XAU/USD') {
            echo "收到其他币种： symbol，丢弃\n";
            continue;
        }

        echo "\n=========================\n";
        echo "ID: $id\n";
        echo "Symbol: $symbol\n";
        echo "Time: $time\n";
        echo "Current: $current\n";
        echo "Ask: $ask\n";
        echo "Bid: $bid\n";
        echo "High: $high\n";
        echo "Low: $low\n";
        echo "=========================\n\n";


        $cacheBuyList = [
            'ID'=>$id,
            'Symbol'=>$symbol,
            'Time'=>$time,
            'Current'=>$current,
            'Ask'=>$ask,
            'Bid'=>$bid,
            'High'=>$high,
            'Low'=>$low,
        ];


//        file_put_contents('11111_get_new_xaut.log',json_encode($cacheBuyList)."\n".date("Y-m-d H:i:s", time())."\n\n\n", FILE_APPEND);

        Cache::store('redis')->put('swap:XAU_USD_data', $cacheBuyList);

//        ID
//        Symbol
//        时间
//        当前价格
//        卖出价 Ask
//        买入价 Bid
//        今日最高价
//        今日最低价




    }

    echo "SSE 断开，重连...\n";
    @fclose($stream);
    sleep(1);
    goto retry;
};

Worker::runAll();
