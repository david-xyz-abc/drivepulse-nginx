#!/bin/bash
# DrivePulse Installation Manager
# This script provides a menu to install, update, or uninstall DrivePulse

# Check for root privileges
if [ "$(id -u)" -ne 0 ]; then
    echo "ERROR: This script must be run as root. Try: sudo bash installation.sh"
    exit 1
fi

# Base GitHub URL
GITHUB_BASE_URL="https://raw.githubusercontent.com/david-xyz-abc/drivepulse-nginx/main"

while true; do
    clear
    echo "======================================"
    echo "DrivePulse Installation Manager"
    echo "======================================"
    echo "Please select an option:"
    echo "1) Install DrivePulse"
    echo "2) Update DrivePulse"
    echo "3) Uninstall DrivePulse"
    echo "4) Change Admin Password"
    echo "5) Exit"
    echo "======================================"

    read choice

    case $choice in
        1)
            echo "You selected: Install DrivePulse"
            echo -n "Are you sure? (y/N): "
            read confirm
            if [ "$confirm" = "y" ] || [ "$confirm" = "Y" ]; then
                curl -sSL "${GITHUB_BASE_URL}/install.sh" | tr -d '\r' | sudo bash
            fi
            ;;
        2)
            echo "You selected: Update DrivePulse"
            echo -n "Are you sure? (y/N): "
            read confirm
            if [ "$confirm" = "y" ] || [ "$confirm" = "Y" ]; then
                curl -sSL "${GITHUB_BASE_URL}/update.sh" | tr -d '\r' | sudo bash
            fi
            ;;
        3)
            echo "You selected: Uninstall DrivePulse"
            echo -n "Are you sure? This will remove all data! (y/N): "
            read confirm
            if [ "$confirm" = "y" ] || [ "$confirm" = "Y" ]; then
                curl -sSL "${GITHUB_BASE_URL}/uninstall.sh" | tr -d '\r' | sudo bash
            fi
            ;;
        4)
            echo "You selected: Change Admin Password"
            echo -n "Are you sure? (y/N): "
            read confirm
            if [ "$confirm" = "y" ] || [ "$confirm" = "Y" ]; then
                curl -sSL "${GITHUB_BASE_URL}/adminpass.sh" | tr -d '\r' | sudo bash
            fi
            ;;
        5)
            echo "Exiting..."
            exit 0
            ;;
        *)
            echo "Invalid option. Please enter a number between 1 and 5."
            ;;
    esac

    echo
    echo -n "Press Enter to continue..."
    read
done 