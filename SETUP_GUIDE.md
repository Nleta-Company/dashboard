# 🚀 NLETA System - Complete Setup Guide

## Quick Start (3 Steps)

### Step 1: Install Requirements
- **XAMPP** (recommended) or **WAMP** - Download from [apachefriends.org](https://www.apachefriends.org/)
- Or any web server with PHP 7.4+ and MySQL 5.7+

### Step 2: Setup Project
1. Copy the `dashboard_original` folder to:
   - **XAMPP**: `C:\xampp\htdocs\`
   - **WAMP**: `C:\wamp64\www\`

2. Rename folder to `dashboard` (optional but recommended)

3. Start Apache and MySQL from XAMPP/WAMP Control Panel

### Step 3: Run Setup
1. Open browser and go to: `http://localhost/dashboard_original/setup.php`
2. Fill in the form:
   - **Database Name**: `maintenance_system` (or your choice)
   - **MySQL Username**: `root`
   - **MySQL Password**: (leave blank for XAMPP default)
   - **Admin Email**: `admin@nleta.org`
   - **Admin Password**: Choose a strong password
3. Click **Run Setup**
4. After success, **DELETE** `setup.php` file for security

### Step 4: Login
Go to: `http://localhost/dashboard_original/login.php`

**Default Credentials:**
- Email: `admin@nleta.org`
- Password: (what you set in setup)

---

## 📁 Project Structure

```
dashboard_original/
├── admin/              # Admin panel (manage everything)
├── owner/              # Owner panel (RWA users)
├── maintenance/        # Technician panel
├── config/
│   ├── db.php         # Database connection
│   └── auth.php       # Authentication
├── includes/           # Headers, sidebars, footers
├── assets/             # CSS, images
├── uploads/            # User uploaded files
├── setup.php          # One-time setup (DELETE after use)
├── login.php          # Login page
└── index.php          # Redirects to login
```

---

## 🔧 Manual Setup (Alternative)

If you prefer manual setup:

### 1. Create Database
Open phpMyAdmin: `http://localhost/phpmyadmin`

Run this SQL:
```sql
CREATE DATABASE maintenance_system;
```

### 2. Import Tables
Run the SQL from `database.sql` file in phpMyAdmin

### 3. Configure Database
Edit `config/db.php`:
```php
<?php
$conn = new mysqli("localhost", "root", "", "maintenance_system");
if ($conn->connect_error) {
    die("Database Connection Failed");
}
session_start();
?>
```

### 4. Create Admin User
Run in phpMyAdmin:
```sql
USE maintenance_system;

INSERT INTO users (name, email, password, role) VALUES
('Admin', 'admin@nleta.org', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin');
```
Password: `password`

---

## 👥 User Roles & Features

### 1. Admin
**Access:** All features

**Main Functions:**
- Dashboard with analytics
- Add/Edit Assets (Lifts)
- Add/Edit Users (Owners, Technicians)
- Assign Technicians to Owners
- Monitor Maintenance Records
- Track Callbacks
- Manage Insurance
- NOC Registration & Renewal
- Fortnightly Safety Checks
- News Management

**Login:** `admin@nleta.org`

### 2. Owner (RWA)
**Access:** Own lifts only

**Main Functions:**
- View owned lifts status
- Create callbacks for issues
- Approve/Reject maintenance reports
- Upload insurance documents
- Submit fortnightly checks
- View maintenance history

**How to Create:**
1. Admin → Users → Add User
2. Role: Owner (RWA)
3. Assign Technician (optional)

### 3. Technician
**Access:** Assigned lifts only

**Main Functions:**
- View assigned lifts
- Submit maintenance checklists
- Handle callbacks
- Upload work reports
- Update job status

**How to Create:**
1. Admin → Users → Add User
2. Role: Technician

---

## 🎯 How to Use the System

### For Admin

#### 1. Add Assets (Lifts)
1. Go to **Assets/Lifts** → **Add Asset**
2. Fill in:
   - Asset Name (e.g., "Tower A - Lift 1")
   - Location
   - Type (Lift/Escalator/Travelator)
   - Category (I/II/III for maintenance frequency)
   - Maintenance Start Date
   - Assign Owner
3. Click **Save Asset**

#### 2. Add Users
1. Go to **User Management** → **Add User**
2. Fill in Name, Email, Password, Role
3. For Owners: Assign a Technician
4. Click **Create User**

#### 3. Assign Technician to Owner
1. Go to **User Management**
2. Click **Edit** on Owner
3. Select Technician from dropdown
4. Save

#### 4. Monitor Maintenance
- Dashboard shows overdue maintenance
- **Maintenance** page shows all records
- Alerts appear for due/overdue items

### For Owner

#### 1. Create Callback
1. Go to **Create Callback**
2. Select Lift
3. Choose Issue Type
4. Add Description
5. Submit

#### 2. Approve Maintenance
1. Check **Checklist Approvals** (badge shows pending count)
2. Review technician's report
3. Approve or Reject with remarks

#### 3. Upload Insurance
1. Go to **Insurance**
2. Select Lift
3. Upload document
4. Set expiry date

### For Technician

#### 1. View Assigned Lifts
1. Go to **Assigned Lifts**
2. See all lifts assigned to you

#### 2. Submit Maintenance
1. Go to **Maintenance Update**
2. Select Lift
3. Fill checklist
4. Add remarks
5. Upload report (optional)
6. Submit

#### 3. Handle Callbacks
1. Go to **Callbacks**
2. Click **Start Work**
3. After completion, submit report
4. Upload photos/documents

---

## 🔐 Security Best Practices

1. **Delete setup.php** after installation
2. Change default admin password
3. Use strong passwords for all users
4. Keep database credentials secure
5. Regular backups of database
6. Update PHP and MySQL regularly

---

## 🐛 Troubleshooting

### Database Connection Failed
**Solution:**
- Check MySQL is running in XAMPP/WAMP
- Verify credentials in `config/db.php`
- Ensure database exists

### 404 Error on Pages
**Solution:**
- Check project folder name matches URLs
- Verify Apache is running
- Check file paths in code

### Login Not Working
**Solution:**
- Clear browser cache/cookies
- Check user exists in database
- Verify password is hashed correctly

### Upload Errors
**Solution:**
- Check `uploads/` folder permissions
- Verify PHP `upload_max_filesize` setting
- Ensure folder exists

### Session Issues
**Solution:**
- Check `session_start()` is called
- Clear browser cookies
- Restart Apache

---

## 📊 Database Tables

- **users** - All system users
- **assets** - Lifts/Escalators
- **maintenance_records** - Maintenance history
- **maintenance_jobs** - Pending/ongoing jobs
- **callbacks** - Issue reports
- **insurance** - Insurance tracking
- **noc_registration** - NOC certificates
- **fortnightly_checks** - Safety checks
- **news** - System announcements

---

## 🌐 Access URLs

After setup, access:

- **Login**: `http://localhost/dashboard_original/login.php`
- **Admin**: `http://localhost/dashboard_original/admin/dashboard.php`
- **Owner**: `http://localhost/dashboard_original/owner/dashboard.php`
- **Technician**: `http://localhost/dashboard_original/maintenance/dashboard.php`

---

## 📞 Support

For technical support:
- Check README.md for documentation
- Review code comments
- Contact NLETA technical team

---

## ✅ Post-Setup Checklist

- [ ] Database created and tables imported
- [ ] Admin user created and can login
- [ ] setup.php deleted
- [ ] Test creating an owner user
- [ ] Test creating a technician user
- [ ] Test adding an asset
- [ ] Test assigning technician to owner
- [ ] All buttons and links working
- [ ] File uploads working
- [ ] Dashboard charts displaying

---

**🎉 Setup Complete! Your NLETA Maintenance System is ready to use.**
