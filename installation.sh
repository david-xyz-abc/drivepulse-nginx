#!/bin/bash
# DrivePulse Installation Manager
# This script provides a menu to install, update, or uninstall DrivePulse

# Check for root privileges
if [ "$(id -u)" -ne 0 ]; then
  echo "ERROR: This script must be run as root. Try: sudo bash installation.sh"
  exit 1
fi

# Function to display the menu
show_menu() {
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
}

# Function to handle the installation
handle_install() {
    echo "Starting DrivePulse installation..."
    if [ -f "./install.sh" ]; then
        bash ./install.sh
        return 0
    else
        echo "ERROR: install.sh not found in the current directory!"
        return 1
    fi
}

# Function to handle the update
handle_update() {
    echo "Starting DrivePulse update..."
    if [ -f "./update.sh" ]; then
        bash ./update.sh
        return 0
    else
        echo "ERROR: update.sh not found in the current directory!"
        return 1
    fi
}

# Function to handle the uninstallation
handle_uninstall() {
    echo "Starting DrivePulse uninstallation..."
    if [ -f "./uninstall.sh" ]; then
        bash ./uninstall.sh
        return 0
    else
        echo "ERROR: uninstall.sh not found in the current directory!"
        return 1
    fi
}

# Function to handle admin password change
handle_admin_password() {
    echo "Starting admin password change..."
    if [ -f "./adminpass.sh" ]; then
        bash ./adminpass.sh
        return 0
    else
        echo "ERROR: adminpass.sh not found in the current directory!"
        return 1
    fi
}

# Main loop
while true; do
    show_menu
    read -p "Enter your choice [1-5]: " choice

    # Validate input is a number
    if ! [[ "$choice" =~ ^[1-5]$ ]]; then
        echo "Invalid input: Please enter a number between 1 and 5"
        sleep 2
        continue
    fi
    
    case "$choice" in
        1)
            echo "You selected: Install DrivePulse"
            read -p "Are you sure you want to install DrivePulse? [y/N] " confirm
            if [[ "$confirm" =~ ^[Yy]$ ]]; then
                handle_install
            fi
            ;;
        2)
            echo "You selected: Update DrivePulse"
            read -p "Are you sure you want to update DrivePulse? [y/N] " confirm
            if [[ "$confirm" =~ ^[Yy]$ ]]; then
                handle_update
            fi
            ;;
        3)
            echo "You selected: Uninstall DrivePulse"
            read -p "Are you sure you want to uninstall DrivePulse? This will remove all data! [y/N] " confirm
            if [[ "$confirm" =~ ^[Yy]$ ]]; then
                handle_uninstall
            fi
            ;;
        4)
            echo "You selected: Change Admin Password"
            read -p "Are you sure you want to change the admin password? [y/N] " confirm
            if [[ "$confirm" =~ ^[Yy]$ ]]; then
                handle_admin_password
            fi
            ;;
        5)
            echo "Exiting..."
            exit 0
            ;;
    esac
    
    # If we get here, we've completed an operation or had an invalid choice
    echo ""
    read -p "Press Enter to return to the menu..."
done 