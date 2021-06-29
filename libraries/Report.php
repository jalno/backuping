<?php
namespace packages\backuping;

use packages\phpmailer\PHPMailer;

class Report {
	private ?string $subject = null;
	private ?string $message = null;
	private ?array $sender = array(
		"mail" => null,
		"name" => null,
	);
	private ?array $receivers = array();

	public function setSubject(string $subject) {
		$this->subject = $subject;
	}
	public function setMessage(string $message) {
		$this->message = $message;
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
		$mail->setFrom($this->sender['mail'], isset($this->sender['name']) ? $this->sender['name'] : '');

		foreach ($this->receivers as $receiver) {
			$mail->addAddress($receiver['mail'], $receiver['name'] ?? '');
		}

		$mail->Subject = $this->subject;
		$mail->Body = $this->message;
		return $mail->send();
	}
}
