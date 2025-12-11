# OKX XAUT Swap 系统 - 代码问题与改进建议

## 严重问题（Critical）

### 1. Redis Key 不一致导致价格叠加功能可能失效

**问题描述**：

代码中存在两个不同的 XAU/USD 数据 Key：

- `swap:XAU_USD_data`：由 `get_new_xaut.php` 写入（第 188 行）
- `swap:XAU_USD_data2`：在各数据采集脚本中读取（如 `OK.php:129`, `swap_market.php:71`）

**影响**：

如果 `swap:XAU_USD_data2` 不存在，价格叠加功能将完全失效，系统将使用 OKX 原始价格而非叠加后的价格。

**证据**：

```php
// get_new_xaut.php:188 - 写入
Cache::store('redis')->put('swap:XAU_USD_data', $cache);

// OK.php:129 - 读取（不一致）
$XAU_USD_data = Cache::store('redis')->get('swap:XAU_USD_data2');

// swap_market.php:71 - 读取（不一致）
$XAU_USD_data = Cache::store('redis')->get('swap:XAU_USD_data');
```

**解决方案**：

统一使用 `swap:XAU_USD_data`，或确认是否有其他进程在处理 `swap:XAU_USD_data2` 的生成。

---

### 2. `OKX.php` 文件中 `getHistory()` 方法为空

**问题描述**：

`OKX.php` 文件与 `OK.php` 几乎完全相同，但 `getHistory()` 方法体为空（第 70-79 行）：

```php
// OKX.php:70-79
public function getHistory($period, $cache_data = [], $before = '')
{
    // 每次请求前做限流
    // 方法体为空！
}
```

**影响**：

- 如果误用了 `OKX` 类，将无法拉取历史 K 线数据
- 代码维护混乱，存在冗余文件

**解决方案**：

1. 删除 `OKX.php` 文件
2. 或者将 `OK.php` 的完整代码复制到 `OKX.php`，并明确其用途

---

### 3. 大量调试日志代码未清理

**问题描述**：

代码中存在大量 `file_put_contents()` 调试日志，部分已注释，部分仍在运行：

```php
// swap_market.php:159-166
file_put_contents('1m-1111111.log',json_encode($cache_data)."\n".date("Y-m-d H:i:s", $timestamp)."\n\n\n", FILE_APPEND);

file_put_contents('1m2-1111111.log',$cache_data['close'].'---'."\n".date("Y-m-d H:i:s", $timestamp)."\n\n\n", FILE_APPEND);

file_put_contents('1m3-1111111.log',json_encode($XAU_USD_data)."\n".date("Y-m-d H:i:s", $timestamp)."\n\n\n", FILE_APPEND);

file_put_contents('name-1m3-1111111.log',json_encode($key)."\n".date("Y-m-d H:i:s", $timestamp)."\n\n\n", FILE_APPEND);
```

**影响**：

1. **磁盘空间耗尽风险**：日志文件会无限增长
2. **性能下降**：频繁的文件写入操作会影响性能
3. **安全风险**：日志文件可能包含敏感信息
4. **代码可读性差**：大量注释和调试代码影响阅读

**已发现的日志文件**：

```bash
swap/111---22222.log
swap/null-111---22222.log
swap/name-1m3-1111111.log
# 还有更多...
```

**解决方案**：

1. 删除所有调试日志代码
2. 使用统一的日志框架（如 Monolog）
3. 实现日志轮转（log rotation）机制
4. 添加日志级别控制（DEBUG/INFO/ERROR）

---

### 4. 配置文件为空

**问题描述**：

`config.php` 文件内容为空：

```php
<?php
$config = [];
```

**影响**：

- 所有配置项硬编码在代码中，难以维护
- 无法在不同环境（开发/测试/生产）间切换

**建议配置项**：

```php
<?php
return [
    // Redis 配置
    'redis' => [
        'host' => '127.0.0.1',
        'port' => 6379,
        'database' => 0,
    ],

    // GatewayWorker 配置
    'gateway' => [
        'register_address' => '127.0.0.1:1338',
    ],

    // OKX API 配置
    'okx' => [
        'ws_url' => 'wss://wspri.okx.com:8443/ws/v5/ipublic',
        'symbol' => 'XAUT',
        'throttle_interval' => 0.35,
    ],

    // EFX 配置
    'efx' => [
        'base_url' => 'https://rates-live.efxnow.com',
        'instrument_id' => '401527511',  // XAU/USD
    ],

    // 数据保留配置
    'data_retention' => [
        'kline_max_count' => 3000,
        'trade_max_count' => 30,
    ],

    // 日志配置
    'log' => [
        'enabled' => false,
        'path' => __DIR__ . '/logs/',
        'level' => 'ERROR',
    ],
];
```

---

### 5. 缺少异常处理

**问题描述**：

代码中大量使用匿名函数处理 WebSocket 消息，但缺少 try-catch 异常处理：

```php
// swap_kline.php:45
$con->onMessage = function ($con, $data) use ($ok) {
    $data = json_decode($data, true);  // 如果 JSON 无效会怎样？

    if (isset($data['ping'])) {
        $msg = ["pong" => $data['ping']];
        $con->send(json_encode($msg));
    } else {
        $cache_data = $ok->data($data);  // 如果这里抛出异常会怎样？

        // 如果出现异常，整个 Worker 进程可能崩溃
    }
};
```

**影响**：

如果数据处理过程中出现异常，整个 Worker 进程可能崩溃，需要手动重启。

**解决方案**：

```php
$con->onMessage = function ($con, $data) use ($ok) {
    try {
        $data = json_decode($data, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            echo "JSON 解析失败：" . json_last_error_msg() . "\n";
            return;
        }

        if (isset($data['ping'])) {
            $msg = ["pong" => $data['ping']];
            $con->send(json_encode($msg));
        } else {
            $cache_data = $ok->data($data);

            if ($cache_data) {
                // 处理数据...
            }
        }
    } catch (\Exception $e) {
        echo "处理消息时发生错误：" . $e->getMessage() . "\n";
        echo $e->getTraceAsString() . "\n";
    }
};
```

---

## 高优先级问题（High）

### 6. `goto` 语句的使用

**问题描述**：

`get_new_xaut.php` 中使用了 `goto` 语句实现重连逻辑：

```php
// get_new_xaut.php:200-224
start_connect:

$token = getToken();
if (!$token) {
    echo "3 秒后重试获取 token...\n";
    sleep(3);
    goto start_connect;  // 使用 goto 跳转
}

// ...

if (!$stream) {
    echo "❌ 无法连接 SSE，1 秒后重试\n";
    sleep(1);
    goto start_connect;  // 使用 goto 跳转
}
```

**问题**：

1. `goto` 语句使代码难以理解和维护
2. 容易导致逻辑混乱
3. 违反结构化编程原则

**解决方案**：

使用 `while(true)` 循环代替：

```php
while (true) {
    try {
        $token = getToken();
        if (!$token) {
            echo "3 秒后重试获取 token...\n";
            sleep(3);
            continue;
        }

        echo "Token: $token\n";

        $stream = @fopen($sseUrl, "r");
        if (!$stream) {
            echo "❌ 无法连接 SSE，1 秒后重试\n";
            sleep(1);
            continue;
        }

        // 处理 SSE 流...

        if (feof($stream)) {
            echo "SSE 断开，重连中...\n";
            @fclose($stream);
            sleep(10);
            continue;
        }
    } catch (\Exception $e) {
        echo "发生错误：" . $e->getMessage() . "\n";
        sleep(10);
    }
}
```

---

### 7. 硬编码的业务逻辑

**问题描述**：

代码中硬编码了 `XAUT` 币种，无法灵活支持其他币种：

```php
// swap_kline.php:25
$symbol = "XAUT";  // 硬编码

// get_new_xaut.php:138
if ($symbol !== "XAU/USD") {  // 硬编码
    return;
}
```

**影响**：

- 无法轻松扩展到其他币种
- 代码复用性差

**解决方案**：

将币种配置化：

```php
// config.php
return [
    'symbols' => ['XAUT', 'BTC', 'ETH'],
    // ...
];

// 动态启动多个币种的采集进程
foreach ($config['symbols'] as $symbol) {
    // 启动对应的 Worker
}
```

---

### 8. K线聚合逻辑存在问题

**问题描述**：

`OK.php` 中的 K 线聚合周期映射不正确：

```php
// OK.php:441-449
$periodMap = [
    '5min'  => ['period' => '1min',  'seconds' => 60],     // ✓ 正确
    '15min' => ['period' => '5min',  'seconds' => 300],    // ✓ 正确
    '30min' => ['period' => '15min', 'seconds' => 900],    // ✓ 正确
    '60min' => ['period' => '30min', 'seconds' => 1800],   // ✓ 正确
    '4hour' => ['period' => '60min', 'seconds' => 3600],   // ✓ 正确
    '1day'  => ['period' => '4hour', 'seconds' => 14400],  // ✓ 正确
    '1week' => ['period' => '1week', 'seconds' => 86400],  // ✗ 错误！应该基于 1day
    '1mon'  => ['period' => '1mon',  'seconds' => 604800], // ✗ 错误！应该基于 1day
];
```

**问题**：

- `1week` 映射到 `1week`（自己聚合自己）
- `1mon` 映射到 `1mon`（自己聚合自己）

**正确的映射**：

```php
$periodMap = [
    '5min'  => ['period' => '1min',  'seconds' => 60],
    '15min' => ['period' => '5min',  'seconds' => 300],
    '30min' => ['period' => '15min', 'seconds' => 900],
    '60min' => ['period' => '30min', 'seconds' => 1800],
    '4hour' => ['period' => '60min', 'seconds' => 3600],
    '1day'  => ['period' => '4hour', 'seconds' => 14400],
    '1week' => ['period' => '1day',  'seconds' => 86400],  // 从1天聚合
    '1mon'  => ['period' => '1day',  'seconds' => 86400],  // 从1天聚合
];
```

---

### 9. 时间戳单位不一致

**问题描述**：

代码中混用了秒级时间戳和毫秒级时间戳：

```php
// OK.php:139 - 秒级
'id' => intval($v[0] / 1000),  // 将毫秒转换为秒

// swap_market.php:76 - 毫秒级
'id' => $resdata['ts'],  // 直接使用13位毫秒时间戳

// swap_trade.php:52 - 毫秒级
'ts' => $resdata['ts'],  // 13位毫秒时间戳
```

**影响**：

- 数据不一致，可能导致查询错误
- 前端需要处理不同单位的时间戳

**解决方案**：

统一使用秒级时间戳或毫秒级时间戳，并在代码中明确注释。

---

### 10. Redis 数据过期策略缺失

**问题描述**：

所有 Redis 数据都使用 `put()` 方法存储，未设置过期时间：

```php
Cache::store('redis')->put($kline_book_key, $kline_book);  // 永不过期
```

**影响**：

- Redis 内存会无限增长
- 可能导致 OOM（内存溢出）

**解决方案**：

为不同类型的数据设置合理的过期时间：

```php
// 实时数据：1小时过期
Cache::store('redis')->put('swap:XAUT_detail', $cache_data, 3600);

// 历史K线数据：7天过期
Cache::store('redis')->put('swap:XAUT_kline_book_1min', $kline_book, 86400 * 7);

// XAU/USD 价格：5分钟过期（防止数据源故障导致使用旧数据）
Cache::store('redis')->put('swap:XAU_USD_data', $cache, 300);
```

---

## 中等优先级问题（Medium）

### 11. 代码重复

**问题描述**：

`OK.php` 和 `OKX.php` 几乎完全相同，存在代码重复。

**解决方案**：

删除 `OKX.php` 或明确区分两者的用途。

---

### 12. 变量命名不规范

**问题描述**：

代码中存在大量不规范的变量命名：

```php
$aaaamei      // swap_market.php:309
$test         // swap_kline.php:65
$t            // OK.php:92
$v            // 大量使用单字母变量
```

**解决方案**：

使用有意义的变量名：

```php
$rawKlineData       // 原始K线数据
$formattedData      // 格式化后的数据
$okxClient          // OKX 客户端实例
$klineItem          // K线项
```

---

### 13. 魔法数字

**问题描述**：

代码中存在大量魔法数字：

```php
if (count($kline_book) > 3000) {      // 3000 是什么？
    array_shift($kline_book);
}

if (count($trade_list) > 30) {        // 30 是什么？
    array_shift($trade_list);
}

sleep(2);                              // 为什么是 2 秒？
usleep(400000);                        // 为什么是 0.4 秒？
```

**解决方案**：

使用常量或配置：

```php
const MAX_KLINE_COUNT = 3000;
const MAX_TRADE_COUNT = 30;
const RETRY_DELAY_SECONDS = 2;
const THROTTLE_DELAY_MICROSECONDS = 400000;

if (count($kline_book) > self::MAX_KLINE_COUNT) {
    array_shift($kline_book);
}
```

---

### 14. 注释掉的代码过多

**问题描述**：

代码中存在大量注释掉的代码：

```php
// OK.php:203-213
// $cache_data = [
//     'id' => intval($v[0] / 1000),
//     'open' => floatval($v[1]),
//     'high' => floatval($v[2]),
//     'low' => floatval($v[3]),
//     'close' => floatval($v[4]),
//     'amount' => floatval($v[5]),
//     'vol' => floatval($v[7]),
//     'time' => time(),
// ];
```

**解决方案**：

- 删除无用的注释代码
- 使用 Git 管理历史版本，无需在代码中保留旧代码

---

### 15. 缺少类型声明

**问题描述**：

PHP 7+ 支持类型声明，但代码中未使用：

```php
public function data($data): array  // 只有返回值类型
{
    // ...
}
```

**建议**：

```php
public function data(array $data): array
{
    // ...
}

public function getHistory(string $period, array $cache_data = [], string $before = ''): void
{
    // ...
}
```

---

## 低优先级问题（Low）

### 16. 缺少代码文档

**问题描述**：

大部分方法和类缺少 PHPDoc 注释。

**建议**：

```php
/**
 * 处理 OKX WebSocket 推送的 K 线数据
 *
 * @param array $data WebSocket 消息数据
 * @return array 返回格式化后的 K 线数据
 */
public function data(array $data): array
{
    // ...
}
```

---

### 17. 错误提示信息不友好

**问题描述**：

错误提示使用 emoji 和中文，不利于日志解析：

```php
echo "❌ negotiate 获取失败\n";
echo "3 秒后重试获取 token...\n";
```

**建议**：

使用英文和结构化日志：

```php
$logger->error("Failed to negotiate connection token");
$logger->info("Retrying in 3 seconds...");
```

---

### 18. 缺少监控和告警

**问题描述**：

系统缺少监控和告警机制，无法及时发现问题。

**建议**：

1. 添加进程存活检查
2. 监控 Redis 数据更新时间
3. 监控 WebSocket 连接状态
4. 当数据超过一定时间未更新时发送告警

---

### 19. 依赖外部文件路径不明确

**问题描述**：

所有 Worker 脚本都依赖 `../../index.php`：

```php
require "../../index.php";
```

但这个文件不在项目中，依赖关系不清晰。

**建议**：

使用 Composer autoload 或明确文档说明依赖。

---

## 安全问题

### 20. 缺少输入验证

**问题描述**：

从 WebSocket 接收的数据未经验证直接使用：

```php
$symbol = explode("-", $stream["instId"])[0];  // 如果格式不对会怎样？
```

**建议**：

```php
if (!isset($stream["instId"]) || !is_string($stream["instId"])) {
    echo "无效的 instId 格式\n";
    return;
}

$parts = explode("-", $stream["instId"]);
if (count($parts) < 1) {
    echo "instId 格式错误\n";
    return;
}

$symbol = $parts[0];
```

---

### 21. 敏感信息可能泄露

**问题描述**：

日志文件可能包含敏感数据，且未设置访问权限。

**建议**：

1. 日志文件不应记录敏感信息
2. 日志文件应设置适当的文件权限（600 或 640）
3. 日志目录应在 Web 服务器根目录之外

---

## 性能优化建议

### 22. Redis 连接复用

**问题描述**：

代码中频繁使用 `Cache::store('redis')`，可能导致连接开销。

**建议**：

确认 Laravel Cache 使用了连接池，或考虑使用持久连接。

---

### 23. 数据结构优化

**问题描述**：

K 线 book 使用数组存储 3000 条数据，查询效率低：

```php
collect($kline_book)->firstWhere('id', $priv_id);  // O(n) 查询
```

**建议**：

使用 Redis Sorted Set 存储 K 线数据，按时间戳排序：

```php
Redis::zadd('swap:XAUT_kline_book_1min', $timestamp, json_encode($klineData));
Redis::zrangebyscore('swap:XAUT_kline_book_1min', $startTime, $endTime);
```

---

### 24. 减少不必要的计算

**问题描述**：

每次 WebSocket 消息都会进行价格叠加计算，即使 XAU/USD 价格未变化。

**建议**：

缓存 XAU/USD 价格，只在价格变化时重新计算。

---

## 架构改进建议

### 25. 引入消息队列

**问题描述**：

所有数据处理都在 WebSocket 回调中进行，可能导致阻塞。

**建议**：

```
WebSocket 接收数据
   ↓
投递到消息队列（Redis/RabbitMQ）
   ↓
异步消费者处理数据
   ↓
存储到 Redis / 数据库
```

---

### 26. 分离关注点

**问题描述**：

数据采集、数据处理、数据存储、数据推送都在一个进程中。

**建议**：

分离为多个独立服务：

1. **数据采集服务**：只负责从 OKX/EFX 采集原始数据
2. **数据处理服务**：负责价格叠加、K 线聚合等
3. **数据存储服务**：负责存储到 Redis/MySQL
4. **推送服务**：负责通过 WebSocket 推送给客户端

---

### 27. 引入数据库持久化

**问题描述**：

所有数据只存储在 Redis 中，服务器重启后历史数据丢失。

**建议**：

将重要数据持久化到 MySQL/PostgreSQL：

1. K 线数据（用于历史查询和分析）
2. 成交明细（用于审计和分析）
3. 系统日志（用于问题排查）

---

### 28. 实现健康检查接口

**建议**：

添加 HTTP 接口用于健康检查：

```php
// health_check.php
$worker = new Worker("http://0.0.0.0:8080");

$worker->onMessage = function($connection, $request) {
    $status = [
        'okx_connected' => checkOKXConnection(),
        'efx_connected' => checkEFXConnection(),
        'redis_connected' => checkRedisConnection(),
        'last_kline_update' => getLastKlineUpdateTime(),
        'last_xau_usd_update' => getLastXAUUSDUpdateTime(),
    ];

    $connection->send(json_encode($status));
};

Worker::runAll();
```

---

## 测试建议

### 29. 缺少单元测试

**建议**：

使用 PHPUnit 编写单元测试：

```php
class OKTest extends TestCase
{
    public function testRemoveDuplicates()
    {
        $ok = new OK('1D', 'XAUT', true, false);

        $data = [
            ['id' => 1000, 'close' => 100],
            ['id' => 1000, 'close' => 101],  // 重复
            ['id' => 2000, 'close' => 102],
        ];

        $result = $ok->removeDuplicates($data);

        $this->assertCount(2, $result);
        $this->assertEquals(100, $result[0]['close']);
    }
}
```

---

### 30. 缺少集成测试

**建议**：

编写集成测试验证整个数据流：

1. 模拟 WebSocket 消息
2. 验证 Redis 数据正确性
3. 验证价格叠加逻辑
4. 验证 K 线聚合逻辑

---

## 总结

### 必须修复的问题（优先级排序）

1. **Redis Key 不一致**（严重影响价格叠加功能）
2. **大量调试日志未清理**（可能导致磁盘耗尽）
3. **缺少异常处理**（可能导致进程崩溃）
4. **K线聚合逻辑错误**（1week 和 1mon 周期无法正确聚合）
5. **Redis 数据无过期时间**（可能导致内存溢出）

### 建议改进的方向

1. **完善配置系统**：将硬编码配置项移到配置文件
2. **统一日志系统**：删除 file_put_contents，使用 Monolog
3. **添加监控告警**：及时发现系统异常
4. **优化数据结构**：使用 Redis 原生数据结构提升性能
5. **引入数据库**：持久化重要数据
6. **编写测试用例**：确保代码质量

### 代码质量评分

| 维度 | 评分 | 说明 |
|-----|------|------|
| 功能完整性 | 8/10 | 核心功能完善，但存在一些逻辑错误 |
| 代码可读性 | 5/10 | 大量注释代码、调试日志、命名不规范 |
| 代码健壮性 | 4/10 | 缺少异常处理、输入验证 |
| 可维护性 | 5/10 | 配置硬编码、代码重复、缺少文档 |
| 性能 | 7/10 | 基于 Workerman 异步框架，性能不错 |
| 安全性 | 6/10 | 缺少输入验证、日志可能泄露敏感信息 |

### 总体评价

这是一个功能基本完善的实时数据采集系统，核心架构设计合理（基于 Workerman 异步框架），但代码质量有待提升：

**优点**：
- 使用异步事件驱动框架，性能高
- 自动重连机制完善
- 支持多周期 K 线数据

**缺点**：
- 大量调试代码未清理
- 缺少异常处理和监控
- 配置管理混乱
- 部分逻辑存在错误

建议按照本文档中的优先级依次修复问题，逐步提升代码质量。
