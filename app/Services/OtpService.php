<?php
// app/Services/OtpService.php
namespace App\Services;

use App\Models\Otp;
use Illuminate\Support\Facades\Mail;

class OtpService
{
  public function generate(string $email, string $type): string
  {
    // Invalidate existing OTPs
    Otp::where('email', $email)
      ->where('type', $type)
      ->where('used', false)
      ->update(['used' => true]);

    $code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

    Otp::create([
      'email' => $email,
      'code' => $code,
      'type' => $type,
      'used' => false,
      'expires_at' => now()->addMinutes(10),
    ]);

    return $code;
  }

  public function verify(string $email, string $code, string $type): bool
  {
    $otp = Otp::where('email', $email)
      ->where('code', $code)
      ->where('type', $type)
      ->where('used', false)
      ->latest()
      ->first();

    if (!$otp || $otp->isExpired()) {
      return false;
    }

    if ($type === 'email_verification') {
      $otp->update(['used' => true]);
    }
    return true;
  }

  public function sendVerificationEmail(string $email, string $name, string $code): void
  {
    $subject = 'Verify your FOBTAD account';
    $body = "
        <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;'>
          <div style='background:#042C53;padding:24px;text-align:center;'>
            <h1 style='color:#fff;margin:0;font-size:24px;letter-spacing:2px;'>FOBTAD</h1>
          </div>
          <div style='padding:32px;background:#f7f8fa;'>
            <h2 style='color:#042C53;'>Hi {$name},</h2>
            <p style='color:#3D5A7A;'>Your verification code is:</p>
            <div style='background:#042C53;border-radius:12px;padding:24px;text-align:center;margin:24px 0;'>
              <span style='font-size:40px;font-weight:800;color:#fff;letter-spacing:12px;'>{$code}</span>
            </div>
            <p style='color:#3D5A7A;'>This code expires in <strong>10 minutes</strong>. Do not share it with anyone.</p>
          </div>
          <div style='background:#042C53;padding:16px;text-align:center;'>
            <p style='color:rgba(255,255,255,0.4);font-size:12px;margin:0;'>© 2026 FOBTAD Technologies Ltd</p>
          </div>
        </div>";

    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: FOBTAD <hi@fobtad.com>\r\n";
    mail($email, $subject, $body, $headers);
  }

  public function sendPasswordResetEmail(string $email, string $name, string $code): void
  {
    $subject = 'Reset your FOBTAD password';
    $body = "
        <div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;'>
          <div style='background:#042C53;padding:24px;text-align:center;'>
            <h1 style='color:#fff;margin:0;font-size:24px;letter-spacing:2px;'>FOBTAD</h1>
          </div>
          <div style='padding:32px;background:#f7f8fa;'>
            <h2 style='color:#042C53;'>Hi {$name},</h2>
            <p style='color:#3D5A7A;'>You requested a password reset. Your code is:</p>
            <div style='background:#E24B4A;border-radius:12px;padding:24px;text-align:center;margin:24px 0;'>
              <span style='font-size:40px;font-weight:800;color:#fff;letter-spacing:12px;'>{$code}</span>
            </div>
            <p style='color:#3D5A7A;'>This code expires in <strong>10 minutes</strong>. If you didn't request this, ignore this email.</p>
          </div>
          <div style='background:#042C53;padding:16px;text-align:center;'>
            <p style='color:rgba(255,255,255,0.4);font-size:12px;margin:0;'>© 2026 FOBTAD Technologies Ltd</p>
          </div>
        </div>";

    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: FOBTAD <hi@fobtad.com>\r\n";
    mail($email, $subject, $body, $headers);
  }
}