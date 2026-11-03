-- Safe initial RBAC seed data.
-- These inserts are idempotent and do not create production credentials or secrets.

INSERT IGNORE INTO permissions (permission_key, description) VALUES
    ('dashboard.view', 'View dashboard'),
    ('parents.view', 'View parent accounts'),
    ('parents.manage', 'Create and update parent accounts'),
    ('admissions.view', 'View admissions'),
    ('admissions.manage', 'Manage admission workflow'),
    ('students.view', 'View student records'),
    ('students.manage', 'Create and update students and parent links'),
    ('courses.manage', 'Manage courses and batches'),
    ('fees.view', 'View fee structures and ledgers'),
    ('fees.manage', 'Manage fee structures, plans, and installments'),
    ('payments.view', 'View payment records'),
    ('payments.reconcile', 'Reconcile gateway transactions'),
    ('payments.refund', 'Process refund operations'),
    ('receipts.view', 'View receipts and downloads'),
    ('academics.manage', 'Manage timetables, examinations, and holidays'),
    ('notifications.manage', 'Manage notifications and announcements'),
    ('reports.view', 'View and export reports'),
    ('staff.manage', 'Manage staff and roles'),
    ('settings.manage', 'Manage institution settings'),
    ('audit.view', 'View audit logs');

INSERT IGNORE INTO permissions (permission_key, description) VALUES
    ('students.create', 'Create student records'),
    ('students.edit', 'Edit student records'),
    ('students.delete', 'Delete student records'),
    ('parents.create', 'Create parent accounts'),
    ('parents.edit', 'Edit parent accounts'),
    ('parents.delete', 'Delete parent accounts'),
    ('staff.view', 'View staff accounts'),
    ('staff.create', 'Create staff accounts'),
    ('staff.edit', 'Edit staff accounts'),
    ('staff.delete', 'Delete staff accounts'),
    ('staff.assign_role', 'Assign roles to staff accounts'),
    ('roles.view', 'View role definitions'),
    ('roles.create', 'Create roles'),
    ('roles.edit', 'Edit roles'),
    ('roles.delete', 'Delete roles'),
    ('roles.permissions.manage', 'Manage role permissions'),
    ('courses.view', 'View courses'),
    ('courses.create', 'Create courses'),
    ('courses.edit', 'Edit courses'),
    ('courses.delete', 'Delete courses'),
    ('batches.view', 'View batches'),
    ('batches.create', 'Create batches'),
    ('batches.edit', 'Edit batches'),
    ('batches.delete', 'Delete batches'),
    ('academic_years.view', 'View academic years'),
    ('academic_years.create', 'Create academic years'),
    ('academic_years.edit', 'Edit academic years'),
    ('academic_years.delete', 'Delete academic years'),
    ('classes.view', 'View classes and subjects'),
    ('classes.create', 'Create classes and subjects'),
    ('classes.edit', 'Edit classes and subjects'),
    ('classes.delete', 'Delete classes and subjects'),
    ('attendance.view', 'View attendance'),
    ('attendance.create', 'Record attendance'),
    ('attendance.edit', 'Edit attendance'),
    ('attendance.delete', 'Delete attendance'),
    ('attendance.export', 'Export attendance'),
    ('examinations.view', 'View examinations'),
    ('examinations.create', 'Create examinations'),
    ('examinations.edit', 'Edit examinations'),
    ('examinations.delete', 'Delete examinations'),
    ('results.view', 'View examination results'),
    ('results.create', 'Enter examination results'),
    ('results.edit', 'Edit examination results'),
    ('results.approve', 'Approve examination results'),
    ('results.publish', 'Publish examination results'),
    ('fee_structures.view', 'View fee structures'),
    ('fee_structures.create', 'Create fee structures'),
    ('fee_structures.edit', 'Edit fee structures'),
    ('fee_structures.delete', 'Delete fee structures'),
    ('student_fees.view', 'View student fees'),
    ('student_fees.create', 'Create student fee assignments'),
    ('student_fees.edit', 'Edit student fees'),
    ('student_fees.delete', 'Delete student fees'),
    ('student_fees.approve', 'Approve student fee changes'),
    ('student_fees.reject', 'Reject student fee changes'),
    ('student_fees.export', 'Export student fee data'),
    ('payments.export', 'Export payment records'),
    ('financial_reports.view', 'View financial reports'),
    ('financial_reports.export', 'Export financial reports'),
    ('announcements.view', 'View announcements'),
    ('announcements.create', 'Create announcements'),
    ('announcements.edit', 'Edit announcements'),
    ('announcements.delete', 'Delete announcements'),
    ('announcements.publish', 'Publish announcements'),
    ('notifications.view', 'View notifications'),
    ('library.view', 'View library records'),
    ('library.create', 'Create library records'),
    ('library.edit', 'Edit library records'),
    ('library.delete', 'Delete library records'),
    ('library.manage', 'Manage library operations'),
    ('study_materials.view', 'View study materials'),
    ('study_materials.create', 'Create study materials'),
    ('study_materials.edit', 'Edit study materials'),
    ('study_materials.delete', 'Delete study materials'),
    ('study_materials.manage', 'Manage study materials'),
    ('admissions.create', 'Create admissions'),
    ('admissions.edit', 'Edit admissions'),
    ('admissions.approve', 'Approve admissions'),
    ('admissions.reject', 'Reject admissions'),
    ('institution_settings.view', 'View institution settings'),
    ('institution_settings.manage', 'Manage institution settings'),
    ('general_reports.view', 'View general reports'),
    ('general_reports.export', 'Export general reports');

INSERT IGNORE INTO roles (id, name, description, is_system) VALUES
    (1, 'super_admin', 'Full system administrator', 1),
    (2, 'admin', 'Institution administrator', 1),
    (3, 'staff', 'Institution staff user', 1),
    (4, 'parent', 'Parent / guardian account', 1);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.permission_key IN (
    'dashboard.view',
    'parents.view',
    'parents.manage',
    'admissions.view',
    'admissions.manage',
    'students.view',
    'students.manage',
    'courses.manage',
    'fees.view',
    'fees.manage',
    'payments.view',
    'payments.reconcile',
    'payments.refund',
    'receipts.view',
    'academics.manage',
    'notifications.manage',
    'reports.view',
    'staff.manage',
    'settings.manage',
    'audit.view'
)
WHERE r.name IN ('super_admin', 'admin');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.permission_key IN (
    'dashboard.view',
    'parents.view',
    'admissions.view',
    'students.view',
    'fees.view',
    'payments.view',
    'receipts.view',
    'reports.view'
)
WHERE r.name = 'staff';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.permission_key IN (
    'dashboard.view',
    'parents.view',
    'students.view',
    'fees.view',
    'payments.view',
    'receipts.view'
)
WHERE r.name = 'parent';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.permission_key IN (
    'dashboard.view',
    'students.view', 'students.create', 'students.edit', 'students.delete',
    'parents.view', 'parents.create', 'parents.edit', 'parents.delete',
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
    'payments.view', 'payments.export', 'payments.reconcile', 'payments.refund',
    'financial_reports.view', 'financial_reports.export',
    'announcements.view', 'announcements.create', 'announcements.edit', 'announcements.delete', 'announcements.publish',
    'notifications.view', 'notifications.manage',
    'library.view', 'library.create', 'library.edit', 'library.delete', 'library.manage',
    'study_materials.view', 'study_materials.create', 'study_materials.edit', 'study_materials.delete', 'study_materials.manage',
    'admissions.view', 'admissions.create', 'admissions.edit', 'admissions.approve', 'admissions.reject',
    'institution_settings.view', 'institution_settings.manage',
    'general_reports.view', 'general_reports.export'
)
WHERE r.is_system = 1 AND r.name IN ('super_admin', 'admin');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
JOIN permissions p ON p.permission_key IN (
    'dashboard.view',
    'students.view',
    'parents.view',
    'admissions.view',
    'staff.view',
    'courses.view',
    'batches.view',
    'academic_years.view',
    'classes.view',
    'attendance.view',
    'examinations.view',
    'results.view',
    'fee_structures.view',
    'student_fees.view',
    'payments.view',
    'financial_reports.view',
    'announcements.view',
    'notifications.view',
    'general_reports.view'
)
WHERE r.is_system = 1 AND r.name = 'staff';
