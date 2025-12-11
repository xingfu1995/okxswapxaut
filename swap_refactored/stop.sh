#!/bin/bash

# ====================================================
# OKX XAUT Swap 数据采集系统停止脚本（重构版）
# ====================================================

# 自动切换到脚本所在目录
cd "$(dirname "$0")"

echo "========================================="
echo "正在停止所有服务..."
echo "========================================="

# 停止所有进程（并行）
php get_forex_price.php stop &
php swap_market.php stop &
php swap_kline_1min.php stop &
php swap_kline_5min.php stop &
php swap_kline_15min.php stop &
php swap_kline_30min.php stop &
php swap_kline_60min.php stop &
php swap_kline_4hour.php stop &
php swap_kline_1day.php stop &
php swap_kline_1week.php stop &
php swap_kline_1mon.php stop &
php swap_depth.php stop &
php swap_trade.php stop &

# 等待所有后台进程完成
wait

echo ""
echo "========================================="
echo "所有服务已停止"
echo "========================================="
