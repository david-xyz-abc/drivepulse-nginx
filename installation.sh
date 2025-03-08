#!/bin/bash
set -euo pipefail
IFS=$'\n\t'

# DrivePulse Installation Manager
# This script manages installation, updates, uninstallation, and admin password changes for DrivePulse

clear
echo "DrivePulse Installation Manager"
echo "------------------------------"

# Ensure the script is run as root
if [ "$(id -u)" -ne 0 ]; then
    echo "Error: This script must be run as root. Use sudo."
    exit 1
fi

# Ensure curl is installed
if ! command -v curl &> /dev/null; then
    echo "curl is not installed. Installing curl..."
    apt-get update && apt-get install -y curl
fi

# Function to download and execute a script from a given URL
verify_and_run() {
    local script_url="$1"
    local temp_file
    temp_file=$(mktemp)

    echo "Downloading script from: $script_url"
    if ! curl -sSL "$script_url" -o "$temp_file"; then
        echo "Error: Failed to download script from $script_url"
        rm -f "$temp_file"
        return 1
    fi

    # Validate the downloaded script (must be non-empty and have at least 5 lines)
    if [ ! -s "$temp_file" ] || [ "$(wc -l < "$temp_file")" -lt 5 ]; then
        echo "Error: Downloaded script appears invalid (empty or too short)"
        rm -f "$temp_file"
        return 1
    fi

    echo "Executing downloaded script..."
    bash "$temp_file"
    local status=$?

    rm -f "$temp_file"
    return $status
}

# Main menu loop
while true; do
    echo ""
    echo "1. Install DrivePulse"
    echo "2. Update DrivePulse"
    echo "3. Uninstall DrivePulse"
    echo "4. Change Admin Password"
    echo "5. Exit"
    echo ""

    read -rp "Choose an option [1-5]: " choice
    echo ""

    case "$choice" in
        1)
            echo "Starting installation..."
            verify_and_run "https://raw.githubusercontent.com/david-xyz-abc/drivepulse-nginx/main/install.sh"
            ;;
        2)
            echo "Starting update..."
            verify_and_run "https://raw.githubusercontent.com/david-xyz-abc/drivepulse-nginx/main/update.sh"
            ;;
        3)
            echo "Starting uninstallation..."
            read -rp "Are you sure you want to uninstall? This will delete all data! (y/N): " confirm
            if [[ "$confirm" =~ ^[Yy]$ ]]; then
                verify_and_run "https://raw.githubusercontent.com/david-xyz-abc/drivepulse-nginx/main/uninstall.sh"
            else
                echo "Uninstallation cancelled."
            fi
            ;;
        4)
            echo "Changing admin password..."
            verify_and_run "https://raw.githubusercontent.com/david-xyz-abc/drivepulse-nginx/main/adminpass.sh"
            ;;
        5)
            echo "Exiting..."
            exit 0
            ;;
        *)
            echo "Invalid option. Please choose a number between 1 and 5."
            ;;
    esac

    echo ""
    read -rp "Press Enter to continue..."
    clear
done
