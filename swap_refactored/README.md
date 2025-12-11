# OKX XAUT Swap 数据采集系统（重构版）

## 项目概述

这是 OKX XAUT-USDT-SWAP 数据采集系统的重构版本，主要改进了价格调整逻辑和容错机制。

### 核心改进

1. **清晰的差值计算逻辑**
   - FOREX 价格获取独立（get_forex_price.php）
   - 差值计算在 market 数据处理中完成（swap_market.php）
   - 所有数据统一应用同一个差值

2. **强大的容错机制**
   - FOREX 数据获取失败时自动重连
   - 差值不存在时使用上次的差值
   - 确保即使 FOREX 离线，OKX 数据仍可用（使用历史差值）

3. **数据分离存储**
   - OKX 原始数据单独存储（可追溯）
   - 调整后的数据用于显示
   - 差值详细信息可查询

4. **完整的监控能力**
   - FOREX 状态监控（online/offline）
   - 差值计算信息可查询
   - 服务运行状态检查

---

## 目录结构

```
swap_refactored/
├── README.md                   # 本文档
├── REDIS_KEYS.md              # Redis 键值对照表
├── runtime/                   # 运行时目录（PID 文件等）
├── get_forex_price.php        # FOREX 价格获取服务
├── swap_market.php            # 市场行情处理 + 差值计算
├── OK.php                     # K线数据处理类
├── swap_kline.php             # K线数据采集主进程
├── swap_depth.php             # 深度数据处理
├── swap_trade.php             # 成交数据处理
├── start.sh                   # 启动脚本
├── stop.sh                    # 停止脚本
└── status.sh                  # 状态检查脚本
```

---

## 快速开始

### 1. 启动所有服务

```bash
cd /path/to/swap_refactored
./start.sh
```

**启动顺序**：
1. FOREX 价格获取服务
2. 市场行情数据处理服务（计算差值）
3. 深度数据处理服务
4. 成交数据处理服务
5. K线数据采集服务

### 2. 检查服务状态

```bash
./status.sh
```

输出示例：
```
========================================
服务状态检查
========================================

[1] FOREX 价格获取服务
Workerman[get_forex_price.php] status
...

FOREX 状态: online
FOREX 最后更新时间: 1733900000
价格差值: 2050.00
OKX 最新价格: 650.50
```

### 3. 停止所有服务

```bash
./stop.sh
```

---

## 数据流程

```
┌─────────────────────────────────────────────────────────────┐
│ 第1步：获取 FOREX 价格                                        │
│ get_forex_price.php                                         │
│   ↓                                                         │
│ 从 EFX 获取 XAU/USD 实时价格                                 │
│   ↓                                                         │
│ 存储到 Redis:                                               │
│   - swap:FOREX_XAU_USD_price                               │
│   - swap:FOREX_status (online/offline)                     │
│   - swap:FOREX_last_update_time                            │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│ 第2步：获取 OKX Market 数据并计算差值                         │
│ swap_market.php                                             │
│   ↓                                                         │
│ 从 OKX 获取 XAUT-USDT-SWAP market 数据                      │
│   ↓                                                         │
│ 存储原始数据: swap:OKX_XAUT_market_original                 │
│   ↓                                                         │
│ 读取 FOREX 价格: swap:FOREX_XAU_USD_price                  │
│   ↓                                                         │
│ 计算差值 = FOREX价格 - OKX价格                              │
│   ↓                                                         │
│ 存储差值:                                                    │
│   - swap:XAUT_price_difference                             │
│   - swap:XAUT_difference_info                              │
│   ↓                                                         │
│ 应用差值到 market 数据                                       │
│   ↓                                                         │
│ 存储调整后数据: swap:XAUT_detail                            │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│ 第3步：处理其他 OKX 数据（K线/深度/成交）                     │
│ swap_kline.php / swap_depth.php / swap_trade.php          │
│   ↓                                                         │
│ 接收 OKX WebSocket 数据                                     │
│   ↓                                                         │
│ 读取差值: swap:XAUT_price_difference                        │
│   ↓                                                         │
│ 应用差值到所有价格字段                                        │
│   ↓                                                         │
│ 存储到 Redis 并推送给客户端                                  │
└─────────────────────────────────────────────────────────────┘
```

---

## Redis 键值说明

详细的 Redis 键值对照表请查看 [REDIS_KEYS.md](./REDIS_KEYS.md)

### 核心键值

| 键值 | 说明 |
|------|------|
| `swap:FOREX_XAU_USD_price` | FOREX 实时价格数据 |
| `swap:FOREX_status` | FOREX 状态（online/offline） |
| `swap:OKX_XAUT_market_original` | OKX 原始 market 数据 |
| `swap:OKX_XAUT_last_price` | OKX 最新价格 |
| `swap:XAUT_price_difference` | 价格差值 |
| `swap:XAUT_difference_info` | 差值详细信息 |
| `swap:XAUT_detail` | 调整后的市场数据 |
| `swap:XAUT_kline_book_*` | K线历史数据 |

### 查询示例

```bash
# 查看 FOREX 状态
redis-cli GET swap:FOREX_status

# 查看价格差值
redis-cli GET swap:XAUT_price_difference

# 查看差值详细信息
redis-cli HGETALL swap:XAUT_difference_info

# 查看 OKX 原始数据
redis-cli HGETALL swap:OKX_XAUT_market_original

# 查看调整后的市场数据
redis-cli HGETALL swap:XAUT_detail
```

---

## 容错机制

### FOREX 数据获取失败

**场景**：无法连接 EFX 服务器或数据获取失败

**处理**：
1. 标记 `swap:FOREX_status` 为 `offline`
2. 保持上次的价格差值不变
3. 所有数据处理继续使用上次的差值
4. 每隔 10 秒自动重试连接
5. 连接恢复后，重新计算差值并标记为 `online`

**结果**：
- ✅ 系统继续运行
- ✅ 使用历史差值，数据仍然可用
- ⚠️ 差值可能不是最新的

### OKX 数据获取失败

**场景**：WebSocket 连接断开

**处理**：
1. 自动重连（1秒后）
2. 重连成功后继续正常处理

### 差值不存在

**场景**：系统首次启动，FOREX 或 OKX 数据尚未获取

**处理**：
1. 使用差值 `0`
2. 数据显示为 OKX 原始价格
3. 等待 FOREX 和 OKX 数据都获取成功后，自动计算差值

---

## 监控和调试

### 查看日志

Workerman 会将输出打印到标准输出。如果需要查看日志，可以重定向到文件：

```bash
# 启动时重定向日志
php get_forex_price.php start > logs/forex.log 2>&1 &
```

### 监控关键指标

```bash
# 1. 检查 FOREX 状态
redis-cli GET swap:FOREX_status
# 应该返回: online

# 2. 检查 FOREX 最后更新时间
redis-cli GET swap:FOREX_last_update_time
# 应该是最近的时间戳

# 3. 检查差值是否存在
redis-cli GET swap:XAUT_price_difference
# 应该返回一个数字（如 2050.00）

# 4. 检查差值详细信息
redis-cli HGETALL swap:XAUT_difference_info
# 应该返回完整的差值计算信息
```

### 常见问题排查

#### 问题1：差值一直为 0

**可能原因**：
- FOREX 数据未获取成功
- OKX 数据未获取成功

**排查**：
```bash
# 检查 FOREX 状态
redis-cli GET swap:FOREX_status

# 检查 FOREX 价格数据
redis-cli HGETALL swap:FOREX_XAU_USD_price

# 检查 OKX 最新价格
redis-cli GET swap:OKX_XAUT_last_price
```

#### 问题2：FOREX 状态一直是 offline

**可能原因**：
- 网络连接问题
- EFX 服务器不可达

**解决**：
- 检查网络连接
- 查看 get_forex_price.php 的日志输出
- 手动测试连接：`curl https://rates-live.efxnow.com`

#### 问题3：数据不更新

**可能原因**：
- 进程崩溃
- WebSocket 连接断开未重连

**解决**：
```bash
# 检查进程状态
./status.sh

# 重启所有服务
./stop.sh
./start.sh
```

---

## 与原版本的兼容性

### 保持兼容的键值

以下 Redis 键值与原版本完全兼容，前端无需修改：

- ✅ `swap:XAUT_detail`
- ✅ `swap:XAUT_kline_book_*`
- ✅ `swap:XAUT_kline_*`
- ✅ `swap:XAUT_depth_buy`
- ✅ `swap:XAUT_depth_sell`
- ✅ `swap:trade_detail_XAUT`
- ✅ `swap:tradeList_XAUT`

### 废弃的键值

以下键值在重构版中不再更新：

- ❌ `swap:XAU_USD_data`（由 `swap:FOREX_XAU_USD_price` 替代）
- ❌ `swap:XAU_USD_data2`（由 `swap:XAUT_price_difference` 替代）

如果有其他程序依赖这两个键值，需要修改为使用新的键值。

---

## 性能优化建议

### Redis 过期策略

重构版已为所有键值设置了合理的过期时间：

- FOREX 数据：5分钟
- Market 数据：1小时
- K线数据：7-365天（根据周期）
- 深度数据：1分钟
- 成交数据：1小时

### 内存优化

如果 Redis 内存不足，可以调整 K线数据的保留时间：

```php
// OK.php 中修改过期时间
Cache::store('redis')->put($kline_book_key, $cache_data, 86400 * 3); // 改为3天
```

---

## 开发和扩展

### 添加新的交易对

1. 修改 `swap_market.php`、`swap_kline.php` 等文件中的 `instId`
2. 修改 Redis 键值前缀
3. 确保 FOREX 数据源支持该交易对

### 自定义差值计算

如果需要自定义差值计算逻辑，修改 `swap_market.php` 第3步：

```php
// 自定义差值计算
$difference = calculateCustomDifference($forex_price, $okx_original['close']);
```

---

## 许可证

内部使用

---

## 支持

如有问题，请联系开发团队。
