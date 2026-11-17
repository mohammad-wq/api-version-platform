<?php
if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode([
        'statusCode' => 403,
        'message' => 'Access denied. This resource is protected and cannot be accessed directly.',
        'data' => null,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_PRETTY_PRINT);
    exit;
}

/**
 * JWT Authentication Helpers
 */
if (!defined('JWT_SECRET')) {
    define('JWT_SECRET', 'API Version Platform PHP Package');
}
if (!defined('JWT_ALGORITHM')) {
    define('JWT_ALGORITHM', 'HS256');
}
if (!defined('JWT_EXPIRATION')) {
    define('JWT_EXPIRATION', 86400 * 7); // 7 days in seconds
}

/**
 * Base64 URL encode
 */
function base64UrlEncode($data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Base64 URL decode
 */
function base64UrlDecode($data): string
{
    return base64_decode(strtr($data, '-_', '+/'));
}

/**
 * Generate JWT Token
 * 
 * @param array $payload Token payload data
 * @param int|null $expiresIn Expiration in seconds
 * @return string JWT token
 */
function generateJWT(array $payload, ?int $expiresIn = null): string
{
    $header = [
        'typ' => 'JWT',
        'alg' => JWT_ALGORITHM
    ];

    $payload['exp'] = time() + ($expiresIn ?? JWT_EXPIRATION);
    $payload['iat'] = time();

    $headerEncoded = base64UrlEncode(json_encode($header));
    $payloadEncoded = base64UrlEncode(json_encode($payload));

    $signature = hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, JWT_SECRET, true);
    $signatureEncoded = base64UrlEncode($signature);

    return $headerEncoded . '.' . $payloadEncoded . '.' . $signatureEncoded;
}

/**
 * Verify and decode JWT Token
 * 
 * @param string $token JWT token
 * @return array|false Decoded payload or false if invalid
 */
function verifyJWT(string $token)
{
    $parts = explode('.', $token);

    if (count($parts) !== 3) {
        return false;
    }

    [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;

    $signature = base64UrlDecode($signatureEncoded);
    $expectedSignature = hash_hmac('sha256', $headerEncoded . '.' . $payloadEncoded, JWT_SECRET, true);

    if (!hash_equals($signature, $expectedSignature)) {
        return false;
    }

    $payload = json_decode(base64UrlDecode($payloadEncoded), true);

    if (isset($payload['exp']) && $payload['exp'] < time()) {
        return false;
    }

    return $payload;
}

/**
 * Get JWT token from Authorization header or Query
 * 
 * @return string|null Token or null if not found
 */
function getJWTFromHeader(): ?string
{
    $authHeader = null;

    $headersToCheck = [
        'HTTP_AUTHORIZATION',
        'REDIRECT_HTTP_AUTHORIZATION',
        'AUTHORIZATION',
        'REMOTE_USER'
    ];

    foreach ($headersToCheck as $header) {
        if (!empty($_SERVER[$header])) {
            $authHeader = $_SERVER[$header];
            break;
        }
    }

    if (!$authHeader) {
        $allHeaders = [];
        if (function_exists('getallheaders')) {
            $allHeaders = getallheaders();
        } elseif (function_exists('apache_request_headers')) {
            $allHeaders = apache_request_headers();
        }

        foreach ($allHeaders as $key => $value) {
            if (strcasecmp($key, 'Authorization') === 0) {
                $authHeader = $value;
                break;
            }
        }
    }

    if ($authHeader) {
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return trim($matches[1]);
        }
        if (count(explode('.', $authHeader)) === 3) {
            return trim($authHeader);
        }
    }

    if (!empty($_GET['token']) && count(explode('.', $_GET['token'])) === 3) {
        return trim($_GET['token']);
    }

    return null;
}

/**
 * Require valid JWT authentication
 * 
 * @return array Decoded token payload
 */
function requireAuth(): array
{
    $token = getJWTFromHeader();

    if (!$token) {
        sendError('Authentication required. Please provide a valid Bearer token in the Authorization header.', 401);
    }

    $payload = verifyJWT($token);

    if (!$payload) {
        sendError('Invalid or expired token. Please login again.', 401);
    }

    return $payload;
}

/**
 * Check if the user is an admin
 * 
 * @return array Decoded token payload
 */
function requireAdminAuth(): array
{
    $payload = requireAuth();

    if (($payload['role'] ?? null) !== 'admin') {
        sendError('Admin authorization required.', 403);
    }

    return $payload;
}
