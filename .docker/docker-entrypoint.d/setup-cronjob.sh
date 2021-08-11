#!/bin/sh

echo "[Backuping]: Check 'BACKUPING_BACKUP_CRON_EXPRESSION' env exists and /backuping-cron-env.save not exists?";
if [ -n "$BACKUPING_BACKUP_CRON_EXPRESSION" ] && [ ! -f /backuping-cron-env.save ]
then
	/bin/echo "[Backuping]: Setup cronjob (expression: $BACKUPING_BACKUP_CRON_EXPRESSION)";
	export > /backuping-cron-env.save;
	/bin/echo "$BACKUPING_BACKUP_CRON_EXPRESSION source /backuping-cron-env.save && /usr/local/bin/php /var/www/html/index.php --process=packages/backuping/processes/Backuping@backup" | crontab -;
else
	/bin/echo "[Backuping]: not exists, skip";
fi
