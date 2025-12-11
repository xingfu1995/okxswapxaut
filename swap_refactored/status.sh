#!/bin/bash

# ====================================================
# OKX XAUT Swap 数据采集系统状态检查脚本（重构版）
# ====================================================

# 自动切换到脚本所在目录
cd "$(dirname "$0")"

echo "========================================="
echo "服务状态检查"
echo "========================================="
echo ""

echo "[1] FOREX 价格获取服务"
php get_forex_price.php status
echo ""

echo "[2] 市场行情数据处理服务"
php swap_market.php status
echo ""

echo "[3] 深度数据处理服务"
php swap_depth.php status
echo ""

echo "[4] 成交数据处理服务"
php swap_trade.php status
echo ""

echo "[5] K线数据采集服务"
php swap_kline.php status
echo ""

echo "========================================="
echo "Redis 数据检查"
echo "========================================="
echo ""

# 如果有 redis-cli，显示关键数据
if command -v redis-cli &> /dev/null; then
    echo "FOREX 状态:"
    redis-cli GET swap:FOREX_status 2>/dev/null || echo "  (redis-cli 不可用)"
    echo ""

    echo "FOREX 最后更新时间:"
    FOREX_TIME=$(redis-cli GET swap:FOREX_last_update_time 2>/dev/null)
    if [ -n "$FOREX_TIME" ]; then
        echo "  $FOREX_TIME ($(date -d @$FOREX_TIME 2>/dev/null || echo 'N/A'))"
    fi
    echo ""

    echo "价格差值:"
    redis-cli GET swap:XAUT_price_difference 2>/dev/null || echo "  (获取失败)"
    echo ""

    echo "OKX 最新价格:"
    redis-cli GET swap:OKX_XAUT_last_price 2>/dev/null || echo "  (获取失败)"
    echo ""
fi

echo "========================================="
