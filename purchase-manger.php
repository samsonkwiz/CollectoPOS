<?php

class PurchaseManager {
    private $db;
    private $inventoryManager; 
    private $companyId;
    private $branchId;
    private $managerId;
    private $apiUrl;
    private $uploadDir = "assets/docs/purchases/";

    public function __construct($db_conn, $inventory_manager, $company_id, $branch_id, $manager_id, $api_url = "") {
        $this->db = $db_conn;
        $this->inventoryManager = $inventory_manager; 
        $this->companyId = $company_id;
        $this->branchId = $branch_id;
        $this->managerId = $manager_id;
        $this->apiUrl = rtrim($api_url, '/');

        if (!is_dir($this->uploadDir)) mkdir($this->uploadDir, 0777, true);
    }

    private function res($status, $message, $data = null) {
        return ['status' => $status, 'message' => $message, 'data' => $data];
    }

    private function handleFileUpload($file, $prefix) {
        if (!$file || !isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) return null;
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = $prefix . "_" . time() . "_" . uniqid() . "." . $ext;
        $target = $this->uploadDir . $filename;
        return move_uploaded_file($file['tmp_name'], $target) ? $target : null;
    }

    // ==========================================================
    // 1. SUPPLIER CRUD
    // ==========================================================

    public function getSuppliers($search = "") {
        $sql = "SELECT * FROM suppliers WHERE company_id = ? AND is_deleted = 0";
        if ($search) $sql .= " AND (supplier_name LIKE '%$search%' OR contact_person LIKE '%$search%')";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $this->companyId);
        $stmt->execute();
        return $this->res(true, "Suppliers fetched", $stmt->get_result()->fetch_all(MYSQLI_ASSOC));
    }

    public function saveSupplier($data) {
        $id = $data['id'] ?? null;
        $now = time();
        if ($id) {
            $stmt = $this->db->prepare("UPDATE suppliers SET supplier_name=?, contact_person=?, phone=?, email=?, updated_at_unix=?, sync_status=0 WHERE id=? AND company_id=?");
            $stmt->bind_param("ssssiii", $data['supplier_name'], $data['contact_person'], $data['phone'], $data['email'], $now, $id, $this->companyId);
        } else {
            $stmt = $this->db->prepare("INSERT INTO suppliers (company_id, supplier_name, contact_person, phone, email, updated_at_unix) VALUES (?,?,?,?,?,?)");
            $stmt->bind_param("issssi", $this->companyId, $data['supplier_name'], $data['contact_person'], $data['phone'], $data['email'], $now);
        }
        return $stmt->execute() ? $this->res(true, "Supplier saved") : $this->res(false, "Error saving supplier");
    }

    // ==========================================================
    // 2. PURCHASE INVOICE (STOCK-IN)
    // ==========================================================

    public function createPurchaseInvoice($supplier_id, $invoice_no, $items, $paid_amount = 0, $file = null, $notes = "") {
        try {
            $this->db->begin_transaction();
            $now = time();
            $total_amount = 0; $stock_adjustments = [];

            foreach ($items as $item) {
                $total_amount += ($item['qty'] * $item['buying_price']);
                $stock_adjustments[] = [
                    'product_id' => $item['product_id'],
                    'qty' => $item['qty'],
                    'reason' => "PURCHASE_INV: $invoice_no (Manager: {$this->managerId})"
                ];
            }

            $due = $total_amount - $paid_amount;
            $status = ($due <= 0) ? 'PAID' : ($paid_amount > 0 ? 'PARTIAL' : 'DUE');
            $path = $this->handleFileUpload($file, "INV");

            $stmt = $this->db->prepare("INSERT INTO purchase_invoices (company_id, branch_id, manager_id, supplier_id, invoice_no, total_amount, paid_amount, due_amount, payment_status, attachment_path, notes, created_at_unix) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param("iiiisdddsssi", $this->companyId, $this->branchId, $this->managerId, $supplier_id, $invoice_no, $total_amount, $paid_amount, $due, $status, $path, $notes, $now);
            $stmt->execute();
            $p_id = $this->db->insert_id;

            foreach ($items as $item) {
                $stmt = $this->db->prepare("INSERT INTO purchase_invoice_items (purchase_id, product_id, qty, buying_price) VALUES (?,?,?,?)");
                $stmt->bind_param("iiid", $p_id, $item['product_id'], $item['qty'], $item['buying_price']);
                $stmt->execute();
            }

            $this->db->query("UPDATE suppliers SET total_balance = total_balance + $due, updated_at_unix = $now, sync_status = 0 WHERE id = $supplier_id");
            $this->inventoryManager->adjustStock($stock_adjustments);

            $this->db->commit();
            return $this->res(true, "Purchase logged");
        } catch (Exception $e) { $this->db->rollback(); return $this->res(false, $e->getMessage()); }
    }

    // ==========================================================
    // 3. RETURNS & DAMAGES
    // ==========================================================

    public function createReturn($supplier_id, $items, $refund = 0, $file = null, $reason = "") {
        try {
            $this->db->begin_transaction();
            $now = time(); $path = $this->handleFileUpload($file, "RET");
            
            $stmt = $this->db->prepare("INSERT INTO purchase_returns (company_id, branch_id, manager_id, supplier_id, total_refunded_amount, attachment_path, reason, created_at_unix) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->bind_param("iiiiidss", $this->companyId, $this->branchId, $this->managerId, $supplier_id, $refund, $path, $reason, $now);
            $stmt->execute();
            
            $adjustments = [];
            foreach ($items as $item) {
                $adjustments[] = ['product_id' => $item['product_id'], 'qty' => ($item['qty'] * -1), 'reason' => "RETURN_TO_SUPPLIER (Manager: {$this->managerId})"];
            }

            if ($refund > 0) $this->db->query("UPDATE suppliers SET total_balance = total_balance - $refund WHERE id = $supplier_id");
            $this->inventoryManager->adjustStock($adjustments);

            $this->db->commit(); return $this->res(true, "Return completed");
        } catch (Exception $e) { $this->db->rollback(); return $this->res(false, $e->getMessage()); }
    }

    public function recordDamage($product_id, $qty, $reason, $notes = "", $file = null) {
        try {
            $this->db->begin_transaction();
            $now = time(); $path = $this->handleFileUpload($file, "DMG");
            $stmt = $this->db->prepare("INSERT INTO inventory_damages (company_id, branch_id, manager_id, product_id, qty, reason, notes, attachment_path, created_at_unix) VALUES (?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param("iiiiisssi", $this->companyId, $this->branchId, $this->managerId, $product_id, $qty, $reason, $notes, $path, $now);
            $stmt->execute();
            $this->inventoryManager->adjustStock([['product_id' => $product_id, 'qty' => ($qty * -1), 'reason' => "DAMAGE: $reason"]]);
            $this->db->commit(); return $this->res(true, "Damage recorded");
        } catch (Exception $e) { $this->db->rollback(); return $this->res(false, $e->getMessage()); }
    }

    // ==========================================================
    // 4. SYNC ENGINE
    // ==========================================================

    public function runFullPurchaseSync() {
        if (empty($this->apiUrl)) return $this->res(false, "Offline");
        $tables = ['suppliers' => '/sync_suppliers', 'purchase_invoices' => '/sync_purchases', 'supplier_payments' => '/sync_payments', 'purchase_returns' => '/sync_returns', 'inventory_damages' => '/sync_damages'];
        foreach ($tables as $t => $e) $this->syncTable($t, $e);
        return $this->res(true, "Sync Complete");
    }

    private function syncTable($table, $endpoint) {
        $stmt = $this->db->prepare("SELECT * FROM $table WHERE company_id = ? AND sync_status = 0");
        $stmt->bind_param("i", $this->companyId); $stmt->execute();
        $records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        if (!empty($records)) {
            $post = ['payload' => json_encode($records)];
            foreach ($records as $i => $r) {
                $fKey = (isset($r['attachment_path'])) ? 'attachment_path' : ((isset($r['receipt_path'])) ? 'receipt_path' : null);
                if ($fKey && file_exists($r[$fKey])) $post['file_'.$i] = new CURLFile($r[$fKey]);
            }
            if ($this->callApi($endpoint . "/push", $post)['status']) {
                $ids = implode(',', array_column($records, 'id'));
                $this->db->query("UPDATE $table SET sync_status = 1 WHERE id IN ($ids)");
            }
        }
    }

    private function callApi($end, $data) {
        $ch = curl_init($this->apiUrl . $end); curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); $res = curl_exec($ch); curl_close($ch);
        return json_decode($res, true) ?? ['status' => false];
    }
}