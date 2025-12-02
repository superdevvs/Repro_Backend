# Weekly Sales Reports & Automated Invoicing - Implementation Summary

## ✅ Features Implemented

### 1. Weekly Sales Reports to Sales Reps

**Automated Schedule:**
- Runs every Monday at 2:00 AM
- Command: `php artisan reports:sales:weekly`

**What it does:**
- Generates comprehensive weekly sales reports for each sales rep
- Includes statistics: total shoots, completed shoots, revenue, payments, outstanding balance
- Breaks down data by clients and photographers
- Lists top performing shoots
- Sends email notifications to all sales reps

**API Endpoints:**
- `GET /api/reports/sales/weekly` - Get weekly report (sales rep only)
- `GET /api/admin/sales-reports/{salesRepId}` - Get report for specific sales rep (admin)
- `POST /api/admin/sales-reports/send-weekly` - Manually trigger sending reports (admin)

### 2. Weekly Automated Invoicing for Photographers

**Automated Schedule:**
- Runs every Monday at 1:00 AM
- Command: `php artisan invoices:generate --weekly`

**What it does:**
- Automatically generates invoices for photographers based on completed shoots from the previous week
- Creates invoice items for each completed shoot
- Sends email notifications to photographers when invoices are generated
- Sets initial approval status to "pending"

**Photographer Features:**
- ✅ View all their invoices
- ✅ Add additional expenses to invoices
- ✅ Remove expenses from invoices
- ✅ Reject invoices (with optional reason)
- ✅ Submit modified invoices for admin approval

**Admin/SuperAdmin Features:**
- ✅ View invoices pending approval
- ✅ Approve modified invoices
- ✅ Reject modified invoices (with required reason)
- ✅ Receive email notifications when invoices need approval

**API Endpoints for Photographers:**
- `GET /api/photographer/invoices` - List all invoices
- `GET /api/photographer/invoices/{invoice}` - View invoice details
- `POST /api/photographer/invoices/{invoice}/expenses` - Add expense
  - Body: `{ "description": "string", "amount": number, "quantity": number (optional) }`
- `DELETE /api/photographer/invoices/{invoice}/expenses/{item}` - Remove expense
- `POST /api/photographer/invoices/{invoice}/reject` - Reject invoice
  - Body: `{ "reason": "string (optional)" }`
- `POST /api/photographer/invoices/{invoice}/submit-for-approval` - Submit for approval
  - Body: `{ "notes": "string (optional)" }`

**API Endpoints for Admin/SuperAdmin:**
- `GET /api/admin/invoices/pending-approval` - List invoices pending approval
- `POST /api/admin/invoices/{invoice}/approve` - Approve invoice
- `POST /api/admin/invoices/{invoice}/reject` - Reject invoice
  - Body: `{ "reason": "string (required)" }`

## 📧 Email Notifications

The system sends automated emails for:

1. **Weekly Sales Reports** - Sent to sales reps every Monday
2. **Invoice Generated** - Sent to photographers when invoice is created
3. **Invoice Pending Approval** - Sent to all admins when photographer submits changes
4. **Invoice Approved** - Sent to photographer when admin approves
5. **Invoice Rejected** - Sent to photographer when admin rejects

## 🗄️ Database Changes

**Migration:** `2025_01_20_000001_add_invoice_approval_workflow_fields.php`

**New Columns in `invoices` table:**
- `approval_status` (string, default: 'pending')
- `rejection_reason` (text, nullable)
- `rejected_by` (foreign key to users, nullable)
- `rejected_at` (timestamp, nullable)
- `approved_by` (foreign key to users, nullable)
- `approved_at` (timestamp, nullable)
- `modified_by` (foreign key to users, nullable)
- `modified_at` (timestamp, nullable)
- `modification_notes` (text, nullable)

**Invoice Item Types:**
- `charge` - Regular shoot charges
- `payment` - Payments received
- `expense` - Additional expenses added by photographers

## 🔄 Workflow

### Invoice Lifecycle:

1. **Generated** (Monday 1:00 AM)
   - Status: `draft`
   - Approval Status: `pending`
   - Email sent to photographer

2. **Photographer Actions:**
   - Can add expenses
   - Can reject invoice
   - Can submit for approval (if modified)

3. **Pending Approval** (if photographer submits changes)
   - Approval Status: `pending_approval`
   - Email sent to all admins
   - Photographer cannot modify further

4. **Admin Actions:**
   - Can approve → Status: `approved`, Email to photographer
   - Can reject → Status: `rejected`, Email to photographer with reason

5. **After Rejection:**
   - Photographer can modify and resubmit
   - Workflow repeats from step 2

## 🧪 Testing Commands

```bash
# Test invoice generation (without sending emails)
php artisan invoices:generate --weekly --no-email

# Test invoice generation (with emails)
php artisan invoices:generate --weekly

# Test sales report generation and sending
php artisan reports:sales:weekly

# Generate invoices for specific period
php artisan invoices:generate --start=2025-01-01 --end=2025-01-07
```

## 📝 Notes

- Invoices are only generated for shoots with workflow status `completed` or `admin_verified`
- The system prevents duplicate invoices for the same photographer and period
- All actions are logged for audit purposes
- Email failures are logged but don't stop the process

## 🚀 Next Steps

1. ✅ Migration has been run
2. ✅ Commands are registered and working
3. ✅ Routes are configured
4. ⏳ Test with actual data
5. ⏳ Configure email settings if needed
6. ⏳ Monitor scheduled tasks

## 📚 Files Created/Modified

### New Files:
- `app/Services/SalesReportService.php`
- `app/Http/Controllers/SalesReportController.php`
- `app/Http/Controllers/PhotographerInvoiceController.php`
- `app/Http/Controllers/Admin/InvoiceApprovalController.php`
- `app/Console/Commands/SendWeeklySalesReports.php`
- `app/Mail/WeeklySalesReportMail.php`
- `app/Mail/InvoiceGeneratedMail.php`
- `app/Mail/InvoicePendingApprovalMail.php`
- `app/Mail/InvoiceApprovedMail.php`
- `app/Mail/InvoiceRejectedMail.php`
- `resources/views/emails/weekly_sales_report.blade.php`
- `resources/views/emails/invoice_generated.blade.php`
- `resources/views/emails/invoice_pending_approval.blade.php`
- `resources/views/emails/invoice_approved.blade.php`
- `resources/views/emails/invoice_rejected.blade.php`
- `database/migrations/2025_01_20_000001_add_invoice_approval_workflow_fields.php`

### Modified Files:
- `app/Models/Invoice.php` - Added approval workflow support
- `app/Models/InvoiceItem.php` - Added expense type
- `app/Services/InvoiceService.php` - Enhanced for weekly generation with emails
- `app/Services/MailService.php` - Added email methods
- `app/Console/Commands/GenerateInvoices.php` - Added email support
- `app/Console/Kernel.php` - Added scheduled tasks
- `routes/api.php` - Added new routes


