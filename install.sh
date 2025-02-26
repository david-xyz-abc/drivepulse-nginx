#!/bin/bash
# Beginner-Friendly Installer and Updater for Self Hosted Google Drive (DriveDAV)
# This script installs Nginx, PHP, required modules, downloads your PHP files from GitHub,
# creates necessary folders, sets proper permissions, adjusts PHP's size limits for both CLI and PHP-FPM,
# and configures Nginx so that your application is available at /selfhostedgdrive/.
# It also sets Nginx's client_max_body_size to allow large file uploads.
# Run this as root (e.g., sudo bash install.sh)

set -e  # Exit immediately if a command fails

# Log output for troubleshooting (optional)
LOGFILE="/var/log/selfhostedgdrive_install.log"
exec > >(tee -a "$LOGFILE") 2>&1

echo "======================================"
echo "Self Hosted Google Drive (DriveDAV) Installer/Updater"
echo "======================================"

# Check for root privileges
if [ "$(id -u)" -ne 0 ]; then
  echo "ERROR: This script must be run as root. Try: sudo bash install.sh"
  exit 1
fi

# Update package lists
echo "Updating package lists..."
apt-get update

# Install Nginx and PHP with required modules
echo "Installing Nginx and PHP with required modules..."
apt-get install -y nginx php-fpm php-json php-mbstring php-xml wget curl

# Define application directories
APP_DIR="/var/www/html/selfhostedgdrive"
WEBDAV_USERS_DIR="/var/www/html/webdav/users"
USERS_JSON="$APP_DIR/users.json"

# Create application directory if it doesn't exist
if [ ! -d "$APP_DIR" ]; then
  echo "Creating application directory at $APP_DIR..."
  mkdir -p "$APP_DIR"
fi

# Backup existing PHP files (if any)
echo "Backing up existing PHP files..."
FILES=("index.php" "authenticate.php" "explorer.php" "logout.php" "register.php")
for file in "${FILES[@]}"; do
  if [ -f "$APP_DIR/$file" ]; then
    cp "$APP_DIR/$file" "$APP_DIR/$file.bak"
    echo "Backed up $file to $file.bak"
  fi
done

# Set the base URL where your PHP files are hosted
BASE_URL="https://raw.githubusercontent.com/david-xyz-abc/drivedavfinal/main"

# Download PHP files from GitHub into the application directory
echo "Downloading PHP files from GitHub..."
for file in "${FILES[@]}"; do
  FILE_URL="${BASE_URL}/${file}"
  echo "Fetching ${file} from ${FILE_URL}..."
  wget -q -O "$APP_DIR/$file" "$FILE_URL" || { echo "ERROR: Failed to download ${file}"; exit 1; }
done

# Create users.json if it doesn't exist
if [ ! -f "$USERS_JSON" ]; then
  echo "{}" > "$USERS_JSON"
fi

# Set ownership and permissions for the application directory and users.json
echo "Setting permissions for $APP_DIR and $USERS_JSON..."
chown -R www-data:www-data "$APP_DIR"
chmod -R 755 "$APP_DIR"
chmod 664 "$USERS_JSON"  # Ensure www-data can write to users.json

# Create the WebDAV users directory for file storage
echo "Creating WebDAV users directory at $WEBDAV_USERS_DIR..."
mkdir -p "$WEBDAV_USERS_DIR"
chown -R www-data:www-data "/var/www/html/webdav"
chmod -R 775 "/var/www/html/webdav"  # 775 to allow group write access

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
  sed -i 's/^\s*upload_max_filesize\s*=.*/upload_max_filesize = 10G/' "$ini_file"
  sed -i 's/^\s*post_max_size\s*=.*/post_max_size = 11G/' "$ini_file"
  sed -i 's/^\s*memory_limit\s*=.*/memory_limit = 12G/' "$ini_file"
  sed -i 's/^\s*max_execution_time\s*=.*/max_execution_time = 3600/' "$ini_file"
  sed -i 's/^\s*max_input_time\s*=.*/max_input_time = 3600/' "$ini_file"
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

# Restart Nginx and PHP-FPM to apply changes
echo "Restarting Nginx and PHP-FPM..."
systemctl restart nginx
systemctl restart php${PHP_VERSION}-fpm

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
