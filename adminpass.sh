#!/bin/bash
# DrivePulse Admin Password Changer
# This script changes the admin password in index.php

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

# Check if index.php exists
if [ ! -f "$APP_DIR/index.php" ]; then
  echo "ERROR: index.php not found at $APP_DIR"
  echo "Please make sure DrivePulse is properly installed."
  exit 1
fi

# Ask for new password
read -p "Enter new admin password: " new_password

# Backup the original file
cp "$APP_DIR/index.php" "$APP_DIR/index.php.bak"

# Replace the password in index.php
sed -i "s/password === \"[^\"]*\"/password === \"$new_password\"/" "$APP_DIR/index.php"

echo "======================================"
echo "Password changed successfully!"
echo "New admin password: $new_password"
echo "A backup of the original file has been created at: $APP_DIR/index.php.bak"
echo "======================================" 