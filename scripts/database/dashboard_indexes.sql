-- Run once in phpMyAdmin to speed up dashboard (doctor + date filters)
ALTER TABLE predictions
    ADD INDEX idx_predictions_doctor_created (doctor_id, deleted, created_at),
    ADD INDEX idx_predictions_doctor_patient (doctor_id, deleted, patient_id);
