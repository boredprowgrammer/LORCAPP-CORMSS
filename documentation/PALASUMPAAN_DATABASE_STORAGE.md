# Palasumpaan Document Storage

## Overview
The palasumpaan generator now stores temporary DOCX and PDF files in the database instead of the file system for better security and management.

## Database Table

### `palasumpaan_temp_docs`
Stores temporary documents during the palasumpaan generation process.

| Column | Type | Description |
|--------|------|-------------|
| `id` | INT | Primary key, auto-increment |
| `request_id` | INT | Foreign key to officer_requests |
| `docx_content` | LONGBLOB | The generated DOCX file content |
| `pdf_content` | LONGBLOB | The converted PDF file content (nullable) |
| `created_at` | TIMESTAMP | When the document was created |
| `converted_at` | TIMESTAMP | When the PDF conversion completed (nullable) |

### Indexes
- Primary Key: `id`
- Unique Key: `request_id` (one document per request)
- Foreign Key: `request_id` → `officer_requests(request_id)` (CASCADE DELETE)
- Index: `created_at` (for cleanup queries)

## Document Lifecycle

1. **Generation**: When a palasumpaan is generated, the DOCX is stored in `docx_content`
2. **Conversion**: The DOCX is sent to Stirling PDF for conversion
3. **Storage**: The resulting PDF is stored in `pdf_content`
4. **Delivery**: The PDF is streamed to the user's browser
5. **Cleanup**: Documents older than 7 days are automatically deleted

## Benefits

### Security
- ✅ Documents stored in database with proper access controls
- ✅ No files left on file system
- ✅ Automatic cleanup of old documents
- ✅ Foreign key constraints ensure data integrity

### Performance
- ✅ LONGBLOB supports files up to 4GB
- ✅ Indexed for fast retrieval
- ✅ Minimal disk I/O operations

### Maintenance
- ✅ Automatic cleanup via cron job
- ✅ CASCADE DELETE when request is removed
- ✅ Easy to backup with database

## Cleanup Script

### Manual Cleanup
```bash
php cleanup-palasumpaan-temp.php
```

### Automated Cleanup (Cron Job)
Add to crontab to run daily at 2 AM:
```bash
0 2 * * * cd /path/to/project && php cleanup-palasumpaan-temp.php >> logs/palasumpaan-cleanup.log 2>&1
```

### Cleanup Policy
- Documents older than **7 days** are automatically deleted
- Configurable in the cleanup script

## Storage Requirements

### Typical Document Sizes
- DOCX: ~50-100 KB
- PDF: ~100-200 KB

### Capacity Planning
With 1000 requests per month:
- Monthly storage: ~150-300 MB
- 7-day retention: ~35-70 MB active storage

### Database Considerations
- Ensure adequate disk space for database
- Monitor LONGBLOB storage growth
- Consider archiving very old records if needed

## API Flow

### Document Generation
```
1. User requests palasumpaan generation
2. System loads DOCX template
3. System replaces placeholders with data
4. System saves DOCX to memory
5. System stores DOCX in database (INSERT/UPDATE)
6. System sends DOCX to Stirling PDF
7. System receives PDF from Stirling PDF
8. System stores PDF in database (UPDATE)
9. System streams PDF to user browser
```

### Error Handling
- If PDF conversion fails, DOCX is still stored
- Users can retry conversion without regenerating DOCX
- Database transactions ensure consistency

## Monitoring

### Check Storage Size
```sql
SELECT 
    COUNT(*) as total_documents,
    ROUND(SUM(LENGTH(docx_content))/1024/1024, 2) as docx_mb,
    ROUND(SUM(LENGTH(pdf_content))/1024/1024, 2) as pdf_mb,
    ROUND(SUM(LENGTH(docx_content) + COALESCE(LENGTH(pdf_content), 0))/1024/1024, 2) as total_mb
FROM palasumpaan_temp_docs;
```

### Check Old Documents
```sql
SELECT 
    COUNT(*) as old_documents,
    MIN(created_at) as oldest_document
FROM palasumpaan_temp_docs 
WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY);
```

### Recent Conversions
```sql
SELECT 
    request_id,
    created_at,
    converted_at,
    TIMESTAMPDIFF(SECOND, created_at, converted_at) as conversion_time_seconds
FROM palasumpaan_temp_docs 
WHERE converted_at IS NOT NULL
ORDER BY converted_at DESC 
LIMIT 10;
```

## Troubleshooting

### Documents Not Being Stored
1. Check database connection
2. Verify table exists: `SHOW TABLES LIKE 'palasumpaan_temp_docs'`
3. Check disk space on database server
4. Review error logs

### Cleanup Not Working
1. Verify cron job is running: `crontab -l`
2. Check cron logs: `tail -f /var/log/cron`
3. Run manually to test: `php cleanup-palasumpaan-temp.php`
4. Check database permissions

### Storage Growing Too Large
1. Reduce retention period (currently 7 days)
2. Run cleanup more frequently
3. Consider archiving instead of deleting
4. Optimize DOCX template file size

## Migration from File System

If migrating from file-based storage:
1. Existing files are not affected
2. New generations use database storage
3. Old files can be cleaned up separately
4. No backward compatibility needed
