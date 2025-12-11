# K线缓存完整验证文档

## 概述

本文档详细说明所有K线周期的缓存机制，确保9个周期的数据都被正确缓存。

---

## 1. 订阅的K线周期

### WebSocket 订阅配置

**位置**: `OK.php:234-240`

```php
// 订阅所有周期
foreach ($this->periods as $key => $period) {
    $params[] = [
        "channel" => "candle" . $key,
        "instId" => $this->symbol . '-USDT-SWAP',
    ];
}
```

**调用位置**: `swap_kline.php:37`

```php
$onConnect = $ok->onConnectParams();  // 无参数，订阅所有周期
```

### 订阅的9个周期

| OKX周期 | WebSocket频道 | 本地周期名 | Redis键值 |
|---------|--------------|-----------|----------|
| 1m | candle1m | 1min | `swap:XAUT_kline_book_1min` |
| 5m | candle5m | 5min | `swap:XAUT_kline_book_5min` |
| 15m | candle15m | 15min | `swap:XAUT_kline_book_15min` |
| 30m | candle30m | 30min | `swap:XAUT_kline_book_30min` |
| 1H | candle1H | 60min | `swap:XAUT_kline_book_60min` |
| 4H | candle4H | 4hour | `swap:XAUT_kline_book_4hour` |
| 1D | candle1D | 1day | `swap:XAUT_kline_book_1day` |
| 1W | candle1W | 1week | `swap:XAUT_kline_book_1week` |
| 1M | candle1M | 1mon | `swap:XAUT_kline_book_1mon` |

**✅ 所有9个周期都已订阅**

---

## 2. 历史数据缓存

### 初始化时拉取历史数据

**位置**: `OK.php:32-42` (构造函数)

```php
public function __construct($period, $symbol, $is_k = true, $isget = true)
{
    if ($is_k) {
        $this->period = $period;
        $this->symbol = $symbol;
        $this->is_k = $is_k;
        if ($isget) {
            // 拉取所有周期的历史数据
            foreach ($this->periods as $k => $v) {
                $this->getHistory($k);
            }
        }
    }
}
```

**说明**:
- 在 `swap_kline.php:36` 创建 `OK` 对象时，会自动调用 `getHistory()` 拉取所有9个周期的历史数据
- 每个周期拉取300条历史K线数据

### 历史数据存储

**位置**: `OK.php:192`

```php
Cache::store('redis')->put($kline_book_key, $cache_data, 86400 * 7); // 7天过期
```

**存储的键值**:
- `swap:XAUT_kline_book_1min`
- `swap:XAUT_kline_book_5min`
- `swap:XAUT_kline_book_15min`
- `swap:XAUT_kline_book_30min`
- `swap:XAUT_kline_book_60min`
- `swap:XAUT_kline_book_4hour`
- `swap:XAUT_kline_book_1day`
- `swap:XAUT_kline_book_1week`
- `swap:XAUT_kline_book_1mon`

**✅ 所有9个周期的历史数据都会在启动时缓存**

---

## 3. 实时K线数据缓存

### 3.1 接收 WebSocket 数据

**位置**: `swap_kline.php:68`

```php
$cache_data = $ok->data($data);
```

### 3.2 处理并缓存

**位置**: `OK.php:260-381` (data方法)

#### 数据流程

```
WebSocket 推送 K线数据
    ↓
OK.php:data() 方法处理
    ↓
获取价格差值 (OK.php:280)
    ↓
应用差值到 OHLC (OK.php:285-288)
    ↓
根据周期类型分别处理：
    ├─ 1min: 直接存储 (OK.php:300-315)
    └─ 其他周期: 聚合后存储 (OK.php:319-371)
    ↓
返回给 swap_kline.php
    ↓
缓存到 swap_kline_now_{period} (swap_kline.php:74)
```

### 3.3 K线历史数据缓存（kline_book）

#### 1分钟K线

**位置**: `OK.php:300-315`

```php
if ($period == '1min') {
    // 新K线或更新最后一根K线
    if (blank($kline_book)) {
        Cache::store('redis')->put($kline_book_key, [$cache_data], 86400 * 7);
    } else {
        $last_item1 = array_pop($kline_book);
        if ($last_item1['id'] == $cache_data['id']) {
            array_push($kline_book, $cache_data);
        } else {
            array_push($kline_book, $last_item1, $cache_data);
        }
        // 最多保留3000条
        if (count($kline_book) > 3000) {
            array_shift($kline_book);
        }
        Cache::store('redis')->put($kline_book_key, $kline_book, 86400 * 7);
    }
}
```

**缓存键**: `swap:XAUT_kline_book_1min`

#### 其他8个周期（5min, 15min, 30min, 60min, 4hour, 1day, 1week, 1mon）

**位置**: `OK.php:319-371`

```php
else {
    // 周期聚合映射
    $periodMap = [
        '5min' => ['period' => '1min', 'seconds' => 60],
        '15min' => ['period' => '5min', 'seconds' => 300],
        '30min' => ['period' => '15min', 'seconds' => 900],
        '60min' => ['period' => '30min', 'seconds' => 1800],
        '4hour' => ['period' => '60min', 'seconds' => 3600],
        '1day' => ['period' => '4hour', 'seconds' => 14400],
        '1week' => ['period' => '1day', 'seconds' => 86400],
        '1mon' => ['period' => '1day', 'seconds' => 86400],
    ];

    if (isset($periodMap[$period])) {
        $map = $periodMap[$period];

        // 从基础周期聚合
        $kline_base_book = Cache::store('redis')->get('swap:' . $symbol . '_kline_book_' . $map['period']);

        // ... 聚合逻辑 ...

        // 存储聚合后的K线
        if (blank($kline_book)) {
            Cache::store('redis')->put($kline_book_key, [$cache_data], 86400 * 7);
        } else {
            // ... 更新逻辑 ...
            Cache::store('redis')->put($kline_book_key, $kline_book, 86400 * 7);
        }
    }
}
```

**聚合逻辑说明**:
- 5分钟K线：从1分钟K线聚合而来
- 15分钟K线：从5分钟K线聚合而来
- 30分钟K线：从15分钟K线聚合而来
- 1小时K线：从30分钟K线聚合而来
- 4小时K线：从1小时K线聚合而来
- 1天K线：从4小时K线聚合而来
- 1周K线：从1天K线聚合而来
- 1月K线：从1天K线聚合而来

**缓存键**:
- `swap:XAUT_kline_book_5min`
- `swap:XAUT_kline_book_15min`
- `swap:XAUT_kline_book_30min`
- `swap:XAUT_kline_book_60min`
- `swap:XAUT_kline_book_4hour`
- `swap:XAUT_kline_book_1day`
- `swap:XAUT_kline_book_1week`
- `swap:XAUT_kline_book_1mon`

**✅ 所有8个周期都会实时更新并缓存**

### 3.4 当前K线数据缓存（kline_now）

**位置**: `swap_kline.php:74`

```php
Cache::store('redis')->put('swap_kline_now_' . $cache_data["period"], $cache_data["data"], 3600);
```

**说明**: 每个周期的最新K线也会单独缓存，用于快速查询

**缓存键**:
- `swap_kline_now_1min`
- `swap_kline_now_5min`
- `swap_kline_now_15min`
- `swap_kline_now_30min`
- `swap_kline_now_60min`
- `swap_kline_now_4hour`
- `swap_kline_now_1day`
- `swap_kline_now_1week`
- `swap_kline_now_1mon`

**✅ 所有9个周期的当前K线都会实时缓存**

---

## 4. 差值应用

### 所有K线数据都应用了价格差值

**位置**: `OK.php:280-288`

```php
// 获取价格差值
$difference = $this->getPriceDifference();

// 应用差值到 OHLC
$cache_data = [
    'id' => intval($v['0'] / 1000),
    'open' => round(floatval($v[1]) + $difference, 4),
    'high' => round(floatval($v[2]) + $difference, 4),
    'low' => round(floatval($v[3]) + $difference, 4),
    'close' => round(floatval($v[4]) + $difference, 4),
    'amount' => floatval($v[5]),
    'vol' => round(floatval($v[7]), 4),
    'time' => intval($v['0'] / 1000),
];
```

**✅ 所有9个周期的K线数据都统一应用了同一个价格差值**

---

## 5. 缓存键值汇总

### 历史K线数据（最多3000条）

| Redis键值 | 数据类型 | 过期时间 | 用途 |
|-----------|---------|---------|------|
| `swap:XAUT_kline_book_1min` | JSON Array | 7天 | 1分钟K线历史数据 |
| `swap:XAUT_kline_book_5min` | JSON Array | 7天 | 5分钟K线历史数据 |
| `swap:XAUT_kline_book_15min` | JSON Array | 7天 | 15分钟K线历史数据 |
| `swap:XAUT_kline_book_30min` | JSON Array | 7天 | 30分钟K线历史数据 |
| `swap:XAUT_kline_book_60min` | JSON Array | 7天 | 1小时K线历史数据 |
| `swap:XAUT_kline_book_4hour` | JSON Array | 7天 | 4小时K线历史数据 |
| `swap:XAUT_kline_book_1day` | JSON Array | 7天 | 1天K线历史数据 |
| `swap:XAUT_kline_book_1week` | JSON Array | 7天 | 1周K线历史数据 |
| `swap:XAUT_kline_book_1mon` | JSON Array | 7天 | 1月K线历史数据 |

### 当前K线数据

| Redis键值 | 数据类型 | 过期时间 | 用途 |
|-----------|---------|---------|------|
| `swap_kline_now_1min` | Hash | 1小时 | 实时1分钟K线 |
| `swap_kline_now_5min` | Hash | 1小时 | 实时5分钟K线 |
| `swap_kline_now_15min` | Hash | 1小时 | 实时15分钟K线 |
| `swap_kline_now_30min` | Hash | 1小时 | 实时30分钟K线 |
| `swap_kline_now_60min` | Hash | 1小时 | 实时1小时K线 |
| `swap_kline_now_4hour` | Hash | 1小时 | 实时4小时K线 |
| `swap_kline_now_1day` | Hash | 1小时 | 实时1天K线 |
| `swap_kline_now_1week` | Hash | 1小时 | 实时1周K线 |
| `swap_kline_now_1mon` | Hash | 1小时 | 实时1月K线 |

**✅ 总计18个Redis键值，覆盖所有9个K线周期的历史数据和当前数据**

---

## 6. 验证方法

### 启动服务后验证所有K线缓存

```bash
# 启动服务
cd /home/user/okxswapxaut/swap_refactored
./start.sh

# 等待30秒，让服务拉取历史数据

# 验证所有K线历史数据
redis-cli KEYS "swap:XAUT_kline_book_*"

# 预期输出（9个键值）：
# 1) "swap:XAUT_kline_book_1min"
# 2) "swap:XAUT_kline_book_5min"
# 3) "swap:XAUT_kline_book_15min"
# 4) "swap:XAUT_kline_book_30min"
# 5) "swap:XAUT_kline_book_60min"
# 6) "swap:XAUT_kline_book_4hour"
# 7) "swap:XAUT_kline_book_1day"
# 8) "swap:XAUT_kline_book_1week"
# 9) "swap:XAUT_kline_book_1mon"

# 验证所有K线当前数据
redis-cli KEYS "swap_kline_now_*"

# 预期输出（9个键值）：
# 1) "swap_kline_now_1min"
# 2) "swap_kline_now_5min"
# 3) "swap_kline_now_15min"
# 4) "swap_kline_now_30min"
# 5) "swap_kline_now_60min"
# 6) "swap_kline_now_4hour"
# 7) "swap_kline_now_1day"
# 8) "swap_kline_now_1week"
# 9) "swap_kline_now_1mon"

# 查看具体某个K线数据
redis-cli --raw GET swap:XAUT_kline_book_1min | jq '.[0:3]'  # 查看前3条
redis-cli --raw HGETALL swap_kline_now_1min                   # 查看当前1分钟K线
```

### 检查每个K线的条数

```bash
# 检查1分钟K线条数
redis-cli --raw GET swap:XAUT_kline_book_1min | jq 'length'

# 检查其他周期（应该都有数据）
for period in 5min 15min 30min 60min 4hour 1day 1week 1mon; do
    count=$(redis-cli --raw GET swap:XAUT_kline_book_$period | jq 'length' 2>/dev/null || echo "0")
    echo "$period: $count 条"
done
```

---

## 7. 总结

### ✅ 完整的K线缓存确认

1. **订阅层面**: 所有9个周期都已通过 WebSocket 订阅
2. **历史数据**: 启动时自动拉取所有9个周期的历史数据（各300条）
3. **实时更新**: WebSocket 推送的数据会实时更新所有9个周期
4. **数据聚合**: 通过聚合机制确保长周期K线的准确性
5. **差值应用**: 所有K线数据都统一应用了价格差值
6. **双重缓存**:
   - `swap:XAUT_kline_book_*` 存储历史K线（最多3000条）
   - `swap_kline_now_*` 存储当前最新K线（快速查询）

### 关键代码位置

| 功能 | 文件 | 行号 |
|------|------|------|
| 订阅所有周期 | OK.php | 234-240 |
| 拉取历史数据 | OK.php | 40, 95-192 |
| 处理1分钟K线 | OK.php | 300-315 |
| 处理其他周期 | OK.php | 319-371 |
| 应用价格差值 | OK.php | 280-288 |
| 缓存当前K线 | swap_kline.php | 74 |

**结论**: 所有9个K线周期的数据都已完整缓存，无遗漏。
