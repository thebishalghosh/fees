-- Development/test-only institution administrator for email OTP sign-in.
-- This file is intentionally separate from seed.sql and never inserts outside
-- databases whose names end in _dev, _development, _test, or _testing.
-- Run schema.sql and seed.sql first, then run this file against a disposable
-- development/test database. Do not run this file in production.

SET @feesportal_development_seed_allowed =
    DATABASE() REGEXP '(^|_)(dev|development|test|testing)$';

SELECT IF(
    @feesportal_development_seed_allowed,
    'Development seed is enabled for this database.',
    'Development seed is blocked: select a database ending in _dev, _development, _test, or _testing.'
) AS seed_environment;

INSERT INTO institutions (name, code, status)
SELECT 'FeesPortal Development Institution', '1234', 'active'
WHERE @feesportal_development_seed_allowed = 1
  AND NOT EXISTS (
      SELECT 1
      FROM institutions
      WHERE code = '1234'
  );

INSERT INTO users (
    institution_id,
    role_id,
    user_type,
    full_name,
    email,
    email_verified_at,
    status
)
SELECT
    i.id,
    r.id,
    'admin',
    'Development Administrator',
    'test@test.com',
    CURRENT_TIMESTAMP,
    'active'
FROM institutions i
JOIN roles r ON r.name = 'admin'
WHERE @feesportal_development_seed_allowed = 1
  AND i.code = '1234'
  AND i.name = 'FeesPortal Development Institution'
  AND i.status = 'active'
  AND NOT EXISTS (
      SELECT 1
      FROM users existing_user
      WHERE existing_user.institution_id = i.id
        AND existing_user.email = 'test@test.com'
  );

SELECT
    i.code AS institution_code,
    i.status AS institution_status,
    u.email AS administrator_email,
    u.user_type,
    u.status AS administrator_status,
    u.email_verified_at
FROM institutions i
LEFT JOIN users u
    ON u.institution_id = i.id
   AND u.email = 'test@test.com'
WHERE @feesportal_development_seed_allowed = 1
  AND i.code = '1234';
