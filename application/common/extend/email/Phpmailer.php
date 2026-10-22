<?php
/*
 * This file is part of JavCMS - https://javcms.net
 * Based on MacCMS 10 (Apache-2.0) by magicblack, changed by JavCMS.
 * JavCMS changes: Copyright (c) 2026 JAVCMS.NET and the JavCMS contributors,
 * JavCMS Community License 1.0. The MacCMS parts stay under the Apache License 2.0.
 * See the LICENSE and NOTICE files that come with JavCMS.
 * Official sources: https://javcms.net https://avdbapi.com https://xvidapi.com https://upload18.org
 * Telegram: https://t.me/avdb_api
 */
namespace app\common\extend\email;

use app\common\util\EmailSettings;
use PHPMailer\PHPMailer\PHPMailer as Mailer;

class Phpmailer
{
    public $name = 'PhpMailer';
    public $ver = '7.1.1';

    private function message($key, $fallback)
    {
        return function_exists('lang') ? lang($key) : $fallback;
    }

    public function submit($to, $title, $body, $config = [])
    {
        try {
            if (!is_array($config)) { throw new \InvalidArgumentException('Invalid email settings.'); }
            if (!$config) {
                $config = $GLOBALS['config']['email']['phpmailer'] ?? [];
                if (!is_array($config)) { throw new \InvalidArgumentException('Invalid email settings.'); }
                $config['nick'] = $GLOBALS['config']['email']['nick'] ?? '';
            }
            if (!class_exists(EmailSettings::class)) {
                require_once dirname(__DIR__, 2) . '/util/EmailSettings.php';
            }
            $settings = EmailSettings::normalizeSmtp($config);
            $recipient = EmailSettings::recipient($to);
            if (!is_scalar($title) || !is_scalar($body)) { throw new \InvalidArgumentException('Invalid email content.'); }
            $library = dirname(__DIR__, 2) . '/vendor/phpmailer/src/';
            foreach (['Exception.php', 'SMTP.php', 'PHPMailer.php'] as $file) {
                if (!is_file($library . $file)) { throw new \RuntimeException('Email dependency is unavailable.'); }
                require_once $library . $file;
            }
            $mail = new Mailer(true);
            $mail->isSMTP();
            $mail->SMTPDebug = 0;
            $mail->Timeout = 15;
            $mail->getSMTPInstance()->Timelimit = 15;
            $mail->SMTPAuth = $settings['auth'];
            $mail->CharSet = 'UTF-8';
            $mail->Host = $settings['host'];
            $mail->SMTPSecure = $settings['secure'];
            $mail->Port = $settings['port'];
            $mail->Username = $settings['username'];
            $mail->Password = $settings['password'];
            $mail->setFrom($settings['from'], $settings['nick']);
            $mail->addAddress($recipient);
            $mail->isHTML(true);
            $mail->Subject = (string) $title;
            $mail->Body = (string) $body;
            if ($mail->send()) {
                return ['code' => 1, 'msg' => $this->message('admin/system/configemail/send_ok', 'Email sent successfully.')];
            }
        } catch (\Throwable $error) {
            // Never return SMTP server responses, credentials, or exception details.
        }
        return ['code' => 102, 'msg' => $this->message('admin/system/configemail/send_failed', 'Unable to send email. Check the email settings and try again.')];
    }
}
