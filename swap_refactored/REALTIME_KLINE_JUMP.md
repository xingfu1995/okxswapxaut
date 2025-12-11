# K线实时跳动功能说明

## 问题描述

**用户反馈**：
- ✓ 实时价在跳动（每秒更新）
- ✗ K线的价格没有跟着跳动
- ✗ K线更新慢，无法跟上实时价的变化

## 根本原因

### OKX WebSocket 推送频率低

OKX 的 WebSocket 推送 K线数据的频率较低：
- **1分钟K线**: 可能每 30-60 秒才推送一次更新
- **5分钟K线**: 可能几分钟才推送一次
- **其他周期**: 更新频率更低

这导致：
- 当前这根K线的收盘价不能实时更新
- 用户看到的K线图是"静止"的，不跟随实时价跳动
- 实时价和K线价格不同步

### 实时价 vs K线价格

```
实时价 (swap_market.php):
  每秒更新 ──┐
             │
             ├─→ Redis: swap:XAUT_detail
             │    { close: 2700.50 } → 2700.80 → 2701.00 ...
             │    ▲ 实时跳动
             │
K线价格 (原始实现):
  OKX WebSocket 推送 ──┐
                      │
                      ├─→ 每30-60秒才推送一次
                      │
                      └─→ K线价格更新慢，看起来"卡住"了
                          ✗ 无法跟上实时价
```

---

## 解决方案

### 添加定时器：主动更新当前K线

不依赖 OKX WebSocket 的推送频率，而是**主动**每秒更新当前这根K线的价格。

### 实现原理

```
                 Timer (每1秒触发)
                        │
                        ├─→ 从 Redis 读取最新市场价
                        │    Cache::get('swap:XAUT_detail')
                        │
                        ├─→ 获取当前K线
                        │    $current_kline = end($kline_book)
                        │
                        ├─→ 更新收盘价
                        │    $current_kline['close'] = $market_data['close']
                        │
                        ├─→ 更新最高价/最低价
                        │    if (close > high) high = close
                        │    if (close < low) low = close
                        │
                        ├─→ 更新 Redis 缓存
                        │
                        └─→ 推送给客户端
                             ▼ 实时跳动！
```

### 代码实现

**位置**: 所有 `swap_kline_*.php` 文件

```php
// 在 onWorkerStart 回调函数内，WebSocket连接之后
$con->connect();
echo "[启动] K线数据采集服务已启动\n";

// ========== 添加定时器：每秒更新当前K线价格 ==========
Timer::add(1, function() use ($ok) {
    try {
        // 1. 从 Redis 获取最新市场价格
        $market_data = Cache::store('redis')->get('swap:XAUT_detail');
        if (!$market_data) {
            return;
        }

        // 2. 获取当前K线的缓存键和数据
        $period = $ok->periods[$ok->period]['period'];
        $kline_book_key = 'swap:' . $ok->symbol . '_kline_book_' . $period;
        $kline_book = Cache::store('redis')->get($kline_book_key);

        if (!$kline_book || empty($kline_book)) {
            return;
        }

        // 3. 获取当前这根K线
        $current_kline = end($kline_book);
        $period_seconds = $ok->periods[$ok->period]['seconds'];
        $current_timestamp = floor(time() / $period_seconds) * $period_seconds;

        // 4. 只更新当前这根K线（时间戳匹配）
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

            // 5. 更新缓存
            $kline_book[count($kline_book) - 1] = $current_kline;
            Cache::store('redis')->put($kline_book_key, $kline_book, 86400 * 7);

            // 6. 更新当前K线缓存
            Cache::store('redis')->put('swap_kline_now_' . $period, $current_kline, 3600);

            // 7. 推送给客户端
            $group_id = 'swapKline_' . $ok->symbol . '_' . $period;
            if (Gateway::getClientIdCountByGroup($group_id) > 0) {
                Gateway::sendToGroup($group_id, json_encode([
                    'code' => 0,
                    'msg' => 'success',
                    'data' => $current_kline,
                    'sub' => $group_id,
                    'type' => 'dynamic'
                ]));
            }
        }
    } catch (\Exception $e) {
        echo "[定时器异常] " . $e->getMessage() . "\n";
    }
});
```

---

## 关键逻辑

### 1. 只更新当前这根K线

```php
$period_seconds = $ok->periods[$ok->period]['seconds'];
$current_timestamp = floor(time() / $period_seconds) * $period_seconds;

// 只更新时间戳匹配的K线
if ($current_kline['id'] == $current_timestamp) {
    // 更新...
}
```

**为什么**：
- 1分钟K线：当前分钟的K线才更新（如 10:30:00 - 10:30:59）
- 5分钟K线：当前5分钟的K线才更新（如 10:30:00 - 10:34:59）
- 历史K线不受影响

### 2. 智能更新高低价

```php
// 收盘价 = 最新市场价
$current_kline['close'] = floatval($market_data['close']);

// 如果新收盘价超过了当前最高价，更新最高价
if ($current_kline['close'] > $current_kline['high']) {
    $current_kline['high'] = $current_kline['close'];
}

// 如果新收盘价低于当前最低价，更新最低价
if ($current_kline['close'] < $current_kline['low']) {
    $current_kline['low'] = $current_kline['close'];
}
```

**效果**：
- 收盘价实时跟随市场价
- 最高价和最低价自动扩展（只增不减）
- 开盘价保持不变（不应该变）

### 3. 推送给客户端

```php
// 只在有客户端订阅时才推送
if (Gateway::getClientIdCountByGroup($group_id) > 0) {
    Gateway::sendToGroup($group_id, json_encode([...]));
}
```

**优势**：
- 避免无效推送（没有客户端时不浪费资源）
- 有客户端时，每秒推送一次最新数据

---

## 数据流对比

### 原始实现（无定时器）

```
时间轴:  0s    30s    60s    90s   120s
        │     │      │      │     │
实时价:  2700 → 2705 → 2710 → 2715 → 2720  (每秒更新)
        ▲▲▲▲▲  ▲▲▲▲▲  ▲▲▲▲▲  ▲▲▲▲▲  ▲▲▲▲▲

K线价:  2700 ─────────→ 2710 ─────────→ 2720
        ▲              ▲              ▲
        WebSocket     WebSocket     WebSocket
        推送          推送          推送

❌ 问题：K线30秒才更新一次，用户看起来"卡住"了
```

### 新实现（有定时器）

```
时间轴:  0s  1s  2s  3s  4s  5s  6s  7s  8s ...
        │   │   │   │   │   │   │   │   │
实时价:  2700→2701→2702→2703→2704→2705→2706→2707→2708
        ▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲▲

K线价:  2700→2701→2702→2703→2704→2705→2706→2707→2708
        ▲   ▲   ▲   ▲   ▲   ▲   ▲   ▲   ▲
        定时器触发（每1秒）

✅ 效果：K线每秒更新，与实时价同步跳动！
```

---

## 适用周期

所有 9 个K线周期都添加了定时器：

| 周期 | 文件 | 定时器频率 | 效果 |
|------|------|-----------|------|
| 1分钟 | `swap_kline_1min.php` | 每1秒 | 当前分钟K线实时跳动 |
| 5分钟 | `swap_kline_5min.php` | 每1秒 | 当前5分钟K线实时跳动 |
| 15分钟 | `swap_kline_15min.php` | 每1秒 | 当前15分钟K线实时跳动 |
| 30分钟 | `swap_kline_30min.php` | 每1秒 | 当前30分钟K线实时跳动 |
| 1小时 | `swap_kline_60min.php` | 每1秒 | 当前小时K线实时跳动 |
| 4小时 | `swap_kline_4hour.php` | 每1秒 | 当前4小时K线实时跳动 |
| 1天 | `swap_kline_1day.php` | 每1秒 | 当前天K线实时跳动 |
| 1周 | `swap_kline_1week.php` | 每1秒 | 当前周K线实时跳动 |
| 1月 | `swap_kline_1mon.php` | 每1秒 | 当前月K线实时跳动 |

**✅ 所有周期的当前K线都能实时跳动！**

---

## 性能影响

### CPU 使用

每个进程每秒执行一次定时器回调：
- **单进程**: ~0.1% CPU（每秒1次）
- **9个进程**: ~0.9% CPU（总计）

**评估**: 非常轻量，完全可以接受

### Redis 操作

每个进程每秒：
- **读取**: 2次（市场数据 + K线数据）
- **写入**: 2次（K线书 + 当前K线）

**9个进程总计**：
- **读取**: 18次/秒
- **写入**: 18次/秒

**评估**: Redis 性能完全足够（可处理数万次/秒）

### 网络推送

只在有客户端订阅时推送：
- **无客户端**: 0 推送
- **有客户端**: 每秒推送一次（每周期）

**带宽**: ~1 KB/秒/周期/客户端（可忽略）

---

## 验证方法

### 1. 重启服务

```bash
cd /home/user/okxswapxaut/swap_refactored
./stop.sh
./start.sh
```

### 2. 监控实时价变化

```bash
# 每秒刷新，查看实时价
watch -n 1 'redis-cli --raw GET swap:XAUT_detail | jq ".close"'
```

**预期**: 每秒价格都在变化

### 3. 监控 K线价格变化

```bash
# 每秒刷新，查看1分钟K线价格
watch -n 1 'redis-cli --raw GET swap_kline_now_1min | jq ".close"'
```

**预期**: K线价格每秒都在变化，与实时价同步！

### 4. 对比同步性

```bash
#!/bin/bash
while true; do
    market_price=$(redis-cli --raw GET swap:XAUT_detail | jq -r '.close')
    kline_price=$(redis-cli --raw GET swap_kline_now_1min | jq -r '.close')
    echo "时间: $(date +%H:%M:%S) | 实时价: $market_price | K线价: $kline_price"
    sleep 1
done
```

**预期输出**：
```
时间: 10:30:15 | 实时价: 2700.50 | K线价: 2700.50  ✓ 同步
时间: 10:30:16 | 实时价: 2700.80 | K线价: 2700.80  ✓ 同步
时间: 10:30:17 | 实时价: 2701.00 | K线价: 2701.00  ✓ 同步
```

### 5. 查看进程日志

```bash
tail -f runtime/swap_kline_1min.log
```

如果看到大量 `[定时器异常]` 输出，说明有问题需要排查。

---

## 故障排查

### 问题 1：K线仍然不跳动

**可能原因**：
1. 实时价本身没有更新（swap_market.php 未运行）
2. K线进程未启动
3. Redis 连接问题

**排查步骤**：
```bash
# 1. 检查实时价是否更新
redis-cli GET swap:XAUT_detail

# 2. 检查K线进程状态
php swap_kline_1min.php status

# 3. 查看K线进程日志
tail -f runtime/swap_kline_1min.log
```

### 问题 2：K线跳动但不准确

**可能原因**：价格差值未正确应用

**排查步骤**：
```bash
# 检查差值
redis-cli GET swap:XAUT_price_difference

# 检查市场数据
redis-cli --raw GET swap:XAUT_detail | jq .
```

### 问题 3：CPU 使用率高

**可能原因**：定时器频率过高或有死循环

**排查步骤**：
```bash
# 查看进程CPU占用
top -p $(cat runtime/swap_kline_1min.pid)

# 如果CPU持续100%，检查日志是否有异常循环
```

---

## 总结

### 核心改进

✅ **添加1秒定时器** - 主动更新当前K线价格
✅ **实时读取市场数据** - 从 Redis 获取最新实时价
✅ **智能更新OHLC** - 收盘价/最高价/最低价自动更新
✅ **实时推送客户端** - 用户看到流畅跳动的K线图

### 效果对比

| 指标 | 原始实现 | 新实现 |
|------|---------|--------|
| **K线更新频率** | 每30-60秒 | 每1秒 ✅ |
| **实时性** | 差（延迟大） | 优秀（实时） ✅ |
| **用户体验** | 卡顿、不跟随 | 流畅、实时跳动 ✅ |
| **CPU占用** | 低 | 低（+0.9%） ✅ |

### 最终效果

```
实时价跳动: 2700 → 2701 → 2702 → 2703 → 2704 ...
              ▼     ▼     ▼     ▼     ▼
K线价跳动:  2700 → 2701 → 2702 → 2703 → 2704 ...
            ✓ 完美同步！
```

**用户看到的效果**：
- K线图像心电图一样实时跳动
- 收盘价跟随实时价变化
- 最高价和最低价动态扩展
- 完全同步，无延迟！🎉
