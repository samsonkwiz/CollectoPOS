# CollectoPOS

This is the definitive database architecture for the Collecto POS. It supports multi-tenant SaaS operations, complex inventory types, agent wallet tracking, and automated financial auditing.

## 🏛 System Architecture

### 1. Multi-Tenancy
- **Isolation:** Every table includes `company_id`.
- **Branches:** `branches` table allows one company to manage multiple locations.
- **Users:** `agents` are unique by `(company_id, username)`.

### 2. Financial Integrity
- **Agent Wallets:** `assigned_value` tracks the credit/cash an agent holds. It decreases automatically upon sale.
- **Value History:** `agent_value_history` logs *every* change to an agent's balance for audit purposes.
- **Registers:** `registers` table manages shift openings and closings to track physical cash vs expected system cash.

### 3. Inventory & Pricing
- **Hybrid Types:** `product_type` separates `STOCKABLE` (Physical) from `SERVICE` (Sauna/Massage).
- **Price Rules:** `price_rules` allows automatic price switching based on the Day of Week (e.g., Friday Happy Hour) and Time.
- **Supply Chain:** `purchases`, `purchase_items`, and `returns` track stock movement in and out.

### 4. POS Features
- **Held Bills:** `held_bills` table allows agents to save a cart to JSON while a customer retrieves money.
- **CRM:** `clients` and `loyalty_settings` handle points and customer data.
- **Promos:** `promos` and `promo_branches` allow you to set discounts that only apply to specific branches.

### 5. Automated Reporting (Views)
- **Profit & Loss:** `report_profit_loss` auto-calculates Net Profit = Revenue - (COGS + Expenses + Damages).
- **Valuation:** `report_inventory_valuation` shows current asset value on shelves.
- **Performance:** `report_agent_performance` compares real-time sales against `branch_targets`.

## ⚙️ Usage Guide

1. **Import:** Run `master_schema.sql` in your MySQL database.
2. **Timestamps:** Ensure your application writes `time()` (Unix Timestamp) to all `_unix` columns.
3. **Sync:** Your local sync script should grab all rows where `sync_status = 0` (in `sales`) and push them to the cloud.