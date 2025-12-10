<?php


use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class OKX
{
    public $period;
    public $symbol;
    public $is_k;

    // ----------------- 全局限流控制（所有 OK 实例共享） -----------------
    protected static $lastRequestAt = 0;     // 上次请求时间
    protected static $minInterval = 0.35;  // 每次请求最小间隔（秒），值越大越安全

    public function __construct($period, $symbol, $is_k = true,$isget=true)
    {

        if($isget){

            $this->period = $period;   // 例如：'1D'
            $this->symbol = $symbol;   // 例如：'XAUT'
            $this->is_k = $is_k;

            foreach ($this->periods as $k => $v) {
                $this->getHistory($k);
            }

        }

    }

    // 周期映射：键是 OKX 的 bar（1m/5m/1D/1W...），value 是你自己的标识
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

    // 统一限流：确保单进程内 QPS 不会太高，避免 429
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
     * 拉某个周期的历史 K 线
     *
     * @param string $period 比如 '1D' / '1m'（对应上面的 periods key）
     * @param array $cache_data 已经积累的历史数据（递归翻页时用）
     * @param string $before 分页用的毫秒时间戳字符串（翻更早的数据）
     */
    public function getHistory($period, $cache_data = [], $before = '')
    {
        // 每次请求前做限流






    }

    // 去重：按时间戳 id 去重
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

    // WebSocket 订阅参数
    public function onConnectParams($channel = null)
    {
        $params = [];
        if ($this->is_k) {
            if ($channel) {
                // 只订阅当前构造传进来的 $this->period
                $this->getHistory($this->period); // 只拉一个周期的历史
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

    // WebSocket 推送的数据处理（你原来的逻辑基本保持不动）
    public function data($data): array
    {
        $res = [];

        if (isset($data['data'])) {
            $stream = $data['arg'];
            $symbol = explode("-", $stream["instId"])[0]; // 币种名称，如 XAUT
            $periodKey = str_after($stream['channel'], 'candle'); // '1m' / '1D' ...

            $seconds = $this->periods[$periodKey]['seconds'];
            $period = $this->periods[$periodKey]['period']; // '1day' 等

            if ($this->is_k) {
                $v = $data['data'][0];




                $XAU_USD_data = Cache::store('redis')->get('swap:XAU_USD_data2');

//                file_put_contents('111xau.log',json_encode($XAU_USD_data)."\n", FILE_APPEND);
//                file_put_contents('222xau.log',json_encode($v)."\n", FILE_APPEND);


                $efx_id = 0;
                $efx_open = 0;
                $efx_high = 0;
                $efx_low = 0;

                if (!empty($XAU_USD_data['id'])) {


                    $cache_data =  [
                        'id'    => intval($v['0'] / 1000),  // 时间戳

                        'open'  => round(  floatval($v[1]) + floatval($XAU_USD_data['open']) , 1), //开盘价（你原来的写法是错误的）
                        'high'  => round(floatval($v[2]) + floatval($XAU_USD_data['high']) , 1),    //最高价（使用真实高价）
                        'low'   => round(   floatval($v[3]) + floatval($XAU_USD_data['low']) , 1),      //最低价（使用真实低价）
                        'close' => round(   floatval($v[4]) + floatval($XAU_USD_data['close']), 1), //收盘价（同上）


                        'amount'=> floatval($v[5]), //成交量
                        'vol'   => round(floatval($v[7]), 4), //成交额
                        'time' => time(),
                    ];


//                    file_put_contents('3xau.log',json_encode($cache_data)."\n", FILE_APPEND);


                } else{

                    $cache_data = [
                        'id' => intval($v[0] / 1000),
                        'open' => floatval($v[1]),
                        'high' => floatval($v[2]),
                        'low' => floatval($v[3]),
                        'close' => floatval($v[4]),
                        'amount' => floatval($v[5]),
                        'vol' => floatval($v[7]),
                        'time' => time(),
                    ];


                }


//
//                $cache_data = [
//                    'id' => intval($v[0] / 1000),
//                    'open' => floatval($v[1]),
//                    'high' => floatval($v[2]),
//                    'low' => floatval($v[3]),
//                    'close' => floatval($v[4]),
//                    'amount' => floatval($v[5]),
//                    'vol' => floatval($v[7]),
//                    'time' => time(),
//                ];
//
//                file_put_contents('4xau.log',json_encode($cache_data)."\n", FILE_APPEND);




                if ($cache_data['id'] <= time() + 1) {

                    $kline_book_key = 'swap:' . $symbol . '_kline_book_' . $period;
                    $kline_book = Cache::store('redis')->get($kline_book_key);

                    // 风控调整价格
//                    $risk_key = 'fkJson:' . $symbol . '/USDT';
//                    $risk = json_decode(Redis::get($risk_key), true);
//                    $minUnit = $risk['minUnit'] ?? 0;
//                    $count = $risk['count'] ?? 0;
//                    $enabled = $risk['enabled'] ?? 0;
//                    if (!blank($risk) && $enabled == 1) {
//                        $change = $minUnit * $count;
//                        $cache_data['close'] = PriceCalculate($cache_data['close'], '+', $change, 8);
//                        $cache_data['open'] = PriceCalculate($cache_data['open'], '+', $change, 8);
//                        $cache_data['high'] = PriceCalculate($cache_data['high'], '+', $change, 8);
//                        $cache_data['low'] = PriceCalculate($cache_data['low'], '+', $change, 8);
//                    }



                    if ($period == '1min') {

//
//                        if (!blank($kline_book)) {
//                            $prev_id = $cache_data['id'] - $seconds;
//                            $prev_item = array_last($kline_book, function ($value, $key) use ($prev_id) {
//                                return $value['id'] == $prev_id;
//                            });
//                            $cache_data['open'] = $prev_item['close'] ?? $cache_data['open'];
//                        }


//                        file_put_contents('1--xau.log',json_encode($cache_data) );


                        if (blank($kline_book)) {
                            Cache::store('redis')->put($kline_book_key, [$cache_data]);
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
                            Cache::store('redis')->put($kline_book_key, $kline_book);
                        }
                    } else {
                        // 其它周期基于短周期聚合的逻辑（保持你原来的逻辑）
                        $periodMap = [
                            '5min' => ['period' => '1min', 'seconds' => 60],
                            '15min' => ['period' => '5min', 'seconds' => 300],
                            '30min' => ['period' => '15min', 'seconds' => 900],
                            '60min' => ['period' => '30min', 'seconds' => 1800],
                            '4hour' => ['period' => '60min', 'seconds' => 3600],
                            '1day' => ['period' => '4hour', 'seconds' => 14400],
                            '1week' => ['period' => '1week', 'seconds' => 86400],
                            '1mon' => ['period' => '1mon', 'seconds' => 604800],
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

//                                file_put_contents('2--xau.log',json_encode($cache_data) );

                                if (blank($kline_book)) {
                                    Cache::store('redis')->put($kline_book_key, [$cache_data]);
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
                                    Cache::store('redis')->put($kline_book_key, $kline_book);
                                }
                            }
                        }
                    }

                    Cache::store('redis')->put('swap:' . $symbol . '_kline_' . $period, $cache_data);

                    $res = [
                        "period" => $period,
                        "data" => $cache_data,
                    ];
                }
            }

        }

        return $res;
    }
}

