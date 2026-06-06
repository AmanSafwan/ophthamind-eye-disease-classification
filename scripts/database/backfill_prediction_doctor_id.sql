-- Run once if dashboard shows 0 screenings but you have older records.
-- Ensures predictions are linked to the clinician who created them.

-- Add column if your schema predates doctor_id (ignore error if it already exists)
-- ALTER TABLE predictions ADD COLUMN doctor_id INT UNSIGNED NULL AFTER patient_id;

UPDATE predictions
SET doctor_id = created_by
WHERE (doctor_id IS NULL OR doctor_id = 0)
  AND created_by IS NOT NULL
  AND created_by > 0;
