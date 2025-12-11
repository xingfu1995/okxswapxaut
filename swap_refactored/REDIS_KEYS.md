# Redis 键值对照表

## 重构后的 Redis 键值结构

### 1. FOREX 相关数据

| Redis Key | 数据类型 | 说明 | 过期时间 | 示例值 |
|-----------|---------|------|---------|--------|
| `swap:FOREX_XAU_USD_price` | Hash | FOREX XAU/USD 实时价格数据 | 5分钟 | 见下方 |
| `swap:FOREX_last_update_time` | String | FOREX 数据最后更新时间（时间戳） | 5分钟 | `1733900000` |
| `swap:FOREX_status` | String | FOREX 数据获取状态 | 5分钟 | `online` / `offline` |

**`swap:FOREX_XAU_USD_price` 结构**：
```json
{
  "current": "2700.50",
  "bid": "2700.30",
  "ask": "2700.70",
  "high": "2710.00",
  "low": "2690.00",
  "time": "1733900000",
  "symbol": "XAU/USD"
}
```

---

### 2. OKX Market 原始数据

| Redis Key | 数据类型 | 说明 | 过期时间 | 示例值 |
|-----------|---------|------|---------|--------|
| `swap:OKX_XAUT_market_original` | Hash | OKX XAUT-USDT-SWAP 原始市场数据（未调整） | 1小时 | 见下方 |
| `swap:OKX_XAUT_last_price` | String | OKX 最新成交价（用于快速获取） | 1小时 | `650.50` |

**`swap:OKX_XAUT_market_original` 结构**：
```json
{
  "id": "1733900000000",
  "close": "650.50",
  "open": "648.00",
  "high": "655.00",
  "low": "645.00",
  "vol": "1234567",
  "amount": "1234567",
  "timestamp": "1733900000"
}
```

---

### 3. 价格差值数据

| Redis Key | 数据类型 | 说明 | 过期时间 | 示例值 |
|-----------|---------|------|---------|--------|
| `swap:XAUT_price_difference` | String | 价格差值（FOREX - OKX） | 永久 | `2050.00` |
| `swap:XAUT_difference_info` | Hash | 差值详细信息（含计算来源） | 永久 | 见下方 |

**`swap:XAUT_difference_info` 结构**：
```json
{
  "difference": "2050.00",
  "forex_price": "2700.50",
  "okx_price": "650.50",
  "forex_status": "online",
  "calculate_time": "1733900000",
  "calculate_time_str": "2025-12-10 15:00:00"
}
```

---

### 4. 调整后的市场数据（对应原有键值）

| Redis Key | 数据类型 | 说明 | 过期时间 | 对应原有键值 |
|-----------|---------|------|---------|------------|
| `swap:XAUT_detail` | Hash | 调整后的市场行情数据 | 1小时 | ✓ 保持不变 |
| `swap:XAUT_Now_detail` | Hash | 当前实时行情数据 | 1小时 | ✓ 保持不变 |

**`swap:XAUT_detail` 结构**：
```json
{
  "id": "1733900000000",
  "close": "2700.50",
  "open": "2696.00",
  "high": "2705.00",
  "low": "2695.00",
  "vol": "1234567",
  "amount": "1234567",
  "increase": "0.0012",
  "increaseStr": "+0.12%"
}
```

---

### 5. K线数据（对应原有键值）

| Redis Key | 数据类型 | 说明 | 过期时间 | 对应原有键值 |
|-----------|---------|------|---------|------------|
| `swap:XAUT_kline_book_1min` | JSON Array | 1分钟K线历史数据（最多3000条） | 7天 | ✓ 保持不变 |
| `swap:XAUT_kline_book_5min` | JSON Array | 5分钟K线历史数据 | 7天 | ✓ 保持不变 |
| `swap:XAUT_kline_book_15min` | JSON Array | 15分钟K线历史数据 | 7天 | ✓ 保持不变 |
| `swap:XAUT_kline_book_30min` | JSON Array | 30分钟K线历史数据 | 7天 | ✓ 保持不变 |
| `swap:XAUT_kline_book_60min` | JSON Array | 1小时K线历史数据 | 7天 | ✓ 保持不变 |
| `swap:XAUT_kline_book_4hour` | JSON Array | 4小时K线历史数据 | 7天 | ✓ 保持不变 |
| `swap:XAUT_kline_book_1day` | JSON Array | 1天K线历史数据 | 30天 | ✓ 保持不变 |
| `swap:XAUT_kline_book_1week` | JSON Array | 1周K线历史数据 | 90天 | ✓ 保持不变 |
| `swap:XAUT_kline_book_1mon` | JSON Array | 1月K线历史数据 | 365天 | ✓ 保持不变 |
| `swap:XAUT_kline_1min` | Hash | 当前1分钟K线数据 | 1小时 | ✓ 保持不变 |
| `swap_kline_now_1min` | Hash | 实时1分钟K线数据 | 1小时 | ✓ 保持不变 |

---

### 6. 深度数据（对应原有键值）

| Redis Key | 数据类型 | 说明 | 过期时间 | 对应原有键值 |
|-----------|---------|------|---------|------------|
| `swap:XAUT_depth_buy` | JSON Array | 买盘深度数据（5档） | 1分钟 | ✓ 保持不变 |
| `swap:XAUT_depth_sell` | JSON Array | 卖盘深度数据（5档） | 1分钟 | ✓ 保持不变 |

---

### 7. 成交数据（对应原有键值）

| Redis Key | 数据类型 | 说明 | 过期时间 | 对应原有键值 |
|-----------|---------|------|---------|------------|
| `swap:trade_detail_XAUT` | Hash | 最新成交明细 | 1小时 | ✓ 保持不变 |
| `swap:tradeList_XAUT` | JSON Array | 最近30条成交明细 | 1小时 | ✓ 保持不变 |

---

## 新增的 Redis 键值（重构后）

与原有系统相比，新增以下键值：

1. `swap:FOREX_XAU_USD_price` - FOREX 价格数据（替代原有的 `swap:XAU_USD_data`）
2. `swap:FOREX_last_update_time` - FOREX 更新时间
3. `swap:FOREX_status` - FOREX 状态监控
4. `swap:OKX_XAUT_market_original` - OKX 原始市场数据
5. `swap:OKX_XAUT_last_price` - OKX 最新价格（快速访问）
6. `swap:XAUT_difference_info` - 差值详细信息

---

## 废弃的 Redis 键值（不再使用）

以下键值在重构后不再使用：

1. ~~`swap:XAU_USD_data`~~ - 由 `swap:FOREX_XAU_USD_price` 替代
2. ~~`swap:XAU_USD_data2`~~ - 由 `swap:XAUT_price_difference` 和 `swap:XAUT_difference_info` 替代

---

## 数据流程

```
第1步：get_forex_price.php
  ↓
  获取 FOREX XAU/USD 价格
  ↓
  存储到: swap:FOREX_XAU_USD_price
  存储到: swap:FOREX_last_update_time
  存储到: swap:FOREX_status

第2步：swap_market.php
  ↓
  获取 OKX XAUT-USDT-SWAP market 数据
  ↓
  存储原始数据: swap:OKX_XAUT_market_original
  存储最新价: swap:OKX_XAUT_last_price
  ↓
  读取 FOREX 价格: swap:FOREX_XAU_USD_price
  读取 OKX 价格: swap:OKX_XAUT_last_price
  ↓
  计算差值 = FOREX价格 - OKX价格
  ↓
  存储差值: swap:XAUT_price_difference
  存储差值详情: swap:XAUT_difference_info
  ↓
  应用差值到 market 数据
  ↓
  存储调整后数据: swap:XAUT_detail

第3步：OK.php（K线数据处理）
  ↓
  拉取/接收 OKX K线数据
  ↓
  读取差值: swap:XAUT_price_difference
  ↓
  应用差值到所有K线数据
  ↓
  存储: swap:XAUT_kline_book_*

第4步：swap_depth.php（深度数据）
  ↓
  接收 OKX 深度数据
  ↓
  读取差值: swap:XAUT_price_difference
  ↓
  应用差值到买卖盘数据
  ↓
  存储: swap:XAUT_depth_buy/sell

第5步：swap_trade.php（成交数据）
  ↓
  接收 OKX 成交数据
  ↓
  读取差值: swap:XAUT_price_difference
  ↓
  应用差值到成交价格
  ↓
  存储: swap:trade_detail_XAUT
```

---

## 容错机制

### FOREX 数据获取失败时

1. `swap:FOREX_status` 设置为 `offline`
2. `swap:XAUT_price_difference` 保持上次的差值（不更新）
3. 所有数据处理继续使用上次的差值
4. 每隔 10 秒重试获取 FOREX 数据
5. 恢复后，`swap:FOREX_status` 设置为 `online`，重新计算差值

### OKX 数据获取失败时

1. WebSocket 自动重连
2. 重连成功后继续正常处理

### 差值不存在时

1. 如果 `swap:XAUT_price_difference` 不存在，使用默认差值 `0`
2. 此时数据显示为 OKX 原始价格
3. 等待 FOREX 和 OKX 数据都获取成功后，重新计算差值

---

## 监控指标

可以通过以下键值监控系统状态：

```bash
# 检查 FOREX 状态
redis-cli GET swap:FOREX_status

# 检查 FOREX 最后更新时间
redis-cli GET swap:FOREX_last_update_time

# 检查当前差值
redis-cli GET swap:XAUT_price_difference

# 检查差值详细信息
redis-cli HGETALL swap:XAUT_difference_info

# 检查 OKX 最新价格
redis-cli GET swap:OKX_XAUT_last_price
```

---

## 兼容性说明

重构后的系统：

✅ **保持兼容**：
- 所有前端使用的 Redis 键值（`swap:XAUT_detail`, `swap:XAUT_kline_book_*` 等）保持不变
- GatewayWorker 推送的数据结构不变
- 前端无需修改

✅ **新增功能**：
- FOREX 状态监控
- 差值计算更稳定
- 原始数据可追溯
- 容错能力增强

❌ **不再兼容**：
- 旧的 `swap:XAU_USD_data` 和 `swap:XAU_USD_data2` 键值不再更新
- 如果有其他程序依赖这两个键值，需要修改
