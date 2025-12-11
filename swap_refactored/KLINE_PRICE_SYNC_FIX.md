# K线价格同步问题修复说明

## 问题描述

**用户反馈**：
- ✓ 实时价更新了（`swap:XAUT_detail` 缓存正确）
- ✗ K线价格没有同步更新
- ✗ K线的价格和实时价对不上

## 根本原因

### 历史数据未拉取

重构版本的 K线进程在初始化时设置了 `$isget = false`：

```php
// ❌ 错误的代码
$ok = new OK($period, $symbol, true, false); // false = 不拉取历史数据
```

这导致：
1. **Redis 中没有历史 K线数据**
2. **只有 WebSocket 推送的实时数据**（只有当前这一根K线）
3. **客户端看到的是空的或不完整的K线图**

### 对比原始代码

原始代码会拉取历史数据：

```php
// ✓ 原始代码
$ok = new OK($period, $symbol);  // 默认 $isget = true
$ok->getHistory();                // 再次确保有历史数据
```

---

## 解决方案

### 方案选择

**不可行方案**：让所有 9 个进程都拉取历史数据
- ❌ 会造成重复拉取（9个进程 × 9个周期 = 81次请求）
- ❌ 浪费资源和带宽
- ❌ 可能触发 OKX API 限流（429 Too Many Requests）

**采用方案**：只让 1分钟进程拉取所有周期历史数据
- ✅ 只拉取一次（9个周期）
- ✅ 其他周期通过聚合获得历史数据
- ✅ 节省资源和避免限流

### 实施修复

#### 1. 修改 1分钟K线进程

**文件**: `swap_refactored/swap_kline_1min.php`

```php
// 初始化 1分钟周期
$period = '1m';
$symbol = "XAUT";

// ⚠️ 重要：1分钟进程负责拉取所有周期的历史数据
// 等待3秒确保 swap_market.php 已经计算并存储了差值
echo "[等待] 等待 3 秒以确保差值已计算...\n";
sleep(3);

$ok = new OK($period, $symbol, true, true); // true = 拉取所有周期历史数据
$onConnect = $ok->onConnectParams($period);  // 只订阅当前周期
```

#### 2. 为什么要等待 3 秒？

**启动顺序**：
```
[1/13] get_forex_price.php   启动
         ↓ sleep 2秒
[2/13] swap_market.php        启动 → 计算并存储差值
         ↓ sleep 2秒
[3/13] swap_depth.php         启动
         ↓ sleep 1秒
[4/13] swap_trade.php         启动
         ↓ sleep 1秒
[5/13] swap_kline_1min.php    启动 → 等待 3秒 → 拉取历史数据
```

**总等待时间**：2 + 2 + 1 + 1 + 3 = **9 秒**

这确保：
- ✅ FOREX 价格已获取
- ✅ 差值已计算并存储到 `swap:XAUT_price_difference`
- ✅ 拉取历史数据时能应用正确的差值

#### 3. 其他周期进程保持不变

其他 8 个 K线进程（5min, 15min, ...）保持 `$isget = false`：

```php
// 其他周期不拉取历史数据
$ok = new OK($period, $symbol, true, false);
```

它们依赖：
1. **1min 进程拉取的历史数据** - 作为聚合的基础
2. **WebSocket 实时推送** - 更新实时数据

---

## 数据流详解

### 历史数据流

```
启动时序：
  ↓
get_forex_price.php 启动
  ↓ 获取 FOREX 价格
  ↓ 存储到: swap:FOREX_XAU_USD_price
  ↓
swap_market.php 启动
  ↓ 获取 OKX 市场价格
  ↓ 读取 FOREX 价格
  ↓ 计算差值 = FOREX - OKX
  ↓ 存储到: swap:XAUT_price_difference
  ↓
swap_kline_1min.php 启动
  ↓ 等待 3 秒
  ↓ 创建 OK 对象 (isget=true)
  ↓ 构造函数: 遍历所有周期
  ↓
  ├─ 拉取 1m 历史数据 (300条)
  │   ↓ 从 Redis 读取差值
  │   ↓ 应用差值到每条数据
  │   ↓ 存储到: swap:XAUT_kline_book_1min
  │
  ├─ 拉取 5m 历史数据 (300条)
  │   ↓ 应用差值
  │   ↓ 存储到: swap:XAUT_kline_book_5min
  │
  ├─ 拉取 15m, 30m, 1H, 4H, 1D, 1W, 1M ...
  │
  ↓ 所有历史数据拉取完成
  ↓ WebSocket 订阅 candle1m
  ↓ 接收实时推送
  ↓ 应用差值
  ↓ 更新 Redis 缓存
  ↓ 推送给客户端
```

### 实时数据流

**1分钟周期**：
```
OKX WebSocket 推送 candle1m
  ↓
swap_kline_1min.php 接收
  ↓
OK.php::data() 处理
  ↓ 读取差值: swap:XAUT_price_difference
  ↓ 应用差值到 OHLC
  ↓
更新缓存: swap:XAUT_kline_book_1min
  ↓
缓存当前: swap_kline_now_1min
  ↓
推送给客户端: swapKline_XAUT_1min
```

**其他周期（如 5分钟）**：
```
OKX WebSocket 推送 candle5m
  ↓
swap_kline_5min.php 接收
  ↓
OK.php::data() 处理
  ↓ 读取差值
  ↓ 应用差值
  ↓
从 1min 缓存聚合 (可选，取决于实现)
  ↓
更新缓存: swap:XAUT_kline_book_5min
  ↓
缓存当前: swap_kline_now_5min
  ↓
推送给客户端: swapKline_XAUT_5min
```

---

## 验证方法

### 1. 检查差值是否存在

```bash
# 检查差值
redis-cli GET swap:XAUT_price_difference

# 检查差值详细信息
redis-cli --raw GET swap:XAUT_difference_info | jq .
```

**预期输出**：
```json
{
  "difference": 2050.5,
  "forex_price": 2700.8,
  "okx_price": 650.3,
  "forex_status": "online",
  "calculate_time": 1702345678,
  "calculate_time_str": "2023-12-12 10:00:00"
}
```

### 2. 检查历史 K线数据

```bash
# 检查 1分钟历史 K线
redis-cli --raw GET swap:XAUT_kline_book_1min | jq 'length'

# 检查最新一条数据的价格
redis-cli --raw GET swap:XAUT_kline_book_1min | jq '.[-1].close'
```

**预期**：
- 应该有约 300 条历史数据
- 价格应该是 OKX 原始价格 + 差值

### 3. 对比价格一致性

```bash
# 实时市场价格
redis-cli --raw GET swap:XAUT_detail | jq '.close'

# 1分钟K线当前价格
redis-cli --raw GET swap_kline_now_1min | jq '.close'

# 计算差异
echo "这两个值应该相同或非常接近"
```

### 4. 监控启动日志

```bash
# 查看 1分钟K线启动日志
tail -f runtime/swap_kline_1min.log
```

**预期日志**：
```
[等待] 等待 3 秒以确保差值已计算...
[请求][1m] https://www.okx.com/api/v5/market/candles?...
[差值][1m] 使用差值: 2050.50
[进度][1m] swap:XAUT_kline_book_1min 当前累计：300 条
[完成][1m] swap:XAUT_kline_book_1min 去重后总条数：300
[请求][5m] ...
[差值][5m] 使用差值: 2050.50
...
[订阅] 已订阅 1分钟K线数据
```

---

## 性能影响

### 启动时间

| 项目 | 时间 |
|------|------|
| 等待差值 | 3 秒 |
| 拉取 9 个周期历史数据 | ~5-10 秒 |
| **总启动时间** | **8-13 秒** |

### 资源占用

| 项目 | 占用 |
|------|------|
| API 请求次数 | 9 次（每周期 1 次） |
| 内存增加 | ~5 MB（缓存历史数据） |
| 网络带宽 | ~500 KB（总下载） |

**评估**：启动时间略有增加，但这是一次性成本，运行后无影响。

---

## 故障排查

### 问题 1：K线价格仍然不正确

**可能原因**：差值未生效或为 0

**排查步骤**：
```bash
# 1. 检查差值
redis-cli GET swap:XAUT_price_difference

# 2. 检查 FOREX 状态
redis-cli GET swap:FOREX_status

# 3. 查看 swap_market.php 日志
tail -f runtime/swap_market.log
```

**解决方法**：
- 确保 get_forex_price.php 和 swap_market.php 正在运行
- 检查 FOREX 连接是否正常
- 重启所有服务：`./stop.sh && ./start.sh`

### 问题 2：历史数据为空

**可能原因**：1分钟进程未启动或启动失败

**排查步骤**：
```bash
# 1. 检查进程状态
php swap_kline_1min.php status

# 2. 查看启动日志
cat runtime/swap_kline_1min.log

# 3. 手动启动测试
php swap_kline_1min.php start
```

### 问题 3：部分周期数据缺失

**可能原因**：网络问题或 API 限流

**排查步骤**：
```bash
# 查看日志中的错误
grep -i "error\|429" runtime/swap_kline_1min.log

# 检查每个周期的数据
for period in 1min 5min 15min 30min 60min 4hour 1day 1week 1mon; do
    count=$(redis-cli --raw GET swap:XAUT_kline_book_$period | jq 'length' 2>/dev/null || echo "0")
    echo "$period: $count 条"
done
```

---

## 总结

### 修复内容

✅ **1分钟K线进程拉取所有历史数据**
- 修改 `swap_kline_1min.php`
- 改为 `$isget = true`
- 添加 3 秒延迟确保差值已计算

✅ **历史数据应用差值**
- OK.php::getHistory() 已实现差值应用
- 所有历史数据都经过差值调整

✅ **实时数据应用差值**
- OK.php::data() 已实现差值应用
- WebSocket 推送的数据实时应用差值

### 预期效果

- ✅ 启动后约 10 秒内所有历史 K线数据准备就绪
- ✅ 所有 K线价格与实时市场价格一致
- ✅ 实时 WebSocket 推送无延迟
- ✅ 客户端看到完整准确的 K线图

### 关键数据流

```
FOREX 价格 (2700.8)
     ↓
OKX 价格 (650.3)
     ↓
差值 (2050.5)
     ↓
应用到所有 K线数据
     ↓
客户端显示: 2700.8
     ↑
实时价显示: 2700.8
     ✓ 一致！
```
