<?php
/**
 * CUBOIDPILOT — CHANNEL ADAPTER
 * Transport Layer Abstraction for Web, WhatsApp, and Instagram.
 * Allows Cai's single intelligence engine to dispatch messages, documents,
 * and quick replies across different channels without hardcoding Meta Graph API details.
 */

require_once __DIR__ . '/../config/db.php';

interface ChannelAdapterInterface {
    public function sendTextMessage(PDO $pdo, int $companyId, string $recipientId, string $text): bool;
    public function sendDocument(PDO $pdo, int $companyId, string $recipientId, string $docUrl, string $docTitle, string $caption = ''): bool;
    public function sendQuickReplies(PDO $pdo, int $companyId, string $recipientId, string $text, array $options): bool;
}

class ChannelAdapterFactory {
    public static function getAdapter(string $channel): ChannelAdapterInterface {
        switch (strtolower(trim($channel))) {
            case 'whatsapp':
                return new WhatsAppChannelAdapter();
            case 'instagram':
                return new InstagramChannelAdapter();
            case 'web':
            default:
                return new WebChannelAdapter();
        }
    }
}

/**
 * Web Channel Adapter (Direct HTTP response / WebSocket / Polling)
 */
class WebChannelAdapter implements ChannelAdapterInterface {
    public function sendTextMessage(PDO $pdo, int $companyId, string $recipientId, string $text): bool {
        return true;
    }
    public function sendDocument(PDO $pdo, int $companyId, string $recipientId, string $docUrl, string $docTitle, string $caption = ''): bool {
        return true;
    }
    public function sendQuickReplies(PDO $pdo, int $companyId, string $recipientId, string $text, array $options): bool {
        return true;
    }
}

/**
 * WhatsApp Cloud API Channel Adapter
 */
class WhatsAppChannelAdapter implements ChannelAdapterInterface {
    public function sendTextMessage(PDO $pdo, int $companyId, string $recipientId, string $text): bool {
        $accStmt = $pdo->prepare("SELECT phone_number_id, access_token_encrypted FROM `whatsapp_accounts` WHERE `company_id` = ? AND `status` != 'disconnected' LIMIT 1");
        $accStmt->execute([$companyId]);
        $acc = $accStmt->fetch(PDO::FETCH_ASSOC);

        if (!$acc || empty($acc['phone_number_id']) || empty($acc['access_token_encrypted'])) {
            return false;
        }

        $cleanPhone = preg_replace('/[^0-9]/', '', $recipientId);
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $cleanPhone,
            'type'              => 'text',
            'text'              => ['preview_url' => true, 'body' => $text]
        ];

        return self::dispatchMetaPost($acc['phone_number_id'], $acc['access_token_encrypted'], $payload);
    }

    public function sendDocument(PDO $pdo, int $companyId, string $recipientId, string $docUrl, string $docTitle, string $caption = ''): bool {
        $accStmt = $pdo->prepare("SELECT phone_number_id, access_token_encrypted FROM `whatsapp_accounts` WHERE `company_id` = ? AND `status` != 'disconnected' LIMIT 1");
        $accStmt->execute([$companyId]);
        $acc = $accStmt->fetch(PDO::FETCH_ASSOC);

        if (!$acc || empty($acc['phone_number_id']) || empty($acc['access_token_encrypted'])) {
            return false;
        }

        $cleanPhone = preg_replace('/[^0-9]/', '', $recipientId);
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $cleanPhone,
            'type'              => 'document',
            'document'          => [
                'link'     => $docUrl,
                'filename' => $docTitle,
                'caption'  => $caption ?: $docTitle
            ]
        ];

        return self::dispatchMetaPost($acc['phone_number_id'], $acc['access_token_encrypted'], $payload);
    }

    public function sendQuickReplies(PDO $pdo, int $companyId, string $recipientId, string $text, array $options): bool {
        $accStmt = $pdo->prepare("SELECT phone_number_id, access_token_encrypted FROM `whatsapp_accounts` WHERE `company_id` = ? AND `status` != 'disconnected' LIMIT 1");
        $accStmt->execute([$companyId]);
        $acc = $accStmt->fetch(PDO::FETCH_ASSOC);

        if (!$acc || empty($acc['phone_number_id']) || empty($acc['access_token_encrypted'])) {
            return false;
        }

        $cleanPhone = preg_replace('/[^0-9]/', '', $recipientId);
        $buttons = [];
        $i = 0;
        foreach (array_slice($options, 0, 3) as $opt) {
            $buttons[] = [
                'type'  => 'reply',
                'reply' => [
                    'id'    => 'btn_' . ($i++),
                    'title' => substr($opt, 0, 20)
                ]
            ];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $cleanPhone,
            'type'              => 'interactive',
            'interactive'       => [
                'type'   => 'button',
                'body'   => ['text' => $text],
                'action' => ['buttons' => $buttons]
            ]
        ];

        return self::dispatchMetaPost($acc['phone_number_id'], $acc['access_token_encrypted'], $payload);
    }

    private static function dispatchMetaPost(string $phoneId, string $token, array $payload): bool {
        $ch = curl_init("https://graph.facebook.com/v19.0/{$phoneId}/messages");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$token}",
                "Content-Type: application/json"
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($code >= 200 && $code < 300);
    }
}

/**
 * Instagram Direct Graph API Channel Adapter
 */
class InstagramChannelAdapter implements ChannelAdapterInterface {
    public function sendTextMessage(PDO $pdo, int $companyId, string $recipientId, string $text): bool {
        $igStmt = $pdo->prepare("SELECT page_access_token_encrypted, page_id FROM `company_instagram_configs` WHERE `company_id` = ? AND `status` = 'connected' LIMIT 1");
        $igStmt->execute([$companyId]);
        $ig = $igStmt->fetch(PDO::FETCH_ASSOC);

        if (!$ig || empty($ig['page_access_token_encrypted'])) {
            return false;
        }

        $payload = [
            'recipient' => ['id' => $recipientId],
            'message'   => ['text' => $text]
        ];

        $ch = curl_init("https://graph.facebook.com/v19.0/me/messages");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer " . $ig['page_access_token_encrypted'],
                "Content-Type: application/json"
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($code >= 200 && $code < 300);
    }

    public function sendDocument(PDO $pdo, int $companyId, string $recipientId, string $docUrl, string $docTitle, string $caption = ''): bool {
        $text = "📄 **{$docTitle}**\n\nDownload Link: {$docUrl}" . ($caption ? "\n\n{$caption}" : "");
        return $this->sendTextMessage($pdo, $companyId, $recipientId, $text);
    }

    public function sendQuickReplies(PDO $pdo, int $companyId, string $recipientId, string $text, array $options): bool {
        return $this->sendTextMessage($pdo, $companyId, $recipientId, $text);
    }
}
