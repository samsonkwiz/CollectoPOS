<?php

class InventoryManager {
    private $db;
    private $apiUrl;
    private $companyId;
    private $branchId;
    private $prodDir = "assets/img/products/";
    private $catDir  = "assets/img/categories/";

    public function __construct($db_conn, $company_id, $branch_id, $api_url = "") {
        $this->db = $db_conn;
        $this->companyId = $company_id;
        $this->branchId = $branch_id;
        $this->apiUrl = rtrim($api_url, '/');
        
        // Ensure local image directories exist
        if (!is_dir($this->prodDir)) mkdir($this->prodDir, 0777, true);
        if (!is_dir($this->catDir)) mkdir($this->catDir, 0777, true);
    }

    private function res($status, $message, $data = null) {
        return ['status' => $status, 'message' => $message, 'data' => $data];
    }

    // ==========================================================
    // 1. PRODUCT CRUD & SEARCH
    // ==========================================================

    public function getProducts($filters = []) {
        try {
            $query = "SELECT i.*, c.category_name, 
                      pr.special_price, pr.day_of_week, pr.start_time, pr.end_time
                      FROM inventory i 
                      LEFT JOIN categories c ON i.category_id = c.id 
                      LEFT JOIN price_rules pr ON i.id = pr.inventory_id 
                        AND pr.is_active = 1 
                        AND (pr.day_of_week = DAYNAME(NOW()) OR pr.day_of_week = 'All')
                        AND CURRENT_TIME() BETWEEN pr.start_time AND pr.end_time
                      WHERE i.company_id = ? AND i.is_deleted = 0";
            
            $params = [$this->companyId];
            $types = "i";

            if (!empty($filters['search'])) {
                $query .= " AND (i.product_name LIKE ? OR i.sku LIKE ?)";
                $term = "%".$filters['search']."%";
                $params[] = $term; $params[] = $term;
                $types .= "ss";
            }
            if (!empty($filters['category_id'])) { $query .= " AND i.category_id = ?"; $params[] = $filters['category_id']; $types .= "i"; }

            $stmt = $this->db->prepare($query);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            return $this->res(true, "Products fetched", $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        } catch (Exception $e) { return $this->res(false, $e->getMessage()); }
    }

    public function saveProducts($products) {
        try {
            $this->db->begin_transaction();
            $now = time();
            foreach ($products as $p) {
                $id = $p['id'] ?? null;
                $photo = $p['product_photo'] ?? 'assets/img/products/default.png';

                if ($id) {
                    $old = $this->db->query("SELECT * FROM inventory WHERE id = $id")->fetch_assoc();
                    $stmt = $this->db->prepare("UPDATE inventory SET category_id=?, sku=?, product_name=?, product_type=?, buying_price=?, selling_price=?, product_photo=?, is_active=?, is_deleted=?, updated_at_unix=?, sync_status=0 WHERE id=? AND company_id=?");
                    $stmt->bind_param("isssddsiiiii", $p['category_id'], $p['sku'], $p['product_name'], $p['product_type'], $p['buying_price'], $p['selling_price'], $photo, $p['is_active'], $p['is_deleted'], $now, $id, $this->companyId);
                    $stmt->execute();
                    $this->auditChanges($id, $old, $p);
                } else {
                    $stmt = $this->db->prepare("INSERT INTO inventory (company_id, branch_id, category_id, sku, product_name, product_type, buying_price, selling_price, product_photo, is_active, is_deleted, updated_at_unix, sync_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 0)");
                    $stmt->bind_param("iiisssddsii", $this->companyId, $this->branchId, $p['category_id'], $p['sku'], $p['product_name'], $p['product_type'], $p['buying_price'], $p['selling_price'], $photo, $p['is_active'], $now);
                    $stmt->execute();
                    $id = $this->db->insert_id;
                }
                if (!empty($p['price_rules'])) $this->savePriceRules($id, $p['price_rules']);
            }
            $this->db->commit();
            return $this->res(true, "Products processed successfully");
        } catch (Exception $e) { $this->db->rollback(); return $this->res(false, $e->getMessage()); }
    }

    public function deleteProducts($ids) {
        $idList = implode(',', array_map('intval', (array)$ids));
        $now = time();
        $sql = "UPDATE inventory SET is_deleted = 1, updated_at_unix = $now, sync_status = 0 WHERE id IN ($idList) AND company_id = {$this->companyId}";
        return $this->db->query($sql) ? $this->res(true, "Products soft-deleted") : $this->res(false, "Delete failed");
    }

    // ==========================================================
    // 2. CATEGORY CRUD
    // ==========================================================

    public function getCategories() {
        $stmt = $this->db->prepare("SELECT * FROM categories WHERE company_id = ? AND is_deleted = 0");
        $stmt->bind_param("i", $this->companyId);
        $stmt->execute();
        return $this->res(true, "Categories fetched", $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    }

    public function saveCategories($categories) {
        try {
            $this->db->begin_transaction();
            $now = time();
            foreach ($categories as $c) {
                $id = $c['id'] ?? null;
                $photo = $c['category_photo'] ?? 'assets/img/categories/default.png';
                $is_del = $c['is_deleted'] ?? 0;
                if ($id) {
                    $stmt = $this->db->prepare("UPDATE categories SET category_name=?, category_photo=?, is_deleted=?, updated_at_unix=?, sync_status=0 WHERE id=? AND company_id=?");
                    $stmt->bind_param("ssiiii", $c['category_name'], $photo, $is_del, $now, $id, $this->companyId);
                } else {
                    $stmt = $this->db->prepare("INSERT INTO categories (company_id, category_name, category_photo, is_deleted, updated_at_unix, sync_status) VALUES (?, ?, ?, ?, ?, 0)");
                    $stmt->bind_param("issii", $this->companyId, $c['category_name'], $photo, $is_del, $now);
                }
                $stmt->execute();
            }
            $this->db->commit();
            return $this->res(true, "Categories saved");
        } catch (Exception $e) { $this->db->rollback(); return $this->res(false, $e->getMessage()); }
    }

    public function deleteCategories($ids) {
        $idList = implode(',', array_map('intval', (array)$ids));
        $now = time();
        $sql = "UPDATE categories SET is_deleted = 1, updated_at_unix = $now, sync_status = 0 WHERE id IN ($idList) AND company_id = {$this->companyId}";
        return $this->db->query($sql) ? $this->res(true, "Categories soft-deleted") : $this->res(false, "Delete failed");
    }

    // ==========================================================
    // 3. STOCK ALERTS & SUMMARY
    // ==========================================================

    public function getStockAlerts() {
        $sql = "SELECT i.*, c.category_name FROM inventory i LEFT JOIN categories c ON i.category_id = c.id 
                WHERE i.company_id = ? AND i.product_type = 'STOCKABLE' AND i.stock_qty <= i.min_stock_level AND i.is_deleted = 0";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $this->companyId);
        $stmt->execute();
        return $this->res(true, "Alerts found", $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    }

    public function getInventorySummary() {
        $sql = "SELECT COUNT(*) as items, SUM(stock_qty * buying_price) as value FROM inventory WHERE company_id = ? AND is_deleted = 0";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $this->companyId);
        $stmt->execute();
        return $this->res(true, "Summary generated", $stmt->get_result()->fetch_assoc());
    }

    public function adjustStock($adjustments) {
        try {
            $this->db->begin_transaction();
            $now = time();
            foreach ($adjustments as $adj) {
                $pid = $adj['product_id']; $qty = $adj['qty']; $reason = $adj['reason'] ?? 'ADJUST';
                $old = $this->db->query("SELECT stock_qty FROM inventory WHERE id = $pid")->fetch_assoc();
                $new = $old['stock_qty'] + $qty;
                $this->db->query("UPDATE inventory SET stock_qty = $new, updated_at_unix = $now, sync_status = 0 WHERE id = $pid");
                $this->db->query("INSERT INTO inventory_audits (company_id, branch_id, product_id, action_type, field_changed, old_value, new_value, change_reason, created_at_unix) 
                    VALUES ({$this->companyId}, {$this->branchId}, $pid, 'STOCK_CHANGE', 'stock_qty', '{$old['stock_qty']}', '$new', '$reason', $now)");
            }
            $this->db->commit(); return $this->res(true, "Stock updated");
        } catch (Exception $e) { $this->db->rollback(); return $this->res(false, $e->getMessage()); }
    }

    // ==========================================================
    // 4. PRICE RULES & AUDITING UTILS
    // ==========================================================

    public function savePriceRules($prod_id, $rules) {
        $now = time();
        foreach ($rules as $r) {
            $stmt = $this->db->prepare("INSERT INTO price_rules (inventory_id, company_id, branch_id, day_of_week, special_price, start_time, end_time, is_active, updated_at_unix, sync_status) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0) ON DUPLICATE KEY UPDATE special_price=VALUES(special_price), sync_status=0");
            $stmt->bind_param("iiisdssii", $prod_id, $this->companyId, $this->branchId, $r['day_of_week'], $r['special_price'], $r['start_time'], $r['end_time'], $r['is_active'], $now);
            $stmt->execute();
        }
    }

    private function auditChanges($pid, $old, $new) {
        $fields = ['selling_price', 'buying_price', 'product_name', 'is_active', 'category_id'];
        foreach ($fields as $f) {
            if (isset($new[$f]) && $old[$f] != $new[$f]) {
                $stmt = $this->db->prepare("INSERT INTO inventory_audits (company_id, branch_id, product_id, action_type, field_changed, old_value, new_value, change_reason, created_at_unix) VALUES (?,?,?,?,?,?,?,?,?)");
                $act = 'INFO_UPDATE'; $now = time(); $reason = "Manual Update";
                $stmt->bind_param("iiiissssi", $this->companyId, $this->branchId, $pid, $act, $f, $old[$f], $new[$f], $reason, $now);
                $stmt->execute();
            }
        }
    }

    // ==========================================================
    // 5. THE SYNC ENGINE (HYBRID)
    // ==========================================================

    public function runFullSync() {
        if (empty($this->apiUrl)) return $this->res(false, "Sync ignored: Standalone mode.");
        $this->syncGenericTable('categories', '/sync_categories');
        $this->syncGenericTable('price_rules', '/sync_pricing');
        return $this->syncProducts();
    }

    private function syncProducts() {
        $stmt = $this->db->prepare("SELECT * FROM inventory WHERE company_id = ? AND sync_status = 0 LIMIT 20");
        $stmt->bind_param("i", $this->companyId); $stmt->execute();
        $records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        if (!empty($records)) {
            $post = ['company_id' => $this->companyId, 'payload' => json_encode($records)];
            foreach ($records as $i => $r) { if (file_exists($r['product_photo'])) $post['file_'.$i] = new CURLFile($r['product_photo']); }
            if ($this->callApi('/sync_products/push', $post)['status']) {
                $ids = implode(',', array_column($records, 'id'));
                $this->db->query("UPDATE inventory SET sync_status = 1 WHERE id IN ($ids)");
            }
        }
        $pull = $this->callApi('/sync_products/pull', ['company_id' => $this->companyId, 'last_sync' => time()-86400]);
        if ($pull['status'] && !empty($pull['data'])) {
            foreach ($pull['data'] as $row) { $this->handleImageDownload($row['product_photo']); $this->upsertLocal('inventory', $row); }
        }
        return $this->res(true, "Sync done");
    }

    private function syncGenericTable($table, $endpoint) {
        $stmt = $this->db->prepare("SELECT * FROM $table WHERE company_id = ? AND sync_status = 0");
        $stmt->bind_param("i", $this->companyId); $stmt->execute();
        $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        if (!empty($data)) {
            if ($this->callApi($endpoint."/push", ['company_id'=>$this->companyId, 'payload'=>json_encode($data)])['status']) {
                $ids = implode(',', array_column($data, 'id'));
                $this->db->query("UPDATE $table SET sync_status = 1 WHERE id IN ($ids)");
            }
        }
        $pull = $this->callApi($endpoint."/pull", ['company_id'=>$this->companyId, 'last_sync'=>0]);
        if ($pull['status'] && !empty($pull['data'])) {
            foreach ($pull['data'] as $row) { 
                if (isset($row['category_photo'])) $this->handleImageDownload($row['category_photo']);
                $this->upsertLocal($table, $row); 
            }
        }
    }

    private function handleImageDownload($path) {
        if (empty($path) || file_exists($path) || !$this->apiUrl) return;
        $data = file_get_contents($this->apiUrl . "/" . $path);
        if ($data) file_put_contents($path, $data);
    }

    private function upsertLocal($table, $row) {
        $cols = array_keys($row);
        $updates = array_map(fn($c) => "$c=VALUES($c)", $cols);
        $sql = "INSERT INTO $table (".implode(',',$cols).", sync_status) VALUES (".implode(',',array_fill(0,count($cols),'?')).", 1) ON DUPLICATE KEY UPDATE ".implode(',',$updates).", sync_status=1";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param(str_repeat("s", count($row)), ...array_values($row));
        $stmt->execute();
    }

    private function callApi($end, $data) {
        $ch = curl_init($this->apiUrl . $end);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $res = curl_exec($ch); curl_close($ch);
        return json_decode($res, true) ?? ['status'=>false];
    }
}