# Local (Limited) Role Implementation Guide

## Overview
This implementation adds a new "Local (Limited)" user role that requires approval from a senior local account for all actions.

## Features
- **New Role**: `local_limited` - Users with this role need approval for all officer-related actions
- **Senior Approver**: Each limited user is assigned a senior local account who approves their actions
- **Pending Actions System**: All actions from limited users are stored in a pending state until approved/rejected
- **Approval Workflow**: Senior accounts can review, approve, or reject pending actions

## Database Setup

### Step 1: Run Database Migrations

Execute the following SQL files in order:

```bash
# 1. Add local_limited role to users table
mysql -u your_username -p your_database < database/add_local_limited_role.sql

# 2. Create pending_actions table
mysql -u your_username -p your_database < database/pending_actions.sql
```

Or run them manually in phpMyAdmin/MySQL client.

### Step 2: Verify Tables

Check that the following changes were applied:

1. **users table**:
   - `role` column should now accept: `admin`, `district`, `local`, `local_limited`
   - New `senior_approver_id` column added

2. **pending_actions table**:
   - New table created with all fields for storing pending actions

## Usage

### Creating a Local (Limited) User

1. Go to **Admin → Users** page
2. Click "Create User"
3. Select **"Local (Limited) - Requires Approval"** as the role
4. Select district and local congregation
5. **Important**: Select a "Senior Approver" from the dropdown
   - Only senior local accounts from the same congregation will appear
   - This person will approve all actions from this limited user
6. Complete the form and create the user

### Approving Pending Actions

Senior local accounts will see pending actions:

1. Go to **Pending Actions** page (link in navigation)
2. Review pending actions from limited users
3. Click **"View Details"** to see full action data
4. Choose to:
   - **Approve**: Execute the action immediately
   - **Reject**: Decline the action with a reason

### For Local (Limited) Users

When a limited user performs actions (add/edit/remove officers), instead of executing immediately:

1. Action is saved to `pending_actions` table
2. User sees a message: "Your action has been submitted for approval to [Senior Name]"
3. User can view their pending actions status
4. Once approved, the action executes automatically
5. If rejected, user is notified with the rejection reason

## Modified Files

### Database
- `database/add_local_limited_role.sql` - Adds local_limited role and senior_approver_id field
- `database/pending_actions.sql` - Creates pending actions table

### Backend
- `includes/permissions.php` - Added local_limited role support and helper functions
- `includes/pending-actions.php` - Helper functions for creating pending actions
- `admin/users.php` - Updated to support creating local_limited users
- `api/get-senior-approvers.php` - API to fetch senior approvers for a local

### Frontend
- `pending-actions.php` - Page for viewing and approving/rejecting pending actions

### To Be Modified (Next Steps)
- `officers/add.php` - Intercept add actions for limited users
- `officers/edit.php` - Intercept edit actions for limited users
- `officers/remove.php` - Intercept remove actions for limited users
- `transfers/*.php` - Intercept transfer actions for limited users
- `includes/layout.php` - Add pending actions link to navigation

## Navigation Updates Needed

Add this to the navigation menu for local accounts:

```php
<?php if ($currentUser['role'] === 'local'): ?>
    <?php 
    $pendingCount = getPendingActionsCount(); 
    ?>
    <a href="<?php echo BASE_URL; ?>/pending-actions.php" 
       class="nav-link">
        Pending Actions
        <?php if ($pendingCount > 0): ?>
            <span class="badge"><?php echo $pendingCount; ?></span>
        <?php endif; ?>
    </a>
<?php endif; ?>
```

## Integration with Existing Code

To integrate with existing officer action pages, add this check at the beginning of form processing:

```php
// In officers/add.php, edit.php, remove.php, etc.
require_once __DIR__ . '/../includes/pending-actions.php';

// After validating the form data but before executing the action:
if (shouldPendAction()) {
    // Create pending action instead of executing
    $actionId = createPendingAddOfficer($formData, $officerName);
    
    if ($actionId) {
        $_SESSION['success'] = getPendingActionMessage('add officer');
        header('Location: list.php');
        exit;
    } else {
        $error = 'Failed to submit action for approval.';
    }
} else {
    // Execute the action normally for non-limited users
    // ... existing code ...
}
```

## Security Considerations

1. **Validation**: All pending actions are validated before execution
2. **Authorization**: Only assigned senior approvers can approve/reject actions
3. **Audit Trail**: All approved/rejected actions are logged with timestamps
4. **CSRF Protection**: All forms include CSRF tokens
5. **Data Encryption**: Officer names remain encrypted in pending actions

## Testing Checklist

- [ ] Create a local_limited user with senior approver
- [ ] Log in as limited user and try to add an officer
- [ ] Verify action appears as pending (not executed)
- [ ] Log in as senior approver
- [ ] Navigate to Pending Actions page
- [ ] Approve the pending action
- [ ] Verify officer was added successfully
- [ ] Test rejection workflow with reason
- [ ] Verify limited user sees appropriate messages

## Rollback

If you need to rollback these changes:

```sql
-- Remove the pending_actions table
DROP TABLE IF EXISTS pending_actions;

-- Remove senior_approver_id column
ALTER TABLE users DROP FOREIGN KEY fk_senior_approver;
ALTER TABLE users DROP COLUMN senior_approver_id;

-- Revert role enum (careful - this will fail if any users have local_limited role)
-- First delete or update any local_limited users:
DELETE FROM users WHERE role = 'local_limited';
-- Then alter the table:
ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'district', 'local') NOT NULL;
```

## Support

For questions or issues:
1. Check error logs in `logs/` directory
2. Verify all database migrations ran successfully
3. Ensure proper permissions are set in `user_permissions` table
4. Check that senior approver assignments are correct
