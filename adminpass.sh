#!/bin/bash
# DrivePulse Admin Password Changer
# This script changes the admin password in index.php and console.php

clear
echo "DrivePulse Admin Password Changer"
echo "--------------------------------"

# Check for root privileges
if [ "$(id -u)" -ne 0 ]; then
    echo "Error: Please run as root (sudo bash adminpass.sh)"
    exit 1
fi

# Define paths
APP_DIR="/var/www/html/selfhostedgdrive"
INDEX_FILE="$APP_DIR/index.php"
CONSOLE_FILE="$APP_DIR/console.php"

# Check if DrivePulse is installed
if [ ! -d "$APP_DIR" ]; then
    echo "Error: DrivePulse directory not found at $APP_DIR"
    echo "Please install DrivePulse first."
    exit 1
fi

# Check if required files exist
if [ ! -f "$INDEX_FILE" ]; then
    echo "Error: index.php not found at $INDEX_FILE"
    exit 1
fi

if [ ! -f "$CONSOLE_FILE" ]; then
    echo "Error: console.php not found at $CONSOLE_FILE"
    exit 1
fi

# Check if files are writable
if [ ! -w "$INDEX_FILE" ]; then
    echo "Error: Cannot write to index.php. Check permissions."
    exit 1
fi

if [ ! -w "$CONSOLE_FILE" ]; then
    echo "Error: Cannot write to console.php. Check permissions."
    exit 1
fi

# Function to validate password
validate_password() {
    if [ -z "$1" ]; then
        return 1
    fi
    if [ ${#1} -lt 3 ]; then
        echo "Error: Password must be at least 3 characters long"
        return 1
    fi
    return 0
}

# Get and validate new password
while true; do
    echo -n "Enter new admin password: "
    read -r NEW_PASSWORD
    
    if validate_password "$NEW_PASSWORD"; then
        break
    fi
done

# Create backups with timestamp
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_INDEX="$INDEX_FILE.bak.$TIMESTAMP"
BACKUP_CONSOLE="$CONSOLE_FILE.bak.$TIMESTAMP"

echo "Creating backups..."
if ! cp "$INDEX_FILE" "$BACKUP_INDEX"; then
    echo "Error: Failed to create backup of index.php"
    exit 1
fi

if ! cp "$CONSOLE_FILE" "$BACKUP_CONSOLE"; then
    echo "Error: Failed to create backup of console.php"
    exit 1
fi

# Update password in files
echo "Updating password..."

# Update index.php
if ! sed -i.tmp "s/password === \"[^\"]*\"/password === \"$NEW_PASSWORD\"/" "$INDEX_FILE"; then
    echo "Error: Failed to update index.php"
    # Restore from backup
    cp "$BACKUP_INDEX" "$INDEX_FILE"
    exit 1
fi

# Update console.php
if ! sed -i.tmp "s/\$_POST\['password'\] !== '[^']*'/\$_POST['password'] !== '$NEW_PASSWORD'/" "$CONSOLE_FILE" || \
   ! sed -i.tmp "s/\$_POST\['password'\] === '[^']*'/\$_POST['password'] === '$NEW_PASSWORD'/" "$CONSOLE_FILE"; then
    echo "Error: Failed to update console.php"
    # Restore from backups
    cp "$BACKUP_INDEX" "$INDEX_FILE"
    cp "$BACKUP_CONSOLE" "$CONSOLE_FILE"
    exit 1
fi

# Clean up temporary files
rm -f "$INDEX_FILE.tmp" "$CONSOLE_FILE.tmp"

# Verify the changes
if ! grep -q "password === \"$NEW_PASSWORD\"" "$INDEX_FILE"; then
    echo "Error: Password update verification failed in index.php"
    echo "Restoring from backup..."
    cp "$BACKUP_INDEX" "$INDEX_FILE"
    cp "$BACKUP_CONSOLE" "$CONSOLE_FILE"
    exit 1
fi

echo ""
echo "Success! Password has been changed."
echo "New admin password: $NEW_PASSWORD"
echo ""
echo "Backup files created:"
echo "- $BACKUP_INDEX"
echo "- $BACKUP_CONSOLE"
echo ""
echo "Please test the new password before closing this window." 