# Call-Up File Number Auto-Generation

## Overview
The call-up file number is now automatically generated based on the selected department and current year. The format follows: `DEPT-YEAR-###`

## Format Examples
- `BUK-2025-001` - First Buklod call-up in 2025
- `KAD-2025-015` - 15th Kadiwa call-up in 2025
- `FYS-2025-123` - 123rd FYS call-up in 2025

## Department Initial Mapping
The system uses the following department initials:

| Department | Initial | Example |
|-----------|---------|---------|
| BUKLOD | BUK | BUK-2025-001 |
| BINHI | BIN | BIN-2025-001 |
| KADIWA | KAD | KAD-2025-001 |
| FYS | FYS | FYS-2025-001 |
| CHOIR | CHR | CHR-2025-001 |
| USHER | USH | USH-2025-001 |
| SECURITY | SEC | SEC-2025-001 |
| FINANCE | FIN | FIN-2025-001 |
| ADMIN | ADM | ADM-2025-001 |
| MEDIA | MED | MED-2025-001 |
| WORSHIP | WOR | WOR-2025-001 |
| EVANGELICAL | EVN | EVN-2025-001 |
| MUSIC | MUS | MUS-2025-001 |
| TECHNICAL | TEC | TEC-2025-001 |
| SANITATION | SAN | SAN-2025-001 |

For departments not in the mapping, the system uses the first 3 letters of the department name in uppercase.

## How It Works

### 1. User Flow
1. User opens the "Create Call-Up" page
2. User selects an officer
3. User selects a department from the modal
4. **File number is automatically generated** based on:
   - Department initial (e.g., "BUK")
   - Current year (e.g., "2025")
   - Next sequential number (e.g., "001")
5. User fills in other fields (reason, deadline, destinado)
6. User submits the form

### 2. Technical Implementation

#### Frontend (JavaScript)
- `getDepartmentInitial(department)` - Maps department to 3-letter code
- `generateFileNumber(department)` - Calls API to get next number
- `selectDepartment(department)` - Triggers auto-generation

#### Backend (API)
- **Endpoint**: `/api/get-next-callup-number.php`
- **Method**: GET
- **Parameters**: `prefix` (e.g., "BUK-2025-")
- **Logic**:
  1. Query database for highest file number with the prefix
  2. Extract numeric part (e.g., "005" from "BUK-2025-005")
  3. Increment by 1
  4. Pad with leading zeros (3 digits)
  5. Return complete file number

#### Database Query
```sql
SELECT file_number 
FROM call_up_slips 
WHERE file_number LIKE 'BUK-2025-%' 
ORDER BY file_number DESC 
LIMIT 1
```

### 3. Sequential Numbering
- Numbers are **sequential per department per year**
- Each department starts at 001 each year
- Numbers are padded to 3 digits (001, 002, ..., 999)
- Maximum 999 call-ups per department per year

### 4. Examples

#### First call-up for Buklod in 2025:
- No existing records with "BUK-2025-" prefix
- System generates: **BUK-2025-001**

#### Second call-up for Buklod in 2025:
- Last record: "BUK-2025-001"
- System generates: **BUK-2025-002**

#### First call-up for Kadiwa in 2025:
- No existing records with "KAD-2025-" prefix
- System generates: **KAD-2025-001**

#### Department not in mapping (e.g., "MAINTENANCE"):
- Takes first 3 letters: "MAI"
- System generates: **MAI-2025-001**

## Files Modified

### 1. `officers/call-up.php`
- Moved department selection above file number
- Made file number field **read-only** (auto-generated)
- Added JavaScript mapping for department initials
- Added `generateFileNumber()` function
- Modified `selectDepartment()` to trigger auto-generation

### 2. `api/get-next-callup-number.php` (NEW)
- API endpoint to fetch next sequential number
- Queries database for highest number with prefix
- Returns formatted file number

## Benefits
1. **No manual typing** - Reduces human error
2. **Consistent format** - All file numbers follow the same pattern
3. **No duplicates** - Sequential numbering prevents conflicts
4. **Department tracking** - Easy to identify which department issued the call-up
5. **Year tracking** - Automatic yearly reset of numbering

## Future Enhancements
- Add more department mappings as needed
- Support custom prefixes per district/local
- Add validation to prevent manual file number modification
- Generate reports by department/year using file number patterns

## Testing
To test the auto-generation:
1. Go to "Create Call-Up" page
2. Select any department
3. Verify file number appears automatically
4. Create multiple call-ups for same department
5. Verify numbers increment (001, 002, 003, etc.)
6. Create call-up for different department
7. Verify it starts at 001

## Troubleshooting

### File number not generating:
- Check browser console for JavaScript errors
- Verify API endpoint is accessible
- Check database connection
- Ensure user is logged in

### Wrong number generated:
- Check database for existing records with same prefix
- Verify query is sorting correctly (DESC order)
- Check for manual file numbers that don't follow format

### Duplicate file numbers:
- Should not happen due to sequential query
- If occurs, check for concurrent requests (race condition)
- Add unique constraint to database if needed:
  ```sql
  ALTER TABLE call_up_slips ADD UNIQUE KEY unique_file_number (file_number);
  ```
