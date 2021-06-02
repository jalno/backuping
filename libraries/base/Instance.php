<?php
namespace packages\backuping\logging;

use packages\base\{JSON, Log\Instance as BaseLogInstance};
use packages\backuping\{Log};

class Instance extends BaseLogInstance {

	public function __construct($level) {
		Log::newChild();
		$this->setLevel($level);
	}

	public function setLevel($level){
		if (in_array($level, array(
			Log::debug,
			Log::info,
			Log::warn,
			Log::error,
			Log::fatal,
			Log::off,
		))) {
			$this->level = $level;
		}
	}

	public function getLevel(){
		return $this->level;
	}

	public function log($level, $data) {
		if ($data) {
			$check = $this->checkLevel($level);
			$this->lastLevel = $level;
			if ($check) {
				Log::write($level, $this->createMessage($data));
			}
			$this->append = false;
			$this->replyCharacter = '';
		}
		return $this;
	}

	private function checkLevel($level) {
		return $this->level and $level >= $this->level;
	}

	private function createMessage($args) {
		$message = '';
		foreach ($args as $arg) {
			if ($message) {
				$message .= " ";
			}
			$type = gettype($arg);
			if (in_array($type, array('array','object','boolean','NULL'))) {
			    if ($type == 'object') {
			        $arg = (array) $arg;
			    }
				$message .= json\encode($arg);
			} else {
				$message .= $arg;
			}
		}
		if ($this->append) {
			$message = $this->lastMessage.$this->replyCharacter.$message;
		}
		$this->lastMessage = $message;
		return $message;
	}

}
