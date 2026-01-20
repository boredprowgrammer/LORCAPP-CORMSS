<?php
// Mobile Navigation Menu - All menu items
// This file is included in layout.php for mobile sidebar

// Special navigation for Local CFO users (restricted access)
if (isset($currentUser) && $currentUser['role'] === 'local_cfo'): 
?>
    <a href="<?php echo BASE_URL; ?>/cfo-dashboard.php" 
       class="flex items-center px-3 py-2.5 text-sm font-medium rounded-lg <?php echo basename($_SERVER['PHP_SELF']) === 'cfo-dashboard.php' ? 'bg-purple-50 text-purple-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
        </svg>
        CFO Dashboard
    </a>

    <div class="pt-4 pb-2">
        <p class="px-3 text-xs font-semibold text-gray-400 uppercase tracking-wider">CFO Management</p>
    </div>

    <a href="<?php echo BASE_URL; ?>/cfo-registry.php" 
       class="flex items-center px-3 py-2.5 text-sm font-medium rounded-lg <?php echo basename($_SERVER['PHP_SELF']) === 'cfo-registry.php' ? 'bg-purple-50 text-purple-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
        </svg>
        CFO Registry
    </a>

    <a href="<?php echo BASE_URL; ?>/cfo-add.php" 
       class="flex items-center px-3 py-2.5 text-sm font-medium rounded-lg <?php echo basename($_SERVER['PHP_SELF']) === 'cfo-add.php' ? 'bg-purple-50 text-purple-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
        </svg>
        Add CFO Member
    </a>

    <a href="<?php echo BASE_URL; ?>/tarheta/list.php" 
       class="flex items-center px-3 py-2.5 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], '/tarheta/list.php') !== false ? 'bg-purple-50 text-purple-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
        <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
        </svg>
        Tarheta Control
    </a>

<?php 
    return; // Exit early for local_cfo users - they don't see other menu items
endif; 
?>

<a href="<?php echo BASE_URL; ?>/launchpad.php" 
   class="flex items-center px-3 py-2.5 text-sm font-medium rounded-lg <?php echo basename($_SERVER['PHP_SELF']) === 'launchpad.php' ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path>
    </svg>
    Launchpad
</a>

<div class="pt-4 pb-2">
    <p class="px-3 text-xs font-semibold text-gray-400 uppercase tracking-wider">Officer Management</p>
</div>

<?php if (hasPermission('can_view_officers')): ?>
<a href="<?php echo BASE_URL; ?>/officers/list.php" 
   class="flex items-center px-3 py-2.5 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], '/officers/list.php') !== false ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
    </svg>
    Officers List
</a>
<?php endif; ?>

<?php if (hasPermission('can_add_officers')): ?>
<a href="<?php echo BASE_URL; ?>/officers/add.php" 
   class="flex items-center px-3 py-2.5 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], '/officers/add.php') !== false ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path>
    </svg>
    Add Officer
</a>
<?php endif; ?>

<?php if (hasPermission('can_transfer_in')): ?>
<a href="<?php echo BASE_URL; ?>/transfers/transfer-in.php" 
   class="flex items-center px-3 py-2.5 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], 'transfer-in.php') !== false ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"></path>
    </svg>
    Transfer In
</a>
<?php endif; ?>

<?php if (hasPermission('can_transfer_out')): ?>
<a href="<?php echo BASE_URL; ?>/transfers/transfer-out.php" 
   class="flex items-center px-3 py-2.5 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], 'transfer-out.php') !== false ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16V4m0 0l4 4m-4-4l-4 4m-6 0v12m0 0l-4-4m4 4l4-4"></path>
    </svg>
    Transfer Out
</a>
<?php endif; ?>

<?php if (hasPermission('can_remove_officers')): ?>
<a href="<?php echo BASE_URL; ?>/officers/removal-requests.php" 
   class="flex items-center px-3 py-2.5 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], '/officers/remove.php') !== false ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7a4 4 0 11-8 0 4 4 0 018 0zM9 14a6 6 0 00-6 6v1h12v-1a6 6 0 00-6-6zM21 12h-6"></path>
    </svg>
    Remove Officer
</a>
<?php endif; ?>

<?php 
// Show Pending Actions link
$showPendingActions = false;
$pendingCount = 0;
$db = Database::getInstance()->getConnection();

if ($currentUser['role'] === 'local') {
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM users WHERE senior_approver_id = ? AND role = 'local_limited'");
    $stmt->execute([$currentUser['user_id']]);
    $result = $stmt->fetch();
    if ($result['count'] > 0) {
        $showPendingActions = true;
        $pendingCount = getPendingActionsCount();
    }
} elseif ($currentUser['role'] === 'local_limited') {
    $showPendingActions = true;
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM pending_actions WHERE requester_user_id = ? AND status = 'pending'");
    $stmt->execute([$currentUser['user_id']]);
    $result = $stmt->fetch();
    $pendingCount = (int)($result['count'] ?? 0);
}

if ($showPendingActions): 
?>
<a href="<?php echo BASE_URL; ?>/pending-actions.php" 
   class="flex items-center px-3 py-2.5 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], 'pending-actions.php') !== false ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
    </svg>
    <span class="flex-1">Pending Actions</span>
    <?php if ($pendingCount > 0): ?>
        <span class="inline-flex items-center justify-center px-2 py-0.5 text-xs font-bold text-white bg-red-600 rounded-full">
            <?php echo $pendingCount; ?>
        </span>
    <?php endif; ?>
</a>
<?php endif; ?>

<div class="pt-4 pb-2">
    <p class="px-3 text-xs font-semibold text-gray-400 uppercase tracking-wider">Call-Up Slips</p>
</div>

<a href="<?php echo BASE_URL; ?>/officers/call-up-list.php" 
   class="flex items-center px-3 py-2.5 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], '/officers/call-up') !== false ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
    </svg>
    View Call-Ups
</a>

<a href="<?php echo BASE_URL; ?>/officers/call-up.php" 
   class="flex items-center px-3 py-2.5 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], '/officers/call-up.php') !== false && strpos($_SERVER['PHP_SELF'], 'call-up-list') === false ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
    </svg>
    Create Call-Up
</a>

<?php if (hasPermission('can_view_legacy_registry')): ?>
<div class="pt-4 pb-2">
    <p class="px-3 text-xs font-semibold text-gray-400 uppercase tracking-wider">Legacy Registry</p>
</div>

<a href="<?php echo BASE_URL; ?>/tarheta/list.php" 
   class="flex items-center px-3 py-2.5 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], '/tarheta/list.php') !== false ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
    </svg>
    Tarheta Control
</a>


<?php endif; ?>

<?php if (hasPermission('can_add_officers')): ?>
<a href="<?php echo BASE_URL; ?>/tarheta/import.php" 
   class="flex items-center px-3 py-2.5 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], '/tarheta/import.php') !== false ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path>
    </svg>
    Import CSV
</a>
<?php endif; ?>

<?php if (hasPermission('can_view_requests')): ?>
<div class="pt-4 pb-2">
    <p class="px-3 text-xs font-semibold text-gray-400 uppercase tracking-wider">Requests</p>
</div>

<a href="<?php echo BASE_URL; ?>/requests/list.php" 
   class="flex items-center px-3 py-2.5 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], '/requests/') !== false ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
    </svg>
    Officer Requests
</a>
<?php endif; ?>

<?php if (in_array($currentUser['role'], ['local', 'district'])): ?>
<div class="pt-4 pb-2">
    <p class="px-3 text-xs font-semibold text-gray-400 uppercase tracking-wider">Communication</p>
</div>

<a href="<?php echo BASE_URL; ?>/chat.php" 
   class="flex items-center px-3 py-2.5 text-sm font-medium rounded-lg <?php echo basename($_SERVER['PHP_SELF']) === 'chat.php' ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
    </svg>
    Messages
</a>
<?php endif; ?>

<?php if (hasAnyPermission(['can_view_reports', 'can_view_headcount', 'can_view_departments'])): ?>
<div class="pt-4 pb-2">
    <p class="px-3 text-xs font-semibold text-gray-400 uppercase tracking-wider">Reports</p>
</div>
<?php endif; ?>

<?php if (hasPermission('can_view_headcount')): ?>
<a href="<?php echo BASE_URL; ?>/reports/headcount.php" 
   class="flex items-center px-3 py-2.5 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], 'headcount.php') !== false ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
    </svg>
    Headcount Report
</a>
<?php endif; ?>

<?php if (hasPermission('can_view_reports')): ?>
<a href="<?php echo BASE_URL; ?>/reports/masterlist.php" 
   class="flex items-center px-3 py-2.5 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], 'masterlist.php') !== false ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
    </svg>
    Masterlist
</a>

<a href="<?php echo BASE_URL; ?>/reports/lorc-lcrc-checker.php" 
   class="flex items-center px-3 py-2.5 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], 'lorc-lcrc-checker.php') !== false ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path>
    </svg>
    LORC/LCRC Checker
</a>
<?php endif; ?>

<?php if (hasPermission('can_view_departments')): ?>
<a href="<?php echo BASE_URL; ?>/reports/departments.php" 
   class="flex items-center px-3 py-2.5 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], 'departments.php') !== false ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z"></path>
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z"></path>
    </svg>
    Departments
</a>
<?php endif; ?>

<?php if (hasPermission('can_view_officers')): ?>


<a href="<?php echo BASE_URL; ?>/logbook-control-number.php" 
   class="flex items-center px-3 py-2.5 text-sm font-medium rounded-lg <?php echo basename($_SERVER['PHP_SELF']) === 'logbook-control-number.php' ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path>
    </svg>
    Logbook Control Number
</a>
<?php endif; ?>

<?php if ($currentUser['role'] === 'admin'): ?>
<div class="pt-4 pb-2">
    <p class="px-3 text-xs font-semibold text-gray-400 uppercase tracking-wider">Administration</p>
</div>

<a href="<?php echo BASE_URL; ?>/admin/users.php" 
   class="flex items-center px-3 py-2.5 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], '/admin/users.php') !== false ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
    </svg>
    Manage Users
</a>

<a href="<?php echo BASE_URL; ?>/admin/announcements.php" 
   class="flex items-center px-3 py-2.5 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], '/admin/announcements.php') !== false ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path>
    </svg>
    Announcements
</a>

<a href="<?php echo BASE_URL; ?>/admin/districts.php" 
   class="flex items-center px-3 py-2.5 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], '/admin/districts.php') !== false ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7"></path>
    </svg>
    Districts & Locals
</a>

<a href="<?php echo BASE_URL; ?>/admin/audit.php" 
   class="flex items-center px-3 py-2.5 text-sm font-medium rounded-lg <?php echo strpos($_SERVER['PHP_SELF'], '/admin/audit.php') !== false ? 'bg-blue-50 text-blue-600' : 'text-gray-700 hover:bg-gray-100'; ?>">
    <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
    </svg>
    Audit Log
</a>
<?php endif; ?>
