<?php

declare(strict_types=1);

namespace App\Webmail;

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;

/**
 * IMAP client wrapper for webmail.
 * Uses native PHP IMAP extension.
 */
final class ImapClient
{
    private ?\IMAP\Connection $connection = null;
    private ?string $email = null;
    private ?string $password = null;

    public function __construct(
        private readonly string $host = 'localhost',
        private readonly int $port = 993,
        private readonly string $encryption = 'ssl',
    ) {}

    public function login(string $email, string $password): bool
    {
        $mailbox = $this->getMailboxString('INBOX');
        
        $connection = @imap_open(
            $mailbox,
            $email,
            $password,
            0,
            1,
            ['DISABLE_AUTHENTICATOR' => 'GSSAPI']
        );
        
        if ($connection === false) {
            return false;
        }
        
        $this->connection = $connection;
        $this->email = $email;
        $this->password = $password;
        
        return true;
    }

    public function logout(): void
    {
        if ($this->connection !== null) {
            imap_close($this->connection);
            $this->connection = null;
        }
    }

    /**
     * @return array<string>
     */
    public function getFolders(): array
    {
        if ($this->connection === null) {
            return [];
        }
        
        $mailboxes = imap_list(
            $this->connection,
            $this->getServerString(),
            '*'
        );
        
        if ($mailboxes === false) {
            return [];
        }
        
        $folders = [];
        $prefix = $this->getServerString();
        
        foreach ($mailboxes as $mailbox) {
            $folder = str_replace($prefix, '', $mailbox);
            $folder = mb_convert_encoding($folder, 'UTF-8', 'UTF7-IMAP');
            $folders[] = $folder;
        }
        
        sort($folders);
        
        return $folders;
    }

    /**
     * @return array<array{uid: int, subject: string, from: string, date: string, seen: bool, size: int}>
     */
    public function getMessages(string $folder, int $page = 1, int $perPage = 25): array
    {
        if ($this->connection === null) {
            return [];
        }
        
        // Switch to folder
        $mailbox = $this->getMailboxString($folder);
        if (!@imap_reopen($this->connection, $mailbox)) {
            return [];
        }
        
        $total = imap_num_msg($this->connection);
        
        if ($total === 0) {
            return [];
        }
        
        // Calculate range (newest first)
        $end = $total - (($page - 1) * $perPage);
        $start = max(1, $end - $perPage + 1);
        
        if ($end < 1) {
            return [];
        }
        
        $messages = [];
        
        // Fetch in reverse order (newest first)
        for ($i = $end; $i >= $start; $i--) {
            $header = imap_headerinfo($this->connection, $i);
            
            if ($header === false) {
                continue;
            }
            
            $uid = imap_uid($this->connection, $i);
            
            $messages[] = [
                'uid' => $uid,
                'subject' => $this->decodeHeader($header->subject ?? ''),
                'from' => $this->formatAddress($header->from[0] ?? null),
                'date' => $header->date ?? '',
                'seen' => isset($header->Seen) || str_contains($header->Unseen ?? '', 'U') === false,
                'size' => $header->Size ?? 0,
            ];
        }
        
        return $messages;
    }

    public function getMessageCount(string $folder): int
    {
        if ($this->connection === null) {
            return 0;
        }
        
        $mailbox = $this->getMailboxString($folder);
        if (!@imap_reopen($this->connection, $mailbox)) {
            return 0;
        }
        
        return imap_num_msg($this->connection);
    }

    /**
     * @return array{uid: int, subject: string, from: string, to: string, date: string, text: ?string, html: ?string, attachments: array}|null
     */
    public function getMessage(string $folder, int $uid): ?array
    {
        if ($this->connection === null) {
            return null;
        }
        
        $mailbox = $this->getMailboxString($folder);
        if (!@imap_reopen($this->connection, $mailbox)) {
            return null;
        }
        
        $msgno = imap_msgno($this->connection, $uid);
        
        if ($msgno === 0) {
            return null;
        }
        
        $header = imap_headerinfo($this->connection, $msgno);
        $structure = imap_fetchstructure($this->connection, $uid, FT_UID);
        
        if ($header === false || $structure === false) {
            return null;
        }
        
        // Parse body
        $body = $this->parseBody($uid, $structure);
        
        return [
            'uid' => $uid,
            'subject' => $this->decodeHeader($header->subject ?? ''),
            'from' => $this->formatAddress($header->from[0] ?? null),
            'to' => $this->formatAddressList($header->to ?? []),
            'cc' => $this->formatAddressList($header->cc ?? []),
            'date' => $header->date ?? '',
            'text' => $body['text'],
            'html' => $body['html'],
            'attachments' => $body['attachments'],
        ];
    }

    public function markAsRead(string $folder, int $uid): void
    {
        if ($this->connection === null) {
            return;
        }
        
        $mailbox = $this->getMailboxString($folder);
        @imap_reopen($this->connection, $mailbox);
        
        imap_setflag_full($this->connection, (string) $uid, '\\Seen', ST_UID);
    }

    public function deleteMessage(string $folder, int $uid): void
    {
        if ($this->connection === null) {
            return;
        }
        
        $mailbox = $this->getMailboxString($folder);
        @imap_reopen($this->connection, $mailbox);
        
        imap_delete($this->connection, (string) $uid, FT_UID);
        imap_expunge($this->connection);
    }

    public function moveMessage(string $folder, int $uid, string $destination): void
    {
        if ($this->connection === null) {
            return;
        }
        
        $mailbox = $this->getMailboxString($folder);
        @imap_reopen($this->connection, $mailbox);
        
        $destFolder = mb_convert_encoding($destination, 'UTF7-IMAP', 'UTF-8');
        imap_mail_move($this->connection, (string) $uid, $destFolder, CP_UID);
        imap_expunge($this->connection);
    }

    /**
     * @return array<array{uid: int, subject: string, from: string, date: string}>
     */
    public function search(string $folder, string $query): array
    {
        if ($this->connection === null) {
            return [];
        }
        
        $mailbox = $this->getMailboxString($folder);
        if (!@imap_reopen($this->connection, $mailbox)) {
            return [];
        }
        
        // Search in subject, from, and body
        $searchQuery = sprintf(
            'OR OR SUBJECT "%s" FROM "%s" BODY "%s"',
            addslashes($query),
            addslashes($query),
            addslashes($query)
        );
        
        $uids = imap_search($this->connection, $searchQuery, SE_UID);
        
        if ($uids === false) {
            return [];
        }
        
        $results = [];
        
        foreach (array_slice($uids, 0, 50) as $uid) {
            $msgno = imap_msgno($this->connection, $uid);
            $header = imap_headerinfo($this->connection, $msgno);
            
            if ($header === false) {
                continue;
            }
            
            $results[] = [
                'uid' => $uid,
                'subject' => $this->decodeHeader($header->subject ?? ''),
                'from' => $this->formatAddress($header->from[0] ?? null),
                'date' => $header->date ?? '',
            ];
        }
        
        return $results;
    }

    /**
     * @return array{used: int, limit: int, percent: float}|null
     */
    public function getQuota(): ?array
    {
        if ($this->connection === null) {
            return null;
        }
        
        $quota = @imap_get_quotaroot($this->connection, 'INBOX');
        
        if ($quota === false || !isset($quota['STORAGE'])) {
            return null;
        }
        
        $used = $quota['STORAGE']['usage'] * 1024; // KB to bytes
        $limit = $quota['STORAGE']['limit'] * 1024;
        
        return [
            'used' => $used,
            'limit' => $limit,
            'percent' => $limit > 0 ? round(($used / $limit) * 100, 1) : 0,
        ];
    }

    /**
     * Send mail via SMTP submission (AUTH + STARTTLS), not PHP mail().
     * PHP-FPM images usually have no sendmail; Postfix listens on 587 inside the stack.
     */
    public function sendMessage(string $from, string $password, string $to, string $subject, string $body): bool
    {
        $host = $_ENV['SMTP_SUBMISSION_HOST'] ?? 'postfix';
        $port = (int) ($_ENV['SMTP_SUBMISSION_PORT'] ?? 587);
        $verifyPeer = filter_var($_ENV['SMTP_TLS_VERIFY_PEER'] ?? 'false', FILTER_VALIDATE_BOOLEAN);

        try {
            $transport = new EsmtpTransport($host, $port);
            $transport->setUsername($from);
            $transport->setPassword($password);

            if (!$verifyPeer) {
                $transport->getStream()->setStreamOptions([
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true,
                    ],
                ]);
            }

            $mailer = new Mailer($transport);
            $message = (new Email())
                ->from($from)
                ->replyTo($from)
                ->to($to)
                ->subject($subject)
                ->text($body);

            $mailer->send($message);

            return true;
        } catch (TransportExceptionInterface | \Throwable $e) {
            if (filter_var($_ENV['APP_DEBUG'] ?? 'false', FILTER_VALIDATE_BOOLEAN)) {
                error_log('[Webmail] SMTP send failed: ' . $e->getMessage());
            }

            return false;
        }
    }

    private function getServerString(): string
    {
        $flags = '/' . $this->encryption;
        if ($this->encryption === 'ssl') {
            $flags .= '/novalidate-cert';
        }
        
        return sprintf('{%s:%d/imap%s}', $this->host, $this->port, $flags);
    }

    private function getMailboxString(string $folder): string
    {
        $folder = mb_convert_encoding($folder, 'UTF7-IMAP', 'UTF-8');
        return $this->getServerString() . $folder;
    }

    private function decodeHeader(string $header): string
    {
        $decoded = imap_mime_header_decode($header);
        
        $result = '';
        foreach ($decoded as $part) {
            $charset = $part->charset === 'default' ? 'UTF-8' : $part->charset;
            $text = @mb_convert_encoding($part->text, 'UTF-8', $charset);
            $result .= $text !== false ? $text : $part->text;
        }
        
        return $result;
    }

    private function formatAddress(?object $address): string
    {
        if ($address === null) {
            return '';
        }
        
        $mailbox = $address->mailbox ?? '';
        $host = $address->host ?? '';
        $personal = isset($address->personal) ? $this->decodeHeader($address->personal) : '';
        
        $email = $mailbox . '@' . $host;
        
        if ($personal !== '') {
            return "$personal <$email>";
        }
        
        return $email;
    }

    /**
     * @param array<object> $addresses
     */
    private function formatAddressList(array $addresses): string
    {
        $formatted = [];
        
        foreach ($addresses as $address) {
            $formatted[] = $this->formatAddress($address);
        }
        
        return implode(', ', $formatted);
    }

    /**
     * @return array{text: ?string, html: ?string, attachments: array}
     */
    private function parseBody(int $uid, object $structure, string $partNumber = ''): array
    {
        $result = ['text' => null, 'html' => null, 'attachments' => []];
        
        if (isset($structure->parts) && is_array($structure->parts)) {
            foreach ($structure->parts as $index => $part) {
                $subPartNumber = $partNumber === '' ? (string) ($index + 1) : "$partNumber." . ($index + 1);
                $subResult = $this->parseBody($uid, $part, $subPartNumber);
                
                $result['text'] = $result['text'] ?? $subResult['text'];
                $result['html'] = $result['html'] ?? $subResult['html'];
                $result['attachments'] = array_merge($result['attachments'], $subResult['attachments']);
            }
        } else {
            $body = $this->fetchBodyPart($uid, $partNumber ?: '1', $structure);
            
            // Determine content type
            $type = $structure->type ?? 0;
            $subtype = strtolower($structure->subtype ?? '');
            
            if ($type === 0) { // Text
                if ($subtype === 'plain') {
                    $result['text'] = $body;
                } elseif ($subtype === 'html') {
                    $result['html'] = $body;
                }
            } elseif (isset($structure->disposition) && strtolower($structure->disposition) === 'attachment') {
                $filename = $this->getFilename($structure);
                $result['attachments'][] = [
                    'filename' => $filename,
                    'part' => $partNumber,
                    'size' => $structure->bytes ?? 0,
                ];
            }
        }
        
        return $result;
    }

    private function fetchBodyPart(int $uid, string $partNumber, object $structure): string
    {
        if ($this->connection === null) {
            return '';
        }
        
        $body = imap_fetchbody($this->connection, $uid, $partNumber, FT_UID);
        
        if ($body === false) {
            return '';
        }
        
        // Decode transfer encoding
        $encoding = $structure->encoding ?? 0;
        
        switch ($encoding) {
            case 3: // BASE64
                $body = base64_decode($body);
                break;
            case 4: // QUOTED-PRINTABLE
                $body = quoted_printable_decode($body);
                break;
        }
        
        // Convert charset
        $charset = $this->getCharset($structure);
        if ($charset !== 'UTF-8' && $charset !== '') {
            $converted = @mb_convert_encoding($body, 'UTF-8', $charset);
            if ($converted !== false) {
                $body = $converted;
            }
        }
        
        return $body;
    }

    private function getCharset(object $structure): string
    {
        if (isset($structure->parameters) && is_array($structure->parameters)) {
            foreach ($structure->parameters as $param) {
                if (strtolower($param->attribute) === 'charset') {
                    return strtoupper($param->value);
                }
            }
        }
        
        return 'UTF-8';
    }

    private function getFilename(object $structure): string
    {
        // Check dparameters first
        if (isset($structure->dparameters) && is_array($structure->dparameters)) {
            foreach ($structure->dparameters as $param) {
                if (strtolower($param->attribute) === 'filename') {
                    return $this->decodeHeader($param->value);
                }
            }
        }
        
        // Then parameters
        if (isset($structure->parameters) && is_array($structure->parameters)) {
            foreach ($structure->parameters as $param) {
                if (strtolower($param->attribute) === 'name') {
                    return $this->decodeHeader($param->value);
                }
            }
        }
        
        return 'attachment';
    }
}
