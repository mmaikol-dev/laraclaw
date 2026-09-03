---
name: laravel_otp_setup
description: Installs and configures spatie/laravel-otp for TOTP/HOTP one‑time passwords (2FA).
category: general
created_by: agent
version: 1
is_active: true
source: database
dependencies: []
---

1. Run `composer require spatie/laravel-otp`.
2. Publish the config and migration:
   ```bash
   php artisan vendor:publish --provider="Spatie\Otp\OtpServiceProvider" --tag="config"
   php artisan vendor:publish --provider="Spatie\Otp\OtpServiceProvider" --tag="migrations"
   ```
3. Run migrations: `php artisan migrate`.
4. In your `User` model add the `HasOtp` trait:
   ```php
   use Spatie\Otp\Traits\HasOtp;
   class User extends Authenticatable {
       use HasOtp;
   }
   ```
5. Configure OTP settings in `config/otp.php` (issuer name, secret length, algorithm, etc.).
6. Generate a secret for a user (e.g., `$user->generateOtpSecret();`).
7. Provide a QR code for the user to scan (use `<img src="{{ $user->otpQrCodeUrl() }}" alt="OTP QR"/>`).
8. Verify OTP codes during login using `<?php if($user->verifyOtp($code)) { // authenticated } ?>`.
9. Run the skill `laravel_shadcn_project` first to have a base project.
