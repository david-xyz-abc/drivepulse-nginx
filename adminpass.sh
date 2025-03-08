#!/bin/bash
# DrivePulse Admin Password Changer
# This script updates the admin password in index.php and console.php

# Clear the screen and print header
clear
echo "DrivePulse Admin Password Changer"
echo "--------------------------------"

# Ensure the script is run as root
if [ "$(id -u)" -ne 0 ]; then
    echo "Error: Please run as root (e.g. sudo bash adminpass.sh)"
    exit 1
fi

# Define paths
APP_DIR="/var/www/html/selfhostedgdrive"
INDEX_FILE="$APP_DIR/index.php"
CONSOLE_FILE="$APP_DIR/console.php"

# Verify DrivePulse installation and required files
if [ ! -d "$APP_DIR" ]; then
    echo "Error: DrivePulse directory not found at $APP_DIR"
    echo "Please install DrivePulse first."
    exit 1
fi

for file in "$INDEX_FILE" "$CONSOLE_FILE"; do
    if [ ! -f "$file" ]; then
        echo "Error: Required file not found: $file"
        exit 1
    fi
    if [ ! -w "$file" ]; then
        echo "Error: Cannot write to file: $file. Check permissions."
        exit 1
    fi
done

# Function to validate the new password
validate_password() {
    if [ -z "$1" ]; then
        return 1
    fi
    if [ "${#1}" -lt 3 ]; then
        echo "Error: Password must be at least 3 characters long"
        return 1
    fi
    return 0
}

# Prompt for a new password until a valid one is provided
while true; do
    read -p "Enter new admin password: " NEW_PASSWORD
    if validate_password "$NEW_PASSWORD"; then
        break
    fi
done

# Create backups with a timestamp
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_INDEX="${INDEX_FILE}.bak.${TIMESTAMP}"
BACKUP_CONSOLE="${CONSOLE_FILE}.bak.${TIMESTAMP}"

echo "Creating backups..."
if ! cp "$INDEX_FILE" "$BACKUP_INDEX"; then
    echo "Error: Failed to create backup of index.php"
    exit 1
fi

if ! cp "$CONSOLE_FILE" "$BACKUP_CONSOLE"; then
    echo "Error: Failed to create backup of console.php"
    exit 1
fi

# Update password in index.php
echo "Updating password in index.php..."
if ! sed -i "s/password === \"[^\"]*\"/password === \"$NEW_PASSWORD\"/" "$INDEX_FILE"; then
    echo "Error: Failed to update index.php"
    cp "$BACKUP_INDEX" "$INDEX_FILE"
    exit 1
fi

# Update password in console.php
echo "Updating password in console.php..."
# Update the '!=='
if ! sed -i "s/\(\$_POST\['password'\] !== \)'\([^']*\)'/\1'$NEW_PASSWORD'/" "$CONSOLE_FILE"; then
    echo "Error: Failed to update the !== condition in console.php"
    cp "$BACKUP_INDEX" "$INDEX_FILE"
    cp "$BACKUP_CONSOLE" "$CONSOLE_FILE"
    exit 1
fi

# Update the '===' condition
if ! sed -i "s/\(\$_POST\['password'\] === \)'\([^']*\)'/\1'$NEW_PASSWORD'/" "$CONSOLE_FILE"; then
    echo "Error: Failed to update the === condition in console.php"
    cp "$BACKUP_INDEX" "$INDEX_FILE"
    cp "$BACKUP_CONSOLE" "$CONSOLE_FILE"
    exit 1
fi

# Verify that the update was successful in index.php
if ! grep -q "password === \"$NEW_PASSWORD\"" "$INDEX_FILE"; then
    echo "Error: Verification failed in index.php; restoring backups..."
    cp "$BACKUP_INDEX" "$INDEX_FILE"
    cp "$BACKUP_CONSOLE" "$CONSOLE_FILE"
    exit 1
fi

echo ""
echo "Success! Password has been changed."
echo "New admin password: $NEW_PASSWORD"
echo ""
echo "Backup files created:"
echo "  $BACKUP_INDEX"
echo "  $BACKUP_CONSOLE"
echo ""
echo "Please test the new password before closing this window."
