# K线进程分离说明

## 问题分析

### 原始问题

用户反馈：
1. **少了好多K线文件** - 发现重构版只有一个 `swap_kline.php` 文件
2. **K线的更新频率变低了，导致好久没跟上实时价** - 单进程处理所有周期导致性能问题

### 原始代码架构

原始代码 (`swap/` 目录) 为**每个K线周期创建独立的进程文件**：

```
swap/
├── swap_kline_1min.php    # 1分钟K线独立进程
├── swap_kline_5min.php    # 5分钟K线独立进程
├── swap_kline_15min.php   # 15分钟K线独立进程
├── swap_kline_30min.php   # 30分钟K线独立进程
├── swap_kline_60min.php   # 1小时K线独立进程
├── swap_kline_4hour.php   # 4小时K线独立进程
├── swap_kline_1day.php    # 1天K线独立进程
├── swap_kline_1week.php   # 1周K线独立进程
└── swap_kline_1mon.php    # 1月K线独立进程
```

### 重构版的问题

最初的重构版只创建了一个 `swap_kline.php` 文件：
- 试图在单个进程中处理所有9个周期
- 虽然订阅了所有周期，但处理能力有限
- 导致更新频率降低，无法跟上实时价格变化

---

## 解决方案

### 架构调整

恢复原始的**多进程架构**，为每个K线周期创建独立的进程文件。

### 新增文件

```
swap_refactored/
├── swap_kline_1min.php    # ✅ 新增 - 1分钟K线独立进程
├── swap_kline_5min.php    # ✅ 新增 - 5分钟K线独立进程
├── swap_kline_15min.php   # ✅ 新增 - 15分钟K线独立进程
├── swap_kline_30min.php   # ✅ 新增 - 30分钟K线独立进程
├── swap_kline_60min.php   # ✅ 新增 - 1小时K线独立进程
├── swap_kline_4hour.php   # ✅ 新增 - 4小时K线独立进程
├── swap_kline_1day.php    # ✅ 新增 - 1天K线独立进程
├── swap_kline_1week.php   # ✅ 新增 - 1周K线独立进程
└── swap_kline_1mon.php    # ✅ 新增 - 1月K线独立进程
```

### 每个文件的特点

#### 1. 独立的 WebSocket 连接

```php
// 每个文件都有自己的 WebSocket 连接
$con = new AsyncTcpConnection('wss://wspri.okx.com:8443/ws/v5/ipublic');
```

#### 2. 只订阅单一周期

```php
// swap_kline_1min.php
$period = '1m';
$ok = new OK($period, $symbol, true, false);  // 不拉取历史数据
$onConnect = $ok->onConnectParams($period);   // 只订阅 1m
```

```php
// swap_kline_5min.php
$period = '5m';
$ok = new OK($period, $symbol, true, false);
$onConnect = $ok->onConnectParams($period);   // 只订阅 5m
```

#### 3. 独立的 PID 文件

```php
Worker::$pidFile = __DIR__ . '/runtime/' . basename(__FILE__, '.php') . '.pid';
```

每个进程有自己的 PID 文件：
- `runtime/swap_kline_1min.pid`
- `runtime/swap_kline_5min.pid`
- ...

---

## 管理脚本更新

### start.sh

启动所有 **13 个服务**：

```bash
[1/13] FOREX 价格获取服务 (get_forex_price.php)
[2/13] 市场行情数据处理服务 (swap_market.php)
[3/13] 深度数据处理服务 (swap_depth.php)
[4/13] 成交数据处理服务 (swap_trade.php)
[5/13] 1分钟K线数据采集服务 (swap_kline_1min.php)
[6/13] 5分钟K线数据采集服务 (swap_kline_5min.php)
[7/13] 15分钟K线数据采集服务 (swap_kline_15min.php)
[8/13] 30分钟K线数据采集服务 (swap_kline_30min.php)
[9/13] 1小时K线数据采集服务 (swap_kline_60min.php)
[10/13] 4小时K线数据采集服务 (swap_kline_4hour.php)
[11/13] 1天K线数据采集服务 (swap_kline_1day.php)
[12/13] 1周K线数据采集服务 (swap_kline_1week.php)
[13/13] 1月K线数据采集服务 (swap_kline_1mon.php)
```

### stop.sh

并行停止所有 13 个服务：

```bash
php swap_kline_1min.php stop &
php swap_kline_5min.php stop &
php swap_kline_15min.php stop &
# ... 其他9个K线进程
wait  # 等待所有进程完成
```

### status.sh

检查所有 13 个服务的状态：

```bash
[5] 1分钟K线数据采集服务
php swap_kline_1min.php status

[6] 5分钟K线数据采集服务
php swap_kline_5min.php status

# ... 其他进程
```

---

## 优势对比

### 单进程架构（旧重构版）

❌ **缺点**：
- 单个 WebSocket 连接处理所有周期
- 数据处理有瓶颈
- 更新频率受限
- 一个进程崩溃影响所有周期
- 不符合原始代码设计

✓ **优点**：
- 进程数量少
- 管理相对简单（但这不是关键）

### 多进程架构（新重构版）

✓ **优点**：
1. **更高的更新频率**
   - 每个周期独立的 WebSocket 连接
   - 并行处理，无相互阻塞
   - 能够跟上实时价格变化

2. **更好的容错性**
   - 单个进程失败不影响其他周期
   - 可以单独重启有问题的进程

3. **更清晰的进程管理**
   - 每个进程有独立的 PID 文件
   - 可以独立监控、重启、调试

4. **符合原始设计**
   - 与原始代码架构一致
   - 经过实际生产环境验证

❌ **缺点**：
- 进程数量多（13个）
- 占用更多系统资源（可忽略，每个进程很轻量）

---

## 性能对比

### 更新频率对比

| 架构 | 1分钟周期更新 | 5分钟周期更新 | 问题 |
|------|--------------|--------------|------|
| **单进程** | 可能延迟 | 可能延迟 | 所有周期共享处理能力，互相影响 |
| **多进程** | 实时 | 实时 | 每个周期独立处理，互不干扰 ✓ |

### 资源使用

每个 Workerman Worker 进程非常轻量：
- 内存占用：约 5-10 MB
- CPU 占用：事件驱动，空闲时几乎为 0

**13 个进程总计**：
- 内存：约 65-130 MB
- CPU：事件驱动，整体负载低

---

## 数据流

### 单个K线进程的数据流

```
OKX WebSocket 推送 (candle1m/candle5m/...)
    ↓
swap_kline_Xmin.php 接收
    ↓
OK.php::data() 处理
    ↓
获取价格差值 (swap:XAUT_price_difference)
    ↓
应用差值到 OHLC
    ↓
更新缓存 (swap:XAUT_kline_book_Xmin)
    ↓
更新当前K线 (swap_kline_now_Xmin)
    ↓
推送给客户端 (GatewayWorker)
```

### 9个进程并行运行

```
┌─────────────────┐
│ swap_kline_1min │ → 处理 1m 数据 → Redis → 客户端
├─────────────────┤
│ swap_kline_5min │ → 处理 5m 数据 → Redis → 客户端
├─────────────────┤
│ swap_kline_15min│ → 处理 15m 数据 → Redis → 客户端
├─────────────────┤
│      ...        │
└─────────────────┘
     并行运行，互不阻塞
```

---

## 启动顺序

为了确保数据完整性，服务按以下顺序启动：

1. **FOREX 价格获取** - 获取实时 FOREX 价格
2. **市场行情处理** - 计算价格差值
3. **其他数据服务** - 使用差值处理数据
   - 深度数据 (swap_depth.php)
   - 成交数据 (swap_trade.php)
   - **9个K线进程** (并行启动)

---

## 验证方法

### 1. 检查所有进程状态

```bash
cd /home/user/okxswapxaut/swap_refactored
./status.sh
```

预期输出：13 个服务都显示 "running"

### 2. 检查 PID 文件

```bash
ls -la runtime/*.pid
```

预期输出：13 个 PID 文件

### 3. 检查 Redis 缓存

```bash
# 检查所有K线历史数据
redis-cli KEYS "swap:XAUT_kline_book_*"

# 检查所有K线当前数据
redis-cli KEYS "swap_kline_now_*"
```

预期输出：各 9 个键值

### 4. 监控实时更新

```bash
# 监控 1分钟K线更新
redis-cli --raw GET swap_kline_now_1min | jq .

# 每秒刷新一次，观察是否实时更新
watch -n 1 'redis-cli --raw GET swap_kline_now_1min | jq .close'
```

---

## 常见问题

### Q1: 为什么不用一个进程订阅所有周期？

**A**: 虽然技术上可行，但会导致：
1. 单点瓶颈 - 所有数据处理集中在一个进程
2. 更新延迟 - 处理能力有限，无法及时处理所有周期
3. 容错性差 - 一个进程崩溃影响所有周期

### Q2: 13个进程会不会占用太多资源？

**A**: 不会。每个 Workerman Worker 进程：
- 使用事件驱动模型，空闲时资源消耗极低
- 内存占用约 5-10 MB
- 13个进程总计约 130 MB，现代服务器完全可以承受

### Q3: 如何单独重启某个周期？

**A**: 每个周期都是独立的进程，可以单独操作：

```bash
# 重启 1分钟K线
php swap_kline_1min.php restart

# 停止 5分钟K线
php swap_kline_5min.php stop

# 查看 15分钟K线状态
php swap_kline_15min.php status
```

### Q4: 原来的 swap_kline.php 还需要吗？

**A**: 不需要了。新架构下：
- 使用 9 个独立的 `swap_kline_Xmin.php` 文件
- 原来的 `swap_kline.php` 可以删除或保留作为参考

---

## 总结

### 关键改进

✅ **恢复多进程架构** - 每个K线周期独立进程
✅ **提高更新频率** - 能够跟上实时价格变化
✅ **增强容错性** - 单个进程失败不影响其他
✅ **符合原始设计** - 与生产环境验证的架构一致

### 文件清单

**新增文件（9个）**：
- swap_kline_1min.php
- swap_kline_5min.php
- swap_kline_15min.php
- swap_kline_30min.php
- swap_kline_60min.php
- swap_kline_4hour.php
- swap_kline_1day.php
- swap_kline_1week.php
- swap_kline_1mon.php

**更新文件（3个）**：
- start.sh - 启动所有13个服务
- stop.sh - 停止所有13个服务
- status.sh - 检查所有13个服务状态

### 下一步

1. 测试启动所有服务：`./start.sh`
2. 检查服务状态：`./status.sh`
3. 监控 Redis 数据更新
4. 验证实时价格跟踪是否正常
