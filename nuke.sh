#!/bin/bash
# NUCLEAR OPTION - Complete System Cleaner
# This script will AGGRESSIVELY remove ALL traces of web servers and PHP
# WARNING: This is extremely aggressive and will remove EVERYTHING related to web servers
# Run this as root (e.g., sudo bash nuke.sh)

set -e  # Exit immediately if a command fails

echo "======================================"
echo "NUCLEAR OPTION - Complete System Cleaner"
echo "======================================"
echo "WARNING: This will COMPLETELY REMOVE all web servers, PHP, and related components"
echo "This is a scorched-earth approach that will leave no trace of these services"
echo "======================================"
echo "Press CTRL+C now to cancel or wait 5 seconds to continue..."
sleep 5

# Check for root privileges
if [ "$(id -u)" -ne 0 ]; then
  echo "ERROR: This script must be run as root. Try: sudo bash nuke.sh"
  exit 1
fi

echo "======================================"
echo "Step 1: Killing ALL web-related processes..."
echo "======================================"

# Kill all web-related processes with extreme prejudice
echo "Killing all Apache processes..."
killall -9 apache2 httpd 2>/dev/null || true

echo "Killing all Nginx processes..."
killall -9 nginx 2>/dev/null || true

echo "Killing all PHP processes..."
killall -9 php php-fpm php-fpm7.0 php-fpm7.1 php-fpm7.2 php-fpm7.3 php-fpm7.4 php-fpm8.0 php-fpm8.1 php-fpm8.2 2>/dev/null || true

echo "======================================"
echo "Step 2: Completely removing ALL web servers and PHP packages..."
echo "======================================"

# Purge ALL Apache packages
echo "Purging ALL Apache packages..."
apt-get remove --purge -y apache* libapache* || true

# Purge ALL Nginx packages
echo "Purging ALL Nginx packages..."
apt-get remove --purge -y nginx* || true

# Purge ALL PHP packages
echo "Purging ALL PHP packages..."
apt-get remove --purge -y php* libphp* || true

# Remove ALL dependencies
echo "Removing ALL dependencies..."
apt-get autoremove --purge -y
apt-get clean

echo "======================================"
echo "Step 3: Obliterating ALL configuration files and directories..."
echo "======================================"

# Remove ALL Apache directories
echo "Removing ALL Apache directories..."
rm -rf /etc/apache* /usr/lib/apache* /var/log/apache* /var/lib/apache* /usr/share/apache* || true

# Remove ALL Nginx directories
echo "Removing ALL Nginx directories..."
rm -rf /etc/nginx* /var/log/nginx* /var/lib/nginx* /usr/share/nginx* || true

# Remove ALL PHP directories
echo "Removing ALL PHP directories..."
rm -rf /etc/php* /var/lib/php* /usr/lib/php* /usr/share/php* || true

# Remove ALL web content
echo "Removing ALL web content..."
rm -rf /var/www/* || true

echo "======================================"
echo "Step 4: Disabling ALL services..."
echo "======================================"

# Disable ALL Apache services
echo "Disabling ALL Apache services..."
systemctl disable apache2 httpd 2>/dev/null || true
systemctl mask apache2 httpd 2>/dev/null || true

# Disable ALL Nginx services
echo "Disabling ALL Nginx services..."
systemctl disable nginx 2>/dev/null || true
systemctl mask nginx 2>/dev/null || true

# Disable ALL PHP services
echo "Disabling ALL PHP services..."
systemctl disable php* 2>/dev/null || true
systemctl mask php* 2>/dev/null || true

echo "======================================"
echo "Step 5: Removing service files..."
echo "======================================"

# Remove ALL service files
echo "Removing ALL service files..."
rm -f /lib/systemd/system/apache* /lib/systemd/system/nginx* /lib/systemd/system/php* || true
rm -f /etc/systemd/system/apache* /etc/systemd/system/nginx* /etc/systemd/system/php* || true
rm -f /usr/lib/systemd/system/apache* /usr/lib/systemd/system/nginx* /usr/lib/systemd/system/php* || true

# Reload systemd
systemctl daemon-reload

echo "======================================"
echo "Step 6: Checking for remaining processes..."
echo "======================================"

# Check for any remaining processes
echo "Checking for any remaining Apache processes..."
ps aux | grep -i apache | grep -v grep || echo "No Apache processes found"

echo "Checking for any remaining Nginx processes..."
ps aux | grep -i nginx | grep -v grep || echo "No Nginx processes found"

echo "Checking for any remaining PHP processes..."
ps aux | grep -i php | grep -v grep || echo "No PHP processes found"

echo "======================================"
echo "Step 7: Checking for remaining packages..."
echo "======================================"

# Check for any remaining packages
echo "Checking for any remaining Apache packages..."
dpkg -l | grep -i apache || echo "No Apache packages found"

echo "Checking for any remaining Nginx packages..."
dpkg -l | grep -i nginx || echo "No Nginx packages found"

echo "Checking for any remaining PHP packages..."
dpkg -l | grep -i php || echo "No PHP packages found"

echo "======================================"
echo "Step 8: Final cleanup..."
echo "======================================"

# Final cleanup
echo "Running final cleanup..."
apt-get update
apt-get autoremove --purge -y
apt-get clean

# Remove any remaining configuration files
find /etc -name "*apache*" -o -name "*nginx*" -o -name "*php*" | xargs rm -rf 2>/dev/null || true

echo "======================================"
echo "NUCLEAR OPTION COMPLETE!"
echo "All web servers, PHP, and related components should be completely removed."
echo "A system reboot is STRONGLY RECOMMENDED to ensure all changes take effect."
echo "======================================"

# Force reboot question
echo "Do you want to reboot the system now? (RECOMMENDED)"
echo "Type 'yes' to reboot now or anything else to skip: "
read -r REBOOT

if [ "$REBOOT" = "yes" ]; then
  echo "Rebooting system now..."
  reboot
else
  echo "Skipping reboot. You should manually reboot as soon as possible."
  echo "WARNING: Until you reboot, some components may still be active in memory."
fi 