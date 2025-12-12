<?php
/**
 * OKX K线数据处理类（重构版）
 *
 * 功能：
 * 1. 拉取 OKX 历史 K线数据
 * 2. 接收 OKX WebSocket 实时 K线数据
 * 3. 从 Redis 读取价格差值
 * 4. 应用差值到所有 K线数据（开、高、低、收）
 * 5. 基于短周期聚合长周期 K线
 * 6. 存储到 Redis 并推送给客户端
 *
 * 与原有系统的区别：
 * - 统一使用 swap:XAUT_price_difference 作为差值
 * - 去除了直接使用 FOREX 价格的逻辑
 * - 增加了差值不存在时的容错处理
 */

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class OK
{
    public $period;
    public $symbol;
    public $is_k;

    // 全局限流控制
    protected static $lastRequestAt = 0;
    protected static $minInterval = 0.35;  // 每次请求最小间隔（秒）

    public function __construct($period, $symbol, $is_k = true, $isget = true)
    {
        // 始终设置基本属性，无论是否拉取历史数据
        $this->period = $period;
        $this->symbol = $symbol;
        $this->is_k = $is_k;

        // 如果需要拉取历史数据
        if ($isget) {
            // 拉取所有周期的历史数据
            foreach ($this->periods as $k => $v) {
                $this->getHistory($k);
            }
        }
    }

    // 周期映射
    public $periods = [
        '1m' => ['period' => '1min', 'seconds' => 60],
        '5m' => ['period' => '5min', 'seconds' => 300],
        '15m' => ['period' => '15min', 'seconds' => 900],
        '30m' => ['period' => '30min', 'seconds' => 1800],
        '1H' => ['period' => '60min', 'seconds' => 3600],
        '4H' => ['period' => '4hour', 'seconds' => 14400],
        '1D' => ['period' => '1day', 'seconds' => 86400],
        '1W' => ['period' => '1week', 'seconds' => 604800],
        '1M' => ['period' => '1mon', 'seconds' => 2592000],
    ];

    /**
     * 请求限流
     */
    protected function throttle()
    {
        $now = microtime(true);
        if (self::$lastRequestAt > 0) {
            $delta = $now - self::$lastRequestAt;
            if ($delta < self::$minInterval) {
                $sleepUs = (int)((self::$minInterval - $delta) * 1000000);
                if ($sleepUs > 0) {
                    usleep($sleepUs);
                }
            }
        }
        self::$lastRequestAt = microtime(true);
    }

    /**
     * 获取价格差值
     */
    protected function getPriceDifference()
    {
        $difference = Cache::store('redis')->get('swap:XAUT_price_difference');

        if ($difference === null) {
            echo "[警告] 价格差值不存在，使用默认值 0\n";
            return 0;
        }

        return floatval($difference);
    }

    /**
     * 拉取历史 K线
     */
    public function getHistory($period, $cache_data = [], $before = '')
    {
        // 限流
        $this->throttle();

        $http = new Workerman\Http\Client();

        $params = [
            'instId' => strtoupper($this->symbol) . '-USDT-SWAP',
            'bar' => $period,
            'limit' => 1500,
        ];

        if ($before) {
            $params['before'] = $before;
        }

        $http_url = 'https://www.okx.com/api/v5/market/candles?' . http_build_query($params);

        echo "[请求][$period] $http_url\n";

        $t = $this;

        $http->get($http_url, function ($response) use ($period, $cache_data, $before, $t) {

            $status = $response->getStatusCode();

            // 处理 429 限流
            if ($status == 429) {
                echo "[警告][$period] 429 Too Many Requests，暂停 2 秒后重试...\n";
                sleep(2);
                $t->getHistory($period, $cache_data, $before);
                return;
            }

            if ($status != 200) {
                echo "[错误][$period] HTTP 状态码：{$status}\n";
                return;
            }

            $data = json_decode($response->getBody(), true);

            if (!is_array($data) || empty($data['data'])) {
                echo "[提示][$period] 返回数据为空，结束此周期拉取。\n";
                return;
            }

            $kline_book_key = 'swap:' . $t->symbol . '_kline_book_' . $t->periods[$period]['period'];

            // 获取价格差值
            $difference = $t->getPriceDifference();

            echo sprintf(
                "[差值][$period] 使用差值: %.2f\n",
                $difference
            );

            // 处理数据并应用差值
            $cache_data2 = collect($data['data'])->map(function ($v) use ($difference) {
                return [
                    'id' => intval($v[0] / 1000),
                    'open' => round(floatval($v[1]) + $difference, 2),
                    'high' => round(floatval($v[2]) + $difference, 2),
                    'low' => round(floatval($v[3]) + $difference, 2),
                    'close' => round(floatval($v[4]) + $difference, 2),
                    'amount' => floatval($v[5]),
                    'vol' => round(floatval($v[7]), 4),
                    'time' => intval($v[0] / 1000),
                ];
            })->reject(function ($v) {
                return $v['id'] > time(); // 过滤未来时间
            })->toArray();

            // 合并数据
            $cache_data = array_merge($cache_data, $cache_data2);

            echo "[进度][$period] {$kline_book_key} 当前累计：" . count($cache_data) . " 条\n";

            // 翻页逻辑
            if (count($cache_data2) == 1500) {
                $beforeNext = $cache_data[count($cache_data) - 1]['id'] . '000';
                usleep(400000); // 0.4秒
                $t->getHistory($period, $cache_data, $beforeNext);
                return;
            }

            // 排序
            usort($cache_data, function ($a, $b) {
                return $a['time'] <=> $b['time'];
            });

            // 去重
            $cache_data = $t->removeDuplicates($cache_data);

            echo "[完成][$period] {$kline_book_key} 去重后总条数：" . count($cache_data) . "\n";

            // 存储到 Redis
            Cache::store('redis')->put($kline_book_key, $cache_data, 86400 * 7); // 7天过期

        }, function ($exception) use ($period) {
            echo "[异常][$period] getHistory 调用失败：" . $exception->getMessage() . "\n";
        });
    }

    /**
     * 去重
     */
    private function removeDuplicates($data)
    {
        $seen = [];
        $result = [];

        foreach ($data as $item) {
            if (in_array($item['id'], $seen)) {
                echo "移除重复时间戳: {$item['id']}\n";
                continue;
            }
            $seen[] = $item['id'];
            $result[] = $item;
        }

        return $result;
    }

    /**
     * WebSocket 订阅参数
     */
    public function onConnectParams($channel = null)
    {
        $params = [];

        if ($this->is_k) {
            if ($channel) {
                $this->getHistory($this->period);
                $params[0] = [
                    "channel" => "candle" . $this->period,
                    "instId" => $this->symbol . '-USDT-SWAP',
                ];
            } else {
                // 订阅所有周期
                foreach ($this->periods as $key => $period) {
                    $params[] = [
                        "channel" => "candle" . $key,
                        "instId" => $this->symbol . '-USDT-SWAP',
                    ];
                }
            }
        } else {
            $params[0] = [
                "channel" => $this->period,
                "instId" => $this->symbol . '-USDT-SWAP',
            ];
        }

        $request = [
            "op" => "subscribe",
            "args" => $params,
        ];

        return json_encode($request);
    }

    /**
     * 处理 WebSocket 推送的 K线数据
     */
    public function data($data): array
    {
        $res = [];

        if (!isset($data['data'])) {
            return $res;
        }

        try {
            $stream = $data['arg'];
            $symbol = explode("-", $stream["instId"])[0];
            $periodKey = str_after($stream['channel'], 'candle');

            $seconds = $this->periods[$periodKey]['seconds'];
            $period = $this->periods[$periodKey]['period'];

            if ($this->is_k) {
                $v = $data['data'][0];

                // 获取价格差值
                $difference = $this->getPriceDifference();

                // 应用差值
                $cache_data = [
                    'id' => intval($v['0'] / 1000),
                    'open' => round(floatval($v[1]) + $difference, 2),
                    'high' => round(floatval($v[2]) + $difference, 2),
                    'low' => round(floatval($v[3]) + $difference, 2),
                    'close' => round(floatval($v[4]) + $difference, 2),
                    'amount' => floatval($v[5]),
                    'vol' => round(floatval($v[7]), 4),
                    'time' => intval($v['0'] / 1000),
                ];

                // 验证时间戳
                if ($cache_data['id'] <= time() + 1) {

                    $kline_book_key = 'swap:' . $symbol . '_kline_book_' . $period;
                    $kline_book = Cache::store('redis')->get($kline_book_key);

                    if ($period == '1min') {
                        // 1分钟K线直接处理
                        if (blank($kline_book)) {
                            Cache::store('redis')->put($kline_book_key, [$cache_data], 86400 * 7);
                        } else {
                            $last_item1 = array_pop($kline_book);
                            if ($last_item1['id'] == $cache_data['id']) {
                                array_push($kline_book, $cache_data);
                            } else {
                                array_push($kline_book, $last_item1, $cache_data);
                            }

                            if (count($kline_book) > 3000) {
                                array_shift($kline_book);
                            }
                            Cache::store('redis')->put($kline_book_key, $kline_book, 86400 * 7);
                        }
                    } else {
                        // 其他周期基于短周期聚合
                        $periodMap = [
                            '5min' => ['period' => '1min', 'seconds' => 60],
                            '15min' => ['period' => '5min', 'seconds' => 300],
                            '30min' => ['period' => '15min', 'seconds' => 900],
                            '60min' => ['period' => '30min', 'seconds' => 1800],
                            '4hour' => ['period' => '60min', 'seconds' => 3600],
                            '1day' => ['period' => '4hour', 'seconds' => 14400],
                            '1week' => ['period' => '1day', 'seconds' => 86400],  // 修复
                            '1mon' => ['period' => '1day', 'seconds' => 86400],   // 修复
                        ];

                        $map = $periodMap[$period] ?? null;
                        if ($map) {
                            $kline_base_book = Cache::store('redis')->get('swap:' . $symbol . '_kline_book_' . $map['period']);
                            if (!blank($kline_base_book)) {
                                $first_item_id = $cache_data['id'];
                                $last_item_id = $cache_data['id'] + $seconds - $map['seconds'];
                                $items1 = array_where($kline_base_book, function ($value, $key) use ($first_item_id, $last_item_id) {
                                    return $value['id'] >= $first_item_id && $value['id'] <= $last_item_id;
                                });

                                if (!blank($items1)) {
                                    $cache_data['open'] = array_first($items1)['open'] ?? $cache_data['open'];
                                    $cache_data['close'] = array_last($items1)['close'] ?? $cache_data['close'];
                                    $cache_data['high'] = max(array_pluck($items1, 'high')) ?? $cache_data['high'];
                                    $cache_data['low'] = min(array_pluck($items1, 'low')) ?? $cache_data['low'];
                                }

                                if (blank($kline_book)) {
                                    Cache::store('redis')->put($kline_book_key, [$cache_data], 86400 * 7);
                                } else {
                                    $last_item1 = array_pop($kline_book);
                                    if ($last_item1['id'] == $cache_data['id']) {
                                        array_push($kline_book, $cache_data);
                                    } else {
                                        $update_last_item1 = $last_item1;
                                        $first_item_id2 = $cache_data['id'] - $seconds;
                                        $last_item_id2 = $cache_data['id'] - $map['seconds'];
                                        $items2 = array_where($kline_base_book, function ($value, $key) use ($first_item_id2, $last_item_id2) {
                                            return $value['id'] >= $first_item_id2 && $value['id'] <= $last_item_id2;
                                        });
                                        if (!blank($items2)) {
                                            $update_last_item1['open'] = array_first($items2)['open'] ?? $update_last_item1['open'];
                                            $update_last_item1['close'] = array_last($items2)['close'] ?? $update_last_item1['close'];
                                            $update_last_item1['high'] = max(array_pluck($items2, 'high')) ?? $update_last_item1['high'];
                                            $update_last_item1['low'] = min(array_pluck($items2, 'low')) ?? $update_last_item1['low'];
                                        }
                                        array_push($kline_book, $update_last_item1, $cache_data);
                                    }
                                    if (count($kline_book) > 3000) {
                                        array_shift($kline_book);
                                    }
                                    Cache::store('redis')->put($kline_book_key, $kline_book, 86400 * 7);
                                }
                            }
                        }
                    }

                    Cache::store('redis')->put('swap:' . $symbol . '_kline_' . $period, $cache_data, 3600);

                    $res = [
                        "period" => $period,
                        "data" => $cache_data,
                    ];
                }
            }

        } catch (\Exception $e) {
            echo "[异常] 处理K线数据时发生错误：" . $e->getMessage() . "\n";
        }

        return $res;
    }
}
