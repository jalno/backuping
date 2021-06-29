<?php
namespace packages\backuping;

use packages\base\{Loader, Log as BaseLog};
use packages\backuping\{logging\Instance};

class Log extends BaseLog {
	static private $api;
	static private $parent;
	private static $generation = -1;
	private static $indentation = "\t";
	private static $messages = array();

	public static function getParent() {
		if (!self::$parent) {
			self::$parent = self::getInstance();
		}
		return self::$parent;
	}

	public static function setLevel($level) {
		switch (strtolower($level)) {
			case 'debug': $level = self::debug; break;
			case 'info': $level = self::info; break;
			case 'warn': $level = self::warn; break;
			case 'error': $level = self::error; break;
			case 'fatal': $level = self::fatal; break;
			case 'off': $level = self::off; break;
		}
		self::getParent()->setLevel($level);
	}

	public static function getInstance() {
		if (!self::$api) {
			self::$api = Loader::sapi();
		}
		$level = self::off;
		if (self::$parent) {
			$level = self::$parent->getLevel();
		}
		return new Instance($level);
	}

	public static function getMessages(): array {
		return self::$messages;
	}

	public static function write($level, $message) {
		parent::write($level, $message);
		$microtime = explode(" ", microtime());
		$date = date("Y-m-d H:i:s." . substr($microtime[0], 2) . " P");
		$pidText = (self::$api == Loader::cli ? (" [" . getmypid() . "] ") : " ");
		$levelText = "";
		switch ($level) {
			case self::debug:
				$levelText = "[DEBUG]";
				break;
			case self::info:
				$levelText = "[INFO]";
				break;
			case self::warn:
				$levelText = "[WARN]";
				break;
			case self::error:
				$levelText = "[ERROR]";
				break;
			case self::fatal:
				$levelText = "[FATAL]";
				break;
		}
		$generation = (self::$generation > 1 ? str_repeat(self::$indentation, self::$generation-1) : " ");
		$line = $date . $pidText . $levelText . $generation . $message;
		self::$messages[] = $line;
	}
}