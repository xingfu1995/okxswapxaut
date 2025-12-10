#!/bin/bash

# 自动切换到脚本所在目录
cd "$(dirname "$0")"

# 创建 PID 目录（如不存在）
if [ ! -d "aaaruntime" ]; then
    mkdir -p aaaruntime
fi

# 给运行目录权限，避免 Workerman 无法写 PID
chmod -R 777 aaaruntime

echo "正在关闭旧进程..."

# 关闭所有 swap Worker
php swap_depth.php stop
#
php swap_trade.php stop
#
php swap_market.php stop
#
php swap_kline.php stop
#
php get_new_xaut.php stop
#
php get_difference.php stop
php swapKline1second.php stop


# 等待一下，避免 stop 后马上 start 导致冲突
sleep 1

echo "正在后台启动所有 swap Workerman 服务..."
php get_new_xaut.php start -d
sleep 1
php get_difference.php start -d
sleep 1
php swap_depth.php start -d
sleep 1
php swap_trade.php start -d
sleep 1
php swap_market.php start -d
sleep 1
php swap_kline.php start -d
sleep 1
php swapKline1second.php start -d



echo "全部启动成功！"

