# Owner Dashboard Maintenance Display Update

## Overview
Updated the owner dashboard to clearly separate and display:
- **Recent maintenance data** → Shows in Checklist Approval section (pending approvals)
- **Old maintenance data** → Shows in Maintenance History section (completed/rejected/closed jobs)

## Changes Made

### 1. Updated `owner/approvals.php`
**Before:** Showed ALL maintenance jobs (pending, approved, rejected, closed) in one table

**After:** Separated into two distinct sections:

#### Section 1: Pending Approvals (Recent Data)
- Shows ONLY jobs with status `pending_owner`
- These are recent maintenance checklists awaiting owner approval
- Displays with warning color scheme to indicate action needed
- Includes Approve/Reject buttons for each pending item
- Shows count badge with number of pending approvals

#### Section 2: Recent History (Last 30 Days)
- Shows completed/approved/rejected/closed jobs from the last 30 days
- Provides quick reference to recent maintenance activity
- Limited to 10 most recent items
- No action buttons (read-only view)
- Helps owners track what was recently done

#### Additional Feature:
- Added "View Complete Maintenance History" button linking to `maintenance-history.php`
- This allows owners to access full historical data when needed

### 2. Updated `owner/dashboard.php`
**Added:** Quick reference section with two cards showing:

#### Card 1: Pending Checklist Approvals
- Shows up to 5 most recent pending approvals
- Direct "Review" button for each item
- Links to approval page for action
- Shows "No pending approvals" message when all caught up

#### Card 2: Recent Maintenance History
- Shows up to 5 most recent completed maintenance jobs
- Displays status badges (Approved/Rejected/Closed)
- Provides quick overview of recent activity
- Links to full maintenance history page

## Data Flow

### Recent Data (Pending Approvals)
```
Maintenance Job Created → Technician Submits Checklist 
→ Status: 'pending_owner' → Shows in Approvals Section
→ Owner Reviews → Approve/Reject → Status Changes
```

### Old Data (Maintenance History)
```
Job Completed → Status Changes to 'approved'/'rejected'/'closed'
→ Moves to Recent History (30 days) → Eventually to Full History
→ Accessible via "View Complete Maintenance History" link
```

## Database Queries Used

### Pending Approvals Query
```sql
SELECT mj.id, mj.checklist, mj.remarks, mj.file, mj.created_at, mj.status, a.asset_name
FROM maintenance_jobs mj
JOIN assets a ON a.id = mj.asset_id
WHERE a.owner_id = ? AND mj.status = 'pending_owner' AND IFNULL(mj.job_type, '') != 'fortnightly'
ORDER BY mj.created_at DESC
```

### Recent History Query (Last 30 Days)
```sql
SELECT mj.id, mj.checklist, mj.remarks, mj.file, mj.created_at, mj.updated_at, mj.status, a.asset_name
FROM maintenance_jobs mj
JOIN assets a ON a.id = mj.asset_id
WHERE a.owner_id = ? AND mj.status IN ('approved','rejected','closed') 
  AND IFNULL(mj.job_type, '') != 'fortnightly'
  AND mj.updated_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
ORDER BY mj.updated_at DESC, mj.created_at DESC
LIMIT 10
```

## User Experience Improvements

1. **Clear Separation:** Owners can easily distinguish between:
   - Items needing their attention (pending approvals)
   - Items already processed (recent history)

2. **Prioritized View:** Most important items (pending approvals) appear first with prominent styling

3. **Quick Access:** Dashboard provides at-a-glance view of both pending and recent items

4. **Reduced Clutter:** Old completed items no longer mixed with pending approvals

5. **Easy Navigation:** Clear links to view full details when needed

## Files Modified
- `owner/approvals.php` - Restructured to show pending vs recent history
- `owner/dashboard.php` - Added quick reference cards for pending and recent maintenance

## Testing Recommendations
1. Test with pending maintenance jobs to verify approval section
2. Test with completed maintenance jobs to verify recent history section
3. Verify dashboard cards show correct counts and items
4. Test navigation links work correctly
5. Verify date filtering (30-day window) works as expected