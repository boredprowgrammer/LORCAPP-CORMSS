<?php
require_once __DIR__ . '/config/config.php';

Security::requireLogin();

$currentUser = getCurrentUser();
$db = Database::getInstance()->getConnection();

// Get the year and month from query parameters or use current
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : date('n');

// Validate year (between 2020 and 2050)
if ($year < 2020 || $year > 2050) {
    $year = date('Y');
}

// Validate month (between 1 and 12)
if ($month < 1 || $month > 12) {
    $month = date('n');
}

// Get current date info
$today = new DateTime();
$currentDay = (int)$today->format('j');
$currentMonth = (int)$today->format('n');
$currentYear = (int)$today->format('Y');
$currentWeek = (int)$today->format('W');

// Get month info
$firstDayOfMonth = new DateTime("$year-$month-01");
$lastDayOfMonth = new DateTime($firstDayOfMonth->format('Y-m-t'));
$daysInMonth = (int)$lastDayOfMonth->format('j');
$startDayOfWeek = (int)$firstDayOfMonth->format('N'); // 1 (Monday) to 7 (Sunday)

// Month names
$monthNames = [
    1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
    5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
    9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
];

// Generate calendar grid
$calendarDays = [];
$currentDate = clone $firstDayOfMonth;
$currentDate->modify('-' . ($startDayOfWeek - 1) . ' days'); // Go back to Monday of first week

for ($i = 0; $i < 42; $i++) { // 6 weeks max
    $day = (int)$currentDate->format('j');
    $dayMonth = (int)$currentDate->format('n');
    $dayYear = (int)$currentDate->format('Y');
    $weekNumber = (int)$currentDate->format('W');
    
    $calendarDays[] = [
        'date' => clone $currentDate,
        'day' => $day,
        'month' => $dayMonth,
        'year' => $dayYear,
        'week' => $weekNumber,
        'isCurrentMonth' => $dayMonth == $month,
        'isToday' => ($day == $currentDay && $dayMonth == $currentMonth && $dayYear == $currentYear),
        'dayName' => $currentDate->format('l')
    ];
    
    $currentDate->modify('+1 day');
}

// Navigation helpers
$prevMonth = $month - 1;
$prevYear = $year;
if ($prevMonth < 1) {
    $prevMonth = 12;
    $prevYear--;
}

$nextMonth = $month + 1;
$nextYear = $year;
if ($nextMonth > 12) {
    $nextMonth = 1;
    $nextYear++;
}

$pageTitle = 'Calendar & Week Guide';
ob_start();
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Header -->
    <div class="mb-6">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <div>
                <h1 class="text-3xl font-bold text-gray-900">Calendar & Week Guide</h1>
                <p class="text-gray-600 mt-1">ISO 8601 week numbering system</p>
            </div>
            
            <!-- Month/Year Navigation -->
            <div class="flex items-center gap-2">
                <a href="?year=<?php echo $prevYear; ?>&month=<?php echo $prevMonth; ?>" class="inline-flex items-center px-3 py-2 border border-gray-300 rounded-lg text-gray-700 bg-white hover:bg-gray-50 transition-colors">
                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                    </svg>
                    <?php echo $monthNames[$prevMonth]; ?>
                </a>
                <div class="px-4 py-2 bg-blue-100 text-blue-800 font-semibold rounded-lg">
                    <?php echo $monthNames[$month] . ' ' . $year; ?>
                </div>
                <a href="?year=<?php echo $nextYear; ?>&month=<?php echo $nextMonth; ?>" class="inline-flex items-center px-3 py-2 border border-gray-300 rounded-lg text-gray-700 bg-white hover:bg-gray-50 transition-colors">
                    <?php echo $monthNames[$nextMonth]; ?>
                    <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </a>
                <?php if ($month != $currentMonth || $year != $currentYear): ?>
                <a href="?year=<?php echo $currentYear; ?>&month=<?php echo $currentMonth; ?>" class="inline-flex items-center px-3 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors ml-2">
                    <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                    </svg>
                    Today
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Info Cards -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
            <div class="flex items-center">
                <svg class="w-8 h-8 text-blue-600 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                </svg>
                <div>
                    <p class="text-sm text-blue-600 font-medium">Current Week</p>
                    <p class="text-2xl font-bold text-blue-800">Week <?php echo $currentWeek; ?></p>
                </div>
            </div>
        </div>
        
        <div class="bg-green-50 border border-green-200 rounded-lg p-4">
            <div class="flex items-center">
                <svg class="w-8 h-8 text-green-600 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <div>
                    <p class="text-sm text-green-600 font-medium">Days in Month</p>
                    <p class="text-2xl font-bold text-green-800"><?php echo $daysInMonth; ?> days</p>
                </div>
            </div>
        </div>
        
        <div class="bg-purple-50 border border-purple-200 rounded-lg p-4">
            <div class="flex items-center">
                <svg class="w-8 h-8 text-purple-600 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <div>
                    <p class="text-sm text-purple-600 font-medium">Today's Date</p>
                    <p class="text-lg font-bold text-purple-800"><?php echo date('M d, Y'); ?></p>
                </div>
            </div>
        </div>
    </div>

   
    <!-- Calendar Grid -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden mb-6">
        <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900"><?php echo $monthNames[$month] . ' ' . $year; ?> Calendar</h2>
        </div>
        
        <div class="p-4">
            <!-- Calendar Header (Days of Week) -->
            <div class="grid grid-cols-8 gap-2 mb-2">
                <!-- Week column header -->
                <div class="text-center font-semibold text-sm text-gray-600 py-2">
                    Week
                </div>
                <?php 
                $dayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
                foreach ($dayNames as $index => $dayName): 
                    $headerClass = 'text-center font-semibold text-sm py-2';
                    if ($dayName === 'Thu') {
                        $headerClass .= ' text-green-700 bg-green-50 rounded-lg';
                    } elseif ($dayName === 'Sun') {
                        $headerClass .= ' text-red-700 bg-red-50 rounded-lg';
                    } else {
                        $headerClass .= ' text-gray-600';
                    }
                ?>
                    <div class="<?php echo $headerClass; ?>">
                        <?php echo $dayName; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Calendar Grid -->
            <div class="grid grid-cols-8 gap-2">
                <?php 
                $weekCounter = 0;
                $currentWeekNum = null;
                foreach ($calendarDays as $index => $day): 
                    // Start new week
                    if ($index % 7 == 0) {
                        $weekCounter++;
                        if ($weekCounter > 6) break; // Only show 6 weeks max
                        
                        // Add week number cell at start of each row
                        $isCurrentWeekInCalendar = ($day['week'] == $currentWeek);
                        $weekCellClass = 'flex items-center justify-center min-h-24 border rounded-lg font-bold text-sm ';
                        if ($isCurrentWeekInCalendar) {
                            $weekCellClass .= 'bg-blue-600 text-white border-blue-700';
                        } else {
                            $weekCellClass .= 'bg-gray-100 text-gray-700 border-gray-300';
                        }
                        $currentWeekNum = $day['week'];
                ?>
                    <div class="<?php echo $weekCellClass; ?>">
                        W<?php echo $currentWeekNum; ?>
                    </div>
                <?php
                    }
                    
                    // Determine day of week (0 = Monday, 6 = Sunday)
                    $dayOfWeek = $index % 7;
                    $isThursday = ($dayOfWeek === 3);
                    $isSunday = ($dayOfWeek === 6);
                    
                    $classes = ['min-h-24', 'border', 'rounded-lg', 'p-2', 'transition-all', 'hover:shadow-md'];
                    
                    if ($day['isToday']) {
                        $classes[] = 'bg-blue-100 border-blue-500 border-2';
                    } elseif ($day['isCurrentMonth']) {
                        if ($isThursday) {
                            $classes[] = 'bg-green-50 border-green-300 hover:border-green-400';
                        } elseif ($isSunday) {
                            $classes[] = 'bg-red-50 border-red-300 hover:border-red-400';
                        } else {
                            $classes[] = 'bg-white border-gray-200 hover:border-blue-300';
                        }
                    } else {
                        $classes[] = 'bg-gray-50 border-gray-100 opacity-50';
                    }
                    
                    // Text color for day number
                    $dayNumberClass = 'text-sm font-semibold ';
                    if ($day['isToday']) {
                        $dayNumberClass = 'inline-flex items-center justify-center w-7 h-7 rounded-full bg-blue-600 text-white font-bold text-sm';
                    } elseif ($day['isCurrentMonth']) {
                        if ($isThursday) {
                            $dayNumberClass .= 'text-green-700';
                        } elseif ($isSunday) {
                            $dayNumberClass .= 'text-red-700';
                        } else {
                            $dayNumberClass .= 'text-gray-900';
                        }
                    } else {
                        $dayNumberClass .= 'text-gray-400';
                    }
                ?>
                    <div class="<?php echo implode(' ', $classes); ?>">
                        <div class="flex items-start justify-between mb-1">
                            <span class="<?php echo $dayNumberClass; ?>">
                                <?php echo $day['day']; ?>
                            </span>
                        </div>
                        <?php if ($day['isCurrentMonth']): ?>
                            <div class="text-xs mt-1 <?php echo $isThursday ? 'text-green-600' : ($isSunday ? 'text-red-600' : 'text-gray-500'); ?>">
                                <?php echo substr($day['dayName'], 0, 3); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <!-- Week Legend -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 bg-gray-50 border-b border-gray-200">
            <h2 class="text-lg font-semibold text-gray-900">Week Numbers for <?php echo $monthNames[$month]; ?></h2>
        </div>
        
        <div class="p-6">
            <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-3">
                <?php 
                // Get unique weeks in this month
                $monthWeeks = [];
                foreach ($calendarDays as $day) {
                    if ($day['isCurrentMonth'] && !isset($monthWeeks[$day['week']])) {
                        $weekInfo = getWeekDateRange($day['week'], $day['year']);
                        $monthWeeks[$day['week']] = $weekInfo;
                    }
                }
                
                foreach ($monthWeeks as $weekNum => $weekInfo):
                    $isCurrent = ($weekNum == $currentWeek && $year == $currentYear);
                    $startDate = new DateTime($weekInfo['start']);
                    $endDate = new DateTime($weekInfo['end']);
                ?>
                    <div class="<?php echo $isCurrent ? 'bg-blue-50 border-blue-300' : 'bg-gray-50 border-gray-200'; ?> border rounded-lg p-3">
                        <div class="flex items-center justify-between mb-2">
                            <span class="<?php echo $isCurrent ? 'bg-blue-600 text-white' : 'bg-gray-200 text-gray-700'; ?> text-xs font-bold px-2 py-1 rounded">
                                Week <?php echo $weekNum; ?>
                            </span>
                            <?php if ($isCurrent): ?>
                                <span class="inline-flex items-center">
                                    <svg class="w-4 h-4 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
                                    </svg>
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="text-xs text-gray-600">
                            <?php echo $startDate->format('M d'); ?> - <?php echo $endDate->format('M d'); ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Quick Links -->
    <div class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-4">
        <a href="<?php echo BASE_URL; ?>/transfers/transfer-in.php" class="flex items-center justify-between p-4 bg-green-50 border border-green-200 rounded-lg hover:bg-green-100 transition-colors group">
            <div class="flex items-center">
                <svg class="w-8 h-8 text-green-600 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path>
                </svg>
                <div>
                    <p class="font-semibold text-green-900">Transfer In</p>
                    <p class="text-xs text-green-700">Week <?php echo $currentWeek; ?></p>
                </div>
            </div>
            <svg class="w-5 h-5 text-green-600 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
            </svg>
        </a>
        
        <a href="<?php echo BASE_URL; ?>/transfers/transfer-out.php" class="flex items-center justify-between p-4 bg-yellow-50 border border-yellow-200 rounded-lg hover:bg-yellow-100 transition-colors group">
            <div class="flex items-center">
                <svg class="w-8 h-8 text-yellow-600 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                </svg>
                <div>
                    <p class="font-semibold text-yellow-900">Transfer Out</p>
                    <p class="text-xs text-yellow-700">Week <?php echo $currentWeek; ?></p>
                </div>
            </div>
            <svg class="w-5 h-5 text-yellow-600 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
            </svg>
        </a>
        
        <a href="<?php echo BASE_URL; ?>/dashboard.php" class="flex items-center justify-between p-4 bg-blue-50 border border-blue-200 rounded-lg hover:bg-blue-100 transition-colors group">
            <div class="flex items-center">
                <svg class="w-8 h-8 text-blue-600 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                </svg>
                <div>
                    <p class="font-semibold text-blue-900">Dashboard</p>
                    <p class="text-xs text-blue-700">Overview</p>
                </div>
            </div>
            <svg class="w-5 h-5 text-blue-600 group-hover:translate-x-1 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
            </svg>
        </a>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/includes/layout.php';
?>
