# 价格调整逻辑梳理

## 业务需求

### 问题
OKX 的 XAUT-USDT-SWAP 价格与实货（FOREX XAU/USD）价格**不一致**

### 目标
通过计算差值，将 OKX 的所有数据（K线、深度、成交等）**整体平移**，使其对齐实货价格

### 方法
```
差值 = FOREX 实时价（XAU/USD） - OKX 实时价（XAUT-USDT）
调整后的价格 = OKX 原始价格 + 差值
```

---

## 正确的逻辑应该是

### 步骤 1：获取 FOREX 实时价格

```php
// get_new_xaut.php
$forex_price = $bid;  // XAU/USD 当前价格，比如 2700

Cache::store('redis')->put('swap:XAU_USD_data', [
    'Current' => 2700,  // FOREX 实时价
    'High' => ...,
    'Low' => ...,
]);
```

### 步骤 2：获取 OKX 实时价格

```php
// 从 OKX WebSocket 获取 XAUT-USDT-SWAP 的当前价格
$okx_current_price = 650;  // OKX 当前实时价
```

### 步骤 3：计算差值

```php
$difference = $forex_price - $okx_current_price;
// 2700 - 650 = 2050
```

### 步骤 4：将差值应用到所有数据

```php
// K线数据
$adjusted_open  = $okx_open  + $difference;  // 650 + 2050 = 2700
$adjusted_high  = $okx_high  + $difference;  // 655 + 2050 = 2705
$adjusted_low   = $okx_low   + $difference;  // 645 + 2050 = 2695
$adjusted_close = $okx_close + $difference;  // 652 + 2050 = 2702

// 深度数据
$adjusted_bid_price = $okx_bid_price + $difference;
$adjusted_ask_price = $okx_ask_price + $difference;
```

---

## 当前代码的实现

### 当前实现（可能有问题）

让我检查一下当前代码是怎么做的：

#### 1. get_new_xaut.php
```php
// 获取 FOREX XAU/USD 价格
$cache = [
    'Current' => $bid,  // 比如 2700
    ...
];
Cache::store('redis')->put('swap:XAU_USD_data', $cache);
```

#### 2. OK.php - 历史K线处理
```php
$XAU_USD_data = Cache::store('redis')->get('swap:XAU_USD_data2');

// 当前实现：直接加上 FOREX 价格（不是差值！）
'open' => round(floatval($v[1]) + floatval($XAU_USD_data['close']), 4)
```

**问题**：
- 直接加的是 `$XAU_USD_data['close']`（FOREX 价格，比如 2700）
- 而不是差值（FOREX 价格 - OKX 实时价 = 2700 - 650 = 2050）

**结果**：
```
OKX 历史数据 open = 100
加上 FOREX 价格 2700
最终显示 = 2800  ❌ 错误！远超实货价格！
```

**正确应该是**：
```
OKX 历史数据 open = 100
加上差值 2050
最终显示 = 2150  ✓ 正确
```

---

## 问题分析

### 当前代码缺少的步骤

当前代码没有计算差值，而是直接加上了 FOREX 的绝对价格！

需要增加的逻辑：

```php
// 1. 获取 FOREX 实时价
$forex_price = Cache::store('redis')->get('swap:XAU_USD_data')['Current'];  // 2700

// 2. 获取 OKX 实时价
$okx_current_price = Cache::store('redis')->get('swap:XAUT_current_price'); // 650

// 3. 计算差值
$difference = $forex_price - $okx_current_price;  // 2050

// 4. 存储差值
Cache::store('redis')->put('swap:XAUT_price_difference', $difference);

// 5. 应用差值到所有数据
$adjusted_price = $okx_price + $difference;
```

---

## 需要明确的问题

### 问题 1：OKX 实时价从哪里获取？

需要确认用哪个数据作为 OKX 的"当前实时价"来计算差值：

**选项 A：使用 tickers 的最新价**
```php
// swap_market.php 中的数据
$okx_current_price = $resdata['last'];  // tickers 频道的最新价
```

**选项 B：使用 1分钟 K线的最新收盘价**
```php
// swap_kline.php 中的数据
$okx_current_price = $cache_data['close'];  // 最新1分钟K线的收盘价
```

**选项 C：使用深度数据的中间价**
```php
// swap_depth.php 中的数据
$best_bid = $data['data'][0]['bids'][0][0];
$best_ask = $data['data'][0]['asks'][0][0];
$okx_current_price = ($best_bid + $best_ask) / 2;
```

### 问题 2：差值是否需要实时更新？

**方案 A：差值固定**
- 系统启动时计算一次差值
- 之后所有数据都使用这个固定差值
- 优点：简单，数据一致
- 缺点：如果 OKX 和 FOREX 的价差变化，显示价格会逐渐偏离

**方案 B：差值实时更新**
- 每次收到新的 FOREX 价格或 OKX 价格时，重新计算差值
- 优点：始终对齐实货价格
- 缺点：历史数据会随着差值变化而变化（可能不合理）

**方案 C：差值定期更新**
- 比如每分钟或每5分钟重新计算一次差值
- 折中方案

### 问题 3：历史数据如何处理？

**情况 A：历史数据使用当前差值**
```
当前差值 = 2050

所有历史K线：
- 昨天的K线：OKX(100) + 2050 = 2150
- 今天的K线：OKX(105) + 2050 = 2155

优点：所有数据使用同一个差值，逻辑简单
缺点：历史K线可能与实际的历史实货价格不完全对齐
```

**情况 B：历史数据不调整**
```
只调整实时数据，历史数据使用 OKX 原始价格

历史K线：显示 OKX 原始价格（100, 105...）
实时K线：显示调整后价格（2155）

优点：历史数据不变，稳定
缺点：图表会出现断层（历史数据和实时数据价格差距很大）
```

---

## 推荐的实现方案

### 方案：使用固定差值，整体平移所有数据

这个方案符合你的需求："整体上移或下移，对上K线数据和深度数据"

#### 实现步骤

##### 1. 新增一个进程计算并存储差值

创建 `get_difference.php`（如果还没有的话）：

```php
<?php
require "../../index.php";

use Workerman\Worker;
use Workerman\Lib\Timer;
use Illuminate\Support\Facades\Cache;

Worker::$pidFile = __DIR__ . '/aaaruntime/get_difference.pid';

$worker = new Worker();
$worker->count = 1;

$worker->onWorkerStart = function () {

    // 每秒计算一次差值
    Timer::add(1, function() {

        // 1. 获取 FOREX 实时价（XAU/USD）
        $forex_data = Cache::store('redis')->get('swap:XAU_USD_data');

        if (empty($forex_data['Current'])) {
            echo "FOREX 价格数据不存在\n";
            return;
        }

        $forex_price = floatval($forex_data['Current']);  // 比如 2700

        // 2. 获取 OKX 实时价（XAUT-USDT-SWAP）
        // 使用 market 数据的最新价
        $okx_market_data = Cache::store('redis')->get('swap:XAUT_detail');

        if (empty($okx_market_data['close'])) {
            echo "OKX 市场数据不存在\n";
            return;
        }

        $okx_price = floatval($okx_market_data['close']);  // 比如 650

        // 3. 计算差值
        $difference = $forex_price - $okx_price;  // 2700 - 650 = 2050

        // 4. 存储差值到 Redis
        Cache::store('redis')->put('swap:XAUT_price_difference', $difference);

        // 5. 同时存储一个包含详细信息的对象（用于调试）
        $difference_info = [
            'forex_price' => $forex_price,
            'okx_price' => $okx_price,
            'difference' => $difference,
            'update_time' => time(),
            'update_time_str' => date('Y-m-d H:i:s'),
        ];

        Cache::store('redis')->put('swap:XAUT_difference_info', $difference_info);

        echo sprintf(
            "[%s] FOREX: %.2f, OKX: %.2f, 差值: %.2f\n",
            date('Y-m-d H:i:s'),
            $forex_price,
            $okx_price,
            $difference
        );
    });
};

Worker::runAll();
```

##### 2. 修改 OK.php - 使用差值而不是 FOREX 绝对价格

```php
// OK.php - getHistory() 方法

// 获取差值
$difference = Cache::store('redis')->get('swap:XAUT_price_difference');

if (empty($difference)) {
    echo "[警告] 价格差值不存在，使用原始价格\n";
    $difference = 0;
}

$cache_data2 = collect($data["data"])->map(function ($v) use ($difference) {
    return [
        'id'     => intval($v[0] / 1000),
        'open'   => round(floatval($v[1]) + $difference, 4),   // 使用差值
        'high'   => round(floatval($v[2]) + $difference, 4),   // 使用差值
        'low'    => round(floatval($v[3]) + $difference, 4),   // 使用差值
        'close'  => round(floatval($v[4]) + $difference, 4),   // 使用差值
        'amount' => floatval($v[5]),
        'vol'    => round(floatval($v[7]), 4),
        'time'   => intval($v[0] / 1000),
    ];
})->reject(function ($v) {
    return $v['id'] > time();
})->toArray();
```

##### 3. 修改 OK.php - data() 方法

```php
// OK.php - data() 方法

// 获取差值
$difference = Cache::store('redis')->get('swap:XAUT_price_difference');

if (empty($difference)) {
    $difference = 0;
}

$cache_data = [
    'id'     => intval($v['0'] / 1000),
    'open'   => round(floatval($v[1]) + $difference, 4),   // 使用差值
    'high'   => round(floatval($v[2]) + $difference, 4),   // 使用差值
    'low'    => round(floatval($v[3]) + $difference, 4),   // 使用差值
    'close'  => round(floatval($v[4]) + $difference, 4),   // 使用差值
    'amount' => floatval($v[5]),
    'vol'    => round(floatval($v[7]), 4),
    'time'   => intval($v['0'] / 1000),
];
```

##### 4. 修改 swap_market.php - 使用差值

```php
// swap_market.php

$difference = Cache::store('redis')->get('swap:XAUT_price_difference') ?: 0;

$cache_data = [
    'id'     => $resdata['ts'],
    'low'    => floatval($resdata['low24h']) + $difference,
    'high'   => floatval($resdata['high24h']) + $difference,
    'open'   => floatval($resdata['open24h']) + $difference,
    'close'  => floatval($resdata['last']) + $difference,
    'vol'    => $resdata['vol24h'],
    'amount' => $resdata['vol24h'],
];
```

##### 5. 修改 swap_depth.php - 使用差值

```php
// swap_depth.php

$difference = Cache::store('redis')->get('swap:XAUT_price_difference') ?: 0;

// 买盘
$cacheBuyList = collect($data['data'][0]['bids'])->map(function ($item) use ($difference) {
    return [
        'id'     => (string)Str::uuid(),
        'amount' => $item[1],
        'price'  => round(floatval($item[0]) + $difference, 4)  // 使用差值
    ];
})->toArray();

// 卖盘
$cacheSellList = collect($data['data'][0]['asks'])->map(function ($item) use ($difference) {
    return [
        'id'     => (string)Str::uuid(),
        'amount' => $item[1],
        'price'  => round(floatval($item[0]) + $difference, 4)  // 使用差值
    ];
})->toArray();
```

##### 6. 修改 swap_trade.php - 使用差值

```php
// swap_trade.php

$difference = Cache::store('redis')->get('swap:XAUT_price_difference') ?: 0;

$cache_data = [
    'ts'        => $resdata['ts'],
    'tradeId'   => $resdata['tradeId'],
    'amount'    => $resdata['sz'],
    'price'     => floatval($resdata['px']) + $difference,  // 使用差值
    'direction' => $resdata['side'],
];
```

---

## 优势

### 1. 数据一致性
- 所有数据（K线、深度、成交）都使用同一个差值
- 整体平移，图表形状不变

### 2. 实时对齐
- 差值每秒更新（或按需调整频率）
- 始终对齐 FOREX 实货价格

### 3. 逻辑清晰
- 差值计算独立成一个进程
- 其他进程只需要读取差值并应用

### 4. 易于调试
- 可以查看 `swap:XAUT_difference_info` 了解差值计算情况
- 可以手动设置差值进行测试

---

## 示例演示

### 假设数据

```
FOREX (XAU/USD) 实时价: 2700
OKX (XAUT-USDT) 实时价: 650
计算差值: 2700 - 650 = 2050
```

### 应用到各个数据

#### K线数据
```
OKX 原始K线:
  open:  648
  high:  655
  low:   645
  close: 652

调整后K线:
  open:  648 + 2050 = 2698
  high:  655 + 2050 = 2705
  low:   645 + 2050 = 2695
  close: 652 + 2050 = 2702  ← 接近 FOREX 实时价 2700 ✓
```

#### 深度数据
```
OKX 原始深度:
  买1: 649.5
  卖1: 650.5

调整后深度:
  买1: 649.5 + 2050 = 2699.5
  卖1: 650.5 + 2050 = 2700.5  ← 围绕 FOREX 实时价 2700 ✓
```

#### 成交明细
```
OKX 原始成交:
  price: 650.2

调整后成交:
  price: 650.2 + 2050 = 2700.2  ← 接近 FOREX 实时价 2700 ✓
```

---

## 总结

### 你的需求
- ✅ 使用固定差值（FOREX 实时价 - OKX 实时价）
- ✅ 所有数据使用同一个差值
- ✅ 整体上移/下移，对齐实货价格

### 当前代码的问题
- ❌ 没有计算差值
- ❌ 直接加的是 FOREX 绝对价格（导致价格错误）
- ❌ Redis Key 不一致（`swap:XAU_USD_data` vs `swap:XAU_USD_data2`）

### 需要修改的地方
1. 创建或完善 `get_difference.php`，计算并存储差值
2. 修改所有数据处理脚本（OK.php, swap_market.php, swap_depth.php, swap_trade.php）
3. 将 `+ FOREX价格` 改为 `+ 差值`
4. 修复 Redis Key 不一致问题

下一步我可以帮你实现这些修改，需要吗？
