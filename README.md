# CuboidPilot (Cai AI) — Autonomous AI Helpdesk & SDR Platform

<div align="center">
  <img src="assets/logo-black.png" width="72" alt="CuboidPilot" />
  <h3>The World's First Autonomous AI Helpdesk & SDR Agent Platform</h3>
  <p>Live lead qualification, human escalation directory, 1-on-1 team appointment booking, and direct in-widget payments.</p>
</div>

---

## 🌟 Key Features

1. **Cai Conversational AI Agent (`widget.js`)**
   - Zero-dependency, Shadow-DOM isolated embeddable widget.
   - Dual theme support (Intercom Dark & Intercom Clean Light).
   - Autonomous lead qualification, instant escalation, calendar booking, and payment processing.
   - Fluid responsive mobile drawer and desktop floating window.

2. **Tenant Operations & Workspace Dashboard (`/app`)**
   - Multi-tenant pipeline CRM (Prospect, Qualified, Demo Scheduled, In Negotiation, Won, Lost).
   - Real-time live conversation inspector with human takeover and auto-polling.
   - Team member calendar scheduling with customizable Google Calendar / Cal.com webhook integration.
   - Knowledge Base ingestion (FAQs, URLs, text docs, manual documents).
   - WhatsApp Cloud API webhook syncing.

3. **SuperAdmin Management Portal (`/super-admin`)**
   - Multi-tenant company oversight with tenant lock/unlock and usage controls.
   - Real-time AI token and WhatsApp message metering.
   - Dynamic plan management with multi-currency INR (₹349/mo) and USD pricing.
   - Inside CuboidPilot editorial blog publishing CMS with real-time markdown editor and SEO tags.

4. **Public Marketing & Content Engine**
   - High-converting landing page (`index.html`) featuring interactive Cai widget live demo.
   - Dynamic multi-currency pricing matrix (`pricing.html`) with annual toggle.
   - Inside CuboidPilot editorial blog (`blog.html`) with reading progress bar, search, and category filtering.
   - Intercom-grade authentication & 5-step onboarding flow (`login.php`, `signup.php`, `/onboarding`).

---

## 🚀 Quick Setup & Installation

### 1. Requirements
- PHP 8.0+ with PDO and cURL extensions.
- MySQL 5.7+ or MariaDB 10.3+.
- Apache / Nginx / XAMPP / Laragon.

### 2. Database Setup
1. Create a new MySQL database:
   ```sql
   CREATE DATABASE cuboidpolit_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
2. Import the fresh production schema and seed dump:
   ```bash
   mysql -u root -p cuboidpolit_db < database/cuboidpilot_fresh.sql
   ```

### 3. Environment Configuration
Edit `config/db.php` if your database credentials differ from the defaults:
```php
$host = '127.0.0.1';
$db   = 'cuboidpolit_db';
$user = 'root';
$pass = '';
```

### 4. Default Credentials

| Portal | URL | Email | Password |
|---|---|---|---|
| **SuperAdmin** | `/login.php` | `admin@cuboidpilot.in` | `admin123` |
| **Demo Tenant Owner** | `/login.php` | `owner@apexedtech.in` | `password123` |
| **Consultant (Ayush)** | `/login.php` | `ayush@cuboidsoft.in` | `password123` |

---

## 📦 Embedding the Widget

Add this snippet before the closing `</body>` tag on any website:
```html
<script 
  src="https://cai.cuboidsoft.in/widget.js" 
  data-company="YOUR_COMPANY_KEY" 
  data-theme="dark" 
  defer>
</script>
```

---

## 🛡️ License & Copyright
© 2026 CuboidPilot Technologies Inc. All Rights Reserved.
