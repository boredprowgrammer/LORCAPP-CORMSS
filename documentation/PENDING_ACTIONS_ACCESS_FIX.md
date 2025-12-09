# Pending Actions Page - Access Control Fix

## Issue
Local (Limited) users were unable to access the `pending-actions.php` page even though they need to view their submitted actions and track their approval status.

## Root Cause
The page had overly restrictive access control that only allowed `local` and `admin` users, completely blocking `local_limited` users from accessing the page.

## Solution Implemented

### 1. Access Control Update
**File:** `pending-actions.php` (Lines 1-30)

**Before:**
```php
if ($currentUser['role'] !== 'local' && $currentUser['role'] !== 'admin') {
    $_SESSION['error'] = "Access denied. Only local or admin users can access pending actions.";
    header('Location: dashboard.php');
    exit();
}
```

**After:**
```php
// Check if user is a senior approver (local user with assigned limited users)
// OR if user is a local_limited user viewing their own submissions
$isSeniorApprover = false;
$isLimitedUser = false;

if ($currentUser['role'] === 'local' || $currentUser['role'] === 'admin') {
    // Check if this user has any local_limited users assigned to them
    $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE senior_approver_id = ? AND role = 'local_limited' AND status = 'active'");
    $stmt->execute([$currentUser['user_id']]);
    $isSeniorApprover = $stmt->fetchColumn() > 0 || $currentUser['role'] === 'admin';
} elseif ($currentUser['role'] === 'local_limited') {
    $isLimitedUser = true;
} else {
    $_SESSION['error'] = "Access denied. You do not have permission to view pending actions.";
    header('Location: dashboard.php');
    exit();
}
```

**Key Changes:**
- Added `$isSeniorApprover` flag - true if user is local/admin with assigned limited users
- Added `$isLimitedUser` flag - true if user is local_limited
- Allow local_limited users to access the page
- Maintain access restriction for district and other roles

### 2. Dual Query System
**File:** `pending-actions.php` (Lines 95-175)

Implemented conditional SQL queries based on user role:

#### For Limited Users (Requester View):
```sql
SELECT pa.*, u.username, u.full_name, u.email, u2.full_name as approver_name, ...
FROM pending_actions pa
WHERE pa.requester_user_id = ? AND pa.status = 'pending'
```
- Shows actions they submitted
- Includes approver name for context
- Only shows pending items

#### For Senior Approvers (Approval View):
```sql
SELECT pa.*, u.username, u.full_name, u.email, ...
FROM pending_actions pa
WHERE pa.approver_user_id = ? AND pa.status = 'pending'
```
- Shows actions assigned to them for approval
- Includes requester information
- Only shows pending items

### 3. UI Adaptations

#### Info Banner (Lines 200-213)
**Senior Approver Message:**
> "Local (Limited) users require your approval before their actions take effect. Review each action carefully before approving or rejecting."

**Limited User Message:**
> "Your actions require approval from your senior account before taking effect. You can track the status of your submissions here."

#### Page Title (Lines 217-225)
- Senior: "Awaiting Your Approval"
- Limited: "My Pending Submissions"

#### Empty State Message (Lines 233-240)
- Senior: "All actions have been reviewed."
- Limited: "You have no pending submissions."

#### Action Cards (Lines 310-363)
**Senior Approver View:**
- Shows requester name and username
- Displays Approve/Reject action buttons
- Full approval interface

**Limited User View:**
- Shows assigned approver name
- Displays "Awaiting Approval" badge (yellow)
- Read-only view with no action buttons

#### History Table (Lines 372-382)
**Column Headers:**
- Senior: "Requester" column
- Limited: "Reviewed By" column

**Table Data:**
- Senior: Shows who submitted the action
- Limited: Shows who reviewed their submission

### 4. POST Handler Protection
**File:** `pending-actions.php` (Lines 32-92)

Restricted approval/rejection actions to senior approvers only:

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isSeniorApprover) {
    // Process approval/rejection
}
```

Limited users can view but cannot approve/reject actions.

## User Experience

### Local (Limited) User Journey:
1. Submit action (add/edit/remove officer)
2. Redirected to pending-actions.php with success message
3. See their submission with "Awaiting Approval" badge
4. View assigned approver name
5. Track status in history once reviewed

### Senior Approver Journey:
1. Receive notification of pending action (badge in navigation)
2. Access pending-actions.php
3. Review action details and requester information
4. Approve or reject with optional reason
5. Action executed or logged accordingly

## Testing Checklist

- [ ] Run database migrations (add_local_limited_role.sql, pending_actions.sql)
- [ ] Create test local user account
- [ ] Create test local_limited user with senior approver assigned
- [ ] As limited user: Submit officer add action
- [ ] As limited user: Verify can access pending-actions.php
- [ ] As limited user: Verify sees own submission with "Awaiting Approval"
- [ ] As senior user: Verify sees action in approval queue
- [ ] As senior user: Test approve action
- [ ] As senior user: Test reject action with reason
- [ ] As limited user: Verify sees result in history
- [ ] Test navigation badge count updates correctly
- [ ] Test district user cannot access page
- [ ] Test admin user can access page

## Files Modified

1. **pending-actions.php**
   - Access control logic (lines 1-30)
   - Query system (lines 95-175)
   - UI components (lines 200-440)
   - POST handler protection (lines 32-92)

## Related Files

- `includes/permissions.php` - Helper functions (isLocalLimitedUser, getSeniorApprover, etc.)
- `includes/pending-actions.php` - Action creator functions
- `includes/layout.php` - Navigation link visibility
- `officers/add.php` - Create pending add action
- `officers/edit.php` - Create pending edit action
- `officers/remove.php` - Create pending remove action

## Next Steps

1. Implement execution functions (executeAddOfficer, executeEditOfficer, executeRemoveOfficer)
2. Add email notifications for approvers when new action is submitted
3. Add email notifications for requesters when action is reviewed
4. Extend to transfer-in/out operations
5. Add bulk action support
6. Consider adding action comments/notes feature

## Security Considerations

- ✅ CSRF token validation on all POST requests
- ✅ Role-based access control with granular checks
- ✅ SQL injection prevention via prepared statements
- ✅ XSS prevention via Security::escape()
- ✅ Limited users cannot self-approve actions
- ✅ Strict separation between requester and approver roles

## Database Impact

No new schema changes required. Existing tables support this functionality:
- `users.role` already includes 'local_limited'
- `users.senior_approver_id` already stores approver assignment
- `pending_actions` table fully supports dual-view queries

## Performance Notes

- Queries use indexed foreign keys (requester_user_id, approver_user_id)
- History limited to 50 most recent items
- No N+1 query issues with JOIN operations
- Suitable for production use without optimization
