#!/bin/bash
# DrivePulse Installation Manager
# This script provides a menu to install, update, or uninstall DrivePulse

# Check for root privileges
if [ "$(id -u)" -ne 0 ]; then
    echo "Please run as root (sudo bash installation.sh)"
    exit 1
fi

while :
do
echo "
DrivePulse Installation Manager
------------------------------
1. Install
2. Update
3. Uninstall
4. Change Admin Password
5. Exit

Choose an option: "
read n
case $n in
    1) echo "Installing DrivePulse..."
       curl -sSL https://raw.githubusercontent.com/david-xyz-abc/drivepulse-nginx/main/install.sh | tr -d '\r' | sudo bash
       echo "Press enter to continue"
       read;;
    2) echo "Updating DrivePulse..."
       curl -sSL https://raw.githubusercontent.com/david-xyz-abc/drivepulse-nginx/main/update.sh | tr -d '\r' | sudo bash
       echo "Press enter to continue"
       read;;
    3) echo "Uninstalling DrivePulse..."
       curl -sSL https://raw.githubusercontent.com/david-xyz-abc/drivepulse-nginx/main/uninstall.sh | tr -d '\r' | sudo bash
       echo "Press enter to continue"
       read;;
    4) echo "Changing admin password..."
       curl -sSL https://raw.githubusercontent.com/david-xyz-abc/drivepulse-nginx/main/adminpass.sh | tr -d '\r' | sudo bash
       echo "Press enter to continue"
       read;;
    5) exit;;
    *) echo "Invalid option"
       echo "Press enter to continue"
       read;;
esac
done 