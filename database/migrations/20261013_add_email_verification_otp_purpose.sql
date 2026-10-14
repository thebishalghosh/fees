-- Apply manually after backing up the development database.
-- Adds the purpose used by the parent email-verification challenge flow.
ALTER TABLE user_otp_verifications
    MODIFY purpose ENUM(
        'mobile_verification',
        'email_verification',
        'login',
        'password_reset',
        'payment_verification'
    ) NOT NULL;
