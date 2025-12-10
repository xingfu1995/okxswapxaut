<?php
require "../../index.php";

use Workerman\Worker;
use Illuminate\Support\Facades\Cache;

// =========================
// PID 文件
// =========================
Worker::$pidFile = __DIR__ . '/aaaruntime/' . basename(__FILE__, '.php') . '.pid';

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
        echo "❌ negotiate 获取失败\n";
        return false;
    }

    echo "negotiate 返回：$res\n";
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

    echo "发送 start：$url\n";

    $res = @file_get_contents($url);
    echo "start 返回：$res\n";

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
        "A" => ["401527511"],
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

    echo "发送订阅 POST URL：$url\n";
    echo "POST 数据：$post\n";

    $context = stream_context_create($opts);
    $res = @file_get_contents($url, false, $context);

    echo "发送订阅结果：$res\n";
    return $res;
}

/**
 * 处理 SSE 数据事件（只处理真正有 M 的）
 */
function processSSE($json)
{
    $res = json_decode($json, true);
    if (empty($res["M"])) {
        // 心跳 / 控制消息，直接丢
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

//        $aaaaaa = date("Y-m-d H:i:s");
//        file_put_contents(
//            '11111_get_new_xaut.log',
//            json_encode($msg) . "\n" . $aaaaaa . "\n\n",
//            FILE_APPEND
//        );


        $str = $msg["A"][0];
        $parts = explode("|", $str);

        if (count($parts) < 8) {
            return;
        }

        list($id, $symbol, $time, $current, $ask, $bid, $high, $low) = $parts;

        if ($symbol !== "XAU/USD") {
            return;
        }

//        echo "\n=========================\n";
//        echo "ID: $id\n";
//        echo "Symbol: $symbol\n";
//        echo "Time: $time\n";
//        echo "Current: $current\n";
//        echo "Ask: $ask\n";
//        echo "Bid: $bid\n";
//        echo "High: $high\n";
//        echo "Low: $low\n";
//        echo "=========================\n\n";

        $cache = [
            'ID' => $id,
            'Symbol' => $symbol,
            'Time' => $time,
            'Current' => $bid ,    // 实时成交价格
            'Ask' => $ask,
            'Bid' => $current,
            'High' => $high,
            'Low' => $low,
        ];


//        file_put_contents(
//            '111112_get_new_xaut.log',
//            $cache['Current'] . "----" . $aaaaaa . "\n",
//            FILE_APPEND
//        );



//        ID
//        Symbol
//        时间
//        当前价格
//        卖出价 Ask
//        买入价 Bid
//        今日最高价
//        今日最低价

//        file_put_contents(
//            '11111_get_new_xaut.log',
//            json_encode($cache) . "\n" . date("Y-m-d H:i:s") . "\n\n",
//            FILE_APPEND
//        );

        Cache::store('redis')->put('swap:XAU_USD_data', $cache);
    }
}

// =========================
// Worker
// =========================
$worker = new Worker();
$worker->count = 1;

$worker->onWorkerStart = function () {

    start_connect:

    $token = getToken();
    if (!$token) {
        echo "3 秒后重试获取 token...\n";
        sleep(3);
        goto start_connect;
    }

    echo "Token: $token\n";

    $sseUrl = "https://rates-live.efxnow.com/signalr/connect?" . http_build_query([
            "transport" => "serverSentEvents",
            "clientProtocol" => "2.1",
            "connectionToken" => $token,
            "connectionData" => '[{"name":"ratesstreamer"}]',
        ]);

    echo "SSE URL: $sseUrl\n\n";

    $stream = @fopen($sseUrl, "r");
    if (!$stream) {
        echo "❌ 无法连接 SSE，1 秒后重试\n";
        sleep(1);
        goto start_connect;
    }

    echo "SSE 已连接，发送 start + 订阅...\n";

    if (!sendStart($token)) {
        echo "start 失败，3 秒后重连\n";
        fclose($stream);
        sleep(10);
        goto start_connect;
    }

    sendSubscribe($token);

    echo "开始读取 SSE 数据...\n";

    $emptynum = 0;


    while (!feof($stream)) {
        $line = fgets($stream);

        if ($line === false) {

            usleep(300000); // 0.3s
            continue;
        }

        $line = trim($line);

        if ($line === '' || $line === 'initialized') {
            continue;
        }

        // 调试：看看原始行
        // echo "RAW LINE: $line\n";

        if (strpos($line, 'data:') !== 0) {
            continue;
        }

//        file_put_contents('111---get_new_xaut.log',date('Y-m-d H:i:s',time())."\n".$line."\n\n", FILE_APPEND);

        $json = trim(substr($line, 5));
        if ($json === '' || $json === '{}' || $json === 'initialized') {
            $emptynum += 1;


            file_put_contents('111---22222.log',$emptynum, FILE_APPEND);

            if ($json == '{}' && $emptynum > 5) {



//                file_put_contents('111---22222.log',date('Y-m-d H:i:s',time())."\n".$line."\n\n", FILE_APPEND);


//                print_r($json);
//                print_r('111222');

                $emptynum = 6;

//                $cache = [
//                    'ID' => 0,
//                    'Symbol' => 0,
//                    'Time' => 0,
//                    'Current' => 0,
//                    'Ask' => 0,
//                    'Bid' => 0,
//                    'High' => 0,
//                    'Low' => 0,
//                ];
//
//                Cache::store('redis')->put('swap:XAU_USD_data', $cache);

            }
//
//            print_r("\n\n\n\n");

            continue;
        }


        $emptynum = 0;
        print_r($emptynum);
        print_r("\n");


        file_put_contents('111---22222.log',$emptynum, FILE_APPEND);


        processSSE($json);
    }

    echo "SSE 断开，重连中...\n";
    @fclose($stream);
    sleep(10);
    goto start_connect;
};

Worker::runAll();
