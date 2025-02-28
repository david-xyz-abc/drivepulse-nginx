#!/bin/bash
# Uninstaller for Self Hosted Google Drive (DriveDAV)
# This script completely removes everything installed by both installation scripts:
# - The Nginx-based installation
# - The Apache-based installation
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

# Define application directories (same as in both install scripts)
APP_DIR="/var/www/html/selfhostedgdrive"
WEBDAV_DIR="/var/www/html/webdav"
WEBDAV_USERS_DIR="$WEBDAV_DIR/users"
USERS_JSON="$APP_DIR/users.json"

# Determine PHP version
PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')

# Locate the php.ini files for all possible configurations
CLI_PHP_INI="/etc/php/$PHP_VERSION/cli/php.ini"
FPM_PHP_INI="/etc/php/$PHP_VERSION/fpm/php.ini"
APACHE_PHP_INI="/etc/php/$PHP_VERSION/apache2/php.ini"

echo "======================================"
echo "Step 1: Removing web server configurations..."
echo "======================================"

# Remove Nginx site configuration (if exists)
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

# Disable Apache mod_rewrite if it was enabled
if command -v a2dismod &> /dev/null; then
  echo "Disabling Apache mod_rewrite if enabled..."
  a2dismod rewrite || true
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

if [ -f "${APACHE_PHP_INI}.backup" ]; then
  echo "Restoring Apache php.ini from backup..."
  cp "${APACHE_PHP_INI}.backup" "$APACHE_PHP_INI"
  rm -f "${APACHE_PHP_INI}.backup"
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

# Remove this uninstall log file when done
trap 'rm -f "$LOGFILE"' EXIT

echo "======================================"
echo "Step 5: Uninstalling dependencies..."
echo "======================================"

# Ask user if they want to remove dependencies
echo "Do you want to remove all dependencies (Nginx, Apache, PHP, and related modules)?"
echo "WARNING: This may affect other applications on your server!"
echo "Type 'yes' to confirm or anything else to skip: "
read -r REMOVE_DEPS

if [ "$REMOVE_DEPS" = "yes" ]; then
  echo "Uninstalling all web server and PHP packages..."
  
  # Stop services first to prevent issues during uninstallation
  systemctl stop nginx apache2 php${PHP_VERSION}-fpm || true
  
  # Remove Nginx packages
  echo "Removing Nginx packages..."
  apt-get remove --purge -y nginx nginx-common nginx-full || true
  
  # Remove Apache packages
  echo "Removing Apache packages..."
  apt-get remove --purge -y apache2 libapache2-mod-php || true
  
  # Remove PHP packages
  echo "Removing PHP packages..."
  apt-get remove --purge -y php php-fpm php-cli php-json php-mbstring php-xml || true
  
  echo "Removing any leftover configuration files..."
  apt-get autoremove -y
  apt-get clean
  
  # Remove PHP configuration directories if empty
  rmdir --ignore-fail-on-non-empty /etc/php/${PHP_VERSION}/cli 2>/dev/null || true
  rmdir --ignore-fail-on-non-empty /etc/php/${PHP_VERSION}/fpm 2>/dev/null || true
  rmdir --ignore-fail-on-non-empty /etc/php/${PHP_VERSION}/apache2 2>/dev/null || true
  rmdir --ignore-fail-on-non-empty /etc/php/${PHP_VERSION} 2>/dev/null || true
  rmdir --ignore-fail-on-non-empty /etc/php 2>/dev/null || true
  
  echo "Dependencies removed successfully."
else
  echo "Skipping dependency removal as requested."
  
  # Restart services with new configurations if they exist
  if systemctl is-active --quiet nginx; then
    echo "Restarting Nginx with new configuration..."
    systemctl restart nginx
  fi
  
  if systemctl is-active --quiet apache2; then
    echo "Restarting Apache with new configuration..."
    systemctl restart apache2
  fi
  
  if systemctl is-active --quiet php${PHP_VERSION}-fpm; then
    echo "Restarting PHP-FPM with new configuration..."
    systemctl restart php${PHP_VERSION}-fpm
  fi
fi

echo "======================================"
echo "Uninstallation Complete!"
echo "All application files, configuration, and user data have been removed."
echo "======================================" 