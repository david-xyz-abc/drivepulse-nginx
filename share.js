// Function to show toast notifications
function showToast(message, type = 'info') {
  const toastContainer = document.getElementById('toastContainer');
  
  // Create toast element
  const toast = document.createElement('div');
  toast.className = `toast ${type}`;
  
  // Set icon based on type
  let icon = 'info-circle';
  if (type === 'success') icon = 'check-circle';
  if (type === 'error') icon = 'exclamation-circle';
  
  // Set toast content
  toast.innerHTML = `
    <div class="toast-icon">
      <i class="fas fa-${icon}"></i>
    </div>
    <div class="toast-message">${message}</div>
    <div class="toast-progress">
      <div class="toast-progress-bar"></div>
    </div>
  `;
  
  // Add toast to container
  toastContainer.appendChild(toast);
  
  // Remove toast after animation completes
  setTimeout(() => {
    toast.remove();
  }, 3000);
}

// Function to share a file
function shareFile(fileName) {
  // Get the current path from the page
  const currentPathElement = document.querySelector('input[name="current_path"]');
  const currentPath = currentPathElement ? currentPathElement.value : '';
  const filePath = currentPath + '/' + fileName;
  
  // Generate a unique share ID
  const shareId = generateUniqueId();
  
  // Create a modal to show the share link
  const shareModal = document.createElement('div');
  shareModal.className = 'modal';
  shareModal.id = 'shareModal';
  
  // Create a unique shareable link
  const shareLink = window.location.origin + '/selfhostedgdrive/share.php?id=' + shareId;
  
  // Modal content
  shareModal.innerHTML = `
    <div class="modal-content">
      <div class="modal-header">
        <h2>Share File</h2>
        <span class="close" id="closeShareModal">&times;</span>
      </div>
      <div class="modal-body">
        <p>Share this link to provide access to <strong>${fileName}</strong>:</p>
        <div class="share-link-container">
          <input type="text" id="shareLinkInput" value="${shareLink}" readonly>
          <button id="copyShareLink" class="btn btn-primary">
            <i class="fas fa-copy"></i> Copy
          </button>
        </div>
        <div class="share-options">
          <div class="share-option">
            <label>
              <input type="checkbox" id="passwordProtect"> Password protect
            </label>
            <div id="passwordField" style="display: none; margin-top: 10px;">
              <input type="password" id="sharePassword" placeholder="Enter password">
            </div>
          </div>
          <div class="share-option">
            <label>Expires after:</label>
            <select id="expiryTime">
              <option value="never">Never</option>
              <option value="1h">1 hour</option>
              <option value="24h">24 hours</option>
              <option value="7d">7 days</option>
              <option value="30d">30 days</option>
            </select>
          </div>
        </div>
        <button id="generateShareLink" class="btn btn-success">
          <i class="fas fa-check"></i> Generate Link
        </button>
      </div>
    </div>
  `;
  
  // Add the modal to the document
  document.body.appendChild(shareModal);
  
  // Show the modal
  shareModal.style.display = 'block';
  
  // Close modal functionality
  document.getElementById('closeShareModal').addEventListener('click', function() {
    shareModal.style.display = 'none';
    setTimeout(() => {
      document.body.removeChild(shareModal);
    }, 300);
  });
  
  // Toggle password field
  document.getElementById('passwordProtect').addEventListener('change', function() {
    const passwordField = document.getElementById('passwordField');
    passwordField.style.display = this.checked ? 'block' : 'none';
  });
  
  // Copy link functionality
  document.getElementById('copyShareLink').addEventListener('click', function() {
    const shareLinkInput = document.getElementById('shareLinkInput');
    shareLinkInput.select();
    document.execCommand('copy');
    
    // Show copied notification
    this.innerHTML = '<i class="fas fa-check"></i> Copied!';
    setTimeout(() => {
      this.innerHTML = '<i class="fas fa-copy"></i> Copy';
    }, 2000);
  });
  
  // Generate share link with options
  document.getElementById('generateShareLink').addEventListener('click', function() {
    const passwordProtected = document.getElementById('passwordProtect').checked;
    const password = passwordProtected ? document.getElementById('sharePassword').value : '';
    const expiryTime = document.getElementById('expiryTime').value;
    
    // AJAX request to save share information
    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'share_handler.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function() {
      if (xhr.readyState === 4 && xhr.status === 200) {
        try {
          const response = JSON.parse(xhr.responseText);
          if (response.success) {
            // Update the share link with the new ID
            const shareLinkInput = document.getElementById('shareLinkInput');
            shareLinkInput.value = window.location.origin + '/selfhostedgdrive/share.php?id=' + response.shareId;
            
            // Show success message
            showToast('Share link generated successfully!', 'success');
          } else {
            showToast('Error generating share link: ' + response.message, 'error');
          }
        } catch (e) {
          showToast('Error processing server response', 'error');
          console.error('Error parsing JSON response:', e, xhr.responseText);
        }
      } else if (xhr.readyState === 4) {
        showToast('Server error: ' + xhr.status, 'error');
      }
    };
    
    // Prepare data
    const data = 'action=create_share&file_path=' + encodeURIComponent(filePath) + 
                 '&share_id=' + encodeURIComponent(shareId) + 
                 '&password=' + encodeURIComponent(password) + 
                 '&expiry=' + encodeURIComponent(expiryTime);
    
    xhr.send(data);
  });
}

// Generate a unique ID for sharing
function generateUniqueId() {
  return 'share_' + Math.random().toString(36).substr(2, 9) + '_' + Date.now();
} 