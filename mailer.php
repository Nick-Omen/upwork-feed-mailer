<?php

require_once(dirname(__FILE__) . '/vendor/phpmailer/phpmailer/PHPMailerAutoload.php');

$mail = new PHPMailer;
$mail->isSMTP();
$mail->Host = 'smtp.gmail.com';
$mail->SMTPAuth = true;
$mail->Username = SMTP_USER;
$mail->Password = SMTP_PASSWORD;
$mail->SMTPSecure = 'tls';
$mail->Port = 587;

$sendTo = explode(',', $configs['emails']);
$mail->setFrom('smtp.omen666@gmail.com', 'Upwork notification');

if(count($sendTo) > 0) {
    foreach ($sendTo as $email) {
        $mail->addAddress($email);
    }
}

$mail->isHTML(true);

$mail->Subject = "New jobs on upwork by key '{$configs['query']}'!";
