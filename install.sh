#!/bin/bash
# Beginner-Friendly Installer and Updater for Self Hosted Google Drive (DriveDAV)
# This script installs Nginx, PHP, FFmpeg, required modules, downloads your PHP files from GitHub,
# creates necessary folders, sets proper permissions, adjusts PHP's size limits for both CLI and PHP-FPM,
# and configures Nginx so that your application is available at /selfhostedgdrive/.
# It also sets Nginx's client_max_body_size to allow large file uploads.
# Run this as root (e.g., sudo bash install.sh)

set -e  # Exit immediately if a command fails

# Log output for troubleshooting (optional)
LOGFILE="/var/log/selfhostedgdrive_install.log"
exec > >(tee -a "$LOGFILE") 2>&1

echo "======================================"
echo "DrivePulse Installer"
echo "======================================"

# Check for root privileges
if [ "$(id -u)" -ne 0 ]; then
  echo "ERROR: This script must be run as root. Try: sudo bash install.sh"
  exit 1
fi

# Update package lists
echo "Updating package lists..."
apt-get update

# Install Nginx, PHP, FFmpeg and required modules
echo "Installing Nginx, PHP, FFmpeg and required modules..."
apt-get install -y nginx php-fpm php-json php-mbstring php-xml wget curl ffmpeg

# Define application directories
APP_DIR="/var/www/html/selfhostedgdrive"
WEBDAV_USERS_DIR="/var/www/html/webdav/users"
USERS_JSON="$APP_DIR/users.json"

# Create application directory if it doesn't exist
if [ ! -d "$APP_DIR" ]; then
  echo "Creating application directory at $APP_DIR..."
  mkdir -p "$APP_DIR"
fi

# Set the base URL where your PHP files are hosted
BASE_URL="https://raw.githubusercontent.com/david-xyz-abc/drivepulse-nginx/main"

# Define files to download
FILES=("index.php" "authenticate.php" "explorer.php" "console.php" "logout.php" "register.php" "share_handler.php" "shared.php" "drivepulse.svg" "styles.css" "shared_folder.php" "folder_share_handler.php" "video_preview.php")

# Download PHP files from GitHub into the application directory
echo "Downloading PHP files from GitHub..."
for file in "${FILES[@]}"; do
  FILE_URL="${BASE_URL}/${file}"
  echo "Fetching ${file} from ${FILE_URL}..."
  wget -q -O "$APP_DIR/$file" "$FILE_URL" || { echo "ERROR: Failed to download ${file}"; exit 1; }
done

# Create a README file with information about the application
cat << EOF > "$APP_DIR/README.md"
# DrivePulse - Self-Hosted Google Drive Alternative

DrivePulse is a lightweight, self-hosted alternative to Google Drive that allows you to store and manage your files securely on your own server.

For more information, see the documentation.
EOF

# Create users.json if it doesn't exist and set proper permissions
echo "Setting up users.json file..."
if [ ! -f "$USERS_JSON" ]; then
  echo "{}" > "$USERS_JSON"
fi

# Ensure users.json has proper permissions
echo "Setting proper permissions for users.json..."
chmod 666 "$USERS_JSON"
chown www-data:www-data "$USERS_JSON"
echo "users.json permissions set to: $(stat -c '%a %U:%G' "$USERS_JSON")"

# Create the WebDAV users directory for file storage with proper permissions
echo "Creating WebDAV users directory at $WEBDAV_USERS_DIR..."
mkdir -p "$WEBDAV_USERS_DIR"

# Set proper ownership and permissions for the entire WebDAV directory structure
echo "Setting proper permissions for WebDAV directories..."
chown -R www-data:www-data "/var/www/html/webdav"
chmod -R 775 "/var/www/html/webdav"  # 775 to allow group write access

# Ensure the web server can create directories under the WebDAV users directory
echo "Ensuring web server can create directories under WebDAV users directory..."
find "/var/www/html/webdav" -type d -exec chmod 775 {} \;
echo "WebDAV directory permissions set to: $(stat -c '%a %U:%G' "$WEBDAV_USERS_DIR")"

# Create thumbnails directory in the WebDAV directory
THUMBNAILS_DIR="/var/www/html/webdav/.thumbnails"
echo "Creating thumbnails directory at $THUMBNAILS_DIR..."
mkdir -p "$THUMBNAILS_DIR"

# Set proper permissions for thumbnails directory
echo "Setting proper permissions for thumbnails directory..."
chown www-data:www-data "$THUMBNAILS_DIR"
chmod 775 "$THUMBNAILS_DIR"

# Determine PHP version
PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')

# Locate the php.ini files for CLI and PHP-FPM
CLI_PHP_INI="/etc/php/$PHP_VERSION/cli/php.ini"
FPM_PHP_INI="/etc/php/$PHP_VERSION/fpm/php.ini"

# Check if both php.ini files exist
if [ ! -f "$CLI_PHP_INI" ]; then
  echo "ERROR: CLI php.ini not found at $CLI_PHP_INI. Exiting."
  exit 1
fi
if [ ! -f "$FPM_PHP_INI" ]; then
  echo "ERROR: PHP-FPM php.ini not found at $FPM_PHP_INI. Exiting."
  exit 1
fi

echo "Found CLI php.ini at: $CLI_PHP_INI"
echo "Found PHP-FPM php.ini at: $FPM_PHP_INI"

# Backup both php.ini files
echo "Backing up CLI php.ini to ${CLI_PHP_INI}.backup..."
cp "$CLI_PHP_INI" "${CLI_PHP_INI}.backup"
echo "Backing up PHP-FPM php.ini to ${FPM_PHP_INI}.backup..."
cp "$FPM_PHP_INI" "${FPM_PHP_INI}.backup"

# Function to update PHP configuration
update_php_ini() {
  local ini_file="$1"
  echo "Adjusting PHP size limits in $ini_file..."
  sed -i 's/^\s*upload_max_filesize\s*=.*/upload_max_filesize = 100G/' "$ini_file"
  sed -i 's/^\s*post_max_size\s*=.*/post_max_size = 110G/' "$ini_file"
  sed -i 's/^\s*memory_limit\s*=.*/memory_limit = 120G/' "$ini_file"
  sed -i 's/^\s*max_execution_time\s*=.*/max_execution_time = 36000/' "$ini_file"
  sed -i 's/^\s*max_input_time\s*=.*/max_input_time = 36000/' "$ini_file"
}

# Update both php.ini files
update_php_ini "$CLI_PHP_INI"
update_php_ini "$FPM_PHP_INI"
echo "PHP configuration updated for both CLI and PHP-FPM (backups saved)"

# Configure Nginx to serve the app under /selfhostedgdrive/ and redirect / to /selfhostedgdrive/
# Also, set client_max_body_size to allow large file uploads.
echo "Configuring Nginx..."
cat << EOF > /etc/nginx/sites-available/selfhostedgdrive
server {
    listen 80;
    server_name _;

    # Use /var/www/html as the document root
    root /var/www/html;
    index index.php index.html index.htm;
    
    # Allow large file uploads
    client_max_body_size 12G;

    # Redirect requests for / to /selfhostedgdrive/
    location = / {
        return 301 /selfhostedgdrive/;
    }

    # Handle requests for /selfhostedgdrive/
    location /selfhostedgdrive/ {
        try_files \$uri \$uri/ /selfhostedgdrive/index.php?\$query_string;
    }

    # Process PHP files in /selfhostedgdrive/
    location ~ ^/selfhostedgdrive/(.+\.php)$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php${PHP_VERSION}-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$document_root/selfhostedgdrive/\$1;
        include fastcgi_params;
    }

    location ~ /\.ht {
        deny all;
    }
}
EOF

# Enable the Nginx site configuration
if [ ! -L /etc/nginx/sites-enabled/selfhostedgdrive ]; then
  ln -s /etc/nginx/sites-available/selfhostedgdrive /etc/nginx/sites-enabled/
else
  echo "Nginx site configuration already enabled."
fi

# Disable the default Nginx site if it exists to prevent conflicts
if [ -L /etc/nginx/sites-enabled/default ]; then
  unlink /etc/nginx/sites-enabled/default
  echo "Default Nginx site disabled."
fi

# Set permissions for the Nginx configuration directories
echo "Setting permissions for Nginx configuration..."
chown -R www-data:www-data /etc/nginx/sites-available
chown -R www-data:www-data /etc/nginx/sites-enabled

# Ensure PHP-FPM is running as the correct user
echo "Configuring PHP-FPM to run as www-data..."
PHP_FPM_POOL="/etc/php/$PHP_VERSION/fpm/pool.d/www.conf"
if [ -f "$PHP_FPM_POOL" ]; then
  sed -i 's/^user = .*/user = www-data/' "$PHP_FPM_POOL"
  sed -i 's/^group = .*/group = www-data/' "$PHP_FPM_POOL"
  echo "PHP-FPM pool configuration updated."
else
  echo "WARNING: PHP-FPM pool configuration not found at $PHP_FPM_POOL"
fi

# Set proper permissions for the entire application directory
echo "Setting proper permissions for the application directory..."
chown -R www-data:www-data "$APP_DIR"
find "$APP_DIR" -type f -exec chmod 644 {} \;
find "$APP_DIR" -type d -exec chmod 755 {} \;
echo "Application directory permissions set."

# Restart Nginx and PHP-FPM to apply changes
echo "Restarting Nginx and PHP-FPM..."
systemctl restart php${PHP_VERSION}-fpm
systemctl restart nginx

# Verify services are running
echo "Verifying services are running..."
if systemctl is-active --quiet nginx && systemctl is-active --quiet php${PHP_VERSION}-fpm; then
  echo "Services are running correctly."
else
  echo "WARNING: One or more services may not be running correctly."
  echo "Nginx status: $(systemctl is-active nginx)"
  echo "PHP-FPM status: $(systemctl is-active php${PHP_VERSION}-fpm)"
fi

# Fetch the server's public IP address
echo "Fetching public IP address..."
PUBLIC_IP=$(curl -s http://ifconfig.me || curl -s http://api.ipify.org || echo "Unable to fetch IP")
if [ "$PUBLIC_IP" = "Unable to fetch IP" ]; then
  echo "WARNING: Could not fetch public IP. Using 'your_server_address' instead."
  PUBLIC_IP="your_server_address"
fi

echo "======================================"
echo "Installation Complete!"
echo "Access your application at: http://$PUBLIC_IP/selfhostedgdrive/"
echo "If the IP doesn't work, check your server's network settings or use its local IP."
echo "======================================"

# Add troubleshooting information
echo ""
echo "Troubleshooting Information:"
echo "- If you encounter 'failed to store user data' errors when registering users:"
echo "  1. Check permissions: ls -la $USERS_JSON"
echo "  2. Check WebDAV directory permissions: ls -la $WEBDAV_USERS_DIR"
echo "  3. Verify PHP-FPM is running as www-data: ps aux | grep php-fpm"
echo "  4. Check PHP error logs: tail -n 50 /var/log/php${PHP_VERSION}-fpm.log"
echo "======================================"
