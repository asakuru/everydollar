# EveryDollar Zero-Based Budget

A secure, self-hosted zero-based budgeting application for households. Built with PHP 8.1+, Slim 4, Twig, and MySQL.

## Features

- 🏠 **Shared Household Budget** - Two users can share the same budget
- 💰 **Zero-Based Budgeting** - Every dollar has a job
- 📊 **Monthly Budget Planning** - Plan income and expenses by category
- 📝 **Transaction Tracking** - Log and categorize spending
- 📈 **Simple Reports** - View spending trends and top merchants
- 🔒 **Security-First Design** - OWASP-aligned, secure by default

## Tech Stack

- PHP 8.1+
- MySQL/MariaDB
- Slim 4 Framework
- Twig Templating
- Tailwind CSS (pre-compiled)
- Composer for dependencies

---

## GitHub → cPanel Deploy Checklist

### Prerequisites

1. cPanel shared hosting with PHP 8.1+ and MySQL/MariaDB
2. SSH access OR cPanel Terminal
3. Composer installed (most cPanel hosts have it via SSH)
4. GitHub repository created (private recommended)

---

### Method A: cPanel Git Version Control (Preferred)

> This method uses cPanel's built-in Git integration for one-click deployments.

#### Step 1: Create GitHub Repository

```bash
# On your local machine
cd /path/to/everydollar
git init
git add .
git commit -m "Initial commit"

# Create repo on GitHub, then:
git remote add origin git@github.com:YOUR_USERNAME/everydollar.git
git branch -M main
git push -u origin main
```

#### Step 2: Set Up Deploy Key in GitHub

1. In cPanel, go to **Terminal** or SSH in
2. Generate a deploy key (if you don't have one):
   ```bash
   ssh-keygen -t ed25519 -C "cpanel-deploy" -f ~/.ssh/github_deploy
   ```
3. Copy the public key:
   ```bash
   cat ~/.ssh/github_deploy.pub
   ```
4. In GitHub repo → Settings → Deploy keys → Add deploy key
5. Paste the public key, give it a name, click "Add key"

#### Step 3: Configure SSH for GitHub

```bash
# Create or edit ~/.ssh/config
echo "Host github.com
  IdentityFile ~/.ssh/github_deploy
  IdentitiesOnly yes" >> ~/.ssh/config
```

#### Step 4: Clone Repository in cPanel

1. In cPanel, go to **Git Version Control**
2. Click **Create** or **Clone**
3. Enter:
   - **Clone URL**: `git@github.com:YOUR_USERNAME/everydollar.git`
   - **Repository Path**: `/home/YOUR_USER/repos/everydollar`
   - **Repository Name**: `everydollar`
4. Click **Create**

#### Step 5: Set Up Deployment Path

1. In cPanel Git Version Control, find your repo
2. Click **Manage**
3. Under **Pull or Deploy**, set:
   - **Deployment Path**: `/home/YOUR_USER/public_html/everydollar`
4. Click **Update**
5. Click **Deploy HEAD Commit**

#### Step 6: Post-Deploy Setup

```bash
# SSH into your server
cd ~/repos/everydollar

# Install dependencies
composer install --no-dev --optimize-autoloader

# Create config file (OUTSIDE webroot)
mkdir -p ~/config/everydollar
cp config.sample.php ~/config/everydollar/config.php
nano ~/config/everydollar/config.php   # Edit with your settings

# Create storage directories with proper permissions
mkdir -p storage/logs storage/cache/twig
chmod 755 storage/logs storage/cache storage/cache/twig

# Run migrations
php migrations/migrate.php
```

#### Step 7: Ongoing Updates

```bash
# In cPanel Git Version Control
# Click "Update from Remote" → "Deploy HEAD Commit"

# OR via SSH:
cd ~/repos/everydollar
git pull
composer install --no-dev --optimize-autoloader
php migrations/migrate.php  # If there are new migrations
```

---

### Method B: SSH + Git Pull (Fallback)

> Use this if cPanel Git Version Control is not available.

#### Step 1: Clone Repository

```bash
ssh user@your-server.com
mkdir -p ~/repos
cd ~/repos
git clone git@github.com:YOUR_USERNAME/everydollar.git
cd everydollar
```

#### Step 2: Create Symlink to Public Directory

```bash
# Option 1: Symlink (preferred if your host allows it)
ln -s ~/repos/everydollar/public_html/everydollar ~/public_html/everydollar

# Option 2: If symlinks don't work, copy files
cp -r ~/repos/everydollar/public_html/everydollar ~/public_html/everydollar
```

#### Step 3: Install Dependencies & Configure

```bash
cd ~/repos/everydollar
composer install --no-dev --optimize-autoloader

mkdir -p ~/config/everydollar
cp config.sample.php ~/config/everydollar/config.php
nano ~/config/everydollar/config.php

mkdir -p storage/logs storage/cache/twig
chmod 755 storage/logs storage/cache storage/cache/twig

php migrations/migrate.php
```

#### Step 4: Deploy Script (Optional)

Create `~/deploy.sh`:

```bash
#!/bin/bash
cd ~/repos/everydollar
git pull origin main
composer install --no-dev --optimize-autoloader
php migrations/migrate.php

# If using copy method instead of symlink:
# rsync -avz --delete ~/repos/everydollar/public_html/everydollar/ ~/public_html/everydollar/
```

```bash
chmod +x ~/deploy.sh
# Run with: ~/deploy.sh
```

---

## Safe Secrets Handling

### Configuration File Location

Create `config.php` **outside** the webroot for security:

```
/home/YOUR_USER/
├── config/
│   └── everydollar/
│       └── config.php     ← Secrets here (not web-accessible)
├── public_html/
│   └── everydollar/       ← Web-accessible
│       └── index.php
└── repos/
    └── everydollar/       ← Git repository
```

### Configuration Setup

```bash
mkdir -p ~/config/everydollar
cp ~/repos/everydollar/config.sample.php ~/config/everydollar/config.php
chmod 600 ~/config/everydollar/config.php
```

### Generate Secrets

```bash
# Generate SECRET_KEY (for CSRF, etc.)
php -r "echo 'SECRET_KEY: ' . bin2hex(random_bytes(32)) . PHP_EOL;"

# Generate TOTP encryption key (for future 2FA)
php -r "echo 'TOTP_KEY: ' . base64_encode(random_bytes(32)) . PHP_EOL;"
```

### Never Commit Secrets

The `.gitignore` excludes:
- `/config.php` - Local dev config
- `/.env` and `/.env.*` - Environment files
- `/storage/logs/` - Log files

### If Secrets Are Leaked

1. **Immediately** rotate the `secret_key` in config.php
2. All existing sessions will be invalidated (users must re-login)
3. If database credentials leaked, change MySQL password in cPanel
4. Review access logs for suspicious activity

---

## Database Setup

### Create Database in cPanel

1. Go to **MySQL Databases** in cPanel
2. Create a new database (e.g., `yourusername_everydollar`)
3. Create a database user with a strong password
4. Add the user to the database with **All Privileges**
5. Update `config.php` with these credentials

### Run Migrations

```bash
cd ~/repos/everydollar
php migrations/migrate.php
```

### Reset Database (Caution!)

```bash
# This will DELETE ALL DATA
mysql -u your_db_user -p your_database < migrations/001_initial_schema.sql
php migrations/migrate.php
```

---

## Implementing 2FA (Future Enhancement)

The database schema and service layer are ready for TOTP-based 2FA. Here's how to implement it:

### Step 1: Install TOTP Library

```bash
composer require spomky-labs/otphp
```

### Step 2: Update TotpService.php

```php
use OTPHP\TOTP;

public function generateSecret(string $email): array
{
    $otp = TOTP::create();
    $otp->setLabel($email);
    $otp->setIssuer($this->config['issuer'] ?? 'EveryDollar');
    
    return [
        'secret' => $otp->getSecret(),
        'qr_uri' => $otp->getProvisioningUri(),
    ];
}

public function verify(string $secret, string $code): bool
{
    $otp = TOTP::create($secret);
    return $otp->verify($code, null, 1); // 1 period tolerance
}
```

### Step 3: Encryption Flow

1. When user enables 2FA:
   - Generate TOTP secret with `generateSecret()`
   - Show QR code (using `qr_uri` with a QR library)
   - Require user to enter code to verify setup
   - Encrypt secret with `encryptSecret()` before storing
   - Generate recovery codes with `generateRecoveryCodes()`
   - Store hashed recovery codes

2. On login when 2FA enabled:
   - After password verification, redirect to 2FA page
   - Decrypt secret with `decryptSecret()`
   - Verify code with `verify()`
   - Or allow recovery code as fallback

### Step 4: Database Fields (Already Created)

- `users.totp_enabled` - Is 2FA enabled?
- `users.totp_secret_encrypted` - Encrypted TOTP secret
- `users.totp_recovery_codes_hashed` - JSON array of hashed recovery codes
- `users.last_totp_verified_at` - Last successful 2FA verification

### Security Considerations

- **Never** store TOTP secrets in plain text
- Use the `totp.encryption_key` config value (32 bytes, base64)
- Recovery codes should be hashed (already implemented)
- Mark recovery codes as used immediately after verification
- Consider rate limiting 2FA attempts

---

## Security Features

### Implemented

- ✅ Secure session cookies (Secure, HttpOnly, SameSite=Lax, Path=/everydollar)
- ✅ CSRF protection (synchronizer token pattern)
- ✅ Password hashing (Argon2id/bcrypt)
- ✅ Rate limiting on login attempts (IP + account based)
- ✅ Security headers (CSP, X-Frame-Options, etc.)
- ✅ Prepared statements (PDO)
- ✅ Input validation and output escaping
- ✅ Generic login errors
- ✅ Session regeneration on login

### HSTS Configuration

HSTS is **disabled by default**. To enable:

1. Ensure HTTPS is working correctly on your domain
2. Edit `config.php`:
   ```php
   'security' => [
       'hsts_enabled' => true,
       'hsts_max_age' => 31536000, // 1 year
   ],
   ```

> ⚠️ **Warning**: Once enabled, browsers will refuse HTTP connections for the duration of `max_age`. Test thoroughly first.

---

## File Structure

```
everydollar/
├── public_html/everydollar/    # Web root
│   ├── index.php               # Front controller
│   ├── .htaccess                # Apache rewrite rules
│   └── assets/css/app.css      # Compiled CSS
├── src/
│   ├── Controllers/            # Request handlers
│   ├── Middleware/             # Auth, CSRF, Security headers
│   ├── Services/               # Business logic
│   ├── bootstrap.php           # App configuration
│   └── routes.php              # Route definitions
├── templates/                   # Twig templates
├── migrations/                  # SQL migrations
├── storage/
│   ├── logs/                   # Application logs
│   └── cache/                  # Twig cache
├── composer.json
├── config.sample.php           # Configuration template
└── README.md
```

---

## Troubleshooting

### 500 Internal Server Error

1. Check PHP error logs: `~/logs/error.log` or cPanel Error Log
2. Verify `config.php` exists and has correct database credentials
3. Ensure storage directories exist and are writable
4. Check PHP version: `php -v` (must be 8.1+)

### CSS Not Loading

1. Clear browser cache
2. Verify `/everydollar/assets/css/app.css` is accessible
3. Check for mixed content warnings (HTTP vs HTTPS)

### Session Issues

1. Verify cookie settings in browser DevTools
2. Check that session path is `/everydollar`
3. Ensure sessions are not being blocked by ad blockers

### Rate Limiting Locked Out

Wait 15 minutes, or manually clear from database:

```sql
DELETE FROM login_attempts WHERE ip_address = 'YOUR_IP';
```

---

## License

Proprietary. For personal use only.
