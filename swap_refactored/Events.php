<?php
/**
 * Gateway Events 事件处理类
 *
 * 处理客户端的连接、消息、断开等事件
 */

use \GatewayWorker\Lib\Gateway;
use \Workerman\Lib\Timer;
use Illuminate\Support\Facades\Cache;

class Events
{
    /**
     * 存储客户端的心跳信息
     * 格式: [client_id => last_pong_time]
     */
    private static $heartbeats = [];

    /**
     * 当客户端连接时触发
     */
    public static function onConnect($client_id)
    {
        echo "[连接] 客户端 $client_id 已连接\n";

        // 初始化心跳时间
        self::$heartbeats[$client_id] = time();
    }

    /**
     * 当客户端发送数据时触发
     *
     * @param int $client_id 客户端ID
     * @param mixed $message 客户端发送的数据
     */
    public static function onMessage($client_id, $message)
    {
        try {
            // 尝试解析 JSON 数据
            $data = json_decode($message, true);

            if (!$data || !isset($data['cmd'])) {
                // 不是合法的 JSON 或没有 cmd 字段，忽略
                return;
            }

            // 处理心跳 pong 回复
            if ($data['cmd'] === 'pong') {
                echo "[心跳] 收到客户端 $client_id 的 pong\n";

                // 更新该客户端的最后心跳时间
                self::$heartbeats[$client_id] = time();

                return;
            }

            // 处理订阅请求
            if ($data['cmd'] === 'subscribe' && isset($data['channel'])) {
                $channel = $data['channel'];

                echo "[订阅] 客户端 $client_id 订阅频道: $channel\n";

                // 将客户端加入对应的分组
                Gateway::joinGroup($client_id, $channel);

                // 发送订阅成功确认
                Gateway::sendToClient($client_id, json_encode([
                    'cmd' => 'subscribed',
                    'channel' => $channel,
                    'timestamp' => time()
                ]));

                return;
            }

            // 处理取消订阅请求
            if ($data['cmd'] === 'unsubscribe' && isset($data['channel'])) {
                $channel = $data['channel'];

                echo "[取消订阅] 客户端 $client_id 取消订阅频道: $channel\n";

                // 将客户端从分组中移除
                Gateway::leaveGroup($client_id, $channel);

                // 发送取消订阅确认
                Gateway::sendToClient($client_id, json_encode([
                    'cmd' => 'unsubscribed',
                    'channel' => $channel,
                    'timestamp' => time()
                ]));

                return;
            }

            // 其他未识别的命令
            echo "[未知命令] 客户端 $client_id 发送: {$data['cmd']}\n";

        } catch (\Exception $e) {
            echo "[异常] 处理客户端 $client_id 消息时出错: " . $e->getMessage() . "\n";
        }
    }

    /**
     * 当客户端断开连接时触发
     */
    public static function onClose($client_id)
    {
        echo "[断开] 客户端 $client_id 已断开\n";

        // 清理心跳记录
        if (isset(self::$heartbeats[$client_id])) {
            unset(self::$heartbeats[$client_id]);
        }
    }

    /**
     * 当 Worker 启动时触发
     */
    public static function onWorkerStart($worker)
    {
        echo "[启动] Gateway Worker #{$worker->id} 已启动\n";

        // 只在第一个 Worker 进程中启动心跳定时器，避免重复
        if ($worker->id === 0) {
            // 每 20 秒向所有客户端发送一次 ping
            Timer::add(20, function() {
                $client_list = Gateway::getAllClientIdList();

                if (empty($client_list)) {
                    return;
                }

                $now = time();
                $timeout = 60; // 60秒未收到pong则判定为超时

                foreach ($client_list as $client_id) {
                    // 检查心跳超时
                    if (isset(self::$heartbeats[$client_id])) {
                        $last_pong_time = self::$heartbeats[$client_id];

                        // 如果超过60秒未收到pong，断开连接
                        if ($now - $last_pong_time > $timeout) {
                            echo "[心跳超时] 客户端 $client_id 超时，断开连接\n";
                            Gateway::closeClient($client_id);
                            unset(self::$heartbeats[$client_id]);
                            continue;
                        }
                    }

                    // 发送 ping
                    try {
                        Gateway::sendToClient($client_id, json_encode([
                            'cmd' => 'ping',
                            'timestamp' => $now
                        ]));
                        echo "[心跳] 向客户端 $client_id 发送 ping\n";
                    } catch (\Exception $e) {
                        echo "[心跳错误] 向客户端 $client_id 发送 ping 失败: " . $e->getMessage() . "\n";
                    }
                }
            });

            echo "[心跳] 心跳定时器已启动，每 20 秒发送一次 ping\n";

            // ========== 添加市场列表推送定时器 ==========
            // 每 1 秒推送一次市场列表数据
            Timer::add(1, function() {
                try {
                    $market_data = self::getMarketList();

                    if (empty($market_data)) {
                        return;
                    }

                    $group_id = 'swapMarketList';

                    // 检查是否有客户端订阅了这个频道
                    if (Gateway::getClientIdCountByGroup($group_id) > 0) {
                        $message = json_encode([
                            'code' => 0,
                            'msg' => 'success',
                            'data' => $market_data,
                            'sub' => $group_id
                        ]);

                        Gateway::sendToGroup($group_id, $message);
                    }
                } catch (\Exception $e) {
                    echo "[市场列表异常] " . $e->getMessage() . "\n";
                }
            });

            echo "[市场列表] 市场列表推送定时器已启动，每 1 秒推送一次\n";
        }
    }

    /**
     * 获取市场列表数据
     * 从 Redis 读取已应用差值的数据
     */
    private static function getMarketList()
    {
        $result = [];

        // 只处理 XAUT
        $symbol = 'XAUT';

        try {
            // 从 Redis 读取调整后的市场数据（已包含 FOREX 差值）
            $detail = Cache::store('redis')->get('swap:' . $symbol . '_detail');

            if (!$detail) {
                echo "[市场列表] swap:{$symbol}_detail 数据不存在\n";
                return $result;
            }

            // 读取1天K线数据用于计算24小时涨跌
            $kline_1day = Cache::store('redis')->get('swap:' . $symbol . '_kline_book_1day');

            // 组装市场列表数据
            $item = [
                'symbol' => $symbol,
                'close' => round(floatval($detail['close']), 2),  // 当前价（已含差值）
                'open' => round(floatval($detail['open']), 2),
                'high' => round(floatval($detail['high']), 2),
                'low' => round(floatval($detail['low']), 2),
                'vol' => round(floatval($detail['vol']), 2),
                'amount' => round(floatval($detail['amount']), 2),
                'increase' => isset($detail['increase']) ? round(floatval($detail['increase']), 4) : 0,
                'increaseStr' => isset($detail['increaseStr']) ? $detail['increaseStr'] : '0.00%',
                'timestamp' => isset($detail['timestamp']) ? intval($detail['timestamp']) : time(),
            ];

            // 如果有1天K线数据，添加图表数据
            if ($kline_1day && is_array($kline_1day)) {
                $item['chart'] = array_map(function($k) {
                    return [
                        'id' => $k['id'],
                        'close' => round(floatval($k['close']), 2),
                    ];
                }, array_slice($kline_1day, -24)); // 最近24条
            }

            $result[] = $item;

        } catch (\Exception $e) {
            echo "[市场列表异常] 处理 {$symbol} 时出错: " . $e->getMessage() . "\n";
        }

        return $result;
    }

    /**
     * 当 Worker 停止时触发
     */
    public static function onWorkerStop($worker)
    {
        echo "[停止] Gateway Worker #{$worker->id} 已停止\n";
    }
}
