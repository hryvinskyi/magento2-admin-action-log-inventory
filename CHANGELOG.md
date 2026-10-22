# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-10-22

### Added
- A product's quantity and stock status in each source (`quantity`, `source_status`) are recorded as changes of the
  product, in the source's scope, through the Admin Action Log's entity change recorder.
- Assigning a product to a source records its stock set from nothing; unassigning records it emptied.
- Recorded for every write through the source item save and deletion (the product form, the classic stock item's
  copy to the default source, the REST endpoints, the import) and through the bulk assignment, unassignment and
  transfers.
- The fields are shown as "Quantity" (without padding zeros) and "Stock Status" (In Stock / Out of Stock), and found
  by those labels.
- The classic stock item no longer records the quantity and status of a product that keeps its stock in sources, so
  no change is recorded twice; its Advanced Inventory settings still are.
- A quantity or status changed through the product form is recorded as the admin's change, never as one set
  automatically.
- The changes of one write are recorded together (`recordAll()`), so the products they name are read in one query;
  each product in each source is recorded on its own, and one that fails is logged without dropping the rest.
- The stock item part this module puts in place of the Admin Action Log's tells its model class and its state after a
  change, as the part it decorates does.
- The README says who a bulk or queued write is credited to: `cli`, the consumer's process.
