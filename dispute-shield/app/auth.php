<?php
/**
 * Email OTP auth — sends a 6-digit code via Resend API.
 * Allowed emails defined in ALLOWED_EMAILS env var (JSON array).
 * Codes stored in SQLite with 10-minute expiry.
 */

define('OTP_EXPIRY_SECONDS', 600); // 10 minutes
define('OTP_LENGTH', 6);

// Get allowed emails from env
function getAllowedEmails(): array {
    $env = getenv('ALLOWED_EMAILS');
    if ($env) {
        $parsed = json_decode($env, true);
        if (is_array($parsed)) return array_map('strtolower', $parsed);
    }
    // Fallback hardcoded
    return ['maria@gigradar.io', 'vadym@gigradar.io', 'antonina@gigradar.io'];
}

function isEmailAllowed(string $email): bool {
    return in_array(strtolower(trim($email)), getAllowedEmails(), true);
}

// Create OTP table if not exists
function ensureOtpTable(\SQLite3 $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS otp_codes (
        email     TEXT NOT NULL,
        code      TEXT NOT NULL,
        expires   INTEGER NOT NULL,
        used      INTEGER DEFAULT 0,
        created   INTEGER NOT NULL
    )");
}

// Generate & store OTP, return the code
function generateOtp(\SQLite3 $db, string $email): string {
    ensureOtpTable($db);
    // Invalidate old codes for this email
    $db->exec("DELETE FROM otp_codes WHERE email = '" . SQLite3::escapeString($email) . "'");
    // Generate new code
    $code    = str_pad((string)random_int(0, 999999), OTP_LENGTH, '0', STR_PAD_LEFT);
    $expires = time() + OTP_EXPIRY_SECONDS;
    $stmt    = $db->prepare("INSERT INTO otp_codes (email, code, expires, used, created) VALUES (?, ?, ?, 0, ?)");
    $stmt->bindValue(1, $email);
    $stmt->bindValue(2, $code);
    $stmt->bindValue(3, $expires);
    $stmt->bindValue(4, time());
    $stmt->execute();
    return $code;
}

// Verify OTP — returns true and marks used, or false
function verifyOtp(\SQLite3 $db, string $email, string $code): bool {
    ensureOtpTable($db);
    $email = strtolower(trim($email));
    $code  = trim($code);
    $stmt  = $db->prepare(
        "SELECT rowid FROM otp_codes WHERE email=? AND code=? AND used=0 AND expires>?"
    );
    $stmt->bindValue(1, $email);
    $stmt->bindValue(2, $code);
    $stmt->bindValue(3, time());
    $res = $stmt->execute();
    $row = $res->fetchArray(SQLITE3_ASSOC);
    if (!$row) return false;
    // Mark used
    $db->exec("UPDATE otp_codes SET used=1 WHERE rowid=" . (int)$row['rowid']);
    return true;
}

// Send OTP via Resend API
function sendOtpEmail(string $email, string $code): bool {
    $resendKey = getenv('RESEND_API_KEY');
    if (!$resendKey) {
        error_log("RESEND_API_KEY not set");
        return false;
    }
    $fromEmail = getenv('FROM_EMAIL') ?: 'disputes@gigradar.io';
    $fromName  = 'GigRadar DisputeShield';

    $body = json_encode([
        'from'    => "$fromName <$fromEmail>",
        'to'      => [$email],
        'subject' => "Your DisputeShield login code: $code",
        'html'    => buildOtpEmailHtml($email, $code),
        'text'    => "Your DisputeShield login code is: $code\n\nThis code expires in 10 minutes.\n\nIf you did not request this, ignore this email.",
    ]);

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $resendKey,
        ],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 && $httpCode !== 201) {
        error_log("Resend error $httpCode: $response");
        return false;
    }
    return true;
}

function buildOtpEmailHtml(string $email, string $code): string {
    $digits = implode('</td><td>', str_split($code));
    return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:system-ui,sans-serif">
<table width="100%" cellpadding="0" cellspacing="0">
<tr><td align="center" style="padding:40px 20px">
<table width="480" cellpadding="0" cellspacing="0" style="background:#0a0f1e;border-radius:16px;overflow:hidden">

  <!-- Header -->
  <tr><td style="background:linear-gradient(135deg,#6366f1,#8b5cf6);padding:28px 36px;text-align:center">
    <div style="font-size:28px;margin-bottom:8px">⚡</div>
    <div style="color:#fff;font-size:20px;font-weight:800;letter-spacing:-0.5px">DisputeShield</div>
    <div style="color:#c7d2fe;font-size:12px;margin-top:4px">GigRadar internal tool</div>
  </td></tr>

  <!-- Body -->
  <tr><td style="padding:36px 36px 28px;color:#e2e8f0">
    <p style="margin:0 0 8px;font-size:14px;color:#94a3b8">Login code for</p>
    <p style="margin:0 0 28px;font-size:14px;font-weight:700;color:#818cf8">{$email}</p>

    <p style="margin:0 0 16px;font-size:13px;color:#64748b">Your one-time login code:</p>

    <!-- Code box -->
    <table cellpadding="0" cellspacing="0" style="margin:0 auto 24px">
    <tr>
      <td style="background:#1e293b;border-radius:12px;padding:20px 32px;text-align:center">
        <span style="font-size:40px;font-weight:900;letter-spacing:12px;color:#f1f5f9;font-family:monospace">{$code}</span>
      </td>
    </tr>
    </table>

    <p style="margin:0 0 6px;font-size:12px;color:#475569;text-align:center">⏱ This code expires in <strong style="color:#f1f5f9">10 minutes</strong></p>
    <p style="margin:0;font-size:11px;color:#334155;text-align:center">If you didn't request this, you can safely ignore this email.</p>
  </td></tr>

  <!-- Footer -->
  <tr><td style="padding:16px 36px;border-top:1px solid #1e293b;text-align:center">
    <p style="margin:0;font-size:11px;color:#334155">GigRadar · gigradar.io · support@gigradar.io</p>
  </td></tr>

</table>
</td></tr>
</table>
</body>
</html>
HTML;
}
