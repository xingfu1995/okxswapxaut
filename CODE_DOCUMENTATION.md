# OKX XAUT Swap 数据采集系统 - 详细文档

## 项目概述

这是一个基于 **PHP + Workerman** 框架的实时数据采集系统，用于采集 OKX 交易所的 **XAUT-USDT-SWAP**（黄金永续合约）交易数据。系统通过 WebSocket 与 OKX 交易所建立长连接，实时获取市场数据，并将数据缓存到 Redis 中，同时通过 GatewayWorker 推送给前端客户端。

### 核心特性

1. **实时数据采集**：通过 WebSocket 实时接收 OKX 交易所的市场数据
2. **多周期 K 线生成**：支持 1分钟、5分钟、15分钟、30分钟、1小时、4小时、1天、1周、1月等多个周期
3. **价格叠加机制**：将 XAUT-USDT 价格与 XAU/USD 价格进行叠加处理
4. **数据缓存**：使用 Redis 缓存历史数据和实时数据
5. **实时推送**：通过 GatewayWorker 向客户端推送实时数据
6. **自动重连**：WebSocket 断开后自动重连

---

## 系统架构

### 技术栈

- **语言**：PHP
- **框架**：Workerman (异步事件驱动框架)
- **WebSocket 库**：AsyncTcpConnection
- **缓存**：Redis (Laravel Cache + Redis Facade)
- **消息推送**：GatewayWorker
- **数据源**：
  - OKX 交易所 WebSocket API (wss://wspri.okx.com:8443/ws/v5/ipublic)
  - EFX 黄金价格数据源 (https://rates-live.efxnow.com)

### 进程结构

系统包含多个独立的 Workerman Worker 进程：

| 进程脚本 | 功能 | 订阅频道 |
|---------|------|---------|
| `swap_kline.php` | K线数据采集 | candle1m, candle5m, candle15m, candle30m, candle1H, candle4H, candle1D, candle1W, candle1M |
| `swap_market.php` | 市场行情数据 | tickers |
| `swap_trade.php` | 成交明细数据 | trades |
| `swap_depth.php` | 盘口深度数据 | books5 |
| `get_new_xaut.php` | 获取 XAU/USD 价格 | SignalR SSE 连接 |
| `get_difference.php` | 价格差异计算 | - |
| `swapKline1second.php` | 1秒K线数据 | - |

---

## 核心文件详解

### 1. `OK.php` - OKX API 封装类（主要使用）

**文件路径**：`/swap/OK.php`

**功能**：封装 OKX 交易所 API 调用，包括历史 K 线拉取和 WebSocket 实时数据处理。

#### 主要属性

```php
public $period;           // K线周期，如 '1D', '1m'
public $symbol;           // 交易对符号，如 'XAUT'
public $is_k;             // 是否为K线数据
protected static $lastRequestAt = 0;      // 上次请求时间
protected static $minInterval = 0.35;     // 请求最小间隔（秒），防止触发限流
```

#### 周期映射

```php
public $periods = [
    '1m'  => ['period' => '1min',   'seconds' => 60],
    '5m'  => ['period' => '5min',   'seconds' => 300],
    '15m' => ['period' => '15min',  'seconds' => 900],
    '30m' => ['period' => '30min',  'seconds' => 1800],
    '1H'  => ['period' => '60min',  'seconds' => 3600],
    '4H'  => ['period' => '4hour',  'seconds' => 14400],
    '1D'  => ['period' => '1day',   'seconds' => 86400],
    '1W'  => ['period' => '1week',  'seconds' => 604800],
    '1M'  => ['period' => '1mon',   'seconds' => 2592000],
];
```

#### 核心方法

##### `throttle()` - 请求限流

```php
protected function throttle()
```

功能：防止请求过快触发 OKX API 的 429 限流错误。

实现逻辑：
- 确保两次请求之间至少间隔 `$minInterval` (0.35秒)
- 使用 `usleep()` 进行微秒级延迟

##### `getHistory($period, $cache_data = [], $before = '')` - 拉取历史K线

```php
public function getHistory($period, $cache_data = [], $before = '')
```

功能：从 OKX API 拉取历史 K 线数据，支持分页递归拉取。

**参数**：
- `$period`：K线周期（如 '1m', '1D'）
- `$cache_data`：已累积的数据（用于递归翻页）
- `$before`：分页参数（毫秒时间戳）

**流程**：
1. 执行限流检查 `throttle()`
2. 构建 HTTP 请求 URL：`https://www.okx.com/api/v5/market/candles`
3. 发送异步 HTTP GET 请求
4. 处理响应：
   - 如果返回 429，暂停 2 秒后重试
   - 解析数据并进行价格叠加（如果存在 XAU_USD_data）
   - 过滤未来时间的 K 线
5. 如果返回数据量 = 1500 条，说明还有更早的数据，继续递归拉取
6. 所有数据拉取完成后，排序、去重，并存入 Redis

**价格叠加逻辑**：

```php
if (!empty($XAU_USD_data['id'])) {
    $cache_data2 = collect($data["data"])->map(function ($v) use ($XAU_USD_data) {
        return [
            'id'     => intval($v[0] / 1000),
            'open'   => round(floatval($v[1]) + floatval($XAU_USD_data['close']), 4),
            'high'   => round(floatval($v[2]) + floatval($XAU_USD_data['close']), 4),
            'low'    => round(floatval($v[3]) + floatval($XAU_USD_data['close']), 4),
            'close'  => round(floatval($v[4]) + floatval($XAU_USD_data['close']), 4),
            'amount' => floatval($v[5]),
            'vol'    => round(floatval($v[7]), 4),
            'time'   => intval($v[0] / 1000),
        ];
    })->toArray();
}
```

##### `data($data)` - 处理 WebSocket 实时数据

```php
public function data($data): array
```

功能：处理从 OKX WebSocket 推送的实时 K 线数据。

**流程**：
1. 解析 WebSocket 消息，提取币种和周期信息
2. 从 Redis 读取 XAU/USD 价格数据
3. 如果存在 XAU/USD 数据，将其价格叠加到 XAUT 价格上
4. 处理不同周期的 K 线：
   - **1分钟周期**：直接更新或追加到 Redis 的 K 线 book 中
   - **其他周期**：基于更短周期的数据聚合计算（如 5分钟从 1分钟聚合）
5. 将处理后的数据存入 Redis，并返回给调用者

**K线聚合逻辑**（非1分钟周期）：

系统采用"分层聚合"策略，从更短周期聚合出更长周期：

```php
$periodMap = [
    '5min'  => ['period' => '1min',  'seconds' => 60],
    '15min' => ['period' => '5min',  'seconds' => 300],
    '30min' => ['period' => '15min', 'seconds' => 900],
    '60min' => ['period' => '30min', 'seconds' => 1800],
    '4hour' => ['period' => '60min', 'seconds' => 3600],
    '1day'  => ['period' => '4hour', 'seconds' => 14400],
];
```

例如：计算 5分钟 K 线时，从 1分钟 K 线数据中提取对应时间范围内的数据：
- `open`：该时间段第一根 1分钟 K 线的开盘价
- `close`：该时间段最后一根 1分钟 K 线的收盘价
- `high`：该时间段所有 1分钟 K 线的最高价
- `low`：该时间段所有 1分钟 K 线的最低价

##### `onConnectParams($channel = null)` - WebSocket 订阅参数

```php
public function onConnectParams($channel = null): string
```

功能：生成 WebSocket 连接后的订阅消息。

**返回示例**：

```json
{
  "op": "subscribe",
  "args": [
    {"channel": "candle1m",  "instId": "XAUT-USDT-SWAP"},
    {"channel": "candle5m",  "instId": "XAUT-USDT-SWAP"},
    {"channel": "candle15m", "instId": "XAUT-USDT-SWAP"},
    ...
  ]
}
```

---

### 2. `OKX.php` - OKX API 封装类（未完全实现）

**文件路径**：`/swap/OKX.php`

**状态**：此文件与 `OK.php` 结构类似，但 `getHistory()` 方法为空，未实际使用。

**差异**：
- `getHistory()` 方法体为空（第 70-79 行）
- 其他代码结构与 `OK.php` 相同

**建议**：删除此文件或合并到 `OK.php`，避免混淆。

---

### 3. `swap_kline.php` - K线数据采集进程

**文件路径**：`/swap/swap_kline.php`

**功能**：通过 WebSocket 连接 OKX 交易所，实时接收 XAUT-USDT-SWAP 的 K 线数据，并通过 GatewayWorker 推送给客户端。

#### 核心流程

```php
Worker::$pidFile = __DIR__ . '/aaaruntime/swap_kline.pid';

$worker = new Worker();
$worker->count = 1;

$worker->onWorkerStart = function ($worker) {
    Gateway::$registerAddress = '127.0.0.1:1338';

    // 初始化 OK 对象，拉取历史数据
    $period = '1D';
    $symbol = "XAUT";
    $ok = new OK($period, $symbol);  // 构造时会自动拉取所有周期的历史数据

    // 建立 WebSocket 连接
    $con = new AsyncTcpConnection('wss://wspri.okx.com:8443/ws/v5/ipublic');
    $con->transport = 'ssl';

    // 连接成功后订阅 K 线频道
    $con->onConnect = function ($con) use ($onConnect) {
        $con->send($onConnect);  // 发送订阅消息
    };

    // 接收 WebSocket 消息
    $con->onMessage = function ($con, $data) use ($ok) {
        $data = json_decode($data, true);

        // 处理 ping/pong 心跳
        if (isset($data['ping'])) {
            $msg = ["pong" => $data['ping']];
            $con->send(json_encode($msg));
        } else {
            // 处理 K 线数据
            $cache_data = $ok->data($data);

            if ($cache_data) {
                $group_id2 = 'swapKline_' . $ok->symbol . '_' . $cache_data["period"];

                // 缓存到 Redis
                Cache::store('redis')->put('swap_kline_now_'. $cache_data["period"], $cache_data["data"]);

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
        }
    };

    // 断线重连
    $con->onClose = function ($con) {
        $con->reConnect(1);
    };

    $con->connect();
};

Worker::runAll();
```

#### Redis 缓存 Key 说明

- `swap:XAUT_kline_book_1min`：1分钟 K 线历史数据数组（最多 3000 条）
- `swap:XAUT_kline_book_5min`：5分钟 K 线历史数据数组
- `swap:XAUT_kline_1min`：当前 1分钟 K 线数据（单条）
- `swap_kline_now_1min`：当前实时 K 线数据（单条）

---

### 4. `swap_market.php` - 市场行情数据采集

**文件路径**：`/swap/swap_market.php`

**功能**：订阅 OKX 的 `tickers` 频道，获取 XAUT-USDT-SWAP 的 24 小时市场概况数据。

#### 订阅参数

```json
{
  "op": "subscribe",
  "args": [
    {
      "channel": "tickers",
      "instId": "XAUT-USDT-SWAP"
    }
  ]
}
```

#### 数据结构

```php
$cache_data = [
    'id'       => $resdata['ts'],           // Unix 时间戳（13位毫秒）
    'low'      => $XAU_USD_data['Low'],     // 24小时最低价（叠加后）
    'high'     => $XAU_USD_data['High'],    // 24小时最高价（叠加后）
    'open'     => $XAU_USD_data['Current'], // 24小时开盘价
    'close'    => $XAU_USD_data['Current'], // 当前最新价格
    'vol'      => $resdata['vol24h'],       // 24小时成交额
    'amount'   => $resdata['vol24h'],       // 24小时成交量
    'increase' => $increase,                // 24小时涨跌幅（小数）
    'increaseStr' => '+1.23%',              // 24小时涨跌幅（字符串）
];
```

#### 涨跌幅计算

系统通过以下逻辑计算 24 小时涨跌幅：

```php
$kline_book_key = 'swap:' . $symbol . '_kline_book_1min';
$kline_book = Cache::store('redis')->get($kline_book_key);
$time = time();
$priv_id = $time - ($time % 60) - 86400;  // 24小时前的分钟K线ID

$last_cache_data = collect($kline_book)->firstWhere('id', $priv_id);

if (isset($last_cache_data) && !blank($last_cache_data)) {
    $increase = round(($cache_data['close'] - $last_cache_data['open']) / $last_cache_data['open'], 4);
} else {
    $increase = round(($cache_data['close'] - $cache_data['open']) / $cache_data['open'], 4);
}
```

#### Redis 缓存 Key

- `swap:XAUT_detail`：市场行情数据
- `swap:XAUT_Now_detail`：当前实时行情数据

---

### 5. `swap_trade.php` - 成交明细数据采集

**文件路径**：`/swap/swap_trade.php`

**功能**：订阅 OKX 的 `trades` 频道，获取 XAUT-USDT-SWAP 的实时成交明细。

#### 订阅参数

```json
{
  "op": "subscribe",
  "args": [
    {
      "channel": "trades",
      "instId": "XAUT-USDT-SWAP"
    }
  ]
}
```

#### 数据结构

```php
$cache_data = [
    'ts'         => $resdata['ts'],        // 成交时间（毫秒）
    'tradeId'    => $resdata['tradeId'],   // 唯一成交ID
    'amount'     => $resdata['sz'],        // 成交量（买或卖一方）
    'price'      => floatval($resdata['px']), // 成交价
    'direction'  => $resdata['side'],      // buy/sell 买卖方向
    'increase'   => 0.0123,                // 涨跌幅
    'increaseStr'=> '+1.23%',              // 涨跌幅字符串
];
```

#### 特殊功能

成交明细采集进程还负责触发止盈止损策略：

```php
\App\Jobs\TriggerStrategy::dispatch([
    'symbol' => $symbol,
    'realtime_price' => $cache_data['price']
])->onQueue('triggerStrategy');
```

#### Redis 缓存 Key

- `swap:trade_detail_XAUT`：最新成交明细（单条）
- `swap:tradeList_XAUT`：最近 30 条成交明细数组

---

### 6. `swap_depth.php` - 盘口深度数据采集

**文件路径**：`/swap/swap_depth.php`

**功能**：订阅 OKX 的 `books5` 频道，获取买卖盘前 5 档的深度数据。

#### 订阅参数

```json
{
  "op": "subscribe",
  "args": [
    {
      "channel": "books5",
      "instId": "XAUT-USDT-SWAP"
    }
  ]
}
```

#### 数据处理

买盘数据：

```php
$cacheBuyList = collect($data['data'][0]['bids'])->map(function ($item) use ($XAU_USD_data) {
    return [
        'id'     => (string)Str::uuid(),
        'amount' => $item[1],
        'price'  => round(floatval($item[0]) + floatval($XAU_USD_data['close']), 4)
    ];
})->toArray();
```

卖盘数据：

```php
$cacheSellList = collect($data['data'][0]['asks'])->map(function ($item) use ($XAU_USD_data) {
    return [
        'id'     => (string)Str::uuid(),
        'amount' => $item[1],
        'price'  => round(floatval($item[0]) + floatval($XAU_USD_data['close']), 4)
    ];
})->toArray();
```

#### Redis 缓存 Key

- `swap:XAUT_depth_buy`：买盘深度数据（5档）
- `swap:XAUT_depth_sell`：卖盘深度数据（5档）

---

### 7. `get_new_xaut.php` - XAU/USD 价格数据采集

**文件路径**：`/swap/get_new_xaut.php`

**功能**：从 EFX (efxnow.com) 获取实时 XAU/USD（黄金/美元）价格数据，用于与 XAUT-USDT 价格进行叠加。

#### 数据源

- **协议**：SignalR Server-Sent Events (SSE)
- **URL**：https://rates-live.efxnow.com/signalr/
- **订阅品种 ID**：401527511 (XAU/USD)

#### 连接流程

1. **Negotiate**：获取 ConnectionToken

```php
GET https://rates-live.efxnow.com/signalr/negotiate?clientProtocol=2.1&connectionData=[{"name":"ratesstreamer"}]&_={timestamp}

返回：{"ConnectionToken": "xxx", ...}
```

2. **Start**：启动连接

```php
GET https://rates-live.efxnow.com/signalr/start?transport=serverSentEvents&clientProtocol=2.1&connectionToken={token}&connectionData=[{"name":"ratesstreamer"}]
```

3. **Subscribe**：订阅价格更新

```php
POST https://rates-live.efxnow.com/signalr/send?transport=serverSentEvents&...

Body: data={"H":"ratesstreamer","M":"SubscribeToPriceUpdates","A":["401527511"],"I":0}
```

4. **Connect**：建立 SSE 长连接，接收实时数据

```php
GET https://rates-live.efxnow.com/signalr/connect?transport=serverSentEvents&...
```

#### 数据解析

SSE 返回的数据格式：

```
data: {"M":[{"H":"ratesStreamer","M":"updateMarketPrice","A":["401527511|XAU/USD|timestamp|current|ask|bid|high|low"]}]}
```

解析后的数据结构：

```php
$cache = [
    'ID'      => $id,
    'Symbol'  => 'XAU/USD',
    'Time'    => $time,
    'Current' => $bid,          // 实时买入价（用作当前价）
    'Ask'     => $ask,          // 卖出价
    'Bid'     => $current,      // 买入价
    'High'    => $high,         // 今日最高价
    'Low'     => $low,          // 今日最低价
];
```

#### Redis 缓存 Key

- `swap:XAU_USD_data`：XAU/USD 实时价格数据

#### 重连机制

代码使用 `goto start_connect;` 实现断线重连：
- 如果 negotiate 失败，3 秒后重试
- 如果 SSE 连接失败，1 秒后重试
- 如果 start 失败，10 秒后重连
- 如果 SSE 流结束（feof），10 秒后重连

---

### 8. `common.php` - 公共函数

**文件路径**：`/swap/common.php`

**功能**：定义公共函数（目前仅包含一个历史 K 线拉取函数，但未被使用）。

**状态**：此文件中的 `history()` 函数使用了 Workerman\Http\Client 异步请求，但代码中存在一些问题（如 `$periods` 变量未定义）。

---

### 9. `config.php` - 配置文件

**文件路径**：`/swap/config.php`

**内容**：

```php
<?php
$config = [];
```

**状态**：配置文件为空，未实际使用。

---

### 10. 启动和停止脚本

#### `swap_start.sh` - 启动脚本

**功能**：按顺序启动所有 Workerman 进程。

**流程**：

```bash
#!/bin/bash

# 1. 停止所有旧进程
php swap_depth.php stop
php swap_trade.php stop
php swap_market.php stop
php swap_kline.php stop
php get_new_xaut.php stop
php get_difference.php stop
php swapKline1second.php stop

sleep 1

# 2. 按顺序启动所有进程（-d 表示守护进程模式）
php get_new_xaut.php start -d       # 先启动价格源
sleep 1
php get_difference.php start -d     # 价格差异计算
sleep 1
php swap_depth.php start -d         # 深度数据
sleep 1
php swap_trade.php start -d         # 成交明细
sleep 1
php swap_market.php start -d        # 市场行情
sleep 1
php swap_kline.php start -d         # K线数据
sleep 1
php swapKline1second.php start -d   # 1秒K线

echo "全部启动成功！"
```

**注意**：每个进程启动后等待 1 秒，确保依赖关系正确（如先启动 get_new_xaut.php 获取 XAU/USD 价格）。

#### `swap_stop.sh` - 停止脚本

**功能**：停止所有 Workerman 进程。

```bash
#!/bin/sh
php swap_depth.php stop &
php swap_trade.php stop &
php swap_market.php stop &
php swap_kline_1min.php stop &
php swap_kline_5min.php stop &
# ... 更多进程
```

**注意**：使用 `&` 并行停止进程，提高效率。

---

## 价格叠加机制详解

这是系统的核心逻辑之一，目的是将 **XAUT-USDT** 的价格与 **XAU/USD** 的价格进行叠加，生成新的价格数据。

### 为什么需要价格叠加？

- **XAUT** 是 Tether 发行的黄金代币，1 XAUT = 1 盎司黄金
- **XAUT-USDT-SWAP** 是 XAUT 对 USDT 的永续合约
- 系统可能需要将其价格调整为接近 **XAU/USD** 的实际黄金价格

### 叠加逻辑

在所有数据采集进程中，都会从 Redis 读取 `swap:XAU_USD_data2` 或 `swap:XAU_USD_data`：

```php
$XAU_USD_data = Cache::store('redis')->get('swap:XAU_USD_data2');

if (!empty($XAU_USD_data['id'])) {
    // 将 XAU/USD 的 close 价格叠加到 XAUT 价格上
    $cache_data = [
        'id'     => intval($v[0] / 1000),
        'open'   => round(floatval($v[1]) + floatval($XAU_USD_data['close']), 4),
        'high'   => round(floatval($v[2]) + floatval($XAU_USD_data['close']), 4),
        'low'    => round(floatval($v[3]) + floatval($XAU_USD_data['close']), 4),
        'close'  => round(floatval($v[4]) + floatval($XAU_USD_data['close']), 4),
        'amount' => floatval($v[5]),
        'vol'    => round(floatval($v[7]), 4),
        'time'   => time(),
    ];
} else {
    // 如果没有 XAU/USD 数据，直接使用原始价格
    $cache_data = [
        'id'     => intval($v[0] / 1000),
        'open'   => floatval($v[1]),
        'high'   => floatval($v[2]),
        'low'    => floatval($v[3]),
        'close'  => floatval($v[4]),
        'amount' => floatval($v[5]),
        'vol'    => floatval($v[7]),
        'time'   => time(),
    ];
}
```

### 疑问点

代码中使用了两个不同的 Key：
- `swap:XAU_USD_data`：由 `get_new_xaut.php` 写入
- `swap:XAU_USD_data2`：在代码中读取，但未找到写入的地方

**可能的问题**：
- 如果 `swap:XAU_USD_data2` 不存在，价格叠加功能将不会生效
- 需要确认是否有其他进程在写入 `swap:XAU_USD_data2`，或者这是一个遗留的错误

---

## 数据流程图

```
1. get_new_xaut.php
   ↓
   从 EFX 获取 XAU/USD 实时价格
   ↓
   存入 Redis: swap:XAU_USD_data

2. swap_kline.php / swap_market.php / swap_trade.php / swap_depth.php
   ↓
   从 OKX WebSocket 接收实时数据
   ↓
   从 Redis 读取 swap:XAU_USD_data2 (?)
   ↓
   价格叠加处理
   ↓
   存入 Redis 缓存
   ↓
   通过 GatewayWorker 推送给客户端
```

---

## Redis 数据结构汇总

### K线数据

| Key | 类型 | 说明 | 最大条数 |
|-----|------|------|---------|
| `swap:XAUT_kline_book_1min` | Array | 1分钟K线历史数据数组 | 3000 |
| `swap:XAUT_kline_book_5min` | Array | 5分钟K线历史数据数组 | 3000 |
| `swap:XAUT_kline_book_15min` | Array | 15分钟K线历史数据数组 | 3000 |
| `swap:XAUT_kline_book_30min` | Array | 30分钟K线历史数据数组 | 3000 |
| `swap:XAUT_kline_book_60min` | Array | 1小时K线历史数据数组 | 3000 |
| `swap:XAUT_kline_book_4hour` | Array | 4小时K线历史数据数组 | 3000 |
| `swap:XAUT_kline_book_1day` | Array | 1天K线历史数据数组 | 3000 |
| `swap:XAUT_kline_book_1week` | Array | 1周K线历史数据数组 | 3000 |
| `swap:XAUT_kline_book_1mon` | Array | 1月K线历史数据数组 | 3000 |
| `swap:XAUT_kline_1min` | Object | 当前1分钟K线数据 | 1 |
| `swap_kline_now_1min` | Object | 当前实时K线数据 | 1 |

### 行情数据

| Key | 类型 | 说明 |
|-----|------|------|
| `swap:XAUT_detail` | Object | 市场行情数据 |
| `swap:XAUT_Now_detail` | Object | 当前实时行情数据 |

### 成交数据

| Key | 类型 | 说明 | 最大条数 |
|-----|------|------|---------|
| `swap:trade_detail_XAUT` | Object | 最新成交明细 | 1 |
| `swap:tradeList_XAUT` | Array | 最近成交明细数组 | 30 |

### 深度数据

| Key | 类型 | 说明 |
|-----|------|------|
| `swap:XAUT_depth_buy` | Array | 买盘深度数据（5档） |
| `swap:XAUT_depth_sell` | Array | 卖盘深度数据（5档） |

### 价格源数据

| Key | 类型 | 说明 |
|-----|------|------|
| `swap:XAU_USD_data` | Object | XAU/USD 实时价格 |
| `swap:XAU_USD_data2` | Object | XAU/USD 价格（用于叠加，来源不明） |

---

## GatewayWorker 推送频道

系统使用 GatewayWorker 的分组功能向客户端推送实时数据：

| 分组 ID | 数据类型 | 推送频率 |
|--------|---------|---------|
| `swapKline_XAUT_1min` | 1分钟K线 | 实时 |
| `swapKline_XAUT_5min` | 5分钟K线 | 实时 |
| `swapKline_XAUT_15min` | 15分钟K线 | 实时 |
| `swapKline_XAUT_30min` | 30分钟K线 | 实时 |
| `swapKline_XAUT_60min` | 1小时K线 | 实时 |
| `swapKline_XAUT_4hour` | 4小时K线 | 实时 |
| `swapKline_XAUT_1day` | 1天K线 | 实时 |
| `swapKline_XAUT_1week` | 1周K线 | 实时 |
| `swapKline_XAUT_1mon` | 1月K线 | 实时 |
| `swapBuyList_XAUT` | 买盘深度 | 实时 |
| `swapSellList_XAUT` | 卖盘深度 | 实时 |
| `swapTradeList_XAUT` | 成交明细 | 实时 |

---

## 运维操作

### 启动系统

```bash
cd /path/to/swap
./swap_start.sh
```

或手动启动单个进程：

```bash
php swap_kline.php start -d
```

### 停止系统

```bash
./swap_stop.sh
```

或手动停止单个进程：

```bash
php swap_kline.php stop
```

### 重启系统

```bash
php swap_kline.php restart -d
```

### 查看进程状态

```bash
php swap_kline.php status
```

### 查看日志

日志文件位于 `/swap/` 目录下，以 `.log` 结尾：

```bash
tail -f 1m-1111111.log          # 市场行情日志
tail -f k1m3-1111111.log         # K线数据日志
tail -f 111---22222.log          # XAU/USD 数据日志
```

### 查看 PID 文件

```bash
ls -la aaaruntime/
# swap_kline.pid
# swap_market.pid
# swap_trade.pid
# swap_depth.pid
# get_new_xaut.pid
# ...
```

---

## 依赖说明

### PHP 扩展

- `redis` - Redis 客户端扩展
- `sockets` - Socket 通信
- `pcntl` - 进程控制
- `posix` - POSIX 函数

### Composer 依赖

- `workerman/workerman` - 异步事件驱动框架
- `workerman/gateway-worker` - WebSocket 推送框架
- `illuminate/support` - Laravel 集合和辅助函数
- `illuminate/redis` - Laravel Redis 封装

### 外部服务

- **OKX WebSocket API**：wss://wspri.okx.com:8443/ws/v5/ipublic
- **EFX SignalR API**：https://rates-live.efxnow.com/signalr/
- **Redis 服务器**：127.0.0.1:6379
- **GatewayWorker 注册中心**：127.0.0.1:1338

---

## 总结

这是一个功能完善的实时数据采集系统，核心优势：

1. **异步高性能**：基于 Workerman 的异步事件驱动架构
2. **实时推送**：通过 GatewayWorker 实现 WebSocket 推送
3. **多周期支持**：支持 9 种不同的 K 线周期
4. **自动重连**：WebSocket 断开后自动重连，确保服务稳定
5. **价格叠加**：独特的价格叠加机制

但也存在一些需要改进的地方（详见下一份文档：`CODE_ISSUES.md`）。
