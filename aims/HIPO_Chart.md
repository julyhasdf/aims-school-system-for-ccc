# HIPO Chart (Hierarchy plus Input-Process-Output) for AIMS

This document outlines the hierarchical structure and the Input-Process-Output logic for the Academic Information Management System (AIMS).

## 1. Visual Table of Contents (VTOC)

This hierarchy chart represents the breakdown of the AIMS system into manageable functions.

```text
1.0 AIMS System
├── 2.0 Authentication Module
│   ├── 2.1 User Login
│   ├── 2.2 Student Registration
│   └── 2.3 Logout
│
├── 3.0 Admin Module
│   ├── 3.1 Dashboard & Analytics
│   ├── 3.2 User Management
│   │   ├── 3.2.1 View/Search Users
│   │   ├── 3.2.2 Edit User Roles (Auto-sync)
│   │   └── 3.2.3 Delete User (Cascade)
│   ├── 3.3 Academic Management
│   │   ├── 3.3.1 Student Management
│   │   ├── 3.3.2 Teacher Management
│   │   ├── 3.3.3 Subject Management
│   │   └── 3.3.4 Section & Schedule Management
│   └── 3.4 System Utilities
│       ├── 3.4.1 Announcements
│       └── 3.4.2 Reports Generation
│
├── 4.0 Teacher Module
│   ├── 4.1 Dashboard Overview
│   ├── 4.2 Class Management
│   │   └── 4.2.1 View Assigned Sections
│   └── 4.3 Grading System
│       └── 4.3.1 Input/Update Grades
│
└── 5.0 Student Module
    ├── 5.1 Dashboard Overview
    ├── 5.2 Academic Records
    │   └── 5.2.1 View Grades
    └── 5.3 Schedule Viewer
```

---

## 2. Input-Process-Output (IPO) Diagrams

Below are the IPO details for the main modules identified in the VTOC.

### 2.0 Authentication Module

| Module | Input | Process | Output |
| :--- | :--- | :--- | :--- |
| **2.1 Login** | Username, Password | 1. Validate credentials against `users` table.<br>2. Verify account status (Active/Inactive).<br>3. Determine user role (Admin/Teacher/Student).<br>4. Create Session. | Dashboard Redirect or Error Message |
| **2.2 Registration** | First Name, Last Name, Username, Email, Password | 1. Validate format (Email, Password strength).<br>2. Check for duplicate Username.<br>3. Hash Password.<br>4. Insert into `users` table.<br>5. Generate Student ID (YYYY-XXXX).<br>6. Insert into `students` table. | New Account, Success Message |

### 3.0 Admin Module

| Module | Input | Process | Output |
| :--- | :--- | :--- | :--- |
| **3.2 User Management** | User Details (Role Change) | 1. Fetch current user role.<br>2. Update `users` table.<br>3. **Sync Role**: If role changed, delete old profile (e.g., Student) and create new profile (e.g., Teacher) with generated ID.<br>4. Commit Transaction. | Updated User Record, Synced Profile |
| **3.3.1 Student Mgmt** | Student Details (Name, Grade, Section) | 1. Validate inputs.<br>2. Update `users` and `students` tables.<br>3. Delete: Remove User (Cascade removes Student & Grades). | Updated/Deleted Student Record |
| **3.3.4 Section Mgmt** | Section Name, Grade, Adviser | 1. Check for duplicates.<br>2. Insert/Update `sections` table.<br>3. Delete: Clean up dependencies (Schedules, Teacher Loads) then delete Section. | New/Updated Section, Clean Database |
| **3.4.1 Announcements** | Title, Message, Target Audience | 1. Insert into `announcements`.<br>2. **Trigger**: Broadcast Notifications to target users. | Published Announcement, User Notifications |

### 4.0 Teacher Module

| Module | Input | Process | Output |
| :--- | :--- | :--- | :--- |
| **4.3 Grading** | Student ID, Subject ID, Grade Value | 1. Verify Teacher is assigned to Subject/Section.<br>2. Update `grades` table.<br>3. **Trigger**: Send Notification to Student ("Grade Updated"). | Saved Grade, Student Notification |

### 5.0 Student Module

| Module | Input | Process | Output |
| :--- | :--- | :--- | :--- |
| **5.2 View Grades** | Student ID (Session) | 1. Query `grades` joined with `subjects`.<br>2. Filter by School Year/Period. | Grade Report / Card |
| **5.3 View Schedule** | Student ID (Session) | 1. Fetch assigned Section.<br>2. Query `schedules` for that Section. | Class Schedule Table |

---

### Data Flow Summary
1.  **Users** interact with the **UI (Forms/Dashboards)**.
2.  **PHP Scripts** process the logic, handling validation, security checks, and business rules (like role syncing).
3.  **MySQL Database** stores the structured data, ensuring integrity via Foreign Keys (Cascading Deletes).
4.  **Feedback** returns to the user via Notifications, Success Messages, or updated Tables.
