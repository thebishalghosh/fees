# Local OTP email testing with Mailpit

The development configuration uses the SMTP endpoint observed in the running Laragon Mailpit process: `127.0.0.1:1025`. Mailpit's web inbox was listening on port `8025` at inspection time. These are local development settings only; the application reads them from `.env`.

1. Start Mailpit from Laragon and confirm it is listening on the configured `MAIL_HOST` and `MAIL_PORT`.
2. Copy the Mailpit settings from `.env.example` into the ignored `.env` file. Change the endpoint if the local Mailpit process is configured differently. Keep `MAIL_PROVIDER=mailpit` only in development.
3. Set a random `OTP_HASH_KEY` of at least 32 characters. Do not use the example value or commit the key.
4. Run `composer install` from the project directory.
5. In a disposable local/test database only, apply `database/schema.sql`, then `database/seed.sql`, then `database/seed-development.sql`. Select a database whose name ends in `_dev`, `_development`, `_test`, or `_testing`; the development seed refuses to insert outside those database names. It inserts institution code `1234` and active, email-verified administrator `test@test.com` only when those identities do not already exist. It does not update conflicting records; review the final status row printed by the script if the account already exists. Never run this seed in production.
6. Open the application login screen, select Staff / Admin, enter the institution code and registered email address, and submit. After the transport accepts delivery, the application opens an OTP verification modal. The OTP is delivered only to the email address stored for that user in `users.email`; it is not shown in the application.
7. Open the Mailpit inbox at the web endpoint configured by the running Mailpit process and retrieve the code from the captured email. Submit it before the five-minute expiry.

If SMTP delivery fails, the challenge remains unusable and the application displays a generic delivery failure in the sign-in modal. In development, server logs include sanitized transport diagnostics without OTP values, credentials, or recipient addresses. Mailpit delivery does not require a production SMTP account. Production cannot select the Mailpit provider or use a test OTP; a production mail provider requires its own explicit adapter.

If sign-in reports that OTP authentication is not configured, check that `.env` contains a random `OTP_HASH_KEY` of at least 32 characters. Generate a local key with the PHP CLI using `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`; put the output in `.env` only and never commit or log it. OTP challenge creation stops before SMTP when this key is missing.

No database migrations are required for email delivery. The existing `users.email` and `users.email_verified_at` columns are used; accounts without a valid verified address cannot request a usable login code. This guide does not instruct importing, migrating, or modifying any database.
