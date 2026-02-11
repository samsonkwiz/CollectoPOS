-- ==================================================================================
-- Includes: Inventory, CRM, Supply Chain, POS, Agent Wallets, Promos, & Reporting
-- Standard: Unix Timestamps (BIGINT) & Multi-Tenant Isolation (company_id)
-- ==================================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;

-- ==========================================================
-- 1. INFRASTRUCTURE & TENANCY
-- ==========================================================

CREATE TABLE companies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_name VARCHAR(150) NOT NULL,
    license_type ENUM('Basic', 'Premium', 'Enterprise') DEFAULT 'Basic',
    is_active TINYINT(1) DEFAULT 1,
    created_at_unix BIGINT(20) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE branches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    branch_name VARCHAR(100) NOT NULL,
    location VARCHAR(255),
    is_active TINYINT(1) DEFAULT 1,
    created_at_unix BIGINT(20) NOT NULL,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ==========================================================
-- 2. USERS, SECURITY & AGENT WALLETS
-- ==========================================================

CREATE TABLE agents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    branch_id INT NOT NULL,
    username VARCHAR(50) NOT NULL,
    password_hash VARCHAR(255) NOT NULL, -- Web Login
    transaction_pin VARCHAR(4) NOT NULL, -- POS Quick Pin
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    phone_primary VARCHAR(20),
    phone_secondary VARCHAR(20),
    photo_path VARCHAR(255) DEFAULT 'assets/img/default.png',
    role ENUM('Agent', 'Supervisor', 'Manager', 'Admin') DEFAULT 'Agent',
    assigned_value DECIMAL(15, 2) DEFAULT 0.00, -- The "Credit" balance
    is_active TINYINT(1) DEFAULT 1,
    created_at_unix BIGINT(20) NOT NULL,
    UNIQUE KEY (company_id, username),
    FOREIGN KEY (company_id) REFERENCES companies(id),
    FOREIGN KEY (branch_id) REFERENCES branches(id)
) ENGINE=InnoDB;

-- Audit: Tracks why an agent's value changed (Sale, Expense, or Manager Refill)
CREATE TABLE agent_value_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agent_id INT NOT NULL,
    previous_value DECIMAL(15,2),
    new_value DECIMAL(15,2),
    change_amount DECIMAL(15,2),
    reference_type ENUM('SALE', 'EXPENSE', 'REFILL', 'CORRECTION'),
    reference_id INT, 
    created_at_unix BIGINT(20),
    FOREIGN KEY (agent_id) REFERENCES agents(id)
) ENGINE=InnoDB;

-- Audit: Tracks Logins and Security Events
CREATE TABLE audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    branch_id INT NOT NULL,
    agent_id INT,
    action_type VARCHAR(50), -- LOGIN, VOID_SALE, PRICE_OVERRIDE
    details TEXT,
    ip_address VARCHAR(45),
    created_at_unix BIGINT(20),
    FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB;

-- ==========================================================
-- 3. INVENTORY & PRICING
-- ==========================================================

CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    category_name VARCHAR(50) NOT NULL,
    category_photo VARCHAR(255) DEFAULT 'assets/img/categories/default.png',
    updated_at_unix BIGINT(20),
    sync_status TINYINT(1) DEFAULT 0, 
    is_deleted TINYINT(1) DEFAULT 0,
    INDEX (company_id), INDEX (sync_status)
) ENGINE=InnoDB;

CREATE TABLE inventory (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    branch_id INT NOT NULL,
    category_id INT,
    sku VARCHAR(50),
    product_photo VARCHAR(255) DEFAULT 'assets/img/products/default.png',
    product_name VARCHAR(150) NOT NULL,
    product_type ENUM('STOCKABLE', 'SERVICE') DEFAULT 'STOCKABLE',
    buying_price DECIMAL(15, 2) DEFAULT 0.00, 
    selling_price DECIMAL(15, 2) NOT NULL,
    stock_qty INT DEFAULT 0,
    min_stock_level INT DEFAULT 5,
    is_active TINYINT(1) DEFAULT 1,
    is_deleted TINYINT(1) DEFAULT 0,
    sync_status TINYINT(1) DEFAULT 0,
    updated_at_unix BIGINT(20),
    INDEX (company_id), INDEX (branch_id), INDEX (sync_status)
) ENGINE=InnoDB;

CREATE TABLE inventory_audits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    branch_id INT NOT NULL,
    product_id INT NOT NULL,
    action_type VARCHAR(50), 
    qty_before INT,
    qty_after INT,
    change_reason TEXT,
    created_at_unix BIGINT(20)
) ENGINE=InnoDB;

-- Pricing: Special prices for specific days (Friday Specials)
CREATE TABLE price_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    inventory_id INT NOT NULL,
    company_id INT NOT NULL, -- Added for multi-tenancy sync
    branch_id INT NOT NULL, 
    day_of_week ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday', 'All'),
    special_price DECIMAL(15, 2),
    start_time TIME DEFAULT '00:00:00',
    end_time TIME DEFAULT '23:59:59',
    is_active TINYINT(1) DEFAULT 1,
    -- Sync Metadata
    updated_at_unix BIGINT(20),
    sync_status TINYINT(1) DEFAULT 0,
    INDEX (inventory_id),
    INDEX (branch_id),
    INDEX (sync_status)
) ENGINE=InnoDB;

-- Audit: Tracks who changed a price and why
CREATE TABLE price_change_audit (
    id INT AUTO_INCREMENT PRIMARY KEY,
    inventory_id INT,
    supervisor_id INT,
    old_price DECIMAL(15, 2),
    new_price DECIMAL(15, 2),
    reason VARCHAR(255),
    changed_at_unix BIGINT(20),
    FOREIGN KEY (inventory_id) REFERENCES inventory(id)
) ENGINE=InnoDB;

-- ==========================================================
-- 4. SUPPLY CHAIN (IN: Purchases, OUT: Returns)
-- ==========================================================

CREATE TABLE suppliers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    supplier_name VARCHAR(100) NOT NULL,
    contact_phone VARCHAR(20),
    email VARCHAR(100),
    FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB;

CREATE TABLE purchases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    branch_id INT NOT NULL,
    supplier_id INT,
    total_cost DECIMAL(15, 2),
    status ENUM('PENDING', 'RECEIVED', 'CANCELLED') DEFAULT 'RECEIVED',
    created_at_unix BIGINT(20),
    FOREIGN KEY (company_id) REFERENCES companies(id),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
) ENGINE=InnoDB;

CREATE TABLE purchase_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_id INT,
    product_id INT,
    qty_received INT,
    unit_cost DECIMAL(15, 2),
    FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES inventory(id)
) ENGINE=InnoDB;

CREATE TABLE returns (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    branch_id INT NOT NULL,
    supplier_id INT,
    agent_id INT,
    total_refund_amount DECIMAL(15, 2),
    reason TEXT,
    created_at_unix BIGINT(20),
    FOREIGN KEY (company_id) REFERENCES companies(id),
    FOREIGN KEY (branch_id) REFERENCES branches(id)
) ENGINE=InnoDB;

CREATE TABLE return_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    return_id INT,
    product_id INT,
    qty INT,
    unit_cost DECIMAL(15, 2),
    FOREIGN KEY (return_id) REFERENCES returns(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ==========================================================
-- 5. CRM & MARKETING
-- ==========================================================

CREATE TABLE clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    client_name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) UNIQUE,
    email VARCHAR(100),
    loyalty_points INT DEFAULT 0,
    created_at_unix BIGINT(20),
    FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB;

CREATE TABLE loyalty_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    points_to_currency_ratio DECIMAL(10,2) DEFAULT 1.00, 
    min_redemption_points INT DEFAULT 100,
    FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB;

CREATE TABLE promos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    promo_name VARCHAR(50) NOT NULL,
    discount_type ENUM('PERCENTAGE', 'FIXED_AMOUNT') DEFAULT 'PERCENTAGE',
    discount_value DECIMAL(10,2) NOT NULL,
    start_unix BIGINT(20),
    end_unix BIGINT(20),
    is_active TINYINT(1) DEFAULT 1,
    FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB;

-- Links promos to specific branches (Many-to-Many)
CREATE TABLE promo_branches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    promo_id INT NOT NULL,
    branch_id INT NOT NULL,
    FOREIGN KEY (promo_id) REFERENCES promos(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ==========================================================
-- 6. POS TRANSACTIONS
-- ==========================================================

-- The Cash Drawer (Shift Management)
CREATE TABLE registers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    agent_id INT NOT NULL,
    opening_balance DECIMAL(15,2) DEFAULT 0.00,
    closing_balance DECIMAL(15,2) DEFAULT 0.00,
    expected_balance DECIMAL(15,2) DEFAULT 0.00,
    status ENUM('OPEN', 'CLOSED') DEFAULT 'OPEN',
    opened_at_unix BIGINT(20),
    closed_at_unix BIGINT(20),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (agent_id) REFERENCES agents(id)
) ENGINE=InnoDB;

-- Temporary Saved Carts (Before Payment)
CREATE TABLE held_bills (
    id VARCHAR(50) PRIMARY KEY, -- e.g. HB-102293
    company_id INT,
    branch_id INT,
    agent_id INT,
    table_area VARCHAR(50), -- "Table 5"
    cart_json TEXT, -- JSON array of items
    client_json TEXT NULL,
    created_at_unix BIGINT(20),
    FOREIGN KEY (branch_id) REFERENCES branches(id)
) ENGINE=InnoDB;

CREATE TABLE sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    branch_id INT NOT NULL,
    agent_id INT NOT NULL,
    register_id INT NOT NULL,
    client_id INT NULL,
    gross_subtotal DECIMAL(15, 2),
    loyalty_discount DECIMAL(15, 2) DEFAULT 0.00,
    promo_discount DECIMAL(15, 2) DEFAULT 0.00,
    net_total DECIMAL(15, 2),
    payment_method ENUM('Cash', 'Mobile Money', 'Card') DEFAULT 'Cash',
    sync_status TINYINT(1) DEFAULT 0,
    created_at_unix BIGINT(20) NOT NULL,
    FOREIGN KEY (company_id) REFERENCES companies(id),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (agent_id) REFERENCES agents(id),
    FOREIGN KEY (register_id) REFERENCES registers(id)
) ENGINE=InnoDB;

CREATE TABLE sale_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    product_id INT NOT NULL,
    qty INT NOT NULL,
    unit_price_at_sale DECIMAL(15, 2),
    unit_cost_at_sale DECIMAL(15, 2), -- Important for Historical P&L
    FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES inventory(id)
) ENGINE=InnoDB;

-- ==========================================================
-- 7. OPERATIONS, LOSS & TARGETS
-- ==========================================================

CREATE TABLE damages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    branch_id INT NOT NULL,
    product_id INT NOT NULL,
    qty_damaged INT,
    cost_impact DECIMAL(15, 2),
    reason ENUM('Breakage', 'Spoilage', 'Expired', 'Theft', 'Other'),
    created_at_unix BIGINT(20),
    FOREIGN KEY (company_id) REFERENCES companies(id),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (product_id) REFERENCES inventory(id)
) ENGINE=InnoDB;

CREATE TABLE expenses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    branch_id INT NOT NULL,
    agent_id INT NOT NULL,
    supervisor_id INT, -- Who authorized it
    category ENUM('Utilities', 'Salaries', 'Maintenance', 'Supplies', 'Other'),
    amount DECIMAL(15, 2),
    description TEXT,
    created_at_unix BIGINT(20),
    FOREIGN KEY (company_id) REFERENCES companies(id),
    FOREIGN KEY (branch_id) REFERENCES branches(id)
) ENGINE=InnoDB;

CREATE TABLE branch_targets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    daily_revenue_target DECIMAL(15,2),
    daily_customer_target INT,
    month_year VARCHAR(10), -- e.g. "02-2026"
    FOREIGN KEY (branch_id) REFERENCES branches(id)
) ENGINE=InnoDB;

-- ==========================================================
-- 8. REPORTING VIEWS (VIRTUAL TABLES)
-- ==========================================================

-- A. Daily Revenue Summary
CREATE VIEW report_daily_revenue AS
SELECT 
    company_id,
    branch_id,
    FROM_UNIXTIME(created_at_unix, '%Y-%m-%d') AS report_date,
    SUM(net_total) AS revenue,
    COUNT(id) AS txn_count
FROM sales
GROUP BY company_id, branch_id, report_date;

-- B. Inventory Valuation (Assets on Shelf)
CREATE VIEW report_inventory_valuation AS
SELECT 
    company_id,
    branch_id,
    SUM(stock_qty * buying_price) AS total_cost_value,
    SUM(stock_qty * selling_price) AS total_sales_value
FROM inventory
WHERE product_type = 'STOCKABLE'
GROUP BY company_id, branch_id;

-- C. Agent Performance vs Targets
CREATE VIEW report_agent_performance AS
SELECT 
    a.id AS agent_id,
    a.full_name,
    b.branch_name,
    COALESCE(SUM(s.net_total), 0) AS actual_revenue,
    bt.daily_revenue_target,
    (COALESCE(SUM(s.net_total), 0) / NULLIF(bt.daily_revenue_target, 0)) * 100 AS achievement_perc
FROM agents a
JOIN branches b ON a.branch_id = b.id
LEFT JOIN sales s ON a.id = s.agent_id AND FROM_UNIXTIME(s.created_at_unix, '%Y-%m-%d') = CURDATE()
LEFT JOIN branch_targets bt ON b.id = bt.branch_id
GROUP BY a.id, b.branch_name, bt.daily_revenue_target;

-- D. Master Profit & Loss (Revenue - COGS - Expenses - Damages)
CREATE VIEW report_profit_loss AS
SELECT 
    s.company_id,
    s.branch_id,
    FROM_UNIXTIME(s.created_at_unix, '%Y-%m-%d') AS report_date,
    SUM(s.net_total) AS revenue,
    -- COGS
    (SELECT SUM(si.qty * si.unit_cost_at_sale) 
     FROM sale_items si JOIN sales s2 ON si.sale_id = s2.id 
     WHERE s2.company_id = s.company_id AND FROM_UNIXTIME(s2.created_at_unix, '%Y-%m-%d') = report_date) AS cost_of_goods,
    -- Expenses
    COALESCE((SELECT SUM(amount) FROM expenses WHERE company_id = s.company_id AND FROM_UNIXTIME(created_at_unix, '%Y-%m-%d') = report_date), 0) AS total_expenses,
    -- Damages
    COALESCE((SELECT SUM(cost_impact) FROM damages WHERE company_id = s.company_id AND FROM_UNIXTIME(created_at_unix, '%Y-%m-%d') = report_date), 0) AS total_damages,
    -- Net Profit
    (SUM(s.net_total) - 
     (COALESCE((SELECT SUM(si.qty * si.unit_cost_at_sale) FROM sale_items si JOIN sales s2 ON si.sale_id = s2.id WHERE s2.company_id = s.company_id AND FROM_UNIXTIME(s2.created_at_unix, '%Y-%m-%d') = report_date), 0) + 
      COALESCE((SELECT SUM(amount) FROM expenses WHERE company_id = s.company_id AND FROM_UNIXTIME(created_at_unix, '%Y-%m-%d') = report_date), 0) +
      COALESCE((SELECT SUM(cost_impact) FROM damages WHERE company_id = s.company_id AND FROM_UNIXTIME(created_at_unix, '%Y-%m-%d') = report_date), 0)
     )) AS net_profit
FROM sales s
GROUP BY s.company_id, s.branch_id, report_date;


CREATE TABLE target_configs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT NOT NULL,
    branch_id INT NOT NULL,
    rev_target_val DECIMAL(15, 2) DEFAULT 1000000, -- e.g. 1M UGX
    cust_target_val INT DEFAULT 50,               -- Your '50' from JS
    rev_weight_pct INT DEFAULT 60,                -- e.g. 60%
    cust_weight_pct INT DEFAULT 40,               -- e.g. 40%
    updated_at_unix BIGINT(20),
    FOREIGN KEY (branch_id) REFERENCES branches(id)
) ENGINE=InnoDB;

COMMIT;