<?php
require "../index.php";

use App\Models\InsideTradePair;
use App\Models\Mongodb\KlineBook;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Lib\Timer;
use Workerman\Worker;
use GatewayWorker\Lib\Gateway;
use Illuminate\Support\Facades\Redis;

Worker::$pidFile = __DIR__ . '/aaaruntime/' . basename(__FILE__, '.php') . '.pid';

$worker = new Worker();
$worker->count = 1;
$worker->onWorkerStart = function($worker){

    Gateway::$registerAddress = '127.0.0.1:1336';

    $http = new Workerman\Http\Client();
    $symbols = InsideTradePair::query()->where('status',1)->where('is_market',1)->where('symbol','!=','ecusdt')->where('symbol','!=','ocusdt')->pluck('symbol')->toArray();
    $period = '1min';
    foreach ($symbols as $symbol){
        if($symbol == 'ecusdt' || $symbol == 'ocusdt'){
            continue;
        }
        $http_url = 'https://api.huobi.pro/market/history/kline?period='.$period.'&size=2000&symbol='.$symbol;
        $http->get($http_url, function($response)use($symbol,$period){
            if($response->getStatusCode() == 200){
                $data = json_decode($response->getBody(),true);
                $kline_book_key = 'market:' . $symbol . '_kline_book_' . $period;
                if(isset($data['data'])){
                    $data['data'] = array_reverse($data['data']);
                    $cache_data = $data['data'];
                    Cache::store('redis')->put($kline_book_key,$cache_data);
                }
            }
        }, function($exception){
            info($exception);
        });
    }

    $con = new AsyncTcpConnection('ws://api.huobi.pro/ws');

    // 设置以ssl加密方式访问，使之成为wss
    $con->transport = 'ssl';

    $con->onConnect = function($con) use ($symbols,$period) {

        //所有交易对
        foreach ($symbols as $symbol){
            // Kline数据
            $ch = "market.". $symbol .".kline." . $period;
            $sub_msg = ["sub"=> $ch, 'id' => $ch . '_sub_' . time()];
            $con->send(json_encode($sub_msg));
        }

    };

    $con->onMessage = function($con, $data) {
        $data =  json_decode(gzdecode($data),true);
        if(isset($data['ping'])){
            $msg = ["pong" => $data['ping']];
            $con->send(json_encode($msg));
        }else{
            if(isset($data['ch'])){
                $ch = $data['ch'];
                $pattern_kline = '/^market\.(.*?)\.kline\.([\s\S]*)/'; //Kline
                if (preg_match($pattern_kline, $ch, $match_kline)){
                    $symbol = $match_kline[1];
                    $period = $match_kline[2];
                    $cache_data = $data['tick'];
                    $cache_data['time'] = time();

                    $kline_book_key = 'market:' . $symbol . '_kline_book_' . $period;
                    $kline_book = Cache::store('redis')->get($kline_book_key);

                    $seconds = 60;

                    if(!blank($kline_book)){
                        $prev_id = $cache_data['id'] - $seconds;
                        $prev_item = array_last($kline_book,function ($value,$key)use($prev_id){
                            return $value['id'] == $prev_id;
                        });
                        if(!empty($prev_item) && $prev_item['close']) $cache_data['open'] = $prev_item['close'];
                    }

                    Cache::store('redis')->put('market:' . $symbol . '_kline_' . $period,$cache_data);

                    if(blank($kline_book)){
                        Cache::store('redis')->put($kline_book_key,[$cache_data]);
                    }else{
                        $last_item1 = array_pop($kline_book);
                        if($last_item1['id'] == $cache_data['id']){
                            array_push($kline_book,$cache_data);
                        }else{
                            array_push($kline_book,$last_item1,$cache_data);
                        }

                        if(count($kline_book) > 3000){
                            array_shift($kline_book);
                        }
                        Cache::store('redis')->put($kline_book_key,$kline_book);
                    }

                    // 缓存kline历史数据到mongodb
//                $cache_data['key'] = 'Kline_' . $symbol . '_' . $period;
//                $cache_data['time'] = time();
//                KlineBook::query()->updateOrCreate(['id' => $cache_data['id'],'key' => 'Kline_' . $symbol . '_' . $period],array_except($cache_data,['id','key']));

                    $group_id2 = 'Kline_' . $symbol . '_' . $period;
                    if($symbol != 'ecusdt' || $symbol != 'ocusdt'){
                        if(Gateway::getClientIdCountByGroup($group_id2) > 0){
                            Gateway::sendToGroup($group_id2, json_encode(['code'=>0,'msg'=>'success','data'=>$cache_data,'sub'=>$group_id2,'type'=>'dynamic']));
                        }
                    }
                }

            }
        }
    };

    $con->onClose = function ($con) {
        //这个是延迟断线重连，当服务端那边出现不确定因素，比如宕机，那么相对应的socket客户端这边也链接不上，那么可以吧1改成适当值，则会在多少秒内重新，我也是1，也就是断线1秒重新链接
        $con->reConnect(1);
    };

    $con->onError = function ($con, $code, $msg) {
        echo "error $code $msg\n";
    };

    $con->connect();
};

Worker::runAll();
