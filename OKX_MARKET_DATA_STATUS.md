# OKX Market 数据获取情况

## ✅ 已确认：有获取 OKX Market 实时价

### 数据来源

**文件**：`swap_market.php`

**订阅频道**：`tickers`（市场行情）

**原始数据结构**：
```php
$resdata = [
    'ts'       => 1733900000000,   // 时间戳（毫秒）
    'last'     => 650.5,           // ← OKX 实时成交价（最重要！）
    'open24h'  => 648.0,           // 24小时开盘价
    'high24h'  => 655.0,           // 24小时最高价
    'low24h'   => 645.0,           // 24小时最低价
    'vol24h'   => 1234567,         // 24小时成交量
];
```

---

## ⚠️ 当前存在的问题

### 问题：OKX 原始价格被覆盖

**当前代码逻辑**：

```php
// swap_market.php:71-95

// 情况 A：如果 FOREX 数据存在
if (!empty($XAU_USD_data['ID'])) {
    $cache_data = [
        'close' => $XAU_USD_data['Current'],  // ← 直接用 FOREX 价格！
        'low'   => $XAU_USD_data['Low'],
        'high'  => $XAU_USD_data['High'],
        'open'  => $XAU_USD_data['Current'],
        // OKX 的原始价格 $resdata['last'] 丢失了！
    ];
}
// 情况 B：如果 FOREX 数据不存在
else {
    $cache_data = [
        'close' => $resdata['last'],      // ← 使用 OKX 原始价格
        'low'   => $resdata['low24h'],
        'high'  => $resdata['high24h'],
        'open'  => $resdata['open24h'],
    ];
}

// 存储到 Redis
Cache::store('redis')->put('swap:XAUT_detail', $cache_data);
```

**问题分析**：

1. **情况 A**（有 FOREX 数据）：
   - 存储的是 FOREX 的绝对价格（如 2700）
   - **不是差值调整后的价格**
   - **不是 OKX 原始价格**
   - 导致显示错误！

2. **情况 B**（无 FOREX 数据）：
   - 存储的是 OKX 原始价格（如 650）
   - 这个是对的，但没有调整

3. **OKX 原始实时价丢失**：
   - `$resdata['last']` 在情况 A 下被丢弃
   - 无法用于计算差值

---

## 💡 正确的做法

### 方案：分别存储原始价格和调整后价格

#### 第 1 步：始终存储 OKX 原始价格

```php
// swap_market.php

// 1. 先存储 OKX 原始 market 数据（未调整）
$okx_original = [
    'id'     => $resdata['ts'],
    'close'  => floatval($resdata['last']),      // OKX 实时价
    'open'   => floatval($resdata['open24h']),
    'high'   => floatval($resdata['high24h']),
    'low'    => floatval($resdata['low24h']),
    'vol'    => floatval($resdata['vol24h']),
    'amount' => floatval($resdata['vol24h']),
];

// 存储原始数据
Cache::store('redis')->put('swap:XAUT_market_original', $okx_original);
```

#### 第 2 步：获取差值并调整

```php
// 2. 获取差值
$difference = Cache::store('redis')->get('swap:XAUT_price_difference') ?: 0;

// 3. 计算调整后的价格
$adjusted_data = [
    'id'     => $okx_original['id'],
    'close'  => $okx_original['close'] + $difference,   // 650 + 2048 = 2698
    'open'   => $okx_original['open']  + $difference,
    'high'   => $okx_original['high']  + $difference,
    'low'    => $okx_original['low']   + $difference,
    'vol'    => $okx_original['vol'],
    'amount' => $okx_original['amount'],
];

// 4. 计算涨跌幅（基于调整后的价格）
$kline_book_key = 'swap:XAUT_kline_book_1min';
$kline_book = Cache::store('redis')->get($kline_book_key);
$time = time();
$priv_id = $time - ($time % 60) - 86400;

if ($kline_book) {
    $last_cache_data = collect($kline_book)->firstWhere('id', $priv_id);
}

if (!isset($last_cache_data) || blank($last_cache_data)) {
    $increase = round(($adjusted_data['close'] - $adjusted_data['open']) / $adjusted_data['open'], 4);
} else {
    $increase = round(($adjusted_data['close'] - $last_cache_data['open']) / $last_cache_data['open'], 4);
}

$adjusted_data['increase'] = $increase;
$flag = $increase >= 0 ? '+' : '';
$adjusted_data['increaseStr'] = $increase == 0 ? '+0.00%' : $flag . $increase * 100 . '%';

// 5. 存储调整后的数据
Cache::store('redis')->put('swap:XAUT_detail', $adjusted_data);
Cache::store('redis')->put('swap:XAUT_Now_detail', $adjusted_data);
```

---

## 📦 修改后的 Redis 数据结构

### OKX 原始数据（未调整）

```
Key: swap:XAUT_market_original

Value: {
    "id": 1733900000000,
    "close": 650.5,      // ← OKX 原始实时价
    "open": 648.0,
    "high": 655.0,
    "low": 645.0,
    "vol": 1234567,
    "amount": 1234567
}
```

### 调整后的数据（用于显示）

```
Key: swap:XAUT_detail

Value: {
    "id": 1733900000000,
    "close": 2698.5,     // ← 调整后：650.5 + 2048 = 2698.5
    "open": 2696.0,      // ← 调整后：648.0 + 2048 = 2696.0
    "high": 2703.0,
    "low": 2693.0,
    "vol": 1234567,
    "amount": 1234567,
    "increase": 0.0012,
    "increaseStr": "+0.12%"
}
```

### 价格差值

```
Key: swap:XAUT_price_difference

Value: 2048
```

---

## 🔄 完整的数据流程

```
1. get_new_xaut.php
   ↓
   获取 FOREX 实时价: 2700
   ↓
   存储: swap:XAU_USD_data

2. swap_market.php
   ↓
   获取 OKX 实时价: 650.5
   ↓
   存储原始数据: swap:XAUT_market_original

3. get_difference.php
   ↓
   读取 FOREX 价格: 2700
   读取 OKX 价格: 650.5 (从 swap:XAUT_market_original)
   ↓
   计算差值: 2700 - 650.5 = 2049.5
   ↓
   存储: swap:XAUT_price_difference = 2049.5

4. swap_market.php (继续)
   ↓
   读取差值: 2049.5
   ↓
   调整价格:
     close = 650.5 + 2049.5 = 2700
     high = 655.0 + 2049.5 = 2704.5
     ...
   ↓
   存储: swap:XAUT_detail
```

---

## ✅ 优势

1. **保留原始数据**：
   - OKX 原始价格始终可用
   - 可用于计算差值
   - 可用于调试和验证

2. **调整后数据准确**：
   - 使用差值调整
   - 整体平移
   - 对齐 FOREX 实货价格

3. **逻辑清晰**：
   - 原始数据和调整数据分开
   - 每一步都有明确的输入输出
   - 易于维护和调试

---

## 下一步

现在确认了有 OKX market 数据，需要：

1. **修改 swap_market.php**：
   - 始终存储 OKX 原始价格到 `swap:XAUT_market_original`
   - 使用差值调整后再存储到 `swap:XAUT_detail`

2. **修改 get_difference.php**：
   - 从 `swap:XAUT_market_original` 读取 OKX 实时价
   - 而不是从 WebSocket K线数据读取

3. **统一所有文件的差值使用方式**

需要我帮你实现这些修改吗？
