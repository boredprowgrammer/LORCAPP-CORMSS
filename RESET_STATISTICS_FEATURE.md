# Reset Statistics Feature

## Overview
The reset statistics feature allows local administrators to "zero out" the dagdag/bawas (additions/subtractions) display in the CFO Reports without deleting any actual data from the database.

## Purpose
- **Flexible Reporting Periods**: Start tracking statistics from any point in time
- **No Data Loss**: Reset only affects the reporting baseline, not the underlying data
- **Consistent Tracking**: Database-backed reset timestamps ensure consistency across sessions
- **Per-Classification Control**: Reset statistics independently for Buklod, Kadiwa, Binhi, or all at once

## How It Works

### Database Structure
```sql
CREATE TABLE cfo_report_resets (
    id INT PRIMARY KEY AUTO_INCREMENT,
    local_code VARCHAR(50) NOT NULL,
    classification VARCHAR(20) NOT NULL,  -- 'Buklod', 'Kadiwa', 'Binhi', or 'all'
    period VARCHAR(20) NOT NULL,          -- 'week' or 'month'
    reset_at DATETIME NOT NULL,
    reset_by INT NOT NULL,                -- FK to users.id
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### Reset Process
1. **User Action**: Local admin clicks a reset button (e.g., "Reset Week" for Buklod)
2. **API Call**: JavaScript sends POST request to `/api/reset-cfo-stats.php`
3. **Validation**: API verifies user has 'local' role
4. **Database Insert**: New reset timestamp is recorded
5. **Calculation Update**: Future statistics queries use reset timestamp as baseline

### Baseline Determination Logic
When calculating weekly or monthly changes:
```php
// For each classification (Buklod/Kadiwa/Binhi)
$baseline = $defaultTime; // e.g., 7 days ago for weekly

// Check classification-specific reset
if (isset($resetTimestamps['week']['Buklod'])) {
    $baseline = $resetTimestamps['week']['Buklod'];
}
// Fallback to 'all' reset
elseif (isset($resetTimestamps['week']['all'])) {
    $baseline = $resetTimestamps['week']['all'];
}

// Count additions/subtractions from $baseline
```

## User Interface

### Location
The reset controls appear in the **Overview tab** of CFO Reports, below the classification statistics cards.

### Layout
Four sections in a grid:
1. **Buklod** (Red theme) - Reset Week, Reset Month, Reset Both
2. **Kadiwa** (Blue theme) - Reset Week, Reset Month, Reset Both
3. **Binhi** (Green theme) - Reset Week, Reset Month, Reset Both
4. **All Classifications** (Gray theme) - Reset Week, Reset Month, Reset Both

### Visibility
- **Only shown to users with 'local' role**
- Hidden for district, overseer, and admin roles

## API Endpoint

### `/api/reset-cfo-stats.php`

**Request:**
```json
{
    "classification": "Buklod|Kadiwa|Binhi|all",
    "period": "week|month|both"
}
```

**Success Response:**
```json
{
    "success": true,
    "reset_at": "2024-01-15 14:30:00"
}
```

**Error Response:**
```json
{
    "success": false,
    "error": "Unauthorized: Only local accounts can reset statistics"
}
```

## Use Cases

### Monthly Tracking
**Scenario**: Local wants to track monthly progress starting fresh on the 1st of each month.

**Steps**:
1. On March 1st, click "Reset Month" for "All Classifications"
2. Statistics now show changes from March 1st forward
3. On April 1st, reset again to start tracking April

### Classification-Specific Reset
**Scenario**: Major Buklod event (wedding) affects statistics; want to track post-event growth.

**Steps**:
1. After event, click "Reset Both" for "Buklod"
2. Buklod statistics reset, Kadiwa and Binhi continue normally
3. Track Buklod growth separately from other classifications

### Weekly Campaign
**Scenario**: Local runs a recruitment campaign for Kadiwa.

**Steps**:
1. At campaign start, click "Reset Week" for "Kadiwa"
2. Track Kadiwa additions during campaign period
3. Evaluate campaign effectiveness at end

## Important Notes

### Data Integrity
- **No deletion**: Original member records remain unchanged
- **Audit trail**: Reset actions are logged with user ID and timestamp
- **Reversible**: Can reset again at any time to change baseline

### Calculation Impact
- Only affects dagdag/bawas display in Overview tab
- Does not affect:
  - Total member counts
  - Transaction lists in other tabs
  - Actual registration dates
  - Transfer records
  - Classification changes

### Reset History
- All resets are stored in `cfo_report_resets` table
- Each reset creates a new record (no updates)
- Most recent reset per classification/period is used as baseline
- History preserved for auditing and troubleshooting

## Technical Implementation

### Files Modified
1. **database/add_cfo_report_reset_tracking.sql** - Created reset tracking table
2. **api/reset-cfo-stats.php** - Reset API endpoint with validation
3. **reports/cfo-reports.php** - UI buttons and calculation logic

### Key Functions
- `resetCfoStats(classification, period)` - JavaScript function to call API
- Reset timestamp fetching - Query most recent reset per classification/period
- Baseline determination - Use reset timestamp if available, else default time

### Security
- **Role check**: `$currentUser['role'] === 'local'`
- **CSRF protection**: Token validation on API endpoint
- **SQL injection prevention**: Prepared statements with parameter binding
- **Input validation**: Whitelist for classification and period values

## Future Enhancements
- [ ] Reset history viewer showing past reset actions
- [ ] Email notification when reset occurs
- [ ] Scheduled auto-reset (e.g., monthly on 1st)
- [ ] Export statistics report with reset information
- [ ] Undo last reset functionality
