
# 💰 Purchase Manager SDK

Complete procurement module with stock-in, returns, damage tracking, and automated inventory synchronization.

## 🚀 Setup
```php
$inventoryManager = new InventoryManager($db, $companyId, $branchId, $apiUrl);
$purchaseManager = new PurchaseManager($db, $inventoryManager, $companyId, $branchId, $managerId, $apiUrl);

```

## 🏷️ 1. Supplier CRUD

```php
// Add/Update
$purchaseManager->saveSupplier([
    'supplier_name' => 'Wholesale Spices',
    'contact_person' => 'John',
    'phone' => '07000000',
    'email' => 'john@spices.com'
]);

// Fetch
$suppliers = $purchaseManager->getSuppliers("Spices");

```

## 📝 2. Stock-In (Purchase Invoice)

Automatically increases physical stock and logs an audit trail in the inventory module.

```php
$items = [
    ['product_id' => 1, 'qty' => 50, 'buying_price' => 120.00]
];
$purchaseManager->createPurchaseInvoice(
    $supplierId, 
    "INV-1002", 
    $items, 
    5000,        // Paid amount
    $_FILES['doc'], 
    "Batch A"
);

```

## 🔄 3. Returns & Damages

Both operations automatically reduce physical stock and update supplier debt where applicable.

```php
// Return goods to supplier
$purchaseManager->createReturn($supplierId, $items, 200, $_FILES['slip'], "Expired");

// Write off damaged stock
$purchaseManager->recordDamage($productId, 5, 'BROKEN', "Dropped in warehouse");

```

## 🔄 4. Background Sync

Pushes all procurement data and file attachments to the cloud server.

```php
$purchaseManager->runFullPurchaseSync();

```