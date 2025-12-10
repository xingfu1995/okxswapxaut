<?php

function history($period,$symbol)
{
    $http = new Workerman\Http\Client();
    $params = http_build_query([
        'instId' => strtoupper($symbol).'-USDT-SWAP',
        'interval' => $period,
        'limit' => 1500
    ]);

    $http_url = 'https://www.okx.com/api/v5/market/history-candles?' . $params;

    $http->get($http_url, function ($response) use ($symbol, $period, $periods) {

        if ($response->getStatusCode() == 200) {
            $data = json_decode($response->getBody(), true);

            $kline_book_key = 'swap:' . $symbol . '_kline_book_' . $periods[$period]['period'];
            if (is_array($data)) {
//                dd($data["data"]);
                $cache_data = collect($data["data"], true)->map(function ($v) {
                    return [
                        'id' => intval($v['0'] / 1000), //时间戳
                        'open' => floatval($v['1']), //开盘价
                        'close' => floatval($v['4']),    //收盘价
                        'high' => floatval($v['2']), //最高价
                        'low' => floatval($v['3']),  //最低价
                        'amount' => floatval($v['5']),    //成交量(币)
                        'vol' => floatval($v['7']),  //成交额
                        'time' => time(),
                    ];
                })->reject(function ($v) {
                    if ($v['id'] > time()) return true;
                })->toArray();

                Cache::store('redis')->put($kline_book_key, $cache_data);
            }
        }
    }, function ($exception) {
        info($exception);
    });
}