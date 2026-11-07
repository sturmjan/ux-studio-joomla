<?php

namespace Ux1\UxStudio\Modules\SmtpEmail\Mail;

use Ux1\UxStudio\Core\Http;

\defined('_JEXEC') or die;

/** Brevo (Sendinblue) transactional e-mail API v3. The API key is passed in, never stored or logged here. */
final class BrevoClient
{
    public const SEND_URL    = 'https://api.brevo.com/v3/smtp/email';
    public const ACCOUNT_URL = 'https://api.brevo.com/v3/account';

    /** Brevo rejects larger requests (message including attachments). */
    private const MAX_ATTACHMENT_BYTES = 4000000;

    public function __construct(private string $apiKey, private ?Http $http = null)
    {
        $this->http ??= new Http();
    }

    /**
     * The JSON body of POST /v3/smtp/email for a prepared message.
     *
     * @return array<string,mixed>
     * @throws \RuntimeException when the message cannot be expressed (no recipient, unreadable attachment, too large)
     */
    public static function payload(UxMail $mail): array
    {
        $addr = static function (array $list): array {
            $out = [];
            foreach (array_values($list) as $a) {
                $row = ['email' => (string) $a[0]];
                if (($a[1] ?? '') !== '') {
                    $row['name'] = (string) $a[1];
                }
                $out[] = $row;
            }

            return $out;
        };

        $to = $addr($mail->getToAddresses());
        if (!$to) {
            throw new \RuntimeException('No valid recipient.');
        }
        $sender = ['email' => (string) $mail->From];
        if ((string) $mail->FromName !== '') {
            $sender['name'] = (string) $mail->FromName;
        }

        $payload = ['sender' => $sender, 'to' => $to, 'subject' => (string) $mail->Subject];
        if ($mail->ContentType === 'text/html') {
            $payload['htmlContent'] = (string) $mail->Body;
            if ((string) $mail->AltBody !== '') {
                $payload['textContent'] = (string) $mail->AltBody;
            }
        } else {
            $payload['textContent'] = (string) $mail->Body;
        }
        if (($cc = $addr($mail->getCcAddresses()))) {
            $payload['cc'] = $cc;
        }
        if (($bcc = $addr($mail->getBccAddresses()))) {
            $payload['bcc'] = $bcc;
        }
        if (($reply = $addr($mail->getReplyToAddresses()))) {
            $payload['replyTo'] = $reply[0];
        }

        $headers = [];
        foreach ($mail->getCustomHeaders() as $h) {
            $name = trim((string) ($h[0] ?? ''));
            if ($name !== '' && preg_match('/^[A-Za-z0-9-]+$/', $name)) {
                $headers[$name] = trim((string) ($h[1] ?? ''));
            }
        }
        if ($headers) {
            $payload['headers'] = $headers;
        }

        $total = 0;
        foreach ($mail->getAttachments() as $a) {
            // Brevo has no Content-ID support: an embedded image would arrive as a plain attachment and break the cid: reference.
            if (($a[6] ?? '') === 'inline') {
                throw new \RuntimeException('The Brevo API cannot send embedded (cid:) images. Use SMTP for this message.');
            }
            $isString = !empty($a[5]);
            $content  = $isString ? (string) $a[0] : (is_file((string) $a[0]) && is_readable((string) $a[0]) ? (string) file_get_contents((string) $a[0]) : null);
            if ($content === null) {
                throw new \RuntimeException('Attachment is not readable: ' . basename((string) $a[0]));
            }
            $total += \strlen($content);
            if ($total > self::MAX_ATTACHMENT_BYTES) {
                throw new \RuntimeException('Attachments are larger than the Brevo limit (4 MB).');
            }
            $name = (string) (($a[2] ?? '') !== '' ? $a[2] : ($a[1] ?? 'attachment'));
            $payload['attachment'][] = ['name' => basename($name), 'content' => base64_encode($content)];
        }

        return $payload;
    }

    /**
     * @throws \RuntimeException with the reason given by Brevo
     */
    public function send(UxMail $mail): string
    {
        $res = $this->http->request('POST', self::SEND_URL, [
            'headers' => ['api-key' => $this->apiKey, 'Accept' => 'application/json', 'Content-Type' => 'application/json'],
            'body'    => json_encode(self::payload($mail), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'timeout' => 20,
        ]);
        $data = json_decode($res['body'], true);
        if ($res['status'] >= 200 && $res['status'] < 300) {
            return \is_array($data) ? (string) ($data['messageId'] ?? '') : '';
        }
        $reason = \is_array($data) && isset($data['message']) ? (string) $data['message'] : '';

        throw new \RuntimeException('Brevo HTTP ' . $res['status'] . ($reason !== '' ? ': ' . $reason : ''));
    }

    /** Checks the key with GET /v3/account. @return array{ok:bool,account:string,error:string} */
    public function verify(): array
    {
        try {
            $res = $this->http->request('GET', self::ACCOUNT_URL, ['headers' => ['api-key' => $this->apiKey, 'Accept' => 'application/json'], 'timeout' => 15]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'account' => '', 'error' => $e->getMessage()];
        }
        $data = json_decode($res['body'], true);
        if ($res['status'] === 200 && \is_array($data)) {
            $account = (string) ($data['email'] ?? '');

            return ['ok' => true, 'account' => $account !== '' ? $account : (string) ($data['companyName'] ?? ''), 'error' => ''];
        }

        return ['ok' => false, 'account' => '', 'error' => \is_array($data) && isset($data['message']) ? (string) $data['message'] : 'HTTP ' . $res['status']];
    }
}
