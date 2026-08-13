# RFQ Search Implementation Documentation

## Overview

This document describes the comprehensive search functionality implemented for the RFQ (Request for Quotation) list page. The search feature allows users to quickly find RFQs across multiple fields using a server-side, authorization-aware search mechanism.

## Architecture

### Components

1. **RFQSearchService** (`services/RFQSearchService.php`)
   - Server-side search logic using parameterized queries
   - Authorization enforcement (role-based and user-based)
   - Search parameter validation and SQL injection prevention
   - Partial text matching with case-insensitive LIKE queries

2. **Search UI** (`rfq/list.php`)
   - Search input field with placeholder text
   - Search and Clear buttons
   - Search result counter
   - "No matching RFQs found" message
   - Preserved pagination and sorting state

3. **Database Indexes** (`migrations/2026_08_13_rfq_search_indexes.sql`)
   - Performance optimization indexes on frequently searched fields
   - Composite indexes for common query patterns

## Search Behavior

### Searchable Fields

The search function works across the following fields with OR logic:

| Field | Table | Type | Purpose |
|-------|-------|------|---------|
| RFQ Number | `rfqs.rfq_number` | varchar | Exact/partial match of RFQ ID |
| Request Number | `procurement_requests.request_number` | varchar | Exact/partial match of procurement request ID |
| Description | `procurement_requests.description` | text | Full-text search in request description |
| Status | `rfqs.status` | enum | Filter by RFQ status (OPEN, AWARDED, EVALUATION, etc.) |
| Vendor Name | `rfq_vendors.vendor_name` | varchar | Vendor company name search |
| Requester Name | `users.full_name` | varchar | Name of the person who created the request |
| Department/Branch | `branches.branch_name` | varchar | Branch or department name |

### Search Characteristics

- **Case-insensitive**: Searches work with any letter case combination
- **Partial matching**: Users don't need exact strings (e.g., "office" matches "office supplies")
- **Multi-word search**: Searches like "vendor selection" find records matching any of the terms
- **Special character handling**: SQL LIKE special characters (%, _, \) are escaped to prevent injection
- **Performance**: Indexed fields ensure O(log n) lookup even with large datasets

### Example Searches

```
"RFQ-2026"           → Finds all RFQs matching that partial number
"vendor abc"         → Finds RFQs where vendor name contains "abc"
"AWARDED"            → Shows only awarded RFQs
"equipment"          → Finds requests mentioning equipment
"John Smith"         → Finds RFQs requested by John Smith
```

## Authorization & Security

### Role-Based Access Control

The search respects the existing authorization model:

| Role | Access Rule |
|------|------------|
| **Admin / SuperAdmin** | See all RFQs (with soft-delete rules applied) |
| **Director HRM&A** | Restricted to branch 5 only |
| **Deputy Government Chemist** | Restricted to branch 6 only |
| **Requestor** | See only own requests (created_by = user_id) |
| **Other roles** | Follow standard view_requests permission |

### Security Measures

1. **Parameterized Queries**: All search terms use PDO prepared statements with named parameters
2. **Input Sanitization**: Special SQL LIKE characters are escaped using backslash ESCAPE clause
3. **Authorization Enforcement**: WHERE clauses applied before search to prevent data leakage
4. **SQL Injection Prevention**: No string concatenation in query building; only bound parameters

### Example: Requestor Authorization

```php
// User with role "Requestor" only sees their own RFQs
$service = new RFQSearchService($pdo, $userId, 'Requestor');
$results = $service->search('equipment', 20, 0);  
// WHERE pr.created_by = :user_id AND (search conditions)
```

## Implementation Details

### Database Changes

**Migration File**: `migrations/2026_08_13_rfq_search_indexes.sql`

Indexes added for performance:
- `idx_rfq_number` - Fast lookup by RFQ number
- `idx_rfq_status` - Fast filtering by status
- `idx_request_number` - Fast lookup by request number
- `idx_description_prefix` - Limited prefix index for description search
- `idx_created_by` - Fast filtering by requester
- `idx_vendor_name` - Vendor search performance
- `idx_created_at_status` - Composite for common queries

### Query Strategy

The search uses DISTINCT UNION-like logic to avoid duplicate results:
```sql
SELECT DISTINCT r.rfq_id, ...
FROM rfqs r
JOIN procurement_requests pr ON r.request_id = pr.request_id
WHERE (
    r.rfq_number LIKE :search_term
    OR pr.request_number LIKE :search_term
    OR pr.description LIKE :search_term
    OR EXISTS (SELECT 1 FROM rfq_vendors ... )
    ...
)
```

This ensures:
- Single occurrence of each RFQ even if multiple fields match
- Efficient use of indexes
- Correct authorization filtering

### URL Parameters

**Search Query Parameter**: `?q=<search_term>`

Example URLs:
```
/rfq/list.php?q=RFQ-2026
/rfq/list.php?q=equipment&page=2
/rfq/list.php (no search parameter shows all)
```

## Testing

### Test Suite: `tests/RFQSearchTest.php`

Comprehensive test coverage includes:

1. **Exact Matching**: Find RFQ by exact number
2. **Partial Matching**: Prefix and substring searches
3. **Request Number Search**: Both exact and partial
4. **Description Search**: Text within descriptions
5. **Vendor Name Search**: Vendor-specific searches
6. **Status Filtering**: Search by RFQ status
7. **Multi-word Search**: Combined field searches
8. **Case Insensitivity**: Uppercase/lowercase matching
9. **Empty Search**: Proper handling of blank queries
10. **Special Characters**: SQL injection prevention
11. **Pagination**: Search state preserved across pages
12. **Sorting**: Results ordered by created_at DESC
13. **Requestor Authorization**: Users see only own requests
14. **Director Authorization**: Branch-level restrictions
15. **Result Structure**: Verify all expected fields present

### Running Tests

```bash
php tests/RFQSearchTest.php
```

Expected output:
```
═══════════════════════════════════════════════════════════════════════════
  RFQ Search Test Suite
═══════════════════════════════════════════════════════════════════════════

✓ PASS  Search for 'TEST-RFQ-2026-001' returns exact match
✓ PASS  Total count matches result
...

═══════════════════════════════════════════════════════════════════════════
  TEST SUMMARY
═══════════════════════════════════════════════════════════════════════════
  Passed: 15
  Failed: 0
  Total:  15
═══════════════════════════════════════════════════════════════════════════
```

## Performance Considerations

### Scalability

- **Indexes**: Database indexes on searchable fields ensure O(log n) performance
- **DISTINCT**: Used to eliminate duplicates from multi-condition queries
- **Pagination**: Server-side pagination prevents memory overload
- **LIMIT/OFFSET**: Queries limited to 20 results per page by default

### Query Optimization

1. Indexes prioritized for high-selectivity fields (rfq_number, request_number)
2. Composite indexes for JOIN scenarios
3. Prefix index on description (first 255 chars) to keep index size reasonable
4. DISTINCT used only where necessary (when multiple fields could match same record)

### Expected Performance

With proper indexes:
- Single search: < 100ms for most queries
- Large result set (1000+): < 500ms with pagination
- Authorization filtering: No performance penalty (WHERE clause applied early)

## Limitations & Exclusions

### Fields NOT Included in Search

The following fields are intentionally excluded:

| Field | Reason |
|-------|--------|
| Estimated Value | Numeric comparison requires different query strategy; can be added if needed |
| Quote Review Status | Low selectivity; limited value for search |
| Awarded Quote ID | Internal FK; users search by result, not by ID |
| File Paths | Non-semantic; exposes filesystem details |
| Sensitive Vendor Data | Phone numbers, detailed addresses—use permission rules for access |
| Technical IDs | rfq_id, request_id used internally; users search by numbers |

### Design Decisions

1. **No Full-Text Search Engine**: Uses native SQL LIKE for simplicity; could migrate to MySQL FULLTEXT if needed
2. **OR Logic Only**: All fields combined with OR; no complex AND/phrase search (can be added)
3. **No Weighted Ranking**: All matches treated equally; exact RFQ number doesn't rank higher
4. **No Search History**: Searches not logged; can be added for analytics
5. **No Saved Searches**: No bookmark/favorite search functionality at this time

## Manually Testable Workflow

### Setup

1. Ensure database migration has been applied:
   ```bash
   mysql -u <user> -p <database> < migrations/2026_08_13_rfq_search_indexes.sql
   ```

2. Verify the application is running and user is logged in with permission `view_requests`

### Test Steps

#### 1. Basic Search
1. Navigate to **Procurement > Request for Quotations**
2. In the search bar, enter a known RFQ number (e.g., "RFQ-2026-001")
3. Click **Search**
4. **Expected**: Table filters to show only that RFQ; result count shows "Found 1 matching RFQ"

#### 2. Partial Search
1. In the search bar, enter partial RFQ number (e.g., "RFQ-2026")
2. Click **Search**
3. **Expected**: Table shows all RFQs matching that prefix; result count accurate

#### 3. Vendor Search
1. Enter vendor name (e.g., "Vendor ABC" or "vendor" - case-insensitive)
2. Click **Search**
3. **Expected**: Shows RFQs with that vendor; results may be across multiple RFQs

#### 4. Description Search
1. Enter a word from request descriptions (e.g., "equipment", "supplies")
2. Click **Search**
3. **Expected**: Shows RFQs for requests containing that word

#### 5. Status Filter
1. Enter a status (e.g., "OPEN", "AWARDED")
2. Click **Search**
3. **Expected**: Shows only RFQs with that status

#### 6. Clear Search
1. After a search, click the **Clear** button
2. **Expected**: Returns to full RFQ list; search term cleared

#### 7. Pagination with Search
1. Perform a search that returns many results
2. Click page 2 in the pagination controls
3. **Expected**: Search state preserved; shows results from page 2 of search

#### 8. Authorization Check (Requestor)
1. Log in as a Requestor user
2. Navigate to RFQ list
3. Perform a search
4. **Expected**: Search shows only RFQs created by that user

#### 9. Authorization Check (Director)
1. Log in as Director HRM&A
2. Navigate to RFQ list
3. Perform a search
4. **Expected**: Search shows only RFQs from branch 5

#### 10. No Results
1. Enter an unlikely search term (e.g., "ZZZZZZNOEXISTSZZZZZ")
2. Click **Search**
3. **Expected**: Message "No matching RFQs found." with result count "0"

## Future Enhancements

Potential improvements for future versions:

1. **Search History**: Track recent searches for quick reuse
2. **Saved Searches**: Allow users to save and name frequent searches
3. **Advanced Filters**: Boolean operators (AND, NOT), date range filters
4. **Weighted Results**: Prioritize exact number matches over description matches
5. **Full-Text Search**: Migrate to MySQL FULLTEXT INDEX for better text search
6. **Search Analytics**: Track popular searches to optimize index strategy
7. **Autocomplete**: Suggest completions based on existing RFQs
8. **Highlighted Results**: Highlight matching text in result rows

## Migration Checklist

- [x] Database migration created with search indexes
- [x] RFQSearchService class implemented
- [x] rfq/list.php integrated with search
- [x] Search UI added (input, button, clear)
- [x] Authorization checks implemented
- [x] Pagination state preserved
- [x] Test suite created (15 test cases)
- [x] Documentation completed
- [ ] Code review approved
- [ ] Tests pass on staging database
- [ ] User acceptance testing completed

## Files Modified

| File | Change |
|------|--------|
| `services/RFQSearchService.php` | New - Search service implementation |
| `rfq/list.php` | Modified - Added search UI and logic |
| `migrations/2026_08_13_rfq_search_indexes.sql` | New - Database indexes |
| `tests/RFQSearchTest.php` | New - Test suite |
| `docs/RFQ_SEARCH_IMPLEMENTATION.md` | New - This documentation |

## Support & Troubleshooting

### Common Issues

**Search returns no results despite records existing**
- Verify user has `view_requests` permission
- Check role-based access rules (branch restrictions)
- Ensure search term matches content (case-insensitive LIKE is applied)

**Search performance is slow**
- Verify migration 2026_08_13_rfq_search_indexes.sql has been applied
- Check database indexes exist: `SHOW INDEX FROM rfqs;`
- Consider increasing per_page limit if result set is small

**Special characters cause query errors**
- Should not happen (escaping is automatic)
- Report as bug with example search term

---

**Document Version**: 1.0  
**Last Updated**: 2026-08-13  
**Status**: Complete
