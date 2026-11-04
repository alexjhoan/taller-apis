USE taller;

ALTER TABLE work_orders
  MODIFY COLUMN assigned_user_id TEXT NULL;
