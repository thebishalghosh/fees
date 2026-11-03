ALTER TABLE institutions
    ADD COLUMN institution_type VARCHAR(40) NULL AFTER code,
    ADD COLUMN principal_name VARCHAR(150) NULL AFTER logo_path,
    ADD COLUMN principal_email VARCHAR(255) NULL AFTER principal_name,
    ADD COLUMN principal_phone VARCHAR(30) NULL AFTER principal_email,
    ADD COLUMN registration_number VARCHAR(120) NULL AFTER principal_phone,
    ADD COLUMN affiliation_body VARCHAR(255) NULL AFTER registration_number,
    ADD COLUMN affiliation_number VARCHAR(120) NULL AFTER affiliation_body,
    ADD COLUMN established_year SMALLINT UNSIGNED NULL AFTER affiliation_number,
    ADD COLUMN currency_code CHAR(3) NOT NULL DEFAULT 'INR' AFTER established_year;
