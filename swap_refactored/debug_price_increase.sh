#!/bin/bash
# 诊断价格累加问题的脚本

echo "=========================================="
echo "价格累加问题诊断工具"
echo "=========================================="
echo ""

# Redis 密码
REDIS_PASSWORD="5ULiRJPngY2Qbpqo"

echo "[1] 检查当前价格和差值..."
echo "----------------------------------------"

# 获取当前数据
XAUT_DETAIL=$(redis-cli -a $REDIS_PASSWORD --raw GET swap:XAUT_detail 2>/dev/null)
PRICE_DIFF=$(redis-cli -a $REDIS_PASSWORD --raw GET swap:XAUT_price_difference 2>/dev/null)
DIFF_INFO=$(redis-cli -a $REDIS_PASSWORD --raw GET swap:XAUT_difference_info 2>/dev/null)
OKX_ORIGINAL=$(redis-cli -a $REDIS_PASSWORD --raw GET swap:OKX_XAUT_market_original 2>/dev/null)
FOREX_PRICE=$(redis-cli -a $REDIS_PASSWORD --raw GET swap:FOREX_XAU_USD_price 2>/dev/null)

echo "swap:XAUT_detail (调整后价格):"
echo "$XAUT_DETAIL" | jq '.' 2>/dev/null || echo "$XAUT_DETAIL"
echo ""

echo "swap:XAUT_price_difference (差值):"
echo "$PRICE_DIFF"
echo ""

echo "swap:XAUT_difference_info (差值详情):"
echo "$DIFF_INFO" | jq '.' 2>/dev/null || echo "$DIFF_INFO"
echo ""

echo "swap:OKX_XAUT_market_original (OKX原始价格):"
echo "$OKX_ORIGINAL" | jq '.' 2>/dev/null || echo "$OKX_ORIGINAL"
echo ""

echo "swap:FOREX_XAU_USD_price (FOREX价格):"
echo "$FOREX_PRICE" | jq '.' 2>/dev/null || echo "$FOREX_PRICE"
echo ""

echo "[2] 检查运行中的进程..."
echo "----------------------------------------"
ps aux | grep -E "swap.*\.php|get_forex|get_difference|get_new_xaut" | grep -v grep | grep -v debug

echo ""
echo "[3] 实时监控 swap:XAUT_detail 的写入（按 Ctrl+C 停止）..."
echo "----------------------------------------"
echo "监控命令: 查看谁在写入 swap:XAUT_detail"
echo ""

redis-cli -a $REDIS_PASSWORD MONITOR 2>/dev/null | grep -E 'swap:XAUT_detail|swap:XAU_USD_data' | grep -E '"SET"|"SETEX"'
