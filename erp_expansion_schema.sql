-- GHION ERP expansion schema
-- Apply after sql/schema.sql. All objects are additive and idempotent.
-- This migration provides normalized extension points for configurable master data,
-- manufacturing traceability, payables, reconciliation, reporting, and offline sync.

USE ghion_erp;

CREATE TABLE IF NOT EXISTS permissions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  permission_key VARCHAR(100) NOT NULL UNIQUE,
  description VARCHAR(255) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS role_permissions (
  role ENUM('accountant','director','consultant') NOT NULL,
  permission_id INT NOT NULL,
  PRIMARY KEY (role, permission_id),
  FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS units_of_measure (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NOT NULL UNIQUE,
  name VARCHAR(80) NOT NULL,
  decimals TINYINT NOT NULL DEFAULT 2,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS packaging_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL UNIQUE,
  name VARCHAR(160) NOT NULL,
  brand VARCHAR(100) NULL,
  pack_level VARCHAR(40) NOT NULL,
  uom VARCHAR(30) NOT NULL DEFAULT 'kg',
  qty_per_pack DECIMAL(14,4) NULL,
  avg_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
  stock_qty DECIMAL(14,2) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_packaging (
  product_id INT NOT NULL,
  packaging_item_id INT NOT NULL,
  quantity DECIMAL(14,4) NOT NULL,
  output_uom VARCHAR(30) NOT NULL,
  PRIMARY KEY (product_id, packaging_item_id),
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  FOREIGN KEY (packaging_item_id) REFERENCES packaging_items(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_materials (
  product_id INT NOT NULL,
  material_id INT NOT NULL,
  quantity_per_output DECIMAL(14,6) NULL,
  costing_method ENUM('actual','standard','weighted_average') NOT NULL DEFAULT 'weighted_average',
  PRIMARY KEY (product_id, material_id),
  FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
  FOREIGN KEY (material_id) REFERENCES raw_materials(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS inventory_locations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  location_type VARCHAR(50) NOT NULL DEFAULT 'warehouse',
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_adjustments (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  adjustment_no VARCHAR(50) NOT NULL UNIQUE,
  item_type VARCHAR(40) NOT NULL,
  item_id INT NOT NULL,
  batch_id INT NULL,
  system_qty DECIMAL(14,2) NOT NULL,
  physical_qty DECIMAL(14,2) NOT NULL,
  variance DECIMAL(14,2) NOT NULL,
  reason VARCHAR(255) NOT NULL,
  status ENUM('pending','posted','reversed') NOT NULL DEFAULT 'pending',
  counted_by INT NULL,
  posted_by INT NULL,
  posted_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (batch_id) REFERENCES batches(id),
  FOREIGN KEY (counted_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (posted_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_adjustment_item (item_type, item_id),
  INDEX idx_adjustment_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS production_consumption (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  production_run_id INT NOT NULL,
  material_id INT NOT NULL,
  batch_id INT NULL,
  opening_qty DECIMAL(14,2) NOT NULL,
  consumed_qty DECIMAL(14,2) NOT NULL,
  estimated_remaining_qty DECIMAL(14,2) NOT NULL,
  physical_verified_qty DECIMAL(14,2) NULL,
  variance_qty DECIMAL(14,2) NULL,
  recorded_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (production_run_id) REFERENCES production_runs(id) ON DELETE CASCADE,
  FOREIGN KEY (material_id) REFERENCES raw_materials(id),
  FOREIGN KEY (batch_id) REFERENCES batches(id),
  FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_consumption_batch (batch_id),
  INDEX idx_consumption_run (production_run_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS production_waste (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  production_run_id INT NOT NULL,
  material_id INT NULL,
  category VARCHAR(80) NOT NULL,
  waste_qty DECIMAL(14,2) NOT NULL,
  estimated_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
  reason VARCHAR(255) NULL,
  recorded_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (production_run_id) REFERENCES production_runs(id) ON DELETE CASCADE,
  FOREIGN KEY (material_id) REFERENCES raw_materials(id),
  FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rework_orders (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  rework_no VARCHAR(50) NOT NULL UNIQUE,
  original_run_id INT NOT NULL,
  product_id INT NOT NULL,
  damaged_qty DECIMAL(14,2) NOT NULL,
  recovered_qty DECIMAL(14,2) NOT NULL DEFAULT 0,
  final_waste_qty DECIMAL(14,2) NOT NULL DEFAULT 0,
  status ENUM('open','completed','cancelled') NOT NULL DEFAULT 'open',
  reason VARCHAR(255) NOT NULL,
  opened_by INT NULL,
  completed_by INT NULL,
  opened_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  FOREIGN KEY (original_run_id) REFERENCES production_runs(id),
  FOREIGN KEY (product_id) REFERENCES products(id),
  FOREIGN KEY (opened_by) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (completed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payment_allocations (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  payment_id INT NOT NULL,
  sale_id INT NULL,
  amount DECIMAL(14,2) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
  FOREIGN KEY (sale_id) REFERENCES sales(id),
  UNIQUE KEY uq_payment_sale (payment_id, sale_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales_returns (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  return_no VARCHAR(50) NOT NULL UNIQUE,
  sale_id INT NOT NULL,
  customer_id INT NOT NULL,
  return_date DATETIME DEFAULT CURRENT_TIMESTAMP,
  reason VARCHAR(255) NULL,
  total DECIMAL(14,2) NOT NULL DEFAULT 0,
  status ENUM('posted','cancelled') NOT NULL DEFAULT 'posted',
  created_by INT NULL,
  FOREIGN KEY (sale_id) REFERENCES sales(id),
  FOREIGN KEY (customer_id) REFERENCES customers(id),
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sales_return_lines (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  return_id BIGINT NOT NULL,
  product_id INT NOT NULL,
  qty DECIMAL(14,2) NOT NULL,
  unit_price DECIMAL(14,2) NOT NULL,
  line_total DECIMAL(14,2) NOT NULL,
  FOREIGN KEY (return_id) REFERENCES sales_returns(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS supplier_payments (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  payment_no VARCHAR(50) NOT NULL UNIQUE,
  supplier_id INT NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  method VARCHAR(40) NOT NULL,
  payment_date DATETIME DEFAULT CURRENT_TIMESTAMP,
  reference VARCHAR(120) NULL,
  unallocated_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  created_by INT NULL,
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS supplier_payment_allocations (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  supplier_payment_id BIGINT NOT NULL,
  purchase_id INT NULL,
  amount DECIMAL(14,2) NOT NULL,
  FOREIGN KEY (supplier_payment_id) REFERENCES supplier_payments(id) ON DELETE CASCADE,
  FOREIGN KEY (purchase_id) REFERENCES purchases(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS expense_categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL UNIQUE,
  classification ENUM('manufacturing','selling','administration','finance','other') NOT NULL DEFAULT 'other',
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS expenses (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  expense_no VARCHAR(50) NOT NULL UNIQUE,
  category_id INT NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  expense_date DATE NOT NULL,
  payment_account_id INT NULL,
  supplier_id INT NULL,
  description VARCHAR(255) NOT NULL,
  attachment_path VARCHAR(255) NULL,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (category_id) REFERENCES expense_categories(id),
  FOREIGN KEY (payment_account_id) REFERENCES cash_accounts(id),
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS machines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  machine_no VARCHAR(50) NOT NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  machine_type VARCHAR(80) NULL,
  department VARCHAR(80) NULL,
  process VARCHAR(120) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  notes VARCHAR(255) NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS overhead_categories (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL UNIQUE,
  allocation_driver ENUM('production_qty','material_qty','labour_cost','machine_hours','floor_area','headcount','manual') NOT NULL DEFAULT 'production_qty',
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS overhead_allocations (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  overhead_category_id INT NOT NULL,
  production_run_id INT NULL,
  period VARCHAR(20) NOT NULL,
  actual_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  allocated_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  driver_value DECIMAL(14,4) NOT NULL DEFAULT 0,
  created_by INT NULL,
  FOREIGN KEY (overhead_category_id) REFERENCES overhead_categories(id),
  FOREIGN KEY (production_run_id) REFERENCES production_runs(id),
  FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS accounting_periods (
  id INT AUTO_INCREMENT PRIMARY KEY,
  period_start DATE NOT NULL,
  period_end DATE NOT NULL,
  status ENUM('open','closed') NOT NULL DEFAULT 'open',
  closed_by INT NULL,
  closed_at DATETIME NULL,
  reopen_reason VARCHAR(255) NULL,
  UNIQUE KEY uq_period (period_start, period_end),
  FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sync_logs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  transaction_uuid VARCHAR(60) NOT NULL,
  direction ENUM('upload','download') NOT NULL,
  status ENUM('accepted','duplicate','conflict','failed') NOT NULL,
  message VARCHAR(255) NULL,
  attempts INT NOT NULL DEFAULT 1,
  original_created_at DATETIME NULL,
  synchronized_at DATETIME NULL,
  user_id INT NULL,
  UNIQUE KEY uq_sync_event (transaction_uuid, direction),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS backups (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  filename VARCHAR(255) NOT NULL,
  status ENUM('started','completed','failed') NOT NULL,
  size_bytes BIGINT NULL,
  retention_until DATE NULL,
  initiated_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (initiated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  notification_type VARCHAR(60) NOT NULL,
  title VARCHAR(160) NOT NULL,
  body TEXT NOT NULL,
  read_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS core_paper_movements (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  movement_no VARCHAR(50) NOT NULL UNIQUE,
  movement_type ENUM('receipt','consumption','adjustment') NOT NULL,
  quantity_kg DECIMAL(14,2) NOT NULL,
  production_run_id INT NULL,
  waste_kg DECIMAL(14,2) NOT NULL DEFAULT 0,
  reason VARCHAR(255) NULL,
  recorded_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (production_run_id) REFERENCES production_runs(id),
  FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO permissions (permission_key, description) VALUES
('view_operational_reports', 'View operational reports'),
('view_confidential_purchases', 'View purchase financial amounts'),
('edit_purchase_financials', 'Enter or revise purchase financial amounts'),
('view_financial_statements', 'View financial statements'),
('post_stock_adjustments', 'Finalize stock adjustments'),
('manage_master_data', 'Create, edit, and deactivate master data'),
('manage_users', 'Manage users and permissions'),
('manage_accounting_periods', 'Open, close, and reopen accounting periods'),
('manage_backups', 'Create, download, and restore backups'),
('export_confidential_reports', 'Export confidential reports');

INSERT IGNORE INTO role_permissions (role, permission_id)
SELECT 'accountant', id FROM permissions
WHERE permission_key IN ('view_operational_reports');

INSERT IGNORE INTO role_permissions (role, permission_id)
SELECT role_name, p.id
FROM (SELECT 'director' role_name UNION ALL SELECT 'consultant') roles
CROSS JOIN permissions p
WHERE p.permission_key IN (
  'view_operational_reports', 'view_confidential_purchases',
  'edit_purchase_financials', 'view_financial_statements',
  'post_stock_adjustments', 'manage_master_data', 'manage_users',
  'manage_accounting_periods', 'manage_backups', 'export_confidential_reports'
);

INSERT IGNORE INTO product_categories (name) VALUES
('Toilet Paper'), ('Serviettes'), ('Kitchen Towels');

INSERT IGNORE INTO units_of_measure (code, name, decimals) VALUES
('kg', 'Kilogram', 2), ('pcs', 'Piece', 0), ('pack', 'Pack', 0),
('tonne', 'Metric Tonne', 3), ('sack', 'Sack', 0), ('hour', 'Hour', 2);

INSERT IGNORE INTO inventory_locations (name, location_type) VALUES
('Raw Materials Store', 'warehouse'), ('Production', 'production'),
('Finished Goods', 'warehouse'), ('Salesman Stock', 'mobile_stock');

INSERT IGNORE INTO expense_categories (name, classification) VALUES
('Factory Overheads', 'manufacturing'), ('Selling and Distribution', 'selling'),
('Administration', 'administration'), ('Bank Charges', 'finance'), ('Other Expense', 'other');
