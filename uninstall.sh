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
echo "Step 1: Stopping all web services..."
echo "======================================"

# Stop all web services first
systemctl stop nginx apache2 php${PHP_VERSION}-fpm || true

echo "======================================"
echo "Step 2: Removing web server configurations..."
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
echo "Step 3: Restoring PHP configuration..."
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
echo "Step 4: Removing application files and user data..."
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
echo "Step 5: Removing log files..."
echo "======================================"

# Remove installation log file
if [ -f "/var/log/selfhostedgdrive_install.log" ]; then
  echo "Removing installation log file..."
  rm -f "/var/log/selfhostedgdrive_install.log"
fi

# Remove this uninstall log file when done
trap 'rm -f "$LOGFILE"' EXIT

echo "======================================"
echo "Step 6: Completely uninstalling all web servers and dependencies..."
echo "======================================"

# Force complete removal of all web servers and PHP
echo "Completely removing all web servers and PHP packages..."

# Forcefully remove Apache and all its modules
echo "Removing Apache and all its modules..."
apt-get remove --purge -y apache2 apache2-bin apache2-data apache2-utils libapache2-mod-php* || true

# Forcefully remove Nginx and all its modules
echo "Removing Nginx and all its modules..."
apt-get remove --purge -y nginx nginx-common nginx-full nginx-core || true

# Forcefully remove PHP and all its modules
echo "Removing PHP and all its modules..."
apt-get remove --purge -y php* php-common libapache2-mod-php php-cli php-fpm php-json php-mbstring php-xml || true

# Remove all configuration files
echo "Removing all configuration files..."
apt-get autoremove -y --purge
apt-get clean

# Remove Apache configuration directories
echo "Removing Apache configuration directories..."
rm -rf /etc/apache2 || true

# Remove Nginx configuration directories
echo "Removing Nginx configuration directories..."
rm -rf /etc/nginx || true

# Remove PHP configuration directories
echo "Removing PHP configuration directories..."
rm -rf /etc/php || true

# Remove any remaining web server files in /var/www
echo "Cleaning up /var/www directory..."
rm -rf /var/www/html/* || true

# Disable services from starting on boot
echo "Disabling services from starting on boot..."
systemctl disable apache2 nginx php${PHP_VERSION}-fpm || true

# Make sure services are stopped
echo "Ensuring all services are stopped..."
systemctl stop apache2 nginx php${PHP_VERSION}-fpm || true

# Kill any remaining processes
echo "Killing any remaining web server processes..."
killall -9 apache2 nginx php-fpm7.4 php-fpm8.0 php-fpm8.1 php-fpm8.2 2>/dev/null || true

echo "======================================"
echo "Uninstallation Complete!"
echo "All application files, configuration, and web servers have been completely removed."
echo "If you still see a web server running, please reboot your system."
echo "======================================"

# Ask if user wants to reboot
echo "Do you want to reboot the system to ensure all changes take effect?"
echo "Type 'yes' to reboot now or anything else to skip: "
read -r REBOOT

if [ "$REBOOT" = "yes" ]; then
  echo "Rebooting system now..."
  reboot
else
  echo "Skipping reboot. You may want to reboot manually later."
fi 