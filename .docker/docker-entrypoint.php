#!/usr/local/bin/php
<?php
const APP_DIR = __DIR__ . "/../../../";

$action = $argv[1] ?? null;

if (in_array($action, ["start", "backup", "restore", "cleanup"])) {

	echo "[backuping]: action is: '{$action}'\n";
	if ($action == "start") {
		$env = "";
		foreach (getenv() as $key => $val) {
			$env .= $key . "=\"" . $val . "\"\n";
		}
		file_put_contents("/app/env.sh", $env);

		$php = "/usr/local/bin/php";
		$app = "/app/index.php ";
		$process = "--process=packages/backuping/processes/Backuping";

		$verboseBackup = in_array(getenv("BACKUPING_BACKUP_CRON_VERBOSE"), [1, "1", true, "true", "yes"]);
		$backupCron = getenv("BACKUPING_BACKUP_CRON_EXPRESSION") ?: "* * * * *";
		$backup = $backupCron . " source /app/env.sh && {$php} {$app} {$process}@backup " . ($verboseBackup ? "--verbose " : "") . "2>&1";
		echo "[backuping]: add cron: {$backup}";
		file_put_contents("/etc/crontabs/root", $backup . "\n");

		$verboseCleanup = in_array(getenv("BACKUPING_CLEANUP_CRON_VERBOSE"), [1, "1", true, "true", "yes"]);
		$cleanupCron = getenv("BACKUPING_CLEANUP_CRON_EXPRESSION") ?: "* * * * *";
		$cleanup = $cleanupCron . " source /app/env.sh && {$php} {$app} {$process}@cleanup " . ($verboseCleanup ? "--verbose" : "") . "2>&1";
		echo "[backuping]: add cron: {$cleanup}";
		file_put_contents("/etc/crontabs/root", $cleanup . "\n", FILE_APPEND);

		passthru("/usr/sbin/crond -f -l 4", $code);
		exit($code);
	}

	echo "[backuping]: change directory to " . realpath(APP_DIR) . "\n";
	chdir(APP_DIR);
	$command = "php index.php --process=packages/backuping/processes/Backuping@{$action} ";
	$command .= implode(" ", array_slice($argv, 2));
	echo "[backuping]: command: {$command}";
	passthru($command, $code);
	exit($code);
} else {
	$code = null;
	passthru(implode(" ", array_slice($argv, 1)) . "> `tty`", $code);
	exit($code);
}