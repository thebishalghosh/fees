# Parent Affiliation Migration Backup and Verification

**Date:** 2026-11-03  
**Database:** Local `fees_management_test` on MySQL 8.4.3  
**Production:** Not accessed or changed

## Backup

- **File:** `C:\Users\Bishal Ghosh\AppData\Local\FeesPortalBackups\fees_management_test_affiliation_20261103_124755.sql`
- **Size:** 181,454 bytes
- **SHA-256:** `9219DC14E4F59727FCF189F76E07F756A11BAD2F8826E5520115B1E771E3FF9C`
- **Verification:** Imported to a separate disposable database. All 45 base-table names and row counts matched the configured database.

## Migration

The affiliation migration was applied to the configured local test database
after verifying the backup checksum and confirming that existing parent and
account institution associations were consistent.

Post-migration checks confirmed:

- The parent/account institution mismatch count is zero.
- Three expected affiliation triggers are present.
- Parent/user association is constrained by a same-institution trigger.
- Session and OTP composite foreign keys use `ON UPDATE CASCADE`, preserving
  their account association during a transfer.
- All 45 base-table row counts match the verified pre-migration backup.
- No application records were inserted, updated, or deleted by the migration.

The backup contains database data and should be handled as sensitive.
