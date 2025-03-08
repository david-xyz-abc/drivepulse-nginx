#!/bin/bash
# DrivePulse Admin Password Changer
# This script changes the admin password in index.php and console.php

# Check for root privileges
if [ "$(id -u)" -ne 0 ]; then
  echo "ERROR: This script must be run as root. Try: sudo bash adminpass.sh"
  exit 1
fi

echo "======================================"
echo "DrivePulse Admin Password Changer"
echo "======================================"

# Define application directory
APP_DIR="/var/www/html/selfhostedgdrive"

# Check if required files exist
if [ ! -f "$APP_DIR/index.php" ]; then
  echo "ERROR: index.php not found at $APP_DIR"
  echo "Please make sure DrivePulse is properly installed."
  exit 1
fi

if [ ! -f "$APP_DIR/console.php" ]; then
  echo "ERROR: console.php not found at $APP_DIR"
  echo "Please make sure DrivePulse is properly installed."
  exit 1
fi

# Ask for new password
read -p "Enter new admin password: " new_password

# Backup the original files
cp "$APP_DIR/index.php" "$APP_DIR/index.php.bak"
cp "$APP_DIR/console.php" "$APP_DIR/console.php.bak"

# Replace the password in index.php
sed -i "s/password === \"[^\"]*\"/password === \"$new_password\"/" "$APP_DIR/index.php"

# Replace the password in console.php (both occurrences)
sed -i "s/\$_POST\['password'\] !== '[^']*'/\$_POST['password'] !== '$new_password'/" "$APP_DIR/console.php"
sed -i "s/\$_POST\['password'\] === '[^']*'/\$_POST['password'] === '$new_password'/" "$APP_DIR/console.php"

echo "======================================"
echo "Password changed successfully!"
echo "New admin password: $new_password"
echo "Backups of the original files have been created at:"
echo "- $APP_DIR/index.php.bak"
echo "- $APP_DIR/console.php.bak"
echo "======================================" 