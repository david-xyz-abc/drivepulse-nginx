#!/bin/bash
# Uninstaller for Self Hosted Google Drive (DriveDAV)
# This script completely removes everything installed by the install.sh script,
# including all dependencies, folders, and data.
# WARNING: This will delete ALL user data and application files!
# Run this as root (e.g., sudo bash uninstall.sh)

set -e  # Exit immediately if a command fails

# Log output for troubleshooting
LOGFILE="/var/log/selfhostedgdrive_uninstall.log"
exec > >(tee -a "$LOGFILE") 2>&1

echo "======================================"
echo "Self Hosted Google Drive (DriveDAV) Uninstaller"
echo "======================================"
echo "WARNING: This will completely remove the application and ALL USER DATA!"
echo "This action cannot be undone!"
echo "======================================"
echo "Press CTRL+C now to cancel or wait 10 seconds to continue..."
sleep 10

# Check for root privileges
if [ "$(id -u)" -ne 0 ]; then
  echo "ERROR: This script must be run as root. Try: sudo bash uninstall.sh"
  exit 1
fi

# Define application directories (same as in install.sh)
APP_DIR="/var/www/html/selfhostedgdrive"
WEBDAV_DIR="/var/www/html/webdav"
WEBDAV_USERS_DIR="$WEBDAV_DIR/users"
USERS_JSON="$APP_DIR/users.json"

# Determine PHP version
PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')

# Locate the php.ini files for CLI and PHP-FPM
CLI_PHP_INI="/etc/php/$PHP_VERSION/cli/php.ini"
FPM_PHP_INI="/etc/php/$PHP_VERSION/fpm/php.ini"

echo "======================================"
echo "Step 1: Removing Nginx configuration..."
echo "======================================"

# Remove Nginx site configuration
if [ -L /etc/nginx/sites-enabled/selfhostedgdrive ]; then
  echo "Removing Nginx site from sites-enabled..."
  unlink /etc/nginx/sites-enabled/selfhostedgdrive
fi

if [ -f /etc/nginx/sites-available/selfhostedgdrive ]; then
  echo "Removing Nginx site configuration file..."
  rm -f /etc/nginx/sites-available/selfhostedgdrive
fi

# Re-enable default Nginx site if it exists
if [ -f /etc/nginx/sites-available/default ] && [ ! -L /etc/nginx/sites-enabled/default ]; then
  echo "Re-enabling default Nginx site..."
  ln -s /etc/nginx/sites-available/default /etc/nginx/sites-enabled/
fi

echo "======================================"
echo "Step 2: Restoring PHP configuration..."
echo "======================================"

# Restore PHP configuration from backups if they exist
if [ -f "${CLI_PHP_INI}.backup" ]; then
  echo "Restoring CLI php.ini from backup..."
  cp "${CLI_PHP_INI}.backup" "$CLI_PHP_INI"
  rm -f "${CLI_PHP_INI}.backup"
fi

if [ -f "${FPM_PHP_INI}.backup" ]; then
  echo "Restoring PHP-FPM php.ini from backup..."
  cp "${FPM_PHP_INI}.backup" "$FPM_PHP_INI"
  rm -f "${FPM_PHP_INI}.backup"
fi

echo "======================================"
echo "Step 3: Removing application files and user data..."
echo "======================================"

# Remove application directory and all user data
if [ -d "$APP_DIR" ]; then
  echo "Removing application directory at $APP_DIR..."
  rm -rf "$APP_DIR"
fi

# Remove WebDAV directory and all user files
if [ -d "$WEBDAV_DIR" ]; then
  echo "Removing WebDAV directory and all user files at $WEBDAV_DIR..."
  rm -rf "$WEBDAV_DIR"
fi

echo "======================================"
echo "Step 4: Removing log files..."
echo "======================================"

# Remove installation log file
if [ -f "/var/log/selfhostedgdrive_install.log" ]; then
  echo "Removing installation log file..."
  rm -f "/var/log/selfhostedgdrive_install.log"
fi

echo "======================================"
echo "Step 5: Uninstalling dependencies..."
echo "======================================"

# Ask user if they want to remove dependencies (nginx, php, etc.)
echo "Do you want to remove all dependencies (nginx, php-fpm, php modules)?"
echo "WARNING: This may affect other applications on your server!"
echo "Type 'yes' to confirm or anything else to skip: "
read -r REMOVE_DEPS

if [ "$REMOVE_DEPS" = "yes" ]; then
  echo "Uninstalling Nginx, PHP, and related packages..."
  apt-get remove --purge -y nginx nginx-common nginx-full php-fpm php-json php-mbstring php-xml
  
  echo "Removing any leftover configuration files..."
  apt-get autoremove -y
  apt-get clean
  
  echo "Dependencies removed successfully."
else
  echo "Skipping dependency removal as requested."
  echo "Restarting Nginx and PHP-FPM with new configuration..."
  systemctl restart nginx
  systemctl restart php${PHP_VERSION}-fpm
fi

echo "======================================"
echo "Uninstallation Complete!"
echo "All application files, configuration, and user data have been removed."
echo "======================================" 