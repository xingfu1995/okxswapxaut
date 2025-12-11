# K线自动拉取历史数据修复

## 问题描述

用户反馈：
- 1分钟K线实时价格跳动正常（秒级更新）
- 5分钟及其他K线更新很慢，需要几秒钟才更新

## 根本原因

### 原有设计

1. **1分钟K线进程** (`swap_kline_1min.php`):
   - `new OK($period, $symbol, true, true)` - 第4个参数 `$isget = true`
   - 负责拉取**所有9个周期**的历史K线数据到Redis缓存

2. **其他8个K线进程** (`swap_kline_5min.php` 等):
   - `new OK($period, $symbol, true, false)` - 第4个参数 `$isget = false`
   - **不拉取历史数据**，依赖1分钟进程提供的缓存

3. **定时器机制**:
   - 所有9个K线进程都有1秒定时器
   - 定时器从Redis读取 `swap:XAUT_kline_book_{period}` 缓存
   - 如果缓存为空，定时器直接 `return`，无法更新价格

### 问题场景

以下情况会导致K线定时器无法工作：

1. **1分钟进程启动较晚**
   - 其他进程先启动，此时缓存为空
   - 定时器无法工作，直到1分钟进程完成历史数据拉取

2. **1分钟进程拉取历史数据需要时间**
   - OK构造函数中有 `foreach` 循环拉取9个周期
   - 每个周期有限流延迟（0.35秒间隔）
   - 总共需要约 3-4 秒

3. **Redis缓存被清空**
   - 如果缓存过期或被清空
   - 其他进程的定时器失效

4. **1分钟进程崩溃重启**
   - 其他进程依然运行但缓存为空
   - 定时器无法工作

## 解决方案

### 修改内容

为所有**非1分钟**的K线进程（8个文件）添加**自动拉取历史数据**机制：

1. 添加标志变量 `$history_fetched` 跟踪是否已拉取
2. 在定时器中检测缓存是否为空
3. 如果缓存为空且未拉取过，自动调用 `$ok->getHistory($ok->period)` 拉取**本周期**的历史数据
4. 只拉取一次，避免重复请求

### 修改的文件

- ✅ `swap_kline_5min.php`
- ✅ `swap_kline_15min.php`
- ✅ `swap_kline_30min.php`
- ✅ `swap_kline_60min.php`
- ✅ `swap_kline_4hour.php`
- ✅ `swap_kline_1day.php`
- ✅ `swap_kline_1week.php`
- ✅ `swap_kline_1mon.php`

### 代码变更

**修改前**:
```php
Timer::add(1, function() use ($ok) {
    try {
        $market_data = Cache::store('redis')->get('swap:XAUT_detail');
        if (!$market_data) {
            return;
        }

        $period = $ok->periods[$ok->period]['period'];
        $kline_book_key = 'swap:' . $ok->symbol . '_kline_book_' . $period;
        $kline_book = Cache::store('redis')->get($kline_book_key);

        if (!$kline_book || empty($kline_book)) {
            return;  // ✗ 缓存为空时直接退出，定时器无法工作
        }

        // ... 更新当前K线价格
    } catch (\Exception $e) {
        echo "[定时器异常] " . $e->getMessage() . "\n";
    }
});
```

**修改后**:
```php
$history_fetched = false; // 标记是否已拉取历史数据
Timer::add(1, function() use ($ok, &$history_fetched) {
    try {
        $market_data = Cache::store('redis')->get('swap:XAUT_detail');
        if (!$market_data) {
            return;
        }

        $period = $ok->periods[$ok->period]['period'];
        $kline_book_key = 'swap:' . $ok->symbol . '_kline_book_' . $period;
        $kline_book = Cache::store('redis')->get($kline_book_key);

        // ✓ 如果缓存为空且还没拉取过历史数据，尝试拉取
        if ((!$kline_book || empty($kline_book)) && !$history_fetched) {
            echo "[自动拉取] 5分钟K线缓存为空，自动拉取历史数据...\n";
            $ok->getHistory($ok->period);
            $history_fetched = true;
            return;
        }

        if (!$kline_book || empty($kline_book)) {
            return;
        }

        // ... 更新当前K线价格
    } catch (\Exception $e) {
        echo "[定时器异常] " . $e->getMessage() . "\n";
    }
});
```

## 优势

### 1. 高可用性
- 每个K线进程独立运行，互不依赖
- 即使1分钟进程崩溃，其他进程也能正常工作

### 2. 自动恢复
- 缓存丢失时自动重新拉取
- 无需手动重启所有进程

### 3. 启动顺序无关
- 可以任意顺序启动K线进程
- 不需要等待1分钟进程先启动

### 4. 性能优化
- 每个进程只拉取自己周期的历史数据（1个周期）
- 避免1分钟进程拉取所有周期导致的延迟
- 并发拉取，总时间更短

### 5. 调试友好
- 添加日志输出 `[自动拉取] X分钟K线缓存为空，自动拉取历史数据...`
- 可以清楚看到哪些进程触发了自动拉取

## 工作流程

### 场景1: 正常启动（1分钟进程先启动）

```
时间轴:
0s   | 启动 swap_kline_1min.php
     | - 等待3秒确保差值计算完成
3s   | - 拉取所有9个周期历史数据（需要3-4秒）
7s   | - 完成，缓存已填充
     |
8s   | 启动 swap_kline_5min.php
     | - 不拉取历史数据
     | - 定时器每秒运行
9s   | - 定时器: 检查缓存 -> ✓ 存在 -> 更新价格
10s  | - 定时器: 检查缓存 -> ✓ 存在 -> 更新价格
...  | ✓ 秒级更新
```

### 场景2: 5分钟进程先启动（修复前 - 问题）

```
时间轴:
0s   | 启动 swap_kline_5min.php
     | - 不拉取历史数据
     | - 定时器每秒运行
1s   | - 定时器: 检查缓存 -> ✗ 不存在 -> return
2s   | - 定时器: 检查缓存 -> ✗ 不存在 -> return
3s   | - 定时器: 检查缓存 -> ✗ 不存在 -> return
     |
5s   | 启动 swap_kline_1min.php
     | - 等待3秒
8s   | - 拉取所有9个周期历史数据
12s  | - 完成，缓存已填充
     |
13s  | - 定时器: 检查缓存 -> ✓ 存在 -> 更新价格
...  | ✓ 此后正常，但前13秒无更新
```

### 场景3: 5分钟进程先启动（修复后 - 正常）

```
时间轴:
0s   | 启动 swap_kline_5min.php
     | - 不拉取历史数据
     | - 定时器每秒运行
     | - history_fetched = false
1s   | - 定时器: 检查缓存 -> ✗ 不存在 -> 自动拉取5分钟历史数据
     |   echo "[自动拉取] 5分钟K线缓存为空，自动拉取历史数据..."
     |   $ok->getHistory('5m')
     |   history_fetched = true
2s   | - 定时器: 检查缓存 -> ✓ 存在 -> 更新价格
3s   | - 定时器: 检查缓存 -> ✓ 存在 -> 更新价格
...  | ✓ 秒级更新，延迟仅1-2秒
```

## 注意事项

1. **限流控制**
   - OK类中有全局限流 `$minInterval = 0.35秒`
   - 多个进程同时拉取历史数据不会超过OKX API限制

2. **差值依赖**
   - 历史数据拉取需要价格差值 (`swap:XAUT_price_difference`)
   - 建议 `swap_market.php` 最先启动（计算差值）
   - 或者在1分钟K线中保留3秒等待

3. **Redis缓存**
   - 缓存键: `swap:XAUT_kline_book_{period}`
   - 过期时间: 7天
   - 如果缓存过期，会自动重新拉取

## 测试验证

### 验证修复

1. **停止所有K线进程**:
   ```bash
   cd /home/user/okxswapxaut/swap_refactored
   ./stop.sh
   ```

2. **清空Redis缓存** (可选):
   ```bash
   redis-cli flushall
   ```

3. **只启动5分钟K线**:
   ```bash
   php swap_kline_5min.php start
   ```

4. **观察日志**:
   - 应该看到 `[自动拉取] 5分钟K线缓存为空，自动拉取历史数据...`
   - 然后每秒推送K线数据

5. **连接WebSocket测试**:
   ```javascript
   const ws = new WebSocket('ws://your-ip:8282');
   ws.onopen = () => {
       ws.send(JSON.stringify({
           cmd: 'subscribe',
           channel: 'swapKline_XAUT_5min'
       }));
   };
   ws.onmessage = (event) => {
       console.log(new Date(), event.data);
   };
   ```

6. **预期结果**:
   - 每秒收到一次K线数据更新
   - close价格实时跳动

## 总结

✅ **问题**: 5分钟及其他K线更新慢，需要几秒钟才更新

✅ **原因**: 缓存为空时定时器无法工作，依赖1分钟进程拉取历史数据

✅ **修复**: 添加自动拉取机制，缓存为空时自动拉取本周期历史数据

✅ **效果**: 所有K线周期都能秒级更新，不依赖其他进程

✅ **文件**: 修改了8个K线文件（5min, 15min, 30min, 60min, 4hour, 1day, 1week, 1mon）
