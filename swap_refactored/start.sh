#!/bin/bash

# ====================================================
# OKX XAUT Swap 数据采集系统启动脚本（重构版）
# ====================================================

# 自动切换到脚本所在目录
cd "$(dirname "$0")"

# 创建运行时目录
if [ ! -d "runtime" ]; then
    mkdir -p runtime
fi

# 给运行目录权限
chmod -R 777 runtime

echo "========================================="
echo "正在关闭旧进程..."
echo "========================================="

# 关闭所有进程
php get_forex_price.php stop 2>/dev/null
php swap_market.php stop 2>/dev/null
php swap_kline_1min.php stop 2>/dev/null
php swap_kline_5min.php stop 2>/dev/null
php swap_kline_15min.php stop 2>/dev/null
php swap_kline_30min.php stop 2>/dev/null
php swap_kline_60min.php stop 2>/dev/null
php swap_kline_4hour.php stop 2>/dev/null
php swap_kline_1day.php stop 2>/dev/null
php swap_kline_1week.php stop 2>/dev/null
php swap_kline_1mon.php stop 2>/dev/null
php swap_depth.php stop 2>/dev/null
php swap_trade.php stop 2>/dev/null

# 等待一下，避免冲突
sleep 2

echo ""
echo "========================================="
echo "正在启动所有服务..."
echo "========================================="

# 按顺序启动服务

echo ""
echo "[1/5] 启动 FOREX 价格获取服务..."
php get_forex_price.php start -d
if [ $? -eq 0 ]; then
    echo "✓ FOREX 价格获取服务启动成功"
else
    echo "✗ FOREX 价格获取服务启动失败"
fi
sleep 2

echo ""
echo "[2/5] 启动市场行情数据处理服务..."
php swap_market.php start -d
if [ $? -eq 0 ]; then
    echo "✓ 市场行情数据处理服务启动成功"
else
    echo "✗ 市场行情数据处理服务启动失败"
fi
sleep 2

echo ""
echo "[3/5] 启动深度数据处理服务..."
php swap_depth.php start -d
if [ $? -eq 0 ]; then
    echo "✓ 深度数据处理服务启动成功"
else
    echo "✗ 深度数据处理服务启动失败"
fi
sleep 1

echo ""
echo "[4/5] 启动成交数据处理服务..."
php swap_trade.php start -d
if [ $? -eq 0 ]; then
    echo "✓ 成交数据处理服务启动成功"
else
    echo "✗ 成交数据处理服务启动失败"
fi
sleep 1

echo ""
echo "[5/13] 启动 1分钟K线数据采集服务..."
php swap_kline_1min.php start -d
if [ $? -eq 0 ]; then
    echo "✓ 1分钟K线数据采集服务启动成功"
else
    echo "✗ 1分钟K线数据采集服务启动失败"
fi
sleep 1

echo ""
echo "[6/13] 启动 5分钟K线数据采集服务..."
php swap_kline_5min.php start -d
if [ $? -eq 0 ]; then
    echo "✓ 5分钟K线数据采集服务启动成功"
else
    echo "✗ 5分钟K线数据采集服务启动失败"
fi
sleep 1

echo ""
echo "[7/13] 启动 15分钟K线数据采集服务..."
php swap_kline_15min.php start -d
if [ $? -eq 0 ]; then
    echo "✓ 15分钟K线数据采集服务启动成功"
else
    echo "✗ 15分钟K线数据采集服务启动失败"
fi
sleep 1

echo ""
echo "[8/13] 启动 30分钟K线数据采集服务..."
php swap_kline_30min.php start -d
if [ $? -eq 0 ]; then
    echo "✓ 30分钟K线数据采集服务启动成功"
else
    echo "✗ 30分钟K线数据采集服务启动失败"
fi
sleep 1

echo ""
echo "[9/13] 启动 1小时K线数据采集服务..."
php swap_kline_60min.php start -d
if [ $? -eq 0 ]; then
    echo "✓ 1小时K线数据采集服务启动成功"
else
    echo "✗ 1小时K线数据采集服务启动失败"
fi
sleep 1

echo ""
echo "[10/13] 启动 4小时K线数据采集服务..."
php swap_kline_4hour.php start -d
if [ $? -eq 0 ]; then
    echo "✓ 4小时K线数据采集服务启动成功"
else
    echo "✗ 4小时K线数据采集服务启动失败"
fi
sleep 1

echo ""
echo "[11/13] 启动 1天K线数据采集服务..."
php swap_kline_1day.php start -d
if [ $? -eq 0 ]; then
    echo "✓ 1天K线数据采集服务启动成功"
else
    echo "✗ 1天K线数据采集服务启动失败"
fi
sleep 1

echo ""
echo "[12/13] 启动 1周K线数据采集服务..."
php swap_kline_1week.php start -d
if [ $? -eq 0 ]; then
    echo "✓ 1周K线数据采集服务启动成功"
else
    echo "✗ 1周K线数据采集服务启动失败"
fi
sleep 1

echo ""
echo "[13/13] 启动 1月K线数据采集服务..."
php swap_kline_1mon.php start -d
if [ $? -eq 0 ]; then
    echo "✓ 1月K线数据采集服务启动成功"
else
    echo "✗ 1月K线数据采集服务启动失败"
fi
sleep 1

echo ""
echo "========================================="
echo "服务状态检查"
echo "========================================="

# 检查所有进程状态
php get_forex_price.php status
php swap_market.php status
php swap_depth.php status
php swap_trade.php status
php swap_kline_1min.php status
php swap_kline_5min.php status
php swap_kline_15min.php status
php swap_kline_30min.php status
php swap_kline_60min.php status
php swap_kline_4hour.php status
php swap_kline_1day.php status
php swap_kline_1week.php status
php swap_kline_1mon.php status

echo ""
echo "========================================="
echo "全部启动完成！"
echo "========================================="
echo ""
echo "监控命令："
echo "  tail -f runtime/*.log  # 查看日志"
echo "  ./status.sh            # 查看服务状态"
echo "  ./stop.sh              # 停止所有服务"
echo ""
