#!/bin/sh

if [[ -n "$BACKUPING_BACKUP_CRON_EXPRESSION" ]]; then
	echo "[Backuping]: Setup cronjob"
	echo "$BACKUPING_BACKUP_CRON_EXPRESSION php /var/www/html/index.php --process=packages/backuping/processes/Backuping@backup" | crontab -;
fi