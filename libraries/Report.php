<?php
namespace packages\backuping;

use \InvalidArgumentException;
use packages\base\Log;
use packages\phpmailer\{PHPMailer, SMTP};

class Report {
	private ?string $subject = null;
	private ?string $message = null;
	private array $from = array(
		"address" => null,
		"name" => null,
	);
	private array $sender = array(
		"mail" => null,
		"name" => null,
	);
	private array $options = array(
		// currently only support 'mail' or 'smtp'
		"mailer" => "mail",
		"host" => null,
		"port" => 25,
		"username" => null,
		"password" => null,
		"auth_type" => null,
		"smtp_auth" => null,
	);

	private ?array $receivers = array();

	public function setSubject(string $subject) {
		$this->subject = $subject;
	}
	public function setMessage(string $message) {
		$this->message = $message;
	}
	public function setFrom(string $address, string $name = "") {
		$this->from["address"] = $address;
		$this->from["name"] = $name;
	}
	public function setMailer(string $mailer, ?array $options) {
		$mailer = strtolower($mailer);
		if (!in_array($mailer, ["mail", "smtp"])) {
			throw new InvalidArgumentException("the mailer is not valid, currently 'mail' or 'smtp' is valid!");
		}
		$this->options["mailer"] = $mailer;
		$this->options = array_merge($this->options, $options);
	}
	public function setSender(array $sender) {
		$this->sender["mail"] = $sender["mail"];
		$this->sender["name"] = $sender["name"];
	}
	public function addReceiver(array $receiver) {
		$this->receivers[] = array(
			"name" => $receiver["name"],
			"mail" => $receiver["mail"],
		);
	}
	public function send() {
		$mail = new PHPMailer();

		// in case you want to debug send email, uncomment these lines:

		/*
		$logger = Log::getInstance();
		$logger->info("Report::send phpMailer debug:");
		$mail->SMTPDebug = SMTP::DEBUG_SERVER;
		$mail->Debugoutput = function($str, $level) use (&$logger) {
			$levelText = "";
			switch ($level) {
				case SMTP::DEBUG_OFF: $levelText = "SMTP::DEBUG_OFF"; break;
				case SMTP::DEBUG_CLIENT: $levelText = "SMTP::DEBUG_CLIENT"; break;
				case SMTP::DEBUG_SERVER: $levelText = "SMTP::DEBUG_SERVER"; break;
				case SMTP::DEBUG_CONNECTION: $levelText = "SMTP::DEBUG_CONNECTION"; break;
				case SMTP::DEBUG_LOWLEVEL: $levelText = "SMTP::DEBUG_LOWLEVEL"; break;
			}
			$logger->info("(" . $levelText . ") : " . $str);
		};
		*/

		foreach ($this->receivers as $receiver) {
			$mail->addAddress($receiver['mail'], $receiver['name'] ?? '');
		}

		if ($this->from['address']) {
			$mail->setFrom($this->from['address'], isset($this->from['name']) ? $this->from['name'] : '');
		}

		if ($this->options['mailer'] == 'mail') {
			$mail->isMail();
		} elseif ($this->options['mailer'] == 'smtp') {
			$mail->isSMTP();
			$mail->Host = $this->options['host'];
			$mail->Port = $this->options['port'] ?? 25;

			if (isset($this->options['username'])) {
				$mail->Username = $this->options['username'];
			}
			if (isset($this->options['password'])) {
				$mail->Password = $this->options['password'];
			}
			if (isset($this->options['smtp_auth'])) {
				$mail->SMTPAuth = $this->options['smtp_auth'];
			} elseif (isset($this->options['username']) or $this->options['password']) {
				$mail->SMTPAuth = true;
			}
			if (isset($this->options['auth_type'])) {
				$mail->AuthType = $this->options['auth_type'];
			}
		}

		$mail->Subject = $this->subject;
		$mail->Body = $this->message;
		return $mail->send();
	}
}
