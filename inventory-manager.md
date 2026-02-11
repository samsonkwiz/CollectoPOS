# 📦 Inventory Manager SDK

The `InventoryManager` is a high-performance PHP engine designed to manage retail inventory across local POS systems and cloud dashboards. It supports **offline-first** logic, **dynamic auditing**, and **bi-directional synchronization**.

## 🛠 1. Initialization

The class can run in **Standalone Mode** (Local only) or **Hybrid Mode** (Cloud Sync enabled).

```php
// Standard connection
$db = new mysqli("localhost", "user", "pass", "pos_db");

// Initialize Manager
$inv = new InventoryManager(
    $db,           // Database Connection
    1,             // Company ID
    101,           // Branch ID
    "https://api.yoursite.com" // Optional API URL (leave empty for Offline only)
);

```

---

## 🏷 2. Category Management

Manage the organizational structure of your shop.

### `getCategories()`

Fetches all active (non-deleted) categories for the current company.

```php
$categories = $inv->getCategories();

```

### `saveCategories($categories_array)`

Creates or updates categories.

```php
$inv->saveCategories([
    ['category_name' => 'Cold Drinks', 'category_photo' => 'assets/img/cat/drinks.png'],
    ['id' => 5, 'category_name' => 'Hot Coffee'] // Updates existing ID 5
]);

```

### `deleteCategories($ids)`

Soft-deletes categories by setting `is_deleted = 1`.

```php
$inv->deleteCategories([1, 2, 3]); // Deletes by ID list

```

---

## 🛒 3. Product Management

The core engine for item handling and dynamic pricing.

### `getProducts($filters)`

Retrieves products. If a **Price Rule** is active (e.g., a "Friday Special"), the `special_price` field will be automatically populated.

* **Available Filters:** `search` (name/sku), `category_id`.

```php
$products = $inv->getProducts(['search' => 'Latte', 'category_id' => 2]);

```

### `saveProducts($products_array)`

The most powerful method. It creates/updates products and automatically **audits** changes to sensitive fields like price.

```php
$inv->saveProducts([[
    'id' => 10, // Provide ID to update, omit to create
    'category_id' => 1,
    'sku' => 'PROD-001',
    'product_name' => 'Double Espresso',
    'product_type' => 'STOCKABLE', // or 'SERVICE'
    'buying_price' => 1200,
    'selling_price' => 2500,
    'is_active' => 1,
    'price_rules' => [ // Nested price rules
        [
            'day_of_week' => 'Friday', 
            'special_price' => 2000, 
            'start_time' => '08:00:00', 
            'end_time' => '12:00:00', 
            'is_active' => 1
        ]
    ]
]]);

```

### `deleteProducts($ids)`

Soft-deletes products.

```php
$inv->deleteProducts([10, 11, 12]);

```

---

## 📈 4. Stock Control & Intelligence

Real-time monitoring and valuation.

### `adjustStock($adjustments)`

Updates physical stock levels and creates a mandatory audit trail.

```php
$inv->adjustStock([
    ['product_id' => 10, 'qty' => 50, 'reason' => 'NEW_STOCK_ARRIVAL'],
    ['product_id' => 12, 'qty' => -2, 'reason' => 'DAMAGED_EXPIRED']
]);

```

### `getStockAlerts()`

Identifies `STOCKABLE` items that have reached or fallen below their `min_stock_level`.

```php
$alerts = $inv->getStockAlerts();

```

### `getInventorySummary()`

Generates total item counts and the total valuation of the warehouse (Stock Quantity × Buying Price).

```php
$stats = $inv->getInventorySummary();
// Output: ['items' => 450, 'value' => 12500000]

```

---

## 🔄 5. Synchronization Engine

This bridges the gap between your local POS and your Cloud Dashboard.

### `runFullSync()`

The primary method for cron jobs. It performs the following in order:

1. **Push Categories:** Sends locally changed categories to the cloud.
2. **Pull Categories:** Downloads new categories from the cloud.
3. **Push Price Rules:** Syncs Friday Specials/Happy Hours.
4. **Push Products:** Sends locally updated products and **uploads binary image files**.
5. **Pull Products:** Downloads new products and **auto-downloads missing images** from the cloud.

```php
// Recommended Cron Job (every 5 minutes)
$inv->runFullSync();

```

---

## 🔍 6. Understanding Audits

The system automatically populates the `inventory_audits` table whenever:

* Stock levels are adjusted (via `adjustStock`).
* Prices are changed (via `saveProducts`).
* Product names or statuses are updated.

This allows you to generate "History" reports for any specific product to see exactly who changed what and when.
