# Box API Integration for WordPress

A comprehensive WordPress plugin for integrating with Box.com using OAuth 2.0 authentication. Manage files, folders, and collaborate directly from your WordPress admin.

## ⚠️ SECURITY WARNING

**NEVER hardcode API credentials in code!** The credentials provided should only be entered through the secure admin interface after installation.

## Features

### Core Functionality
- **OAuth 2.0 Authentication**: Secure Box API authentication with automatic token refresh
- **File Management**: Upload, download, delete, and move files
- **Folder Management**: Create, rename, delete folders and navigate folder structure
- **File Browser**: Visual file explorer with breadcrumb navigation
- **Bulk Operations**: Select multiple files for batch operations
- **Search**: Find files and folders quickly
- **Sharing**: Create shared links with access control
- **Collaboration**: Add collaborators with specific permissions
- **Storage Monitoring**: Track storage usage and limits

### Advanced Features
- **Large File Support**: Automatic chunked upload for files over 50MB
- **Upload from URL**: Download and upload files directly from URLs
- **File Preview**: Preview images and get file information
- **Activity Logging**: Track all API operations
- **Token Management**: Automatic token refresh before expiration
- **Multiple Upload**: Drag & drop multiple files
- **Progress Tracking**: Real-time upload progress

## Requirements

- WordPress 5.0 or higher
- PHP 7.2 or higher
- Box Developer Account
- Box Application with OAuth 2.0 enabled

## Installation

1. **Download and Install Plugin**
   ```
   - Upload the `box-api-plugin` folder to `/wp-content/plugins/`
   - Activate the plugin through the 'Plugins' menu in WordPress
   ```

2. **Create Box Application**
   - Go to [Box Developer Console](https://app.box.com/developers/console)
   - Click "Create New App"
   - Choose "Custom App"
   - Select "User Authentication (OAuth 2.0)"
   - Name your app

3. **Configure Box Application**
   - In your Box App configuration:
     - Copy the Client ID and Client Secret
     - Add the Redirect URI from WordPress plugin settings
     - Enable necessary scopes (read, write, manage)
     - Save changes

4. **Configure WordPress Plugin**
   - Go to WordPress Admin > Box Integration > Settings
   - Enter your Client ID and Client Secret
   - Optionally enter Enterprise ID
   - Save settings
   - Click "Authorize with Box"
   - Grant permissions when prompted

## Configuration

### Token Refresh Settings

The plugin automatically refreshes Box API access tokens before they expire. Box tokens expire after 60 minutes. You have two options for managing token refresh:

#### Option 1: Keep Connection Alive (Recommended for Most Users)

**Description:** Automatically maintains continuous authentication without ever expiring.

**Configuration:**
- **Location:** Settings > Keep Connection Alive
- **How it works:**
  - Enable the "Keep Connection Alive" checkbox
  - Tokens automatically refresh every 45 minutes in the background
  - Authentication is maintained indefinitely without manual intervention
  - No need to re-authorize or worry about token expiration
  - Manual refresh interval is automatically disabled when this is enabled

**Use Cases:**
- Production environments requiring uninterrupted access
- Applications with regular scheduled tasks
- Sites where re-authentication would disrupt service
- Any scenario where you want "set it and forget it" reliability

**Note:** While individual Box API tokens still expire after 60 minutes (Box API limitation), the plugin continuously refreshes them in the background to maintain your connection indefinitely. From your perspective, authentication "never expires."

#### Option 2: Manual Refresh Interval

**Description:** Set a custom token refresh interval for more control.

**Configuration:**
- **Range:** 5-55 minutes
- **Default:** 50 minutes (recommended)
- **Location:** Settings > Manual Refresh Interval

**How it works:**
- The plugin schedules automatic token refresh at your specified interval
- Tokens are refreshed in the background using WordPress cron
- Changes take effect after the next token refresh or when you re-authorize
- Setting the interval too close to 60 minutes may result in expired tokens
- Setting it too frequently may increase API calls unnecessarily

**Recommendations:**
- Use 50 minutes (default) for most use cases
- Use shorter intervals (30-40 minutes) for high-traffic sites
- Minimum 5 minutes to prevent excessive API calls
- Maximum 55 minutes to ensure refresh before expiry

**Use Cases:**
- Development/testing environments where you want more control
- Scenarios where you want to minimize API refresh calls
- When you need to coordinate refresh timing with other processes

### Box App Settings

In the Box Developer Console, configure these settings:

**OAuth 2.0 Redirect URI:**
```
https://yoursite.com/wp-admin/admin.php?page=box-api-integration&box_oauth_callback=1
```

**Application Scopes:**
- Read all files and folders
- Write all files and folders  
- Manage users
- Manage groups
- Manage webhooks
- Manage enterprise properties

### Plugin Settings

| Setting | Description | Required | Default |
|---------|-------------|----------|---------|
| Client ID | Your Box App Client ID | Yes | - |
| Client Secret | Your Box App Client Secret | Yes | - |
| Enterprise ID | Your Box Enterprise ID | No | - |
| Redirect URI | OAuth callback URL (auto-generated) | Yes | - |
| Keep Connection Alive | Maintain continuous authentication (never expire) | No | Disabled |
| Manual Refresh Interval | How often to refresh access tokens when keep-alive is disabled | No | 50 minutes |

## Usage

### File Manager

Navigate to **Box Integration > File Manager** to:
- Browse files and folders
- Upload new files
- Create folders
- Download files
- Delete items
- Search for files
- Share files

### Upload Files

Navigate to **Box Integration > Upload Files** to:
- Drag & drop multiple files
- Select upload destination
- Upload from URLs
- View recent uploads

### Programmatic Usage

#### Upload a File
```php
$credentials = Box_API_Integration::get_instance()->get_credentials();
$file_manager = new Box_File_Manager($credentials);

$file = array(
    'name' => 'document.pdf',
    'tmp_name' => '/path/to/file.pdf',
    'type' => 'application/pdf',
    'size' => filesize('/path/to/file.pdf')
);

$result = $file_manager->upload_file($file, '0'); // '0' is root folder

if ($result['success']) {
    echo 'File uploaded: ' . $result['file']['id'];
}
```

#### List Folder Contents
```php
$client = new Box_API_Client($credentials);
$items = $client->list_folder_items('0'); // '0' for root folder

if (!is_wp_error($items)) {
    foreach ($items['entries'] as $item) {
        echo $item['name'] . ' (' . $item['type'] . ')<br>';
    }
}
```

#### Create a Folder
```php
$folder_manager = new Box_Folder_Manager($credentials);
$result = $folder_manager->create_folder('New Folder', '0');

if ($result['success']) {
    echo 'Folder created: ' . $result['folder']['id'];
}
```

#### Download a File
```php
$file_manager = new Box_File_Manager($credentials);
$result = $file_manager->download_to_server('file_id', '/local/path/file.pdf');

if ($result['success']) {
    echo 'File downloaded to: ' . $result['path'];
}
```

#### Search for Files
```php
$client = new Box_API_Client($credentials);
$results = $client->search('quarterly report', 'file', 10);

if (!is_wp_error($results)) {
    foreach ($results['entries'] as $file) {
        echo $file['name'] . '<br>';
    }
}
```

#### Create Shared Link
```php
$client = new Box_API_Client($credentials);
$result = $client->create_shared_link('file_id', 'open', 'optional_password');

if (!is_wp_error($result)) {
    echo 'Shared link: ' . $result['shared_link']['url'];
}
```

### Hooks and Filters

#### Actions
```php
// After file upload
do_action('box_file_uploaded', $file_data, $folder_id);

// After folder creation
do_action('box_folder_created', $folder_data, $parent_id);

// After authentication
do_action('box_authenticated', $user_data);
```

#### Filters
```php
// Modify upload parameters
add_filter('box_upload_params', function($params) {
    $params['description'] = 'Uploaded via WordPress';
    return $params;
});

// Modify API request headers
add_filter('box_api_headers', function($headers) {
    $headers['X-Custom-Header'] = 'value';
    return $headers;
});
```

### Shortcodes

Display files from a Box folder:
```
[box_files folder="0" limit="10"]
```

Create upload form:
```
[box_upload folder="0"]
```

## API Reference

### Main Classes

#### Box_API_Client
Main API client for Box operations

**Methods:**
- `request($endpoint, $method, $data, $upload)` - Make API request
- `test_connection()` - Test API connection
- `get_current_user()` - Get authenticated user info
- `list_folder_items($folder_id, $limit, $offset)` - List folder contents
- `get_file_info($file_id)` - Get file details
- `download_file($file_id)` - Download file
- `delete_file($file_id)` - Delete file
- `search($query, $type, $limit)` - Search files/folders

#### Box_File_Manager
Handle file operations

**Methods:**
- `upload_file($file, $folder_id)` - Upload file
- `chunked_upload($file, $folder_id)` - Upload large file
- `upload_from_url($url, $folder_id, $filename)` - Upload from URL
- `download_to_server($file_id, $destination)` - Download to server
- `get_thumbnail($file_id, $size)` - Get file thumbnail

#### Box_Folder_Manager
Handle folder operations

**Methods:**
- `create_folder($name, $parent_id)` - Create folder
- `get_folder_tree($parent_id, $depth)` - Get folder hierarchy
- `copy_folder($folder_id, $parent_id, $name)` - Copy folder
- `move_folder($folder_id, $parent_id)` - Move folder
- `delete_folder($folder_id, $recursive)` - Delete folder

#### Box_Auth
Handle OAuth authentication

**Methods:**
- `get_authorization_url($state)` - Get auth URL
- `exchange_code_for_token($code)` - Exchange code for token
- `refresh_access_token($refresh_token)` - Refresh token
- `revoke_token($token)` - Revoke token

## Database Tables

The plugin creates one table:

**{prefix}_box_api_logs**
- `id` - Log entry ID
- `action` - Action performed
- `file_id` - Box file ID
- `file_name` - File name
- `folder_id` - Box folder ID
- `user_id` - WordPress user ID
- `status` - Success/error
- `message` - Log message
- `created_at` - Timestamp

## Troubleshooting

### Common Issues

#### Authentication Failed
- Verify Client ID and Client Secret are correct
- Check Redirect URI matches exactly
- Ensure Box App is configured for OAuth 2.0
- Try clearing authentication and re-authorizing

#### Upload Failed
- Check file size (50MB limit for direct upload)
- Verify folder permissions
- Check available storage space
- Ensure file name is unique in folder

#### Token Expired
- Plugin should auto-refresh tokens
- Manually refresh via Settings > Refresh Token
- Re-authorize if refresh fails

### Debug Mode

Enable WordPress debug mode for detailed logs:
```php
// In wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

Check logs at: `/wp-content/debug.log`

## Security Best Practices

1. **Never commit credentials to version control**
2. **Use environment variables in production:**
   ```php
   // In wp-config.php
   define('BOX_CLIENT_ID', getenv('BOX_CLIENT_ID'));
   define('BOX_CLIENT_SECRET', getenv('BOX_CLIENT_SECRET'));
   ```

3. **Rotate credentials regularly**
4. **Limit plugin access to admin users only**
5. **Use HTTPS for all communications**
6. **Keep WordPress and plugin updated**
7. **Monitor activity logs regularly**

## Performance Optimization

- Files are uploaded in chunks for better reliability
- API responses are cached where appropriate
- Token refresh is scheduled via WP-Cron
- Database logs are cleaned automatically (30-day retention)

## Support

For issues or questions:
1. Check the [Box API Documentation](https://developer.box.com/)
2. Review plugin logs in WordPress admin
3. Enable debug mode for detailed errors
4. Check Box Developer Console for API errors

## Contributing

Contributions are welcome! Please:
1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Submit a pull request

## License

GPL v2 or later

## Changelog

### 1.2.0
- **NEW:** Added "Keep Connection Alive" feature for continuous authentication (never expire)
- When enabled, tokens automatically refresh every 45 minutes indefinitely
- Authentication is maintained without manual intervention or re-authorization
- Manual refresh interval automatically disabled when keep-alive is enabled
- Enhanced UI with dynamic toggling between keep-alive and manual modes
- Updated documentation with comprehensive token refresh options guide

### 1.1.0
- Added configurable token refresh interval setting
- Improved token management with custom refresh schedules
- Enhanced admin UI with token refresh interval field
- Added validation for refresh interval (5-55 minutes)
- Updated documentation with token refresh configuration

### 1.0.0
- Initial release
- OAuth 2.0 authentication
- File and folder management
- File browser interface
- Upload functionality
- Search capability
- Sharing features
- Activity logging

## Credits

Developed for WordPress integration with Box.com API.

---

**Remember:** Always keep your API credentials secure and never expose them in code or public repositories!
