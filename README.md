# NLETA Maintenance Management System

A comprehensive lift/elevator maintenance management system for NLETA (National Lift Escalator Testing Agency).

## Features

### Admin Panel
- Dashboard with analytics and alerts
- Asset/Lift Management
- User Management (Admin, Owner, Technician)
- Maintenance Tracking
- Callback Management
- Insurance Tracking
- NOC Registration & Renewal
- Fortnightly Safety Checks
- News Management

### Owner Panel
- View owned lifts
- Create callbacks for issues
- Approve maintenance reports
- Track insurance status
- Fortnightly check submissions

### Technician Panel
- View assigned lifts
- Submit maintenance reports
- Handle callbacks
- Update job status

## Requirements

- PHP 7.4 or higher
- MySQL 5.7 or higher
- Apache/Nginx web server
- Web browser (Chrome, Firefox, Edge)

## Installation

### 1. Database Setup

Create a MySQL database and import the schema:

```sql
CREATE DATABASE maintenance_system;
```

Import the database structure (create tables as needed based on the application).

### 2. Configure Database Connection

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

### 3. Web Server Setup

#### Option A: Using XAMPP/WAMP
1. Copy project folder to `htdocs` (XAMPP) or `www` (WAMP)
2. Rename folder to `dashboard`
3. Access: `http://localhost/dashboard/`

#### Option B: Using PHP Built-in Server
```bash
cd dashboard_original
php -S localhost:8000
```
Access: `http://localhost:8000/`

### 4. Fix Path Configuration

Update `config/auth.php` to match your setup:

```php
<?php
if (!isset($_SESSION['role'])) {
    header("Location: /login.php");
    exit;
}
?>
```

## Default Login Credentials

Create admin user in database:

```sql
INSERT INTO users (name, email, password, role) 
VALUES ('Admin', 'admin@nleta.org', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin');
```

Password: `password` (hashed with bcrypt)

## Project Structure

```
dashboard_original/
├── admin/              # Admin panel files
├── owner/              # Owner panel files
├── maintenance/        # Technician panel files
├── config/             # Configuration files
│   ├── db.php         # Database connection
│   └── auth.php       # Authentication check
├── includes/           # Reusable components
│   ├── header.php
│   ├── footer.php
│   ├── sidebar-admin.php
│   ├── sidebar-owner.php
│   └── sidebar-tech.php
├── assets/             # Static assets
│   ├── css/
│   ├── images/
│   └── uploads/
├── uploads/            # User uploaded files
├── index.php           # Entry point (redirects to login)
├── login.php           # Login page
└── logout.php          # Logout handler
```

## Usage

### Admin
1. Login with admin credentials
2. Add users (owners, technicians)
3. Add assets/lifts
4. Assign technicians to owners
5. Monitor maintenance and callbacks

### Owner
1. Login with owner credentials
2. View your lifts
3. Create callbacks for issues
4. Approve maintenance reports
5. Upload insurance documents

### Technician
1. Login with technician credentials
2. View assigned lifts
3. Submit maintenance checklists
4. Handle callbacks
5. Upload work reports

## Security Notes

- All passwords are hashed using PHP's `password_hash()`
- Session-based authentication
- Role-based access control
- SQL injection prevention needed (use prepared statements)

## Troubleshooting

### Database Connection Failed
- Check MySQL service is running
- Verify credentials in `config/db.php`
- Ensure database exists

### 404 Errors on Links
- Check if project is in correct folder
- Update paths in `config/auth.php`
- Verify `.htaccess` if using Apache

### Session Issues
- Ensure `session_start()` is called
- Check PHP session configuration
- Clear browser cookies

## License

© 2025 NLETA. All Rights Reserved.

## Support

For issues and support, contact NLETA technical team.
