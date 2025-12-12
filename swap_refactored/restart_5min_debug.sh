#!/bin/bash
# 重启5分钟K线并查看调试日志

cd /home/user/okxswapxaut/swap_refactored

echo "停止5分钟K线服务..."
php swap_kline_5min.php stop

echo "等待2秒..."
sleep 2

echo "启动5分钟K线服务..."
php swap_kline_5min.php start

echo "等待5秒让服务启动..."
sleep 5

echo "========================================"
echo "查看最近的日志（按 Ctrl+C 停止）:"
echo "========================================"
tail -f /tmp/workerman.log | grep -E "(定时器|5分钟)"
