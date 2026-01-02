# Officer Details Modal Component

A reusable modal component for displaying officer information quickly without navigating away from the current page.

## Features

- ✅ Quick view of officer details in a modal popup
- ✅ Displays personal information, location, registry data, and departments
- ✅ Obfuscated names for security
- ✅ "View Full Page" link for detailed view
- ✅ Dark mode support
- ✅ Responsive design
- ✅ Loading and error states
- ✅ Keyboard accessible (ESC to close)
- ✅ Role-based permissions

## Files

- **Component**: `/includes/ui-components.php` - `renderOfficerDetailsModal()` function
- **JavaScript**: `/assets/js/officer-details-modal.js` - Modal logic and API calls
- **API Endpoint**: `/api/get-officer-details.php` - Fetches officer data
- **Example**: `/officer-modal-example.php` - Usage demonstration

## Quick Start

### 1. Include Required Files

```php
<?php
require_once __DIR__ . '/includes/ui-components.php';
?>
```

### 2. Render the Modal Component

Add this before your content's `ob_get_clean()`:

```php
<?php
// Render the reusable officer details modal
renderOfficerDetailsModal();

$content = ob_get_clean();
?>
```

### 3. Include JavaScript File

```php
<?php
// Add the JavaScript file for the officer modal
$extraScripts = '<script src="' . BASE_URL . '/assets/js/officer-details-modal.js"></script>';

include __DIR__ . '/includes/layout.php';
?>
```

### 4. Trigger the Modal

Add buttons or links in your HTML to open the modal:

```html
<!-- As a button -->
<button onclick="OfficerDetailsModal.open('officer-uuid-here')" 
        class="text-purple-600 hover:text-purple-800"
        title="Quick View">
    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
              d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
    </svg>
</button>

<!-- As a link -->
<a href="#" 
   onclick="event.preventDefault(); OfficerDetailsModal.open('officer-uuid-here');"
   class="text-purple-600 hover:text-purple-800">
    Quick View
</a>
```

## Complete Example

```php
<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/includes/ui-components.php';

Security::requireLogin();

$pageTitle = 'My Page';
ob_start();
?>

<div class="p-6">
    <h1>Officers List</h1>
    
    <?php foreach ($officers as $officer): ?>
        <div class="flex items-center justify-between p-4 border rounded">
            <span><?php echo obfuscateName($officer['name']); ?></span>
            
            <div class="flex gap-2">
                <!-- Quick View Modal Button -->
                <button onclick="OfficerDetailsModal.open('<?php echo $officer['uuid']; ?>')" 
                        class="px-3 py-1 text-purple-600 hover:bg-purple-50 rounded">
                    Quick View
                </button>
                
                <!-- Full Page Link -->
                <a href="<?php echo BASE_URL; ?>/officers/view.php?id=<?php echo $officer['uuid']; ?>"
                   class="px-3 py-1 text-blue-600 hover:bg-blue-50 rounded">
                    View Full
                </a>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php
// Render the modal
renderOfficerDetailsModal();

$content = ob_get_clean();

// Add JavaScript
$extraScripts = '<script src="' . BASE_URL . '/assets/js/officer-details-modal.js"></script>';

include __DIR__ . '/includes/layout.php';
?>
```

## Pages Using This Component

✅ `/requests/list.php` - Officer Requests List
✅ `/reports/lorc-lcrc-checker.php` - LORC/LCRC Checker Report
✅ `/reports/r518-checker.php` - R5-18 Checker Report
✅ `/officer-modal-example.php` - Usage Example

## API Response Structure

```json
{
  "officer_uuid": "uuid-here",
  "full_name": "Jo** D**",
  "last_name": "Do*",
  "first_name": "Jo**",
  "middle_initial": "D",
  "is_active": true,
  "district_code": "D001",
  "district_name": "District Name",
  "local_code": "L001",
  "local_name": "Local Name",
  "purok": "Purok 1",
  "grupo": "Grupo A",
  "control_number": "CN-001",
  "registry_number": "RN-001",
  "departments": [
    {
      "id": 1,
      "department": "Department Name",
      "duty": "Duty Name",
      "is_active": 1,
      "created_at": "2026-01-01 00:00:00"
    }
  ]
}
```

## Security Features

- **Role-based access**: Users can only view officers within their scope
- **Name obfuscation**: Names are obfuscated for security
- **Permission checks**: API validates user permissions
- **Encrypted data**: Names stored encrypted in database
- **XSS protection**: All output is escaped

## Customization

### Modal Styling

The modal uses Tailwind CSS classes and supports dark mode automatically. You can customize the appearance by modifying the `renderOfficerDetailsModal()` function in `/includes/ui-components.php`.

### JavaScript Events

The modal dispatches events that you can listen to:

```javascript
// Listen for modal open
document.addEventListener('modalOpened', function(event) {
    console.log('Modal opened for officer:', event.detail.officerUuid);
});

// Listen for modal close
document.addEventListener('modalClosed', function() {
    console.log('Modal closed');
});
```

### API Customization

You can extend the API endpoint at `/api/get-officer-details.php` to include additional officer data as needed.

## Troubleshooting

### Modal doesn't appear
- Ensure `renderOfficerDetailsModal()` is called before `ob_get_clean()`
- Check that the JavaScript file is included with `$extraScripts`
- Verify the modal div has id="officerDetailsModal"

### "Officer not found" error
- Verify the officer UUID is correct
- Check user has permission to view the officer (role-based access)
- Ensure officer exists in database

### JavaScript errors
- Check browser console for specific errors
- Verify BASE_URL is properly defined
- Ensure fetch API is supported by browser

## Best Practices

1. **Always include all three components**: PHP function, JavaScript file, and CSS/HTML
2. **Use obfuscated names**: Never display full names in untrusted contexts
3. **Check permissions**: Verify user can access officer data before showing button
4. **Provide fallback**: Always include a "View Full Page" link as alternative
5. **Accessibility**: Ensure modal is keyboard accessible (ESC to close)

## Future Enhancements

- [ ] Add call-up history to modal
- [ ] Include transfer history
- [ ] Show recent requests
- [ ] Add edit button (for authorized users)
- [ ] Support for printing modal content
- [ ] Add officer photo display
- [ ] Include timeline of officer activities
