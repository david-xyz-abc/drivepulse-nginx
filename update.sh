#!/bin/bash
# DrivePulse Updater Script
# This script only updates the PHP files from GitHub without modifying any other configurations.
# Run this as root (e.g., sudo bash update.sh)

set -e  # Exit immediately if a command fails

# Log output for troubleshooting (optional)
LOGFILE="/var/log/selfhostedgdrive_update.log"
exec > >(tee -a "$LOGFILE") 2>&1

echo "======================================"
echo "DrivePulse Updater"
echo "======================================"

# Check for root privileges
if [ "$(id -u)" -ne 0 ]; then
  echo "ERROR: This script must be run as root. Try: sudo bash update.sh"
  exit 1
fi

# Define application directory
APP_DIR="/var/www/html/selfhostedgdrive"

# Check if application directory exists
if [ ! -d "$APP_DIR" ]; then
  echo "ERROR: Application directory not found at $APP_DIR."
  echo "Please run the install.sh script first to set up DrivePulse."
  exit 1
fi

# Set the base URL where your PHP files are hosted
BASE_URL="https://raw.githubusercontent.com/david-xyz-abc/drivepulse-nginx/main"

# Define files to update
FILES=("index.php" "authenticate.php" "explorer.php" "console.php" "logout.php" "register.php" "share_handler.php" "shared.php" "drivepulse.svg" "styles.css" "shared_folder.php" "folder_share_handler.php")

# Download PHP files from GitHub into the application directory
echo "Downloading PHP files from GitHub..."
for file in "${FILES[@]}"; do
  FILE_URL="${BASE_URL}/${file}"
  echo "Fetching ${file} from ${FILE_URL}..."
  wget -q -O "$APP_DIR/$file" "$FILE_URL" || { echo "ERROR: Failed to download ${file}"; exit 1; }
done

# Set proper permissions for the updated files
echo "Setting proper permissions for the updated files..."
chown www-data:www-data "$APP_DIR"/*.php "$APP_DIR"/*.svg
chmod 644 "$APP_DIR"/*.php "$APP_DIR"/*.svg
echo "File permissions set."

# Determine PHP version for service restart
PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')

# Restart PHP-FPM to apply changes
echo "Restarting PHP-FPM..."
systemctl restart php${PHP_VERSION}-fpm

echo "======================================"
echo "Update Complete!"
echo "Your DrivePulse installation has been updated with the latest files from GitHub."
echo "======================================"

echo ""
echo "If you encounter any issues after the update:"
echo "Check PHP error logs: tail -n 50 /var/log/php${PHP_VERSION}-fpm.log"
echo "======================================" 