<?php
/**
 * Lightweight DB-backed rate limiting for public widget/API surfaces.
 */

if (!function_exists('cpRateLimitClientIp')) {
    function cpRateLimitClientIp(): string {
        $candidates = [
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '',
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '',
            $_SERVER['REMOTE_ADDR'] ?? ''
        ];
        foreach ($candidates as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate === '') continue;
            $ip = trim(explode(',', $candidate)[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
        return 'unknown';
    }
}

if (!function_exists('cpRateLimit')) {
    function cpRateLimit(PDO $pdo, string $scope, int $limit, int $windowSeconds = 60, array $parts = []): void {
        if ($limit <= 0 || $windowSeconds <= 0) {
            return;
        }

        try {
            $now = time();
            $windowStartTs = (int)(floor($now / $windowSeconds) * $windowSeconds);
            $windowStart = date('Y-m-d H:i:s', $windowStartTs);
            $expiresAt = date('Y-m-d H:i:s', $windowStartTs + $windowSeconds + 60);
            $identityParts = [
                $scope,
                cpRateLimitClientIp(),
                substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120)
            ];
            foreach ($parts as $part) {
                $part = trim((string)$part);
                if ($part !== '') {
                    $identityParts[] = $part;
                }
            }
            $keyHash = hash('sha256', implode('|', $identityParts) . '|' . $windowStart);

            $stmt = $pdo->prepare("
                INSERT INTO `api_rate_limits`
                (`rate_key`, `scope`, `window_start`, `requests`, `expires_at`, `last_seen_at`)
                VALUES (?, ?, ?, 1, ?, NOW())
                ON DUPLICATE KEY UPDATE
                    `requests` = `requests` + 1,
                    `expires_at` = VALUES(`expires_at`),
                    `last_seen_at` = NOW()
            ");
            $stmt->execute([$keyHash, substr($scope, 0, 80), $windowStart, $expiresAt]);

            $countStmt = $pdo->prepare("SELECT `requests` FROM `api_rate_limits` WHERE `rate_key` = ? LIMIT 1");
            $countStmt->execute([$keyHash]);
            $requests = (int)$countStmt->fetchColumn();
            if ($requests > $limit) {
                $retryAfter = max(1, ($windowStartTs + $windowSeconds) - $now);
                header('Retry-After: ' . $retryAfter);
                http_response_code(429);
                echo json_encode([
                    'success' => false,
                    'error' => 'Too many requests. Please try again shortly.'
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                exit;
            }

            if (random_int(1, 100) === 1) {
                $pdo->exec("DELETE FROM `api_rate_limits` WHERE `expires_at` < NOW() LIMIT 500");
            }
        } catch (Throwable $e) {
            error_log('[RateLimit] ' . $e->getMessage());
        }
    }
}
