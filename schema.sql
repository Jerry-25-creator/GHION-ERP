-- ============================================================
-- GHION INVESTMENTS AND ENTERPRISE LTD - ERP Database Schema
-- MySQL 8.0+
-- ============================================================
CREATE DATABASE IF NOT EXISTS ghion_erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE ghion_erp;

-- ---------- USERS / SECURITY ----------
CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(160) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('accountant','director','consultant') NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  last_login DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------- AUDIT TRAIL ----------
CREATE TABLE audit_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  user_name VARCHAR(120) NULL,
  action VARCHAR(60) NOT NULL,
  module VARCHAR(60) NOT NULL,
  record_id VARCHAR(60) NULL,
  old_value TEXT NULL,
  new_value TEXT NULL,
  reason VARCHAR(255) NULL,
  ip_address VARCHAR(45) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_module (module), INDEX idx_created (created_at)
) ENGINE=InnoDB;

-- ---------- MASTER DATA ----------
CREATE TABLE products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NOT NULL UNIQUE,
  name VARCHAR(160) NOT NULL,
  category VARCHAR(80) NOT NULL,
  uom VARCHAR(30) NOT NULL DEFAULT 'pcs',
  units_per_pack INT NOT NULL DEFAULT 1,
  packs_per_master INT NOT NULL DEFAULT 1,
  standard_price DECIMAL(14,2) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE raw_materials (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NOT NULL UNIQUE,
  name VARCHAR(160) NOT NULL,
  uom VARCHAR(20) NOT NULL DEFAULT 'kg',
  avg_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
  stock_qty DECIMAL(14,2) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE batches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  batch_no VARCHAR(60) NOT NULL UNIQUE,
  material_id INT NOT NULL,
  supplier_id INT NULL,
  qty_received DECIMAL(14,2) NOT NULL,
  qty_remaining DECIMAL(14,2) NOT NULL,
  price_per_tonne DECIMAL(14,2) NULL,           -- CONFIDENTIAL (director/consultant)
  total_cost DECIMAL(14,2) NULL,                -- CONFIDENTIAL
  reference VARCHAR(120) NULL,
  status ENUM('stored','in_production','partially_consumed','consumed') DEFAULT 'stored',
  received_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (material_id) REFERENCES raw_materials(id)
) ENGINE=InnoDB;

CREATE TABLE stock_movements (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  item_type ENUM('raw_material','packaging','finished_good') NOT NULL,
  item_id INT NOT NULL,
  batch_id INT NULL,
  qty DECIMAL(14,2) NOT NULL,
  from_state VARCHAR(40) NULL,
  to_state VARCHAR(40) NULL,
  movement_type VARCHAR(40) NOT NULL,
  reference VARCHAR(80) NULL,
  reason VARCHAR(255) NULL,
  user_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_item (item_type, item_id)
) ENGINE=InnoDB;

-- ---------- PRODUCTION ----------
CREATE TABLE production_runs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  run_no VARCHAR(40) NOT NULL UNIQUE,
  product_id INT NOT NULL,
  batch_id INT NULL,
  qty_produced DECIMAL(14,2) NOT NULL,
  est_kg_consumed DECIMAL(14,2) NULL,
  est_kg_remaining DECIMAL(14,2) NULL,
  waste_kg DECIMAL(14,2) NOT NULL DEFAULT 0,
  status ENUM('wip','completed') DEFAULT 'completed',
  remarks VARCHAR(255) NULL,
  user_id INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (product_id) REFERENCES products(id),
  FOREIGN KEY (batch_id) REFERENCES batches(id)
) ENGINE=InnoDB;

CREATE TABLE finished_stock (
  product_id INT PRIMARY KEY,
  qty DECIMAL(14,2) NOT NULL DEFAULT 0,
  avg_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
  FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

CREATE TABLE stocktakes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  stocktake_no VARCHAR(40) NOT NULL UNIQUE,
  item_type VARCHAR(30) NOT NULL,
  item_id INT NOT NULL,
  system_qty DECIMAL(14,2) NOT NULL,
  physical_qty DECIMAL(14,2) NOT NULL,
  variance DECIMAL(14,2) NOT NULL,
  reason VARCHAR(255) NULL,
  counted_by INT NULL,
  status ENUM('counted','posted') DEFAULT 'counted',
  posted_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------- CUSTOMERS / SALES ----------
CREATE TABLE customers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  type VARCHAR(60) NOT NULL DEFAULT 'Individual',
  phone VARCHAR(40) NULL,
  contact VARCHAR(120) NULL,
  address VARCHAR(255) NULL,
  tin VARCHAR(60) NULL,
  opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE sales (
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_no VARCHAR(40) NOT NULL UNIQUE,
  customer_id INT NOT NULL,
  salesman VARCHAR(80) NULL,
  sale_date DATETIME DEFAULT CURRENT_TIMESTAMP,
  subtotal DECIMAL(14,2) NOT NULL,
  discount DECIMAL(14,2) NOT NULL DEFAULT 0,
  total DECIMAL(14,2) NOT NULL,
  payment_method ENUM('cash','bank','mobile_money','credit') NOT NULL DEFAULT 'cash',
  payment_status ENUM('paid','partial','unpaid') NOT NULL DEFAULT 'paid',
  amount_paid DECIMAL(14,2) NOT NULL DEFAULT 0,
  balance DECIMAL(14,2) NOT NULL DEFAULT 0,
  remarks VARCHAR(255) NULL,
  user_id INT NULL,
  FOREIGN KEY (customer_id) REFERENCES customers(id),
  INDEX idx_date (sale_date)
) ENGINE=InnoDB;

CREATE TABLE sale_lines (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  sale_id INT NOT NULL,
  product_id INT NOT NULL,
  qty DECIMAL(14,2) NOT NULL,
  unit_price DECIMAL(14,2) NOT NULL,
  discount DECIMAL(14,2) NOT NULL DEFAULT 0,
  line_total DECIMAL(14,2) NOT NULL,
  FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
  FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

CREATE TABLE payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  receipt_no VARCHAR(40) NOT NULL UNIQUE,
  customer_id INT NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  method ENUM('cash','bank','mobile_money') NOT NULL,
  payment_date DATETIME DEFAULT CURRENT_TIMESTAMP,
  reference VARCHAR(120) NULL,
  allocated DECIMAL(14,2) NOT NULL DEFAULT 0,
  received_by INT NULL,
  FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB;

CREATE TABLE salesman_routes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  route_date DATE NOT NULL,
  salesman VARCHAR(80) NOT NULL,
  product_id INT NOT NULL,
  issued DECIMAL(14,2) NOT NULL,
  sold DECIMAL(14,2) NOT NULL DEFAULT 0,
  returned DECIMAL(14,2) NOT NULL DEFAULT 0,
  status ENUM('open','closed') DEFAULT 'open',
  UNIQUE KEY uq_route (route_date, salesman, product_id)
) ENGINE=InnoDB;

-- ---------- SUPPLIERS / PURCHASES (CONFIDENTIAL WORKFLOW) ----------
CREATE TABLE suppliers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  phone VARCHAR(40) NULL,
  contact VARCHAR(120) NULL,
  tin VARCHAR(60) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE purchases (
  id INT AUTO_INCREMENT PRIMARY KEY,
  reference VARCHAR(80) NOT NULL UNIQUE,
  supplier_id INT NOT NULL,
  item_type VARCHAR(40) NOT NULL,
  item_name VARCHAR(160) NOT NULL,
  material_id INT NULL,
  qty DECIMAL(14,2) NOT NULL,
  uom VARCHAR(20) NOT NULL DEFAULT 'kg',
  batch_no VARCHAR(60) NULL,
  financial_status ENUM('pending','complete') DEFAULT 'pending',
  unit_cost DECIMAL(14,2) NULL,                 -- CONFIDENTIAL
  total_cost DECIMAL(14,2) NULL,                -- CONFIDENTIAL
  notes VARCHAR(255) NULL,
  received_by INT NULL,
  priced_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
  INDEX idx_finstatus (financial_status)
) ENGINE=InnoDB;

-- ---------- PAYROLL ----------
CREATE TABLE employees (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  phone VARCHAR(40) NULL,
  nin VARCHAR(60) NULL,
  job_title VARCHAR(80) NULL,
  department VARCHAR(80) NULL,
  basic_salary DECIMAL(14,2) NOT NULL DEFAULT 0,
  paye_applicable TINYINT(1) NOT NULL DEFAULT 1,
  nssf_applicable TINYINT(1) NOT NULL DEFAULT 1,
  status ENUM('active','exited') DEFAULT 'active',
  exit_date DATE NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE payroll_runs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  period VARCHAR(20) NOT NULL,
  status ENUM('draft','paid') DEFAULT 'draft',
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE payroll_lines (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  run_id INT NOT NULL,
  employee_id INT NOT NULL,
  basic DECIMAL(14,2) NOT NULL,
  allowances DECIMAL(14,2) NOT NULL DEFAULT 0,
  bonus DECIMAL(14,2) NOT NULL DEFAULT 0,
  gross DECIMAL(14,2) NOT NULL,
  paye DECIMAL(14,2) NOT NULL DEFAULT 0,
  nssf DECIMAL(14,2) NOT NULL DEFAULT 0,
  other_deductions DECIMAL(14,2) NOT NULL DEFAULT 0,
  net DECIMAL(14,2) NOT NULL,
  FOREIGN KEY (run_id) REFERENCES payroll_runs(id) ON DELETE CASCADE,
  FOREIGN KEY (employee_id) REFERENCES employees(id)
) ENGINE=InnoDB;

-- ---------- ACCOUNTING ----------
CREATE TABLE accounts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(20) NOT NULL UNIQUE,
  name VARCHAR(160) NOT NULL,
  category VARCHAR(60) NOT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE journal_entries (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  entry_no VARCHAR(40) NOT NULL UNIQUE,
  source_type VARCHAR(40) NULL,
  source_id INT NULL,
  entry_date DATETIME DEFAULT CURRENT_TIMESTAMP,
  narration VARCHAR(255) NULL,
  posted_by INT NULL
) ENGINE=InnoDB;

CREATE TABLE journal_lines (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  entry_id BIGINT NOT NULL,
  account_id INT NOT NULL,
  debit DECIMAL(14,2) NOT NULL DEFAULT 0,
  credit DECIMAL(14,2) NOT NULL DEFAULT 0,
  FOREIGN KEY (entry_id) REFERENCES journal_entries(id) ON DELETE CASCADE,
  FOREIGN KEY (account_id) REFERENCES accounts(id)
) ENGINE=InnoDB;

CREATE TABLE cash_accounts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  type ENUM('cash','bank','mobile_money') NOT NULL,
  balance DECIMAL(14,2) NOT NULL DEFAULT 0
) ENGINE=InnoDB;

-- ---------- SETTINGS / SYNC ----------
CREATE TABLE settings (k VARCHAR(80) PRIMARY KEY, v TEXT NULL) ENGINE=InnoDB;

CREATE TABLE sync_queue (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  uuid VARCHAR(60) NOT NULL UNIQUE,
  payload MEDIUMTEXT NOT NULL,
  status ENUM('pending','synced','failed') DEFAULT 'pending',
  attempts INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------- SEED DATA ----------
INSERT INTO products (code, name, category, uom, units_per_pack, packs_per_master, standard_price) VALUES
('CL-BLD', 'Classic Blended', 'Toilet Paper', 'pcs', 10, 10, 0),
('CL-VIR', 'Classic Virgin', 'Toilet Paper', 'pcs', 10, 10, 0),
('CL-TIM', 'Classic Timber', 'Toilet Paper', 'pcs', 10, 10, 0),
('COMFRT', 'Comfort', 'Toilet Paper', 'pcs', 10, 10, 0),
('ECO-CR', 'Eco Care', 'Toilet Paper', 'pcs', 10, 10, 0),
('AFYA-S', 'Afya Soft Serviettes', 'Serviettes', 'pcs', 1, 1, 0),
('KTWL-12', 'Kitchen Towels', 'Kitchen Towels', 'pcs', 12, 1, 0);

INSERT INTO raw_materials (code, name) VALUES
('VJ', 'Virgin Jumbo'), ('BJ', 'Blended Jumbo'), ('TJ', 'Timber Jumbo'), ('SJ', 'Serviette Jumbo'), ('CP', 'Core Paper');

INSERT INTO cash_accounts (name, type) VALUES
('Cash on Hand', 'cash'), ('Bank Account 1', 'bank'), ('Mobile Money 1', 'mobile_money');

INSERT INTO accounts (code, name, category) VALUES
('1000','Inventory - Raw Materials','Assets'),
('1100','Inventory - Finished Goods','Assets'),
('1200','Trade Receivables','Assets'),
('1300','Cash on Hand','Assets'),
('1310','Bank','Assets'),
('1320','Mobile Money','Assets'),
('2000','Accounts Payable','Liabilities'),
('2100','PAYE Payable','Liabilities'),
('2110','NSSF Payable','Liabilities'),
('2200','Payroll Payable','Liabilities'),
('3000','Capital','Equity'),
('4000','Sales Revenue','Revenue'),
('5000','Cost of Sales','Cost of Sales'),
('6000','Direct Labour','Direct Manufacturing Costs'),
('6100','Factory Overheads','Factory Overheads'),
('6200','Packaging Materials','Direct Manufacturing Costs'),
('7000','Salaries & Wages - Admin','Administration'),
('8000','Other Expenses','Other Expenses');
