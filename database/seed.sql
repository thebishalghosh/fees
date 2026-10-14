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
