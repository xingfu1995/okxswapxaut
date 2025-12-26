#!/bin/bash
# 监控 Redis 写入并记录进程信息

REDIS_PASSWORD="wobuzhidao"
LOG_FILE="/tmp/redis_write_monitor.log"

echo "=========================================="
echo "Redis 写入监控（包含进程追踪）"
echo "日志文件: $LOG_FILE"
echo "=========================================="
echo ""

# 清空旧日志
> $LOG_FILE

echo "开始监控 swap:XAUT_detail 的写入..."
echo "按 Ctrl+C 停止"
echo ""

# 监控 Redis 并记录每次写入时的进程快照
redis-cli -a $REDIS_PASSWORD MONITOR 2>/dev/null | grep 'swap:XAUT_detail' | grep -E '"SET"|"SETEX"' | while read line; do
    TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')

    echo "=========================================" | tee -a $LOG_FILE
    echo "[$TIMESTAMP] 检测到写入" | tee -a $LOG_FILE
    echo "$line" | tee -a $LOG_FILE
    echo "" | tee -a $LOG_FILE

    # 记录当前运行的 PHP 进程
    echo "当前运行的 PHP 进程:" | tee -a $LOG_FILE
    ps aux | grep -E "php.*swap|php.*get_|php.*forex" | grep -v grep | tee -a $LOG_FILE

    echo "" | tee -a $LOG_FILE

    # 记录所有 PHP 进程（以防遗漏）
    echo "所有 PHP 进程:" | tee -a $LOG_FILE
    ps aux | grep php | grep -v grep | head -20 | tee -a $LOG_FILE

    echo "" | tee -a $LOG_FILE

    # 立即读取写入的数据
    echo "写入的数据:" | tee -a $LOG_FILE
    redis-cli -a $REDIS_PASSWORD --raw GET swap:XAUT_detail 2>/dev/null | tee -a $LOG_FILE

    echo "" | tee -a $LOG_FILE
done
