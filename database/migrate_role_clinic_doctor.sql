-- Rename role ophthalmologist → clinic_doctor (display: Clinic Doctor)
-- Run in order; do not skip step 1.

ALTER TABLE users
    MODIFY role ENUM('admin', 'ophthalmologist', 'clinic_doctor') NOT NULL;

UPDATE users
SET role = 'clinic_doctor'
WHERE role = 'ophthalmologist';

ALTER TABLE users
    MODIFY role ENUM('admin', 'clinic_doctor') NOT NULL;

-- If role was blank after a partial run:
-- UPDATE users SET role = 'clinic_doctor' WHERE role = '' OR role IS NULL;
