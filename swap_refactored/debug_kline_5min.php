<?php
/**
 * 诊断 5分钟K线定时器问题
 */

require "../../index.php";

use Illuminate\Support\Facades\Cache;

echo "========== 5分钟K线诊断 ==========\n\n";

// 1. 检查市场价格数据
echo "1. 检查市场价格数据:\n";
$market_data = Cache::store('redis')->get('swap:XAUT_detail');
if ($market_data) {
    echo "   ✓ 市场价格存在\n";
    echo "   当前价格: " . $market_data['close'] . "\n";
} else {
    echo "   ✗ 市场价格不存在\n";
}
echo "\n";

// 2. 检查5分钟K线缓存
echo "2. 检查5分钟K线缓存:\n";
$kline_book = Cache::store('redis')->get('swap:XAUT_kline_book_5min');
if ($kline_book && !empty($kline_book)) {
    echo "   ✓ K线数据存在\n";
    echo "   K线数量: " . count($kline_book) . "\n";

    $current_kline = end($kline_book);
    echo "   最后一根K线:\n";
    echo "   - id: " . $current_kline['id'] . " (" . date('Y-m-d H:i:s', $current_kline['id']) . ")\n";
    echo "   - open: " . $current_kline['open'] . "\n";
    echo "   - high: " . $current_kline['high'] . "\n";
    echo "   - low: " . $current_kline['low'] . "\n";
    echo "   - close: " . $current_kline['close'] . "\n";
} else {
    echo "   ✗ K线数据不存在或为空\n";
}
echo "\n";

// 3. 检查时间戳计算
echo "3. 检查时间戳计算:\n";
$now = time();
$period_seconds = 300; // 5分钟
$current_timestamp = floor($now / $period_seconds) * $period_seconds;

echo "   当前时间: " . date('Y-m-d H:i:s', $now) . " ($now)\n";
echo "   5分钟周期秒数: $period_seconds\n";
echo "   计算的当前K线时间戳: " . date('Y-m-d H:i:s', $current_timestamp) . " ($current_timestamp)\n";

if ($kline_book && !empty($kline_book)) {
    $current_kline = end($kline_book);
    if ($current_kline['id'] == $current_timestamp) {
        echo "   ✓ 时间戳匹配！应该会更新\n";
    } else {
        echo "   ✗ 时间戳不匹配！\n";
        echo "   缓存K线 id: " . $current_kline['id'] . "\n";
        echo "   计算的时间戳: $current_timestamp\n";
        echo "   差值: " . ($current_timestamp - $current_kline['id']) . " 秒\n";
    }
}
echo "\n";

// 4. 检查当前K线缓存
echo "4. 检查当前K线缓存 (swap_kline_now_5min):\n";
$current_cache = Cache::store('redis')->get('swap_kline_now_5min');
if ($current_cache) {
    echo "   ✓ 当前K线缓存存在\n";
    echo "   - id: " . $current_cache['id'] . " (" . date('Y-m-d H:i:s', $current_cache['id']) . ")\n";
    echo "   - close: " . $current_cache['close'] . "\n";
} else {
    echo "   ✗ 当前K线缓存不存在\n";
}
echo "\n";

// 5. 检查进程是否运行
echo "5. 检查进程状态:\n";
$pid_file = __DIR__ . '/runtime/swap_kline_5min.pid';
if (file_exists($pid_file)) {
    $pid = trim(file_get_contents($pid_file));
    echo "   PID 文件存在: $pid\n";

    // 检查进程是否存在
    exec("ps -p $pid", $output, $return_code);
    if ($return_code === 0) {
        echo "   ✓ 进程正在运行\n";
    } else {
        echo "   ✗ 进程未运行（PID文件存在但进程不存在）\n";
    }
} else {
    echo "   ✗ PID 文件不存在\n";
}

echo "\n========== 诊断完成 ==========\n";
