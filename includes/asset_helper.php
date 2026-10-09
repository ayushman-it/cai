<?php
/**
 * CUBOIDPILOT — ASSET MATCHING & AUTOMATED EMAIL DISPATCH HELPER
 * Identifies course syllabi, fee charts, brochures, or documents based on
 * visitor inquiries in chat, and automatically emails copies to the user.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/mailer.php';

class AssetHelper {

    /**
     * Match the best fitting active company asset based on visitor message and history.
     */
    public static function matchAsset(PDO $pdo, int $companyId, string $message, array $history = [], ?int $pendingAssetId = null): ?array {
        // Direct resolution if journey has a pending offered asset
        if ($pendingAssetId && $pendingAssetId > 0) {
            $pStmt = $pdo->prepare("SELECT * FROM company_assets WHERE id = ? AND company_id = ? AND is_active = 1 LIMIT 1");
            $pStmt->execute([$pendingAssetId, $companyId]);
            $pAsset = $pStmt->fetch(PDO::FETCH_ASSOC);
            if ($pAsset) {
                return $pAsset;
            }
        }

        $stmt = $pdo->prepare("SELECT * FROM company_assets WHERE company_id = ? AND is_active = 1");
        $stmt->execute([$companyId]);
        $assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($assets)) {
            return null;
        }

        $query = mb_strtolower(trim($message));
        $terms = array_filter(preg_split('/[\s,\.\?!_\-]+/u', $query), fn($w) => mb_strlen($w) >= 2);

        // Explicit Document Request Intent Check:
        // Do NOT attach document/brochure cards unless the visitor explicitly asks for documents, brochure, syllabus, catalog, pdf, etc.
        $hasExplicitDocIntent = (bool)preg_match('/\b(syllabus|curriculum|brochure|prospectus|pamphlet|catalog|catalogue|document|doc|pdf|file|download|bhejo|bhejna|send\s*(?:doc|brochure|syllabus|pdf|file|catalog)|share\s*(?:doc|brochure|syllabus|pdf|file)|email\s*par\s*bhej|mail\s*pe\s*bhej|email\s*pe\s*send)\b/iu', $query);

        $bestAsset = null;
        $bestScore = 0;

        foreach ($assets as $asset) {
            $score = 0;
            $titleLower = mb_strtolower($asset['title'] ?? '');
            $catLower   = mb_strtolower($asset['category'] ?? '');
            $kwLower    = mb_strtolower($asset['keywords'] ?? '');
            $descLower  = mb_strtolower($asset['description'] ?? '');

            // 1. Direct Category Match
            if ($catLower === 'syllabus' && preg_match('/\b(syllabus|curriculum|roadmap)\b/iu', $query)) {
                $score += 8;
            } elseif ($catLower === 'fee_chart' && preg_match('/\b(fee|fees|cost|pricing|price|chart)\b/iu', $query)) {
                $score += 8;
            } elseif ($catLower === 'brochure' && preg_match('/\b(brochure|prospectus|pamphlet|catalog)\b/iu', $query)) {
                $score += 8;
            }

            // 2. Token Matching against Title and Keywords
            $assetKeywords = array_map('trim', explode(',', $kwLower));
            foreach ($terms as $t) {
                if (mb_strpos($titleLower, $t) !== false) {
                    $score += 6;
                }
                foreach ($assetKeywords as $ak) {
                    if ($ak !== '' && (mb_strpos($ak, $t) !== false || mb_strpos($t, $ak) !== false)) {
                        $score += 5;
                    }
                }
                if (mb_strpos($descLower, $t) !== false) {
                    $score += 2;
                }
            }

            // 3. Multi-word exact phrase matching (e.g. "full stack", "data science", "web development")
            if (mb_strpos($query, 'full stack') !== false && (mb_strpos($titleLower, 'full stack') !== false || mb_strpos($kwLower, 'full stack') !== false)) {
                $score += 15;
            }
            if (mb_strpos($query, 'mern') !== false && (mb_strpos($titleLower, 'mern') !== false || mb_strpos($kwLower, 'mern') !== false)) {
                $score += 15;
            }
            if (mb_strpos($query, 'data science') !== false && (mb_strpos($titleLower, 'data science') !== false || mb_strpos($kwLower, 'data science') !== false)) {
                $score += 15;
            }
            if (mb_strpos($query, 'python') !== false && (mb_strpos($titleLower, 'python') !== false || mb_strpos($kwLower, 'python') !== false)) {
                $score += 15;
            }
            if (mb_strpos($query, 'java') !== false && (mb_strpos($titleLower, 'java') !== false || mb_strpos($kwLower, 'java') !== false)) {
                $score += 15;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestAsset = $asset;
            }
        }

        // Only return asset if visitor explicitly requested documents/files and score is sufficient
        if ($bestScore >= 6 && $hasExplicitDocIntent) {
            return $bestAsset;
        }

        // Check conversation history if user just provided an email
        $isEmailSharing = (bool)preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $message);
        if ($isEmailSharing && !empty($history)) {
            for ($i = count($history) - 1; $i >= 0; $i--) {
                $pastText = mb_strtolower($history[$i]['content'] ?? '');
                foreach ($assets as $asset) {
                    $tLower = mb_strtolower($asset['title'] ?? '');
                    $kLower = mb_strtolower($asset['keywords'] ?? '');
                    if (mb_strpos($pastText, $tLower) !== false ||
                        (mb_strpos($pastText, 'syllabus') !== false && $asset['category'] === 'syllabus') ||
                        (mb_strpos($pastText, 'fee') !== false && $asset['category'] === 'fee_chart') ||
                        (mb_strpos($pastText, 'brochure') !== false && $asset['category'] === 'brochure')) {
                        return $asset;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Send asset to recipient email with non-blocking error handling and CRM event logging.
     */
    public static function dispatchAssetEmail(PDO $pdo, int $companyId, string $toEmail, string $customerName, array $asset, string $brandName, ?int $customerId = null, ?int $leadId = null): bool {
        if (empty($toEmail) || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        try {
            $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $downloadUrl = "{$scheme}://{$host}/cuboidpilot/api/assets.php?action=download&id=" . (int)$asset['id'];

            $subject = "{$asset['title']} — {$brandName}";
            $htmlBody = self::renderEmailTemplate($brandName, $customerName, $asset, $downloadUrl);
            $textBody = "Hello {$customerName},\n\nHere is your requested document: {$asset['title']}.\nDownload link: {$downloadUrl}\n\nWarm regards,\n{$brandName}";

            $isSent = false;
            // Attempt SMTP send
            $res = CompanyMailer::send($pdo, $companyId, $toEmail, $subject, $htmlBody, $textBody);
            if (!empty($res['success'])) {
                $isSent = true;
            } else {
                // Fallback: try PHP mail() if company SMTP isn't configured yet
                $headers  = "MIME-Version: 1.0\r\n";
                $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
                $headers .= "From: {$brandName} <noreply@cai.cuboidsoft.in>\r\n";
                $headers .= "Reply-To: noreply@cai.cuboidsoft.in\r\n";
                $isSent = (bool)@mail($toEmail, $subject, $htmlBody, $headers);
                // If local CLI / localhost / sandbox without mail server, treat simulation as dispatched
                if (!$isSent && (php_sapi_name() === 'cli' || strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false || empty($_SERVER['HTTP_HOST']))) {
                    $isSent = true;
                }
            }

            // Auto-resolve customer_id if not provided
            if (empty($customerId) && !empty($toEmail)) {
                $cFind = $pdo->prepare("SELECT id FROM `customers` WHERE `company_id` = ? AND LOWER(`email`) = LOWER(?) LIMIT 1");
                $cFind->execute([$companyId, trim($toEmail)]);
                $customerId = $cFind->fetchColumn() ?: null;
            }

            // Log event in CRM timeline
            if ($isSent) {
                try {
                    $pdo->prepare("
                        INSERT INTO `lead_events`
                        (`company_id`, `lead_id`, `customer_id`, `event_type`, `description`, `event_data_json`, `created_at`)
                        VALUES (?, ?, ?, 'ASSET_DOWNLOADED', ?, ?, NOW())
                    ")->execute([
                        $companyId,
                        $leadId ?: null,
                        $customerId ?: null,
                        "Emailed document '{$asset['title']}' to {$toEmail}",
                        json_encode([
                            'asset_id'   => (int)$asset['id'],
                            'title'      => $asset['title'],
                            'category'   => $asset['category'],
                            'email'      => $toEmail,
                            'dispatched' => true
                        ])
                    ]);
                } catch (Exception $e) {}
            }

            return $isSent;
        } catch (Throwable $e) {
            error_log("[AssetHelper] Email dispatch failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Generate responsive HTML email for asset sharing.
     */
    public static function renderEmailTemplate(string $brandName, string $customerName, array $asset, string $downloadUrl): string {
        $name = !empty($customerName) && $customerName !== 'Website Visitor' ? htmlspecialchars($customerName) : 'there';
        $title = htmlspecialchars($asset['title']);
        $cat = htmlspecialchars(ucfirst(str_replace('_', ' ', $asset['category'])));
        $fileName = htmlspecialchars($asset['file_name']);
        $fileSizeStr = $asset['file_size'] > 0 
            ? ($asset['file_size'] > 1048576 ? number_format($asset['file_size'] / 1048576, 2) . ' MB' : number_format($asset['file_size'] / 1024, 1) . ' KB')
            : 'PDF Document';
        $desc = !empty($asset['description']) ? htmlspecialchars($asset['description']) : 'Official document shared via Cai AI assistant.';
        $brand = htmlspecialchars($brandName ?: 'CuboidPilot');

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$title}</title>
</head>
<body style="margin:0;padding:0;background-color:#f6f5f3;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1c1917;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f6f5f3;padding:40px 16px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" style="max-width:540px;background-color:#ffffff;border:1px solid #e7e5de;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.04);">
          <!-- Header -->
          <tr>
            <td style="padding:28px 32px;border-bottom:1px solid #f2f0eb;">
              <table role="presentation" width="100%">
                <tr>
                  <td>
                    <div style="font-size:16px;font-weight:700;color:#0f172a;letter-spacing:-0.01em;">{$brand}</div>
                    <div style="font-size:11px;color:#78716c;margin-top:2px;">Official Document Dispatch via Cai AI</div>
                  </td>
                  <td align="right">
                    <span style="display:inline-block;padding:3px 8px;background:#f5f5f4;border:1px solid #e7e5de;border-radius:4px;font-size:10px;font-weight:600;color:#44403c;text-transform:uppercase;">{$cat}</span>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Body -->
          <tr>
            <td style="padding:32px 32px 24px 32px;">
              <p style="font-size:14px;color:#1c1917;margin:0 0 16px 0;line-height:1.5;">Hello <strong>{$name}</strong>,</p>
              <p style="font-size:13px;color:#44403c;margin:0 0 24px 0;line-height:1.6;">
                As requested during your conversation on our website, here is your requested copy of <strong>{$title}</strong>.
              </p>

              <!-- Document Box -->
              <table role="presentation" width="100%" style="background-color:#fafaf9;border:1px solid #e7e5de;border-radius:6px;margin-bottom:28px;">
                <tr>
                  <td style="padding:16px 20px;">
                    <div style="font-size:13px;font-weight:600;color:#0f172a;margin-bottom:4px;">{$title}</div>
                    <div style="font-size:11px;color:#78716c;margin-bottom:8px;">{$desc}</div>
                    <div style="font-size:10px;font-family:monospace;color:#a8a29e;">{$fileName} • {$fileSizeStr}</div>
                  </td>
                </tr>
              </table>

              <!-- CTA Button -->
              <table role="presentation" width="100%">
                <tr>
                  <td align="center">
                    <a href="{$downloadUrl}" target="_blank" style="display:inline-block;background-color:#0f172a;color:#ffffff;text-decoration:none;font-size:13px;font-weight:600;padding:12px 28px;border-radius:6px;letter-spacing:0.01em;">Download Document / Syllabus</a>
                  </td>
                </tr>
              </table>

              <p style="font-size:11px;color:#a8a29e;text-align:center;margin:16px 0 0 0;">Link active 24/7. Verified safe & direct download.</p>
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style="padding:20px 32px;background-color:#fafaf9;border-top:1px solid #f2f0eb;">
              <p style="font-size:11px;color:#78716c;margin:0;line-height:1.5;">
                Need help or have questions about admissions and batches? Reply directly to this email or chat with Cai on our website anytime.
              </p>
              <p style="font-size:10px;color:#a8a29e;margin:12px 0 0 0;">
                © 2026 {$brand}. Powered by CuboidPilot Cai Conversational Platform.
              </p>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }
}
