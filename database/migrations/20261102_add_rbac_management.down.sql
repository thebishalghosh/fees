DELIMITER //
CREATE PROCEDURE rollback_rbac_management()
BEGIN
    IF EXISTS (SELECT 1 FROM roles WHERE is_system = 0) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Reassign or remove institution roles before rolling back RBAC management.';
    END IF;

    DELETE rp
    FROM role_permissions rp
    JOIN permissions p ON p.id = rp.permission_id
    WHERE p.permission_key IN (
        'students.create', 'students.edit', 'students.delete',
        'parents.create', 'parents.edit', 'parents.delete',
        'staff.view', 'staff.create', 'staff.edit', 'staff.delete', 'staff.assign_role',
        'roles.view', 'roles.create', 'roles.edit', 'roles.delete', 'roles.permissions.manage',
        'courses.view', 'courses.create', 'courses.edit', 'courses.delete',
        'batches.view', 'batches.create', 'batches.edit', 'batches.delete',
        'academic_years.view', 'academic_years.create', 'academic_years.edit', 'academic_years.delete',
        'classes.view', 'classes.create', 'classes.edit', 'classes.delete',
        'attendance.view', 'attendance.create', 'attendance.edit', 'attendance.delete', 'attendance.export',
        'examinations.view', 'examinations.create', 'examinations.edit', 'examinations.delete',
        'results.view', 'results.create', 'results.edit', 'results.approve', 'results.publish',
        'fee_structures.view', 'fee_structures.create', 'fee_structures.edit', 'fee_structures.delete',
        'student_fees.view', 'student_fees.create', 'student_fees.edit', 'student_fees.delete',
        'student_fees.approve', 'student_fees.reject', 'student_fees.export',
        'payments.export', 'financial_reports.view', 'financial_reports.export',
        'announcements.view', 'announcements.create', 'announcements.edit', 'announcements.delete', 'announcements.publish',
        'notifications.view', 'library.view', 'library.create', 'library.edit', 'library.delete', 'library.manage',
        'study_materials.view', 'study_materials.create', 'study_materials.edit', 'study_materials.delete', 'study_materials.manage',
        'admissions.create', 'admissions.edit', 'admissions.approve', 'admissions.reject',
        'institution_settings.view', 'institution_settings.manage',
        'general_reports.view', 'general_reports.export'
    );

    DELETE FROM permissions
    WHERE permission_key IN (
        'students.create', 'students.edit', 'students.delete',
        'parents.create', 'parents.edit', 'parents.delete',
        'staff.view', 'staff.create', 'staff.edit', 'staff.delete', 'staff.assign_role',
        'roles.view', 'roles.create', 'roles.edit', 'roles.delete', 'roles.permissions.manage',
        'courses.view', 'courses.create', 'courses.edit', 'courses.delete',
        'batches.view', 'batches.create', 'batches.edit', 'batches.delete',
        'academic_years.view', 'academic_years.create', 'academic_years.edit', 'academic_years.delete',
        'classes.view', 'classes.create', 'classes.edit', 'classes.delete',
        'attendance.view', 'attendance.create', 'attendance.edit', 'attendance.delete', 'attendance.export',
        'examinations.view', 'examinations.create', 'examinations.edit', 'examinations.delete',
        'results.view', 'results.create', 'results.edit', 'results.approve', 'results.publish',
        'fee_structures.view', 'fee_structures.create', 'fee_structures.edit', 'fee_structures.delete',
        'student_fees.view', 'student_fees.create', 'student_fees.edit', 'student_fees.delete',
        'student_fees.approve', 'student_fees.reject', 'student_fees.export',
        'payments.export', 'financial_reports.view', 'financial_reports.export',
        'announcements.view', 'announcements.create', 'announcements.edit', 'announcements.delete', 'announcements.publish',
        'notifications.view', 'library.view', 'library.create', 'library.edit', 'library.delete', 'library.manage',
        'study_materials.view', 'study_materials.create', 'study_materials.edit', 'study_materials.delete', 'study_materials.manage',
        'admissions.create', 'admissions.edit', 'admissions.approve', 'admissions.reject',
        'institution_settings.view', 'institution_settings.manage',
        'general_reports.view', 'general_reports.export'
    );

    ALTER TABLE roles
        DROP FOREIGN KEY fk_roles_institution,
        DROP INDEX uq_roles_institution_name,
        DROP INDEX uq_roles_system_name,
        DROP INDEX idx_roles_institution_active,
        DROP COLUMN system_name,
        DROP COLUMN is_active,
        DROP COLUMN institution_id,
        ADD UNIQUE KEY uq_roles_name (name);
END//
DELIMITER ;

CALL rollback_rbac_management();
DROP PROCEDURE rollback_rbac_management;
