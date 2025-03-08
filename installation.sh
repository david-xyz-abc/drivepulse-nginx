#!/bin/bash
clear
echo "DrivePulse Installation Manager"
echo "------------------------------"

# Check for root privileges
if [ "$(id -u)" -ne 0 ]; then
    echo "Error: Please run as root (sudo bash installation.sh)"
    exit 1
fi

# Check if curl is installed
if ! command -v curl &> /dev/null; then
    echo "Error: curl is not installed. Installing curl..."
    apt-get update && apt-get install -y curl
fi

# Function to verify script download
verify_and_run() {
    local script_url="$1"
    local temp_file=$(mktemp)
    
    echo "Downloading script..."
    if ! curl -sSL "$script_url" -o "$temp_file"; then
        echo "Error: Failed to download script"
        rm -f "$temp_file"
        return 1
    fi
    
    # Check if file is empty or too small
    if [ ! -s "$temp_file" ] || [ $(wc -l < "$temp_file") -lt 5 ]; then
        echo "Error: Downloaded script is invalid"
        rm -f "$temp_file"
        return 1
    fi
    
    # Execute the script
    bash "$temp_file"
    local status=$?
    
    # Cleanup
    rm -f "$temp_file"
    return $status
}

while true; do
    echo ""
    echo "1. Install DrivePulse"
    echo "2. Update DrivePulse"
    echo "3. Uninstall DrivePulse"
    echo "4. Change Admin Password"
    echo "5. Exit"
    echo ""
    read -p "Choose an option [1-5]: " choice
    echo ""

    case $choice in
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
            read -p "Are you sure you want to uninstall? This will delete all data! (y/N): " confirm
            if [[ "$confirm" =~ ^[Yy]$ ]]; then
                verify_and_run "https://raw.githubusercontent.com/david-xyz-abc/drivepulse-nginx/main/uninstall.sh"
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
            echo "Invalid option. Please enter a number between 1 and 5."
            ;;
    esac

    echo ""
    read -p "Press Enter to continue..."
    clear
done 