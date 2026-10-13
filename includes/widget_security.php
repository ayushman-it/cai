<?php
/**
 * Tenant-scoped widget install helpers.
 *
 * The widget token is intentionally install-code visible. It is not a user secret;
 * it prevents accidental cross-tenant embeds and allows strict enforcement when a
 * company turns token enforcement on.
 */

if (!function_exists('cpGetWidgetRequestToken')) {
    function cpGetWidgetRequestToken(array $data = []): string {
        return trim((string)(
            $data['widget_token']
            ?? $_GET['widget_token']
            ?? $_POST['widget_token']
            ?? $_SERVER['HTTP_X_WIDGET_TOKEN']
            ?? ''
        ));
    }
}

if (!function_exists('cpValidateWidgetInstallToken')) {
    function cpValidateWidgetInstallToken(array $company, string $token): void {
        $expected = trim((string)($company['widget_install_token'] ?? ''));
        $isEnforced = !empty($company['enforce_widget_token']);

        if ($expected === '') {
            return;
        }

        if ($token !== '' && !hash_equals($expected, $token)) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => 'Widget install token is invalid for this workspace.'
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($isEnforced && $token === '') {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => 'Widget install token is required for this workspace.'
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}
