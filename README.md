# Magento 2 Admin Action Log: Inventory

Records the stock of products per source (Magento's Inventory, "MSI") in the
[Admin Action Log](https://github.com/hryvinskyi/magento2-admin-action-log): each change of a product's quantity or
stock status in a source becomes a change of the product, with the value before and after.

## What it records
- **A change of the product.** The change is recorded under the product's id and named the way the product's own
  changes are ("Product 24-MB01 · Joust Duffle Bag"), so it shows in the product's Activity panel and in a search
  for the product (`product: 24-MB01`).
- **In the source's scope.** The scope of the change is the source, labelled with the source's name ("Default
  Source"). `store: Default Source` on the Activity Log search page finds the changes of that source.
- **Two fields:**
  - `quantity`, shown as "Quantity" without padding zeros ("100", "4.5");
  - `source_status`, shown as "Stock Status" (In Stock / Out of Stock).
- **Assigning and unassigning.** Assigning a product to a source records both fields set from nothing; unassigning
  records them emptied. The product itself is neither created nor deleted by it.
- **The same rules as every entity change.** The Admin Action Log's settings apply: whether entity changes are
  recorded, the actors whose changes are recorded, the product's mode (changed fields or full snapshot) and retention,
  and the rule that a write that changes nothing records nothing.

`field: quantity` (or `field: Quantity`) finds these changes on the Activity Log search page, together with the
quantity of the product's classic stock item.

## When it records
A change is recorded when it goes through the inventory's services:
- the admin product form, its Sources section and its quantity;
- the classic stock item when Magento copies it to the default source: a product saved through the product
  repository with stock data, the REST endpoint `PUT /V1/products/{sku}/stockItems/{itemId}`,
  `StockRegistryInterface::updateStockItemBySku()` and `StockItemRepositoryInterface::save()`;
- the REST endpoints for source items (`POST /V1/inventory/source-items`, `POST /V1/inventory/source-items-delete`) and
  any code that calls `SourceItemsSaveInterface` or `SourceItemsDeleteInterface`;
- the inventory import (append, replace and delete);
- the quantity and stock status set for many products at once with the product grid's Update Attributes, which the
  inventory applies from the message queue;
- the admin mass actions Assign Inventory Source, Unassign Inventory Source and Transfer Inventory To Source, and the
  partial transfer (`BulkSourceAssignInterface`, `BulkSourceUnassignInterface`, `BulkInventoryTransferInterface`,
  `BulkPartialInventoryTransferInterface`), including when they run in the message queue.

The stored values are read before and after each write, and the difference is recorded. So a write that stores
what is already stored records nothing. The admin product form writes the default source twice in one save (with the
product's stock item, then again from the form's sources): the change is recorded once.

A change is recorded as soon as the write returns. Inside a transaction it is part of that transaction: a write that
is rolled back leaves no record. The changes of one write are recorded together: the products they name are read in
one query, and each product in each source is recorded on its own, so one that cannot be recorded is logged and the
rest of the write is still recorded.

## Who made the change
A write made while the admin waits (the product form, its Sources section, a source item saved through REST) is
recorded with the admin or integration that made it. A bulk or queued write runs later in a message queue consumer:
the Update Attributes quantity and status, and the mass actions Assign Inventory Source, Unassign Inventory Source
and Transfer Inventory To Source when they run in the queue. Its actor is `cli`, the consumer's process, not the admin
who started it, and nothing is recorded when `cli` is not among the actors chosen in **Record Changes Made By**. The
consumer processes the message without the admin's session, and the Admin Action Log credits a change to whoever runs
the code that makes it; the admin's own request to start the bulk action is recorded as an admin request.

## No change is recorded twice
The Admin Action Log also records the product's classic stock item. For a product that keeps its stock in sources
(a simple, virtual or downloadable product), the stock item only copies the default source's quantity and status.
This module replaces the stock item part of the Admin Action Log's tracked entity pool with one that leaves the
stock item's `qty` and `is_in_stock` out for those products. The stock item's own settings (Manage Stock, Backorders,
the thresholds and increments, each with "Use Config Settings") are still recorded from it. For a configurable or
bundle product, whose stock follows its children, the stock item records its quantity and status as before.

## Never "set automatically"
The Admin Action Log marks a change a form save did not ask for as set automatically. The quantity and status of a
source are not attributes of the product, and the product form sends them under other names (per source, or as the
classic stock quantity), so a quantity typed into the form is always recorded as asked for, never marked.

## Not recorded
- The quantity an order's shipment deducts from a source: the inventory writes it with plain SQL. Orders reserve
  stock rather than change it, and the reservations are not recorded either.
- Stock changed with plain SQL by any other code.

## Requirements
- Magento 2.4 with Inventory (MSI) and PHP 8.1 or later
- [`hryvinskyi/magento2-admin-action-log`](https://github.com/hryvinskyi/magento2-admin-action-log) ^1.1

## Installation
```bash
composer require hryvinskyi/magento2-admin-action-log-inventory
bin/magento module:enable Hryvinskyi_AdminActionLogInventory
bin/magento setup:upgrade
```

No configuration of its own: the Admin Action Log's settings under **Stores › Configuration › Hryvinskyi › Admin
Action Log** apply.

## Known limits
- A source is a scope of its own, not a store view. With "All store views" switched off, a product's Activity panel
  shows the changes of the store view being edited and of the default scope, so per-source changes are not among
  them. The Activity Log search page shows them all.
- Each changed product in each source is one change: a bulk assignment of many products writes as many changes, though
  the products are named from one read.
- A bulk or queued write is credited to `cli`, not to the admin who started it (see *Who made the change*).

## License
MIT. See [LICENSE](LICENSE).
